import { parseRelayJson } from "./fishing-map";

const schemas = new Map();
async function schemaFor(host, database, signal) {
  const key = `${host}/${database}`;
  if (schemas.has(key)) return schemas.get(key);
  const response = await fetch(
    `${host}/v1/database/${database}/schema?version=9`,
    { signal },
  );
  if (!response.ok)
    throw new Error(`Relay schema unavailable (${response.status})`);
  const schema = await response.json();
  const fields = new Map(
    schema.tables
      .filter((t) => ["resource_state", "location_state"].includes(t.name))
      .map((t) => [
        t.name,
        schema.typespace.types[t.product_type_ref].Product.elements.map(
          (e) => e.name.some,
        ),
      ]),
  );
  for (const name of ["resource_state", "location_state"]) {
    if (!fields.has(name)) throw new Error(`Relay does not expose ${name}`);
  }
  schemas.set(key, fields);
  return fields;
}

// Read-only public subscription. Each instance owns its socket, timeout and reconnect.
export function subscribeMapDirect(region, queries, callbacks) {
  const database = `bitcraft-live-${region}`;
  const hosts = [
    "https://relay.bitjita.com",
    `https://relay.bitcraftsync.app:${3000 + region}`,
  ];
  let stopped = false,
    socket,
    timer,
    reconnect,
    attempt = 0,
    abort,
    requestId = 1,
    greeted = false,
    refreshDeadline;
  const sendQueries = () =>
    socket.send(
      JSON.stringify({
        Subscribe: { request_id: requestId, query_strings: queries },
      }),
    );
  async function connect() {
    if (stopped) return;
    const host = hosts[attempt++ % hosts.length];
    let ready = false,
      failed = false;
    greeted = false;
    abort = new AbortController();
    const fail = (message) => {
      if (failed || stopped) return;
      failed = true;
      clearTimeout(timer);
      abort.abort();
      if (socket) {
        socket.onclose = null;
        socket.close();
      }
      callbacks.status?.("reconnecting", message);
      reconnect = setTimeout(connect, 2500);
    };
    refreshDeadline = () => {
      ready = false;
      clearTimeout(timer);
      timer = setTimeout(
        () => fail("Map data is taking too long to arrive; reconnecting."),
        18000,
      );
    };
    callbacks.status?.("connecting");
    timer = setTimeout(
      () => fail("Map data is taking too long to arrive; reconnecting."),
      18000,
    );
    try {
      const fields = await schemaFor(host, database, abort.signal);
      if (stopped || failed) return;
      socket = new WebSocket(
        `${host.replace(/^http/, "ws")}/v1/database/${database}/subscribe?compression=None`,
        "v1.json.spacetimedb",
      );
      socket.binaryType = "arraybuffer";
      socket.onerror = () => fail("Map connection interrupted; reconnecting.");
      socket.onclose = () => fail("Map connection closed; reconnecting.");
      socket.onmessage = (event) => {
        if (stopped || failed) return;
        try {
          const message = parseRelayJson(
            typeof event.data === "string"
              ? event.data
              : new TextDecoder().decode(event.data),
          );
          if (message.IdentityToken) {
            greeted = true;
            sendQueries();
          }
          if (message.SubscriptionError)
            throw new Error(
              message.SubscriptionError.error ?? "Map subscription failed",
            );
          const update =
            message.InitialSubscription?.database_update ??
            message.TransactionUpdate?.status?.Committed ??
            message.TransactionUpdateLight?.database_update;
          if (!update) return;
          const initial = Boolean(message.InitialSubscription);
          if (
            initial &&
            message.InitialSubscription.request_id !== undefined &&
            message.InitialSubscription.request_id !== requestId
          )
            return;
          const changes = [];
          for (const table of update.tables ?? [])
            for (const rows of table.updates ?? []) {
              const names = fields.get(table.table_name);
              if (!names) continue;
              for (const action of ["deletes", "inserts"])
                for (const raw of rows[action] ?? []) {
                  const parsed =
                    typeof raw === "string" ? parseRelayJson(raw) : raw;
                  const row = Array.isArray(parsed)
                    ? Object.fromEntries(
                        names.map((name, i) => [name, parsed[i]]),
                      )
                    : parsed;
                  changes.push({ table: table.table_name, action, row });
                }
            }
          if (initial) {
            ready = true;
            clearTimeout(timer);
          }
          if (initial || changes.length) callbacks.rows(changes, initial);
          if (ready && initial) callbacks.status?.("live");
        } catch (error) {
          fail(error.message);
        }
      };
    } catch (error) {
      if (!stopped && !failed) fail(error.message);
    }
  }
  connect();
  const stop = () => {
    stopped = true;
    clearTimeout(timer);
    clearTimeout(reconnect);
    abort?.abort();
    if (socket) {
      socket.onclose = null;
      socket.close();
    }
  };
  stop.replace = (next) => {
    if (stopped) return;
    queries = next;
    requestId++;
    callbacks.status?.("connecting");
    if (greeted && socket?.readyState === 1) {
      refreshDeadline();
      sendQueries();
    }
  };
  return stop;
}
