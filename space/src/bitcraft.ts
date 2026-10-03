import { ref } from "vue";
import { DbConnection } from "./bindings/bitcraft";
export const bitcraftConnected = ref(false);
export const bitcraftError = ref("");
let connection: DbConnection | null = null;
let connecting: Promise<DbConnection> | null = null;
const identityKey = `space.bitcraft.identity:${import.meta.env.VITE_SPACETIMEDB_HOST || "local"}:${import.meta.env.VITE_BITCRAFT_DATABASE || "space-bitcraft-tools"}`;
export function connectBitcraft(): Promise<DbConnection> {
  if (connection?.isActive) return Promise.resolve(connection);
  if (connecting) return connecting;
  connecting = new Promise((resolve, reject) => {
    connection = DbConnection.builder()
      .withUri(import.meta.env.VITE_SPACETIMEDB_HOST || "ws://127.0.0.1:3100")
      .withDatabaseName(
        import.meta.env.VITE_BITCRAFT_DATABASE || "space-bitcraft-tools",
      )
      .withToken(localStorage.getItem(identityKey) || undefined)
      .onConnect((conn, _identity, token) => {
        localStorage.setItem(identityKey, token);
        bitcraftConnected.value = true;
        bitcraftError.value = "";
        connecting = null;
        resolve(conn);
      })
      .onConnectError((_ctx, error) => {
        bitcraftError.value = "The live data connection is unavailable.";
        connecting = null;
        reject(error);
      })
      .onDisconnect(() => {
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
    subscription.unsubscribe();
    conn.db.snapshot.removeOnInsert(update);
    conn.db.snapshot.removeOnUpdate(updateRow);
  };
}
