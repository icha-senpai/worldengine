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
function claimConnection() {
  return {
    procedures: {
      readTrackedClaims: vi
        .fn()
        .mockResolvedValue(
          JSON.stringify({
            claims: [],
            coverage: [],
            total: 0,
            trackedClaims: 0,
            pages: 1,
            page: 1,
          }),
        ),
      readStarredClaims: vi
        .fn()
        .mockResolvedValue(JSON.stringify({ claims: [] })),
      readTrackedClaimHistory: vi
        .fn()
        .mockResolvedValue(
          JSON.stringify({
            events: [],
            next: "",
            claim: { id: "1", regionId: 9, name: "A", supplies: 200 },
            members: [],
          }),
        ),
    },
    subscriptionBuilder: vi.fn(),
  };
}
it("keeps Claim History snapshots stable until the user requests a refresh", async () => {
  const conn = claimConnection();
  mocks.connect.mockResolvedValue(conn);
  const page = mount(ClaimHistoryPage);
  await settle();
  expect(conn.procedures.readTrackedClaims).toHaveBeenCalledOnce();
  await vi.advanceTimersByTimeAsync(2 * 60 * 60 * 1000);
  window.dispatchEvent(new Event("focus"));
  document.dispatchEvent(new Event("visibilitychange"));
  bitcraftConnected.value = false;
  await nextTick();
  bitcraftConnected.value = true;
  await nextTick();
  await vi.advanceTimersByTimeAsync(30000);
  expect(conn.procedures.readTrackedClaims).toHaveBeenCalledOnce();
  expect(conn.subscriptionBuilder).not.toHaveBeenCalled();
  await page.refresh();
  expect(conn.procedures.readTrackedClaims).toHaveBeenCalledTimes(2);
  page.query = "new filter";
  await nextTick();
  await vi.advanceTimersByTimeAsync(300);
  expect(conn.procedures.readTrackedClaims).toHaveBeenCalledTimes(3);
});
it("refreshes claims, stars and open history together without discarding older events", async () => {
  const conn = claimConnection();
  mocks.connect.mockResolvedValue(conn);
  const page = mount(ClaimHistoryPage);
  await settle();
  page.openHistory({ id: "1", regionId: 9, name: "A" });
  await settle();
  page.stars = [
    { id: "1", regionId: 9, name: "A", seenSnapshot: "already seen" },
  ];
  page.events = [{ key: "older", observedAt: "10" }];
  page.moreLoaded = true;
  page.next = "older-cursor";
  conn.procedures.readTrackedClaimHistory.mockResolvedValue(
    JSON.stringify({
      events: [{ key: "new", observedAt: "20" }],
      next: "new-cursor",
      claim: { id: "1", regionId: 9, name: "A", supplies: 100 },
      members: [],
    }),
  );
  let finish;
  conn.procedures.readTrackedClaims.mockImplementationOnce(
    () =>
      new Promise((resolve) => {
        finish = resolve;
      }),
  );
  const pending = page.refresh();
  await settle();
  expect(page.refreshing).toBe(true);
  await page.refresh();
  expect(conn.procedures.readTrackedClaims).toHaveBeenCalledTimes(2);
  expect(conn.procedures.readStarredClaims).toHaveBeenCalledOnce();
  expect(conn.procedures.readTrackedClaimHistory).toHaveBeenCalledTimes(2);
  finish(
    JSON.stringify({
      claims: [],
      coverage: [],
      total: 0,
      trackedClaims: 0,
      pages: 1,
      page: 1,
    }),
  );
  await pending;
  expect(page.refreshing).toBe(false);
  expect(page.currentClaim.supplies).toBe(100);
  expect(page.events.map((event) => event.key)).toEqual(["new", "older"]);
  expect(page.next).toBe("older-cursor");
  expect(page.stars[0].seenSnapshot).toBe("already seen");
});
