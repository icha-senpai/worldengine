import pinned from "./relay-wire-contract.json";
export type RelayRow = Record<string, any>;
export type RelayTables = Map<string, Map<string, RelayRow>>;
type WireType = any;
export const TRADING_TABLES = [
  "sell_order_state",
  "buy_order_state",
  "marketplace_state",
  "barter_stall_state",
  "building_state",
  "building_nickname_state",
  "trade_order_state",
  "claim_state",
  "claim_local_state",
  "player_username_state",
];
export function tradingQueries() {
  return TRADING_TABLES.map((table) =>
    ["building_state", "building_nickname_state"].includes(table)
      ? `SELECT ${table}.* FROM ${table} JOIN barter_stall_state ON ${table}.entity_id = barter_stall_state.entity_id`
      : `SELECT * FROM ${table}`,
  );
}
// The wire parser must preserve large JSON integers before Number loses precision.
export function parseRelayJson(text: string): any {
  return (JSON.parse as any)(
    text,
    (_key: string, value: any, context?: { source?: string }) => {
      if (
        typeof value === "number" &&
        Number.isInteger(value) &&
        !Number.isSafeInteger(value)
      ) {
        if (!context?.source || !/^-?\d+$/.test(context.source))
          throw Error("Lossless JSON parsing requires Node 22.3 or newer.");
        return context.source;
      }
      return value;
    },
  );
}
export function decodeRelayValue(type: WireType, value: any): any {
  if (type.Product) {
    const elements = type.Product.elements;
    if (Array.isArray(value) && value.length !== elements.length)
      throw Error("Relay product width changed.");
    if (!value || typeof value !== "object")
      throw Error("Invalid relay product.");
    return Object.fromEntries(
      elements.map((e: any, i: number) => [
        e.name || String(i),
        decodeRelayValue(
          e.type,
          Array.isArray(value) ? value[i] : value[e.name],
        ),
      ]),
    );
  }
  if (type.Array) {
    if (!Array.isArray(value)) throw Error("Invalid relay array.");
    return value.map((v) => decodeRelayValue(type.Array, v));
  }
  if (type.Sum) {
    const variants = type.Sum.variants;
    let index: any, child: any;
    if (Array.isArray(value)) {
      [index, child] = value;
    } else if (value && typeof value === "object") {
      index = variants.findIndex((v: any) => Object.hasOwn(value, v.name));
      child = value[variants[index]?.name];
    } else throw Error("Invalid relay sum.");
    const variant = variants[Number(index)];
    if (!variant) throw Error("Unknown relay variant.");
    return {
      [variant.name || String(index)]: decodeRelayValue(variant.type, child),
    };
  }
  if (
    type.U64 ||
    type.I64 ||
    type.U128 ||
    type.I128 ||
    type.U256 ||
    type.I256
  ) {
    if (typeof value === "number" && !Number.isSafeInteger(value))
      throw Error("Lossy relay ID.");
    if (!/^-?\d+$/.test(String(value))) throw Error("Invalid relay integer.");
    return String(value);
  }
  if (type.String) {
    if (typeof value !== "string") throw Error("Invalid relay string.");
    return value;
  }
  if (type.Bool) {
    if (typeof value !== "boolean") throw Error("Invalid relay boolean.");
    return value;
  }
  if (!Number.isFinite(Number(value)) || value === null || value === undefined)
    throw Error("Invalid relay number.");
  return Number(value);
}
export function decodeRelayRow(
  table: string,
  raw: any,
  provider: "bitconnect" | "bitsync" = "bitconnect",
): RelayRow {
  const definition = (pinned as Record<string, any>)[provider][table];
  if (!definition) throw Error("Unreviewed relay table.");
  return decodeRelayValue(
    definition.type,
    typeof raw === "string" ? parseRelayJson(raw) : raw,
  );
}
export function relayRowKey(
  table: string,
  row: RelayRow,
  provider: "bitconnect" | "bitsync" = "bitconnect",
): string {
  const d = (pinned as Record<string, any>)[provider][table];
  return d.key
    .map((i: number) => String(row[d.type.Product.elements[i].name]))
    .join(":");
}
function schemaContract(schema: any, name: string): any {
  const table = schema.tables.find((t: any) => t.name === name);
  if (!table) throw Error("Missing relay table: " + name);
  const expand = (t: any, depth = 0): any => {
    if (depth > 30) throw Error("Recursive schema.");
    if (t.Ref !== undefined)
      return expand(schema.typespace.types[t.Ref], depth + 1);
    if (t.Product)
      return {
        Product: {
          elements: t.Product.elements.map((e: any) => ({
            name: e.name.some ?? "",
            type: expand(e.algebraic_type, depth + 1),
          })),
        },
      };
    if (t.Sum)
      return {
        Sum: {
          variants: t.Sum.variants.map((e: any) => ({
            name: e.name.some ?? "",
            type: expand(e.algebraic_type, depth + 1),
          })),
        },
      };
    if (t.Array) return { Array: expand(t.Array, depth + 1) };
    return t;
  };
  return {
    key: table.primary_key,
    type: expand(schema.typespace.types[table.product_type_ref]),
  };
}
export class RelaySubscription {
  readonly tables: RelayTables = new Map();
  ready = false;
  stopped = false;
  generation = 0;
  lastReceivedAt = 0;
  private socket?: WebSocket;
  private retry?: ReturnType<typeof setTimeout>;
  private timeout?: ReturnType<typeof setTimeout>;
  private failures = 0;
  constructor(
    readonly provider: "bitconnect" | "bitsync",
    readonly region: number,
    readonly queries: string[],
    readonly changed: (snapshot: boolean, tables: string[]) => void,
    readonly failed: (error: string) => void,
  ) {}
  async start() {
    if (this.stopped) return;
    this.ready = false;
    const generation = ++this.generation;
    const host =
      this.provider === "bitconnect"
        ? "https://relay.bitjita.com"
        : `https://relay.bitcraftsync.app:${3000 + this.region}`;
    try {
      const response = await fetch(
        `${host}/v1/database/bitcraft-live-${this.region}/schema?version=9`,
        { signal: AbortSignal.timeout(15000) },
      );
      if (!response.ok) throw Error("Relay schema unavailable.");
      const schema = await response.json();
      const names = [
        ...new Set(
          this.queries.map(
            (q) =>
              q.match(/^SELECT (?:\*|\w+\.\*) FROM (\w+)(?: .+)?$/)?.[1] ?? "",
          ),
        ),
      ];
      for (const name of names)
        if (
          JSON.stringify(schemaContract(schema, name)) !==
          JSON.stringify((pinned as Record<string, any>)[this.provider][name])
        )
          throw Error(
            `Relay contract changed for ${name}; regenerate and review bindings.`,
          );
      if (this.stopped || generation !== this.generation) return;
      const ws = (this.socket = new WebSocket(
        `${host.replace(/^http/, "ws")}/v1/database/bitcraft-live-${this.region}/subscribe?compression=None`,
        "v1.json.spacetimedb",
      ));
      this.timeout = setTimeout(
        () => this.disconnect("Relay snapshot timed out."),
        45000,
      );
      ws.addEventListener("message", (event) => {
        if (this.stopped || generation !== this.generation) return;
        try {
          if (typeof event.data !== "string" || event.data.length > 64000000)
            throw Error("Invalid relay frame.");
          const msg = parseRelayJson(event.data);
          this.lastReceivedAt = Date.now();
          if (msg.IdentityToken) {
            ws.send(
              JSON.stringify({
                Subscribe: { request_id: 1, query_strings: this.queries },
              }),
            );
            return;
          }
          if (msg.SubscriptionError || msg.error)
            throw Error("Relay subscription refused.");
          const initial = msg.InitialSubscription;
          const tx = msg.TransactionUpdate ?? msg.TransactionUpdateLight;
          const update =
            initial?.database_update ??
            tx?.status?.Committed ??
            tx?.database_update;
          if (!update) return;
          const changes: (() => void)[] = [];
          const target = initial
            ? new Map<string, Map<string, RelayRow>>(
                names.map((n) => [n, new Map()]),
              )
            : this.tables;
          for (const table of update.tables ?? []) {
            const rows = target.get(table.table_name);
            if (!rows) throw Error("Unexpected subscribed table.");
            for (const set of table.updates ?? []) {
              for (const raw of set.deletes ?? []) {
                const row = decodeRelayRow(
                    table.table_name,
                    raw,
                    this.provider,
                  ),
                  key = relayRowKey(table.table_name, row, this.provider);
                changes.push(() => rows.delete(key));
              }
              for (const raw of set.inserts ?? []) {
                const row = decodeRelayRow(
                    table.table_name,
                    raw,
                    this.provider,
                  ),
                  key = relayRowKey(table.table_name, row, this.provider);
                changes.push(() => rows.set(key, row));
              }
            }
          }
          // Validate the entire frame before changing any cached rows.
          changes.forEach((apply) => apply());
          if (initial) {
            clearTimeout(this.timeout);
            this.tables.clear();
            for (const [n, rows] of target) this.tables.set(n, rows);
            this.ready = true;
            this.failures = 0;
          }
          if (
            [...this.tables.values()].reduce((n, t) => n + t.size, 0) > 300000
          )
            throw Error("Relay scope exceeded its row cap.");
          if (this.ready)
            this.changed(
              Boolean(initial),
              (update.tables ?? []).map((t: any) => t.table_name),
            );
        } catch (error) {
          this.disconnect((error as Error).message);
        }
      });
      ws.addEventListener("error", () => {
        if (!this.stopped && generation === this.generation)
          this.disconnect("Relay connection failed.");
      });
      ws.addEventListener("close", () => {
        if (!this.stopped && generation === this.generation)
          this.disconnect("Relay connection closed.");
      });
    } catch (error) {
      if (!this.stopped && generation === this.generation)
        this.disconnect((error as Error).message);
    }
  }
  private disconnect(error: string) {
    if (this.stopped) return;
    ++this.generation;
    this.ready = false;
    clearTimeout(this.timeout);
    this.socket?.close();
    this.failed(error);
    clearTimeout(this.retry);
    this.retry = setTimeout(
      () => void this.start(),
      Math.min(60000, 2000 * 2 ** Math.min(this.failures++, 5)) +
        Math.floor(Math.random() * 1000),
    );
  }
  stop() {
    this.stopped = true;
    ++this.generation;
    this.ready = false;
    clearTimeout(this.retry);
    clearTimeout(this.timeout);
    this.socket?.close();
  }
}
