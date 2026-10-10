import { ref } from "vue";
import { DbConnection } from "./bindings/bitcraft";
import { recordPageLifecycle } from "./pageLifecycle";
import { spacetimeHost } from "./spacetimeHost";
export const bitcraftConnected = ref(false);
export const bitcraftError = ref("");
let connection: DbConnection | null = null;
let connecting: Promise<DbConnection> | null = null;
let reconnectTimer: ReturnType<typeof setTimeout> | undefined;
let reconnectDelay = 1000;
let disposed = false;
function scheduleReconnect() {
  if (disposed || reconnectTimer) return;
  reconnectTimer = setTimeout(() => {
    reconnectTimer = undefined;
    void connectBitcraft().catch(() => scheduleReconnect());
  }, reconnectDelay);
  reconnectDelay = Math.min(10000, reconnectDelay * 2);
}
const resumeConnection = () => {
  if (!disposed && connection) void connectBitcraft().catch(() => {});
};
const visibleConnection = () => {
  if (!document.hidden) resumeConnection();
};
if (typeof window !== "undefined") {
  window.addEventListener("pageshow", resumeConnection);
  document.addEventListener("visibilitychange", visibleConnection);
}
import.meta.hot?.dispose(() => {
  disposed = true;
  clearTimeout(reconnectTimer);
  window.removeEventListener("pageshow", resumeConnection);
  document.removeEventListener("visibilitychange", visibleConnection);
  connection?.disconnect();
});
const identityKey = `space.bitcraft.identity:${import.meta.env.VITE_SPACETIMEDB_HOST || "local"}:${import.meta.env.VITE_BITCRAFT_DATABASE || "space-bitcraft-tools"}`;
export function connectBitcraft(): Promise<DbConnection> {
  if (connection?.isActive && !connection.isSocketClosed)
    return Promise.resolve(connection);
  if (connection?.isSocketClosed) connection.disconnect();
  if (connecting) return connecting;
  connecting = new Promise((resolve, reject) => {
    let attempt: DbConnection;
    const timeout = setTimeout(() => {
      if (connection === attempt) {
        connecting = null;
        attempt.disconnect();
        scheduleReconnect();
        reject(new Error("The live data connection timed out."));
      }
    }, 15000);
    connection = attempt = DbConnection.builder()
      .withUri(spacetimeHost)
      .withDatabaseName(
        import.meta.env.VITE_BITCRAFT_DATABASE || "space-bitcraft-tools",
      )
      .withToken(localStorage.getItem(identityKey) || undefined)
      .onConnect((conn, _identity, token) => {
        clearTimeout(timeout);
        clearTimeout(reconnectTimer);
        reconnectTimer = undefined;
        reconnectDelay = 1000;
        const keys = [...observedFeeds.keys()];
        for (const close of observedFeeds.values()) close();
        observedFeeds.clear();
        observedConnection = conn;
        localStorage.setItem(identityKey, token);
        bitcraftConnected.value = true;
        recordPageLifecycle("bitcraft-connected");
        bitcraftError.value = "";
        connecting = null;
        resolve(conn);
        for (const key of keys) void observeCollection(key).catch(() => {});
      })
      .onConnectError((_ctx, error) => {
        clearTimeout(timeout);
        if (connection !== attempt) return;
        scheduleReconnect();
        recordPageLifecycle("bitcraft-connect-failed");
        bitcraftError.value = "The live data connection is unavailable.";
        connecting = null;
        reject(error);
      })
      .onDisconnect(() => {
        clearTimeout(timeout);
        if (connection !== attempt) return;
        reject(new Error("The live data connection closed."));
        scheduleReconnect();
        recordPageLifecycle("bitcraft-disconnected");
        bitcraftConnected.value = false;
        connecting = null;
      })
      .build();
  });
  return connecting;
}
export async function requestData(
  resource: string,
  id = "",
  query = "",
  page = 1,
  options: Record<string, unknown> = {},
) {
  const conn = await connectBitcraft();
  return conn.procedures.requestData({
    resource,
    id,
    query,
    page,
    options: JSON.stringify(options),
  });
}
export async function subscribeData(
  key: string,
  changed: (row: {
    payload: string;
    updatedAt: bigint;
    retryAt: bigint;
    error: string;
  }) => void,
) {
  const conn = await connectBitcraft();
  const update = (
    _ctx: unknown,
    row: {
      key: string;
      payload: string;
      updatedAt: bigint;
      retryAt: bigint;
      error: string;
    },
  ) => {
    if (row.key === key) changed(row);
  };
  conn.db.snapshot.onInsert(update);
  const updateRow = (
    _ctx: unknown,
    _old: unknown,
    row: Parameters<typeof update>[1],
  ) => update(_ctx, row);
  conn.db.snapshot.onUpdate(updateRow);
  const subscription = conn
    .subscriptionBuilder()
    .onApplied(() => {
      const row = conn.db.snapshot.key.find(key);
      if (row) changed(row);
    })
    .subscribe([
      `SELECT * FROM snapshot WHERE key = '${key.replace(/'/g, "''")}'`,
    ]);
  return () => {
    if (conn.isActive) subscription.unsubscribe();
    conn.db.snapshot.removeOnInsert(update);
    conn.db.snapshot.removeOnUpdate(updateRow);
  };
}

const observedFeeds = new Map<string, () => void>();
let observedConnection: DbConnection | null = null;
export async function observeCollection(key: string) {
  const conn = await connectBitcraft();
  if (observedConnection !== conn) {
    for (const close of observedFeeds.values()) close();
    observedFeeds.clear();
    observedConnection = conn;
  }
  if (observedFeeds.has(key)) return;
  if (observedFeeds.size >= 100) {
    const oldest = observedFeeds.keys().next().value!;
    observedFeeds.get(oldest)!();
    observedFeeds.delete(oldest);
  }
  const changed = (_ctx: unknown, row: { key: string }) => {
    if (row.key === key)
      window.dispatchEvent(new Event("bitcraft-data-updated"));
  };
  const updated = (_ctx: unknown, _old: unknown, row: { key: string }) =>
    changed(_ctx, row);
  conn.db.collectionFeed.onInsert(changed);
  conn.db.collectionFeed.onUpdate(updated);
  const subscription = conn
    .subscriptionBuilder()
    .subscribe([
      `SELECT * FROM collection_feed WHERE key = '${key.replace(/'/g, "''")}'`,
    ]);
  observedFeeds.set(key, () => {
    if (conn.isActive) subscription.unsubscribe();
    conn.db.collectionFeed.removeOnInsert(changed);
    conn.db.collectionFeed.removeOnUpdate(updated);
  });
}

export async function subscribeStoredMarket(changed: () => void) {
  const conn = await connectBitcraft();
  const inserted = () => changed();
  const updated = () => changed();
  conn.db.marketStatus.onInsert(inserted);
  conn.db.marketStatus.onUpdate(updated);
  conn.db.relayStatus.onInsert(inserted);
  conn.db.relayStatus.onUpdate(updated);
  const subscription = conn
    .subscriptionBuilder()
    .subscribe([
      "SELECT * FROM market_status WHERE key = 'market'",
      "SELECT * FROM relay_status",
    ]);
  return () => {
    if (conn.isActive) subscription.unsubscribe();
    conn.db.marketStatus.removeOnInsert(inserted);
    conn.db.marketStatus.removeOnUpdate(updated);
    conn.db.relayStatus.removeOnInsert(inserted);
    conn.db.relayStatus.removeOnUpdate(updated);
  };
}
