import { afterEach, beforeEach, expect, it, vi } from "vitest";
import { createRenderer, nextTick, reactive, ssrContextKey } from "vue";
const mocks = vi.hoisted(() => ({
  pageProps: vi.fn(),
  connect: vi.fn(),
  route: null,
}));
vi.mock("../src/bitcraft", async () => {
  const { ref } = await import("vue");
  return {
    bitcraftConnected: ref(true),
    connectBitcraft: mocks.connect,
    subscribeStoredMarket: vi.fn(),
  };
});
vi.mock("../src/bitcraft-ui/api", () => ({
  pageProps: mocks.pageProps,
  live: vi.fn(),
}));
vi.mock("../src/bitcraft-ui/widgets", () => ({ readWidget: vi.fn() }));
vi.mock("vue-router", () => ({
  useRoute: () => mocks.route,
  useRouter: () => ({ replace: vi.fn() }),
}));
vi.mock("../src/bitcraft-ui/navigation", () => ({
  pageState: { props: { bitcraft: { player: null } }, url: "", error: "" },
  setPageLoader: vi.fn(),
  trackPageLoad: (p) => p,
}));
import { bitcraftConnected } from "../src/bitcraft";
import BitcraftUiPage from "../src/pages/BitcraftUiPage.vue";
import ClaimHistoryPage from "../src/pages/ClaimHistoryPage.vue";

// Run real SFC setup/watch/lifecycle code with a null view; no browser or network.
const renderer = createRenderer({
  createComment: () => ({}),
  insert() {},
  remove() {},
  parentNode: () => null,
  nextSibling: () => null,
  createElement: () => ({}),
  createText: () => ({}),
  setText() {},
  setElementText() {},
  patchProp() {},
});
let app;
function mount(component) {
  app = renderer.createApp({ ...component, render: () => null });
  app.provide(ssrContextKey, {});
  app.mount({});
  return app._instance.setupState;
}
const settle = async () => {
  for (let i = 0; i < 12; i++) await Promise.resolve();
  await nextTick();
};
beforeEach(() => {
  vi.useFakeTimers();
  vi.clearAllMocks();
  vi.stubGlobal(
    "window",
    Object.assign(new EventTarget(), {
      localStorage: { getItem: () => null, setItem() {} },
    }),
  );
  vi.stubGlobal(
    "document",
    Object.assign(new EventTarget(), { hidden: false }),
  );
  mocks.route = reactive({
    params: { tool: "crafting" },
    meta: {},
    query: {},
    fullPath: "/bitcraft/crafting",
  });
  bitcraftConnected.value = true;
});
afterEach(() => {
  app?.unmount();
  app = null;
  vi.clearAllTimers();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

it("reloads a page whose initial read failed during an outage", async () => {
  bitcraftConnected.value = false;
  mocks.pageProps
    .mockRejectedValueOnce(Error("The live data connection timed out."))
    .mockResolvedValue({ filters: {}, items: ["fresh"] });
  const page = mount(BitcraftUiPage);
  await settle();
  expect(page.error).toMatch(/timed out/);
  await vi.advanceTimersByTimeAsync(120000);
  expect(mocks.pageProps).toHaveBeenCalledOnce();
  bitcraftConnected.value = true;
  await nextTick();
  await vi.advanceTimersByTimeAsync(1000);
  expect(mocks.pageProps).toHaveBeenCalledTimes(2);
  expect(page.currentProps.items).toEqual(["fresh"]);
  expect(page.error).toBe("");
});
it("recovers a failure returned as page props and leaves successful pages alone on later reconnects", async () => {
  bitcraftConnected.value = false;
  mocks.pageProps
    .mockResolvedValueOnce({
      levels: [],
      error: "Not connected to SpacetimeDB",
    })
    .mockResolvedValue({ levels: [1], error: null });
  const page = mount(BitcraftUiPage);
  await settle();
  bitcraftConnected.value = true;
  await nextTick();
  await vi.advanceTimersByTimeAsync(1000);
  expect(page.currentProps.levels).toEqual([1]);
  // Another brief reconnect must leave an already successful page/draft alone.
  bitcraftConnected.value = false;
  await nextTick();
  bitcraftConnected.value = true;
  await nextTick();
  await vi.advanceTimersByTimeAsync(30000);
  expect(mocks.pageProps).toHaveBeenCalledTimes(2);
});
it("preserves an open guide editor's unsaved draft through reconnects", async () => {
  mocks.route.meta.guideEditor = true;
  mocks.route.fullPath = "/bitcraft/guides/1/edit";
  mocks.route.params.guide = "1";
  mocks.pageProps.mockResolvedValue({ guide: { title: "Saved title" } });
  const page = mount(BitcraftUiPage);
  await settle();
  page.currentProps.guide.title = "Unsaved draft";
  bitcraftConnected.value = false;
  await nextTick();
  bitcraftConnected.value = true;
  await nextTick();
  await vi.advanceTimersByTimeAsync(30000);
  expect(mocks.pageProps).toHaveBeenCalledOnce();
  expect(page.currentProps.guide.title).toBe("Unsaved draft");
});
it("cancels the old page's retry when navigating within the tool shell", async () => {
  mocks.pageProps
    .mockRejectedValueOnce(Error("Not connected to SpacetimeDB"))
    .mockResolvedValue({ levels: [1] });
  const page = mount(BitcraftUiPage);
  await settle();
  mocks.route.params.tool = "hunting-calculator";
  mocks.route.fullPath = "/bitcraft/hunting-calculator";
  await settle();
  await vi.advanceTimersByTimeAsync(30000);
  expect(mocks.pageProps).toHaveBeenCalledTimes(2);
  expect(page.currentProps.levels).toEqual([1]);
});
it("does not automatically retry access errors or a page that has been left", async () => {
  mocks.pageProps.mockRejectedValue(
    Error("Guide editing needs a local administrator grant."),
  );
  mount(BitcraftUiPage);
  await settle();
  await vi.advanceTimersByTimeAsync(30000);
  expect(mocks.pageProps).toHaveBeenCalledOnce();
  app.unmount();
  app = null;
  bitcraftConnected.value = false;
  mocks.pageProps.mockRejectedValue(
    Error("The live data connection timed out."),
  );
  mount(BitcraftUiPage);
  await settle();
  app.unmount();
  app = null;
  bitcraftConnected.value = true;
  await nextTick();
  await vi.advanceTimersByTimeAsync(30000);
  expect(mocks.pageProps).toHaveBeenCalledTimes(2);
});
it("retries Claim History subscription setup, cleans failed listeners and retains a recovered subscription", async () => {
  const subscriptions = [];
  const table = Object.fromEntries(
    [
      "onInsert",
      "onUpdate",
      "onDelete",
      "removeOnInsert",
      "removeOnUpdate",
      "removeOnDelete",
    ].map((name) => [name, vi.fn()]),
  );
  const conn = {
    db: { claimChanges: table },
    procedures: {
      readTrackedClaims: vi.fn().mockResolvedValue(
        JSON.stringify({
          claims: [],
          coverage: [],
          total: 0,
          trackedClaims: 0,
          pages: 1,
          page: 1,
        }),
      ),
    },
    subscriptionBuilder() {
      const sub = {
        ended: false,
        unsubscribe: vi.fn(),
        isEnded: () => sub.ended,
      };
      const builder = {
        onApplied(fn) {
          sub.applied = fn;
          return builder;
        },
        onError(fn) {
          sub.error = fn;
          return builder;
        },
        subscribe() {
          subscriptions.push(sub);
          return sub;
        },
      };
      return builder;
    },
  };
  // The list read succeeds; the separate first subscription connection fails.
  mocks.connect
    .mockResolvedValueOnce(conn)
    .mockRejectedValueOnce(Error("The live data connection timed out."))
    .mockResolvedValue(conn);
  mount(ClaimHistoryPage);
  await settle();
  expect(subscriptions).toHaveLength(0);
  await vi.advanceTimersByTimeAsync(1000);
  expect(subscriptions).toHaveLength(1);
  subscriptions[0].ended = true;
  subscriptions[0].error();
  expect(table.removeOnInsert).toHaveBeenCalledOnce();
  expect(subscriptions[0].unsubscribe).not.toHaveBeenCalled();
  await vi.advanceTimersByTimeAsync(2000);
  expect(subscriptions).toHaveLength(2);
  subscriptions[1].applied();
  bitcraftConnected.value = false;
  await nextTick();
  bitcraftConnected.value = true;
  await nextTick();
  await vi.advanceTimersByTimeAsync(1000);
  expect(subscriptions).toHaveLength(2);
  expect(table.onInsert).toHaveBeenCalledTimes(2);
  app.unmount();
  app = null;
  expect(subscriptions[1].unsubscribe).toHaveBeenCalledOnce();
  expect(table.removeOnInsert).toHaveBeenCalledTimes(2);
  await vi.advanceTimersByTimeAsync(30000);
  expect(subscriptions).toHaveLength(2);
});
