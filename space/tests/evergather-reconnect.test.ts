import { afterEach, expect, it, vi } from "vitest";
import { nextTick, ref } from "vue";

const fake = vi.hoisted(() => ({ attempts: [] as any[] }));
vi.mock("../src/auth", async () => {
  const { ref } = await import("vue");
  return { user: ref(null), localPlay: true, discordLinkConfigured: false };
});
vi.mock("../src/evergather/market", () => ({
  loadMarketPage: vi.fn(),
  marketListingsFor: () => [],
}));
vi.mock("../src/bindings/evergather", () => ({
  DbConnection: {
    builder() {
      const callbacks: any = {},
        subscriptions: any[] = [];
      const tables = new Map<string, any>();
      const db = new Proxy(
        {},
        {
          get(_target, name: string) {
            if (!tables.has(name))
              tables.set(name, {
                onInsert: vi.fn(),
                onUpdate: vi.fn(),
                onDelete: vi.fn(),
                iter: () =>
                  name === "myPlayer" ? [{ name: "retained character" }] : [],
                key: { find: () => undefined },
              });
            return tables.get(name);
          },
        },
      );
      const conn: any = {
        db,
        isActive: true,
        isReconnecting: false,
        reducers: { setUiScope: vi.fn().mockResolvedValue(undefined) },
        disconnect() {
          conn.isActive = false;
          callbacks.closed?.(conn);
        },
        subscriptionBuilder() {
          let applied: () => void;
          const sub: any = {
            onApplied(fn: () => void) {
              applied = fn;
              return sub;
            },
            onError() {
              return sub;
            },
            subscribe(queries: string[]) {
              const handle = {
                queries,
                applied: () => applied?.(),
                unsubscribe: vi.fn(),
              };
              subscriptions.push(handle);
              return handle;
            },
          };
          return sub;
        },
      };
      const builder: any = {
        withUri: () => builder,
        withDatabaseName: () => builder,
        withToken(token: string) {
          callbacks.token = token;
          return builder;
        },
        withAutomaticReconnect: () => builder,
        withTokenProvider: () => builder,
        onConnect(fn: any) {
          callbacks.connected = fn;
          return builder;
        },
        onAutomaticReconnect(fn: any) {
          callbacks.reconnected = fn;
          return builder;
        },
        onConnectError(fn: any) {
          callbacks.failed = fn;
          return builder;
        },
        onDisconnect(fn: any) {
          callbacks.closed = fn;
          return builder;
        },
        build() {
          fake.attempts.push({ conn, callbacks, subscriptions });
          return conn;
        },
      };
      return builder;
    },
  },
}));
afterEach(() => {
  vi.unstubAllGlobals();
});

it("keeps cached Evergather data and restores the selected panel after a hidden pause", async () => {
  const values = new Map<string, string>();
  vi.stubGlobal("localStorage", {
    getItem: (key: string) => values.get(key) ?? null,
    setItem: (key: string, value: string) => values.set(key, value),
  });
  vi.stubGlobal("sessionStorage", { getItem: () => null, setItem: () => {} });
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
  const app = await import("../src/evergather");
  const enabled = ref(true);
  app.setEvergatherPanel("progress", "leaderboards");
  app.initializeEvergather(enabled);
  const first = fake.attempts[0];
  first.callbacks.connected(first.conn, {}, "retained-character-token");
  first.subscriptions[0].applied();
  await nextTick();
  await Promise.resolve();
  first.subscriptions[1].applied();
  expect(app.evergatherReady.value).toBe(true);
  expect(app.evergatherPanelReady.value).toBe(true);
  page.hidden = true;
  page.dispatchEvent(new Event("visibilitychange"));
  expect(first.conn.isActive).toBe(true);
  page.dispatchEvent(new Event("freeze"));
  for (let i = 0; i < 20; i++) page.dispatchEvent(new Event("resume"));
  await nextTick();
  expect(fake.attempts).toHaveLength(1);
  expect(app.evergather.value).toBe(first.conn);
  expect(app.evergatherReady.value).toBe(true);
  expect(app.evergatherConnected.value).toBe(false);
  expect(app.evergatherReconnecting.value).toBe(true);
  expect([...app.evergather.value!.db.myPlayer.iter()]).toEqual([
    { name: "retained character" },
  ]);
  page.hidden = false;
  page.dispatchEvent(new Event("visibilitychange"));
  page.dispatchEvent(new Event("resume"));
  await nextTick();
  expect(fake.attempts).toHaveLength(2);
  const second = fake.attempts[1];
  expect(second.callbacks.token).toBe("retained-character-token");
  second.callbacks.connected(second.conn, {}, "retained-character-token");
  expect(app.evergather.value).toBe(first.conn);
  expect(app.evergatherConnected.value).toBe(false);
  second.subscriptions[0].applied();
  await nextTick();
  await Promise.resolve();
  expect(app.evergather.value).toBe(second.conn);
  expect(second.conn.reducers.setUiScope).toHaveBeenCalledWith({
    workspace: "progress",
    panel: "leaderboards",
    page: 1,
  });
  expect(app.evergatherPanelReady.value).toBe(false);
  second.subscriptions[1].applied();
  expect(app.evergatherPanelReady.value).toBe(true);
  expect(app.evergatherReconnecting.value).toBe(false);
  page.hidden = true;
  second.callbacks.closed(second.conn, Error("Network lost"), 1, 1000);
  expect(app.evergatherConnected.value).toBe(false);
  enabled.value = false;
  await nextTick();
  page.hidden = false;
  page.dispatchEvent(new Event("visibilitychange"));
  await nextTick();
  expect(fake.attempts).toHaveLength(2);
  expect(app.evergather.value).toBe(null);
});
