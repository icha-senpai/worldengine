import WebSocket from "ws";
import {
  DbConnectionBuilder,
  DbConnectionImpl,
  schema,
  table,
  t,
} from "spacetimedb";
import pinned from "./boss-wire-contract.json";
import { schemaContract } from "./bitcraft-relay";
import { BossProjection, type BossMutation } from "./boss-projection";
import type { BossDefeat } from "../bitcraft/src/bosses";

export const BOSS_TABLES = [
  "enemy_state",
  "health_state",
  "contribution_state",
  "player_username_state",
  "attack_event",
];
export const BOSS_QUERIES = BOSS_TABLES.map((name) =>
  name === "health_state"
    ? "SELECT * FROM health_state WHERE health = 0"
    : `SELECT * FROM ${name}`,
);

// Build the small, reviewed client contract from the pinned SATS types. The
// official SDK owns v2 transaction and event decoding; no private SDK imports.
function builder(type: any, name: string): any {
  if (type.Product)
    return t.object(
      name,
      Object.fromEntries(
        type.Product.elements.map((e: any, i: number) => [
          e.name,
          builder(e.type, `${name}_${i}`),
        ]),
      ),
    );
  if (type.Sum)
    return t.enum(
      name,
      Object.fromEntries(
        type.Sum.variants.map((e: any, i: number) => [
          e.name,
          builder(e.type, `${name}_${i}`),
        ]),
      ),
    );
  if (type.Array) return t.array(builder(type.Array, `${name}_array`));
  const primitive = Object.keys(type)[0].toLowerCase();
  const factory = (t as any)[primitive];
  if (!factory) throw Error(`Unsupported boss relay type: ${primitive}`);
  return factory();
}
function remoteModule() {
  const tables = Object.fromEntries(
    BOSS_TABLES.map((name) => {
      const contract = (pinned as any)[name];
      const columns = Object.fromEntries(
        contract.type.Product.elements.map((e: any, i: number) => {
          let column = builder(e.type, `Boss_${name}_${i}`);
          if (contract.key.includes(i)) column = column.primaryKey();
          return [e.name, column];
        }),
      );
      return [
        name,
        table({ name, event: name === "attack_event" }, t.row(columns)),
      ];
    }),
  );
  return {
    versionInfo: { cliVersion: "2.12.0" },
    tables: schema(tables).schemaType.tables,
    reducers: [],
    procedures: [],
  } as any;
}

// Bounded transport, uncompressed v2 only, with protocol ping/pong liveness.
class BossSocket {
  private socket: WebSocket;
  private heartbeat?: ReturnType<typeof setInterval>;
  private awaitingPong = false;
  constructor(url: URL, database: string) {
    const target = new URL(
      `/v1/database/${database}/subscribe?compression=None`,
      url,
    );
    this.socket = new WebSocket(target, "v2.bsatn.spacetimedb", {
      maxPayload: 64_000_000,
      perMessageDeflate: false,
      handshakeTimeout: 15000,
    });
    this.socket.on("open", () => {
      this.heartbeat = setInterval(() => {
        if (this.awaitingPong) {
          this.close();
          return;
        }
        this.awaitingPong = true;
        this.socket.ping();
      }, 30000);
    });
    this.socket.on("pong", () => {
      this.awaitingPong = false;
    });
    this.socket.on("close", () => clearInterval(this.heartbeat));
  }
  get protocol() {
    return this.socket.protocol;
  }
  get readyState() {
    return this.socket.readyState;
  }
  send(message: Uint8Array) {
    this.socket.send(message);
  }
  close() {
    clearInterval(this.heartbeat);
    this.socket.terminate();
  }
  set onopen(fn: () => void) {
    this.socket.on("open", fn);
  }
  set onclose(fn: (e: any) => void) {
    this.socket.on("close", (code, reason) =>
      fn({ code, reason: reason.toString() }),
    );
  }
  set onerror(fn: (e: any) => void) {
    this.socket.on("error", (error) => fn({ message: error.message, error }));
  }
  set onmessage(fn: (e: { data: Uint8Array }) => void) {
    this.socket.on("message", (data) => {
      const bytes = data as Buffer;
      if (bytes[0] !== 0) {
        this.close();
        return;
      }
      fn({ data: bytes.subarray(1) });
    });
  }
}

export class BossRelay {
  ready = false;
  generation = 0;
  private stopped = false;
  private connection?: DbConnectionImpl<any>;
  private retry?: ReturnType<typeof setTimeout>;
  private timeout?: ReturnType<typeof setTimeout>;
  private guard?: ReturnType<typeof setInterval>;
  private abort?: AbortController;
  private failures = 0;
  private pending = new Map<string, BossMutation[]>();
  private projection: BossProjection;
  constructor(
    readonly region: number,
    private changed: (defeats: BossDefeat[]) => void,
    private status: (ready: boolean, error: string) => void,
  ) {
    this.projection = new BossProjection((id) => {
      const rows = (this.connection?.db as any)?.player_username_state;
      return rows?.entity_id.find(BigInt(id))?.username ?? id;
    });
  }
  async start() {
    if (this.stopped) return;
    const generation = ++this.generation;
    this.abort = new AbortController();
    const host = `https://relay.bitcraftsync.app:${3000 + this.region}`;
    try {
      const response = await fetch(
        `${host}/v1/database/bitcraft-live-${this.region}/schema?version=9`,
        {
          signal: AbortSignal.any([
            this.abort.signal,
            AbortSignal.timeout(15000),
          ]),
        },
      );
      if (!response.ok) throw Error("Boss relay schema unavailable.");
      const source: any = await response.json();
      for (const name of BOSS_TABLES) {
        const definition = source.tables.find((x: any) => x.name === name);
        if (
          !definition?.table_access.Public ||
          JSON.stringify(schemaContract(source, name)) !==
            JSON.stringify((pinned as any)[name])
        )
          throw Error(
            `Boss recording paused: upstream ${name} contract changed.`,
          );
      }
      if (this.stopped || generation !== this.generation) return;
      this.timeout = setTimeout(
        () => this.fail("Boss relay snapshot timed out."),
        45000,
      );
      const remote = remoteModule();
      const conn = (this.connection = new DbConnectionBuilder<any>(
        remote,
        (config) => new DbConnectionImpl<any>(config),
      )
        .withUri(host.replace(/^http/, "ws"))
        .withDatabaseName(`bitcraft-live-${this.region}`)
        .withCompression("none")
        .withWSFn(async (args) => new BossSocket(args.url, args.nameOrAddress))
        .onConnect((conn: any) => {
          if (this.stopped || generation !== this.generation) {
            conn.disconnect();
            return;
          }
          for (const name of BOSS_TABLES) {
            const rows = conn.db[name];
            const record = (ctx: any, row: any, kind: "insert" | "delete") => {
              if (!this.ready || ctx.event.tag !== "Transaction") return;
              const key = String(ctx.event.id),
                first = !this.pending.has(key);
              const changes = this.pending.get(key) ?? [];
              if (changes.length >= 20000) {
                this.fail("Boss transaction exceeded its operation limit.");
                return;
              }
              changes.push({ table: name, row, kind });
              this.pending.set(key, changes);
              if (first)
                queueMicrotask(() => {
                  const transaction = this.pending.get(key);
                  this.pending.delete(key);
                  if (
                    !transaction ||
                    this.stopped ||
                    generation !== this.generation
                  )
                    return;
                  try {
                    const defeats = this.projection.apply(transaction);
                    if (defeats.length) this.changed(defeats);
                  } catch (error) {
                    this.fail((error as Error).message);
                  }
                });
            };
            rows.onInsert((ctx: any, row: any) => record(ctx, row, "insert"));
            rows.onDelete((ctx: any, row: any) => record(ctx, row, "delete"));
            rows.onUpdate((ctx: any, before: any, after: any) => {
              record(ctx, before, "delete");
              record(ctx, after, "insert");
            });
          }
          conn
            .subscriptionBuilder()
            .onError((_ctx: any, error: any) =>
              this.fail(error?.message || "Boss subscription refused."),
            )
            .onApplied(() => {
              clearTimeout(this.timeout);
              this.projection.baseline(
                conn.db.enemy_state.iter(),
                conn.db.contribution_state.iter(),
                conn.db.health_state.iter(),
              );
              this.ready = true;
              this.failures = 0;
              this.status(true, "");
            })
            .subscribe(BOSS_QUERIES);
          this.guard = setInterval(() => {
            const count = BOSS_TABLES.reduce(
              (n, key) => n + Number(conn.db[key].count()),
              0,
            );
            if (count > 200000)
              this.fail("Boss relay exceeded its cached row limit.");
          }, 15000);
        })
        .onConnectError((_ctx: any, error: Error) =>
          this.fail(error?.message || "Boss connection failed."),
        )
        .onDisconnect(() => {
          if (!this.stopped && generation === this.generation)
            this.fail("Boss relay disconnected; recording gap.");
        })
        .build());
      void conn;
    } catch (error) {
      if (!this.stopped && generation === this.generation)
        this.fail((error as Error).message);
    }
  }
  private fail(error: string) {
    if (this.stopped) return;
    ++this.generation;
    this.ready = false;
    clearTimeout(this.timeout);
    clearInterval(this.guard);
    clearTimeout(this.retry);
    this.abort?.abort();
    this.connection?.disconnect();
    this.connection = undefined;
    this.pending.clear();
    this.projection.reset();
    this.status(false, error.slice(0, 240));
    this.retry = setTimeout(
      () => void this.start(),
      Math.min(60000, 2000 * 2 ** Math.min(this.failures++, 5)),
    );
  }
  stop() {
    this.stopped = true;
    ++this.generation;
    clearTimeout(this.timeout);
    clearTimeout(this.retry);
    clearInterval(this.guard);
    this.abort?.abort();
    this.connection?.disconnect();
    this.connection = undefined;
    this.pending.clear();
    this.projection.reset();
    this.ready = false;
  }
}
