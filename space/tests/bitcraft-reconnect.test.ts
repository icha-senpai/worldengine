import { it, expect, vi, afterEach } from "vitest";
vi.mock("vue", () => ({ ref: (value: unknown) => ({ value }) }));
const fake = vi.hoisted(() => ({ attempts: [] as any[] }));
vi.mock("../src/bindings/bitcraft", () => ({
  DbConnection: {
    builder() {
      const callbacks: any = {};
      const builder: any = {
        withUri: () => builder,
        withDatabaseName: () => builder,
        withToken: (token: string) => {
          callbacks.token = token;
          return builder;
        },
        withAutomaticReconnect: () => {
          callbacks.automatic = true;
          return builder;
        },
        onAutomaticReconnect: (fn: any) => {
          callbacks.reconnected = fn;
          return builder;
        },
        onConnect: (fn: any) => {
          callbacks.connected = fn;
          return builder;
        },
        onConnectError: (fn: any) => {
          callbacks.failed = fn;
          return builder;
        },
        onDisconnect: (fn: any) => {
          callbacks.closed = fn;
          return builder;
        },
        build() {
          const table = () => ({
            onInsert: vi.fn(),
            onUpdate: vi.fn(),
            removeOnInsert: vi.fn(),
            removeOnUpdate: vi.fn(),
          });
          const subscriptions: any[] = [];
          const conn: any = {
            isActive: true,
            isSocketClosed: false,
            isReconnecting: false,
            procedures: {
              requestData: vi.fn().mockResolvedValue({ payload: "fixture" }),
            },
            db: {
              collectionFeed: table(),
              marketStatus: table(),
              relayStatus: table(),
            },
            subscriptionBuilder: () => {
              let applied: (() => void) | undefined;
              const builder: any = {
                onApplied: (fn: () => void) => {
                  applied = fn;
                  return builder;
                },
                subscribe: (queries: string[]) => {
                  const sub = { queries, unsubscribe: vi.fn(), applied };
                  subscriptions.push(sub);
                  if (applied) queueMicrotask(applied);
                  return sub;
                },
              };
              return builder;
            },
            disconnect: () => {
              conn.isActive = false;
              conn.isSocketClosed = true;
              callbacks.closed();
            },
          };
          fake.attempts.push({ conn, callbacks, subscriptions });
          queueMicrotask(() =>
            callbacks.connected(
              conn,
              {},
              callbacks.token || "retained-browser-identity",
            ),
          );
          return conn;
        },
      };
      return builder;
    },
  },
}));
afterEach(() => {
  vi.clearAllTimers();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});
it("keeps the connection and watched subscriptions through SDK recovery, including offline unsubscription", async () => {
  vi.useFakeTimers();
  const values = new Map<string, string>();
  const storage = {
    getItem: (key: string) => values.get(key) ?? null,
    setItem: (key: string, value: string) => values.set(key, value),
  };
  vi.stubGlobal("localStorage", storage);
  vi.stubGlobal("sessionStorage", storage);
  vi.stubGlobal(
    "window",
    Object.assign(new EventTarget(), {
      location: new URL("https://space.test"),
    }),
  );
  vi.stubGlobal(
    "document",
    Object.assign(new EventTarget(), { hidden: false }),
  );
  const app = await import("../src/bitcraft");
  const first = await app.connectBitcraft();
  await app.observeCollection("relaySkills|player/1/skills");
  const closeMarket = await app.subscribeStoredMarket(() => {});
  const attempt = fake.attempts[0];
  expect(attempt.callbacks.automatic).toBe(true);
  expect(attempt.subscriptions).toHaveLength(3);
  first.isActive = false;
  first.isSocketClosed = true;
  first.isReconnecting = true;
  attempt.callbacks.closed(first, Error("Network lost"), 1, 1000);
  expect(app.bitcraftConnected.value).toBe(false);
  expect(app.bitcraftReconnecting.value).toBe(true);
  let recovered = false;
  const waiting = app.connectBitcraft().then((conn) => {
    recovered = true;
    return conn;
  });
  const request = app.requestData("regions");
  await Promise.resolve();
  expect(recovered).toBe(false);
  expect(first.procedures.requestData).not.toHaveBeenCalled();
  closeMarket();
  expect(attempt.subscriptions[2].unsubscribe).toHaveBeenCalledOnce();
  await vi.advanceTimersByTimeAsync(30000);
  expect(fake.attempts).toHaveLength(1);
  first.isActive = true;
  first.isSocketClosed = false;
  first.isReconnecting = false;
  attempt.callbacks.reconnected(first, {}, "retained-browser-identity");
  await Promise.resolve();
  expect(recovered).toBe(false);
  expect(first.procedures.requestData).not.toHaveBeenCalled();
  attempt.subscriptions[0].applied();
  expect(app.bitcraftConnected.value).toBe(true);
  expect(app.bitcraftReconnecting.value).toBe(false);
  expect(await waiting).toBe(first);
  await expect(request).resolves.toEqual({ payload: "fixture" });
  expect(first.procedures.requestData).toHaveBeenCalledOnce();
  expect(attempt.subscriptions[1].queries[0]).toContain(
    "relaySkills|player/1/skills",
  );
  expect(attempt.subscriptions[1].unsubscribe).not.toHaveBeenCalled();
  expect(first.db.collectionFeed.onInsert).toHaveBeenCalledOnce();
  expect(await app.connectBitcraft()).toBe(first);

  first.isActive = false;
  first.isSocketClosed = true;
  first.isReconnecting = true;
  attempt.callbacks.closed(
    first,
    Error("WebSocket closed (code 1006: private token must not be stored)"),
    1,
    1000,
  );
  const timedOut = app.requestData("regions");
  const rejected = expect(timedOut).rejects.toThrow("still reconnecting");
  await vi.advanceTimersByTimeAsync(60000);
  await rejected;
  expect(fake.attempts).toHaveLength(1);
  expect(first.procedures.requestData).toHaveBeenCalledOnce();
  const retry = app.connectBitcraft();
  const terminal = expect(retry).rejects.toThrow("Refresh to reconnect");
  first.isReconnecting = false;
  attempt.callbacks.closed(first, Error("WebSocket closed (code 1008)"));
  await terminal;
  const trail = JSON.parse(values.get("space.pageLifecycle")!);
  expect(
    trail.some((e: any) => e.closeCode === 1006 && e.retryAttempt === 1),
  ).toBe(true);
  expect(JSON.stringify(trail)).not.toContain("private token");
});
