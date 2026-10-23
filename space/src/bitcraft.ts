import { ref } from "vue";
import { DbConnection } from "./bindings/bitcraft";
import {
  recordConnectionLifecycle,
  recordPageLifecycle,
} from "./pageLifecycle";
import { spacetimeHost } from "./spacetimeHost";
import { createConnectionRecovery } from "./connectionRecovery";
import { connectionLifecycle } from "../../shared/connection-lifecycle";
export const bitcraftConnected = ref(false);
export const bitcraftReconnecting = ref(false);
export const bitcraftError = ref("");
let connection: DbConnection | null = null;
let connecting: Promise<DbConnection> | null = null;
let disposed = false;
let retryInitial = false;
let suspended = false;
let resumeNeeded = false;
let sessionToken: string | undefined;
let cancelAttempt: ((error: Error) => void) | null = null;
const connectionListeners = new Set<(conn: DbConnection) => void>();
let readiness: {
  conn: DbConnection;
  promise: Promise<DbConnection>;
  resolve: (conn: DbConnection) => void;
  reject: (error: Error) => void;
  timer: ReturnType<typeof setTimeout>;
} | null = null;
function finishReadiness(conn: DbConnection, error?: Error) {
  if (readiness?.conn !== conn) return;
  const pending = readiness;
  readiness = null;
  clearTimeout(pending.timer);
  if (error) pending.reject(error);
  else pending.resolve(conn);
}
function waitForReadiness(conn: DbConnection): Promise<DbConnection> {
  if (
    conn === connection &&
    conn.isActive &&
    !conn.isSocketClosed &&
    bitcraftConnected.value &&
    !bitcraftReconnecting.value
  )
    return Promise.resolve(conn);
  if (readiness?.conn === conn) return readiness.promise;
  if (readiness)
    finishReadiness(
      readiness.conn,
      new Error("The live data connection was replaced."),
    );
  let resolve!: (conn: DbConnection) => void;
  let reject!: (error: Error) => void;
  const promise = new Promise<DbConnection>((done, fail) => {
    resolve = done;
    reject = fail;
  });
  const timer = setTimeout(() => {
    recordPageLifecycle("bitcraft-readiness-timeout");
    finishReadiness(
      conn,
      new Error(
        "Live data is still reconnecting. Waiting for the next refresh.",
      ),
    );
  }, 60000);
  readiness = { conn, promise, resolve, reject, timer };
  return promise;
}
// The SDK handles established connections. Only a failed first connection
// needs an application retry, because SDK auto-reconnect deliberately excludes it.
const initialRecovery = createConnectionRecovery(
  () => {
    void connectBitcraft().catch(() => {});
  },
  () =>
    !disposed &&
    !suspended &&
    retryInitial &&
    !connecting &&
    !connection?.isReconnecting,
);
const lifecycle = connectionLifecycle(
  () => {
    resumeNeeded = Boolean(connection || connecting || retryInitial);
    suspended = true;
    initialRecovery.cancel();
    const previous = connection;
    connection = null;
    cancelAttempt?.(
      new Error("Live data is paused while this tab is inactive."),
    );
    cancelAttempt = null;
    if (readiness)
      finishReadiness(
        readiness.conn,
        new Error("Live data is paused while this tab is inactive."),
      );
    connecting = null;
    retryInitial = false;
    bitcraftConnected.value = false;
    bitcraftReconnecting.value = resumeNeeded;
    previous?.disconnect();
  },
  () => {
    suspended = false;
    if (!resumeNeeded) return;
    retryInitial = true;
    void connectBitcraft().catch(() => {});
  },
);
import.meta.hot?.dispose(() => {
  disposed = true;
  lifecycle.dispose();
  initialRecovery.stop();
  if (readiness)
    finishReadiness(
      readiness.conn,
      new Error("The live data connection was closed."),
    );
  connection?.disconnect();
});
const identityKey = `space.bitcraft.identity:${import.meta.env.VITE_SPACETIMEDB_HOST || "local"}:${import.meta.env.VITE_BITCRAFT_DATABASE || "space-bitcraft-tools"}`;
export function connectBitcraft(): Promise<DbConnection> {
  if (disposed || suspended)
    return Promise.reject(
      new Error("Live data is paused while this tab is inactive."),
    );
  if (
    connection?.isReconnecting ||
    (connection?.isActive && !connection.isSocketClosed)
  )
    return waitForReadiness(connection);
  if (connection?.isSocketClosed) connection.disconnect();
  if (connecting) return connecting.then(waitForReadiness);
  let token = sessionToken;
  try {
    token ??= localStorage.getItem(identityKey) || undefined;
  } catch {
    /* Retain the current session when storage is unavailable. */
  }
  connecting = new Promise((resolve, reject) => {
    let attempt: DbConnection;
    const timeout = setTimeout(() => {
      if (connection === attempt) {
        connecting = null;
        attempt.disconnect();
        retryInitial = true;
        initialRecovery.schedule();
        reject(new Error("The live data connection timed out."));
      }
    }, 15000);
    cancelAttempt = (error) => {
      clearTimeout(timeout);
      reject(error);
    };
    connection = attempt = DbConnection.builder()
      .withUri(spacetimeHost)
      .withDatabaseName(
        import.meta.env.VITE_BITCRAFT_DATABASE || "space-bitcraft-tools",
      )
      .withToken(token)
      .withAutomaticReconnect()
      .onConnect((conn, _identity, token) => {
        clearTimeout(timeout);
        if (connection !== conn) {
          conn.disconnect();
          return;
        }
        cancelAttempt = null;
        sessionToken = token;
        initialRecovery.reset();
        retryInitial = false;
        const keys = [...observedFeeds.keys()];
        for (const close of observedFeeds.values()) close();
        observedFeeds.clear();
        observedConnection = conn;
        try {
          localStorage.setItem(identityKey, token);
        } catch {
          /* Session identity is retained in memory. */
        }
        recordPageLifecycle("bitcraft-connected", {
          connectionId: conn.connectionId?.toHexString(),
        });
        bitcraftError.value = "";
        connecting = null;
        resolve(conn);
        for (const rebind of connectionListeners) rebind(conn);
        for (const key of keys) void observeCollection(key).catch(() => {});
        // A replay applies every subscription against one consistent snapshot.
        // This small status subscription marks that snapshot ready without
        // replacing connection objects or resubscribing page listeners.
        conn
          .subscriptionBuilder()
          .onApplied(() => {
            if (connection !== conn) return;
            recordPageLifecycle("bitcraft-ready");
            bitcraftConnected.value = true;
            bitcraftReconnecting.value = false;
            bitcraftError.value = "";
            finishReadiness(conn);
            window.dispatchEvent(new Event("bitcraft-data-updated"));
          })
          .subscribe(["SELECT * FROM market_status WHERE key = 'market'"]);
      })
      .onAutomaticReconnect((conn, _identity, token) => {
        if (connection !== conn) return;
        sessionToken = token;
        try {
          localStorage.setItem(identityKey, token);
        } catch {
          /* Session identity is retained in memory. */
        }
        recordPageLifecycle("bitcraft-reconnected", {
          connectionId: conn.connectionId?.toHexString(),
        });
      })
      .onConnectError((_ctx, error, nextAttempt, delayMs) => {
        clearTimeout(timeout);
        if (connection !== attempt) return;
        recordConnectionLifecycle(
          "bitcraft-connect-failed",
          error,
          nextAttempt,
          delayMs,
        );
        if (
          document.hidden &&
          !(error instanceof Error && error.name === "IdentityChangedError")
        ) {
          lifecycle.suspend();
          return;
        }
        if (nextAttempt !== undefined) {
          bitcraftReconnecting.value = true;
          return;
        }
        bitcraftError.value = "The live data connection is unavailable.";
        connecting = null;
        reject(error);
        finishReadiness(
          attempt,
          new Error("The live data connection is unavailable."),
        );
        if (retryInitial) initialRecovery.schedule();
      })
      .onDisconnect((_ctx, error, nextAttempt, delayMs) => {
        clearTimeout(timeout);
        if (connection !== attempt) return;
        reject(new Error("The live data connection closed."));
        recordConnectionLifecycle(
          "bitcraft-disconnected",
          error,
          nextAttempt,
          delayMs,
        );
        if (
          document.hidden &&
          !(error instanceof Error && error.name === "IdentityChangedError")
        ) {
          lifecycle.suspend();
          return;
        }
        bitcraftConnected.value = false;
        bitcraftReconnecting.value = nextAttempt !== undefined;
        if (nextAttempt === undefined) {
          bitcraftError.value =
            "The live data connection closed. Refresh to reconnect.";
          finishReadiness(attempt, new Error(bitcraftError.value));
        }
        connecting = null;
      })
      .build();
    retryInitial = true;
  });
  return connecting.then(waitForReadiness);
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
async function bindSubscription(bind: (conn: DbConnection) => () => void) {
  const conn = await connectBitcraft();
  let close = bind(conn);
  const rebind = (next: DbConnection) => {
    close();
    close = bind(next);
  };
  connectionListeners.add(rebind);
  return () => {
    connectionListeners.delete(rebind);
    close();
  };
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
  return bindSubscription((conn) => {
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
  });
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
    subscription.unsubscribe();
    conn.db.collectionFeed.removeOnInsert(changed);
    conn.db.collectionFeed.removeOnUpdate(updated);
  });
}

export async function subscribeStoredMarket(changed: () => void) {
  return bindSubscription((conn) => {
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
      subscription.unsubscribe();
      conn.db.marketStatus.removeOnInsert(inserted);
      conn.db.marketStatus.removeOnUpdate(updated);
      conn.db.relayStatus.removeOnInsert(inserted);
      conn.db.relayStatus.removeOnUpdate(updated);
    };
  });
}
