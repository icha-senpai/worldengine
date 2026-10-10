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
            db: {
              collectionFeed: table(),
              marketStatus: table(),
              relayStatus: table(),
            },
            subscriptionBuilder: () => ({
              subscribe: (queries: string[]) => {
                const sub = { queries, unsubscribe: vi.fn() };
                subscriptions.push(sub);
                return sub;
              },
            }),
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
it("reconnects after maintenance with the same identity and restores watched XP subscriptions", async () => {
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
    Object.assign(new EventTarget(), { location: new URL("https://space.test") }),
  );
  vi.stubGlobal(
    "document",
    Object.assign(new EventTarget(), { hidden: false }),
  );
  const app = await import("../src/bitcraft");
  const first = await app.connectBitcraft();
  await app.observeCollection("relaySkills|player/1/skills");
  const closeMarket = await app.subscribeStoredMarket(() => {});
  expect(fake.attempts[0].subscriptions).toHaveLength(2);
  first.disconnect();
  expect(app.bitcraftConnected.value).toBe(false);
  closeMarket();
  await vi.advanceTimersByTimeAsync(1000);
  expect(fake.attempts).toHaveLength(2);
  expect(app.bitcraftConnected.value).toBe(true);
  expect(fake.attempts[1].callbacks.token).toBe("retained-browser-identity");
  expect(fake.attempts[1].subscriptions[0].queries[0]).toContain(
    "relaySkills|player/1/skills",
  );
  expect(await app.connectBitcraft()).toBe(fake.attempts[1].conn);
});
