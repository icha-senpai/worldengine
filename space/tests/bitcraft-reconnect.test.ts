import { it, expect, vi, afterEach, beforeEach } from "vitest";
vi.mock("vue", () => ({ ref: (value: unknown) => ({ value }) }));
const fake = vi.hoisted(() => ({ attempts: [] as any[] }));
beforeEach(() => {
  vi.resetModules();
  fake.attempts.length = 0;
});
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
              snapshot: {
                ...table(),
                key: {
                  find: () => ({
                    payload: "cached",
                    updatedAt: 0n,
                    retryAt: 0n,
                    error: "",
                  }),
                },
              },
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
it("pauses frozen and failed hidden sockets, then restores every watched feed once", async () => {
  vi.resetModules();
  fake.attempts.length = 0;
  vi.useFakeTimers();
  const values = new Map<string, string>();
  let blocked = false;
  const storage = {
    getItem: (key: string) => {
      if (blocked) throw Error("Storage disabled");
      return values.get(key) ?? null;
    },
    setItem: (key: string, value: string) => {
      if (blocked) throw Error("Storage disabled");
      values.set(key, value);
    },
  };
  vi.stubGlobal("localStorage", storage);
  vi.stubGlobal("sessionStorage", storage);
  vi.stubGlobal(
    "window",
    Object.assign(new EventTarget(), {
      location: new URL("https://space.test"),
    }),
  );
  const page = Object.assign(new EventTarget(), { hidden: false });
  Object.defineProperty(page, "visibilityState", {
    get: () => (page.hidden ? "hidden" : "visible"),
  });
  vi.stubGlobal("document", page);
  const app = await import("../src/bitcraft");
  page.hidden = true;
  page.dispatchEvent(new Event("freeze"));
  expect(app.bitcraftReconnecting.value).toBe(false);
  page.hidden = false;
  page.dispatchEvent(new Event("visibilitychange"));
  expect(fake.attempts).toHaveLength(0);
  const first = await app.connectBitcraft();
  const changed = vi.fn();
  const closeData = await app.subscribeData("fixture", changed);
  const closeMarket = await app.subscribeStoredMarket(vi.fn());
  await app.observeCollection("relaySkills|player/1/skills");
  expect(fake.attempts[0].subscriptions).toHaveLength(4);
  page.hidden = true;
  page.dispatchEvent(new Event("visibilitychange"));
  expect(first.isActive).toBe(true);
  page.dispatchEvent(new Event("freeze"));
  expect(app.bitcraftConnected.value).toBe(false);
  expect(app.bitcraftReconnecting.value).toBe(true);
  expect(first.isActive).toBe(false);
  await expect(app.requestData("regions")).rejects.toThrow("paused");
  for (let i = 0; i < 20; i++) page.dispatchEvent(new Event("resume"));
  await vi.advanceTimersByTimeAsync(120000);
  expect(fake.attempts).toHaveLength(1);
  blocked = true;
  page.hidden = false;
  page.dispatchEvent(new Event("visibilitychange"));
  page.dispatchEvent(new Event("resume"));
  await vi.advanceTimersByTimeAsync(0);
  const second = await app.connectBitcraft();
  expect(second).not.toBe(first);
  expect(fake.attempts).toHaveLength(2);
  expect(fake.attempts[1].callbacks.token).toBe("retained-browser-identity");
  expect(fake.attempts[1].subscriptions).toHaveLength(4);
  expect(changed).toHaveBeenCalledTimes(2);
  expect(app.bitcraftConnected.value).toBe(true);
  page.hidden = true;
  second.isActive = false;
  second.isSocketClosed = true;
  second.isReconnecting = true;
  fake.attempts[1].callbacks.closed(second, Error("Network lost"), 1, 1000);
  await vi.advanceTimersByTimeAsync(120000);
  expect(fake.attempts).toHaveLength(2);
  closeData();
  closeMarket();
  page.hidden = false;
  page.dispatchEvent(new Event("visibilitychange"));
  await vi.advanceTimersByTimeAsync(0);
  await app.connectBitcraft();
  expect(fake.attempts).toHaveLength(3);
  expect(fake.attempts[2].subscriptions).toHaveLength(2);
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
