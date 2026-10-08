import { beforeEach, afterEach, describe, expect, it, vi } from "vitest";
const { requestData, readMarket, readMarketBook, requestMarketRefresh } =
  vi.hoisted(() => ({
    requestData: vi.fn(),
    readMarket: vi.fn(),
    readMarketBook: vi.fn(),
    requestMarketRefresh: vi.fn(),
  }));
vi.mock("../src/bitcraft", () => ({
  requestData,
  observeCollection: async () => {},
  connectBitcraft: async () => ({
    procedures: { readMarket, readMarketBook },
    reducers: { requestMarketRefresh },
  }),
}));
vi.mock("../src/bitcraft-ui/navigation", () => ({
  pageState: { props: { bitcraft: {} } },
  route: () => "/bitcraft/market",
}));
import { marketPage, bitcraftFetch } from "../src/bitcraft-ui/api";
import { sortTradingRows } from "../src/bitcraft-ui/market";
describe("stored market browsing", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal("location", { origin: "https://space.test" });
    requestMarketRefresh.mockResolvedValue(undefined);
    readMarket.mockImplementation(async ({ filters: json }) => {
      const filters = JSON.parse(json);
      if (filters.region) {
        filters.regionId = "1";
        filters.regionName = "Solmere";
      }
      return JSON.stringify({
        filters,
        regions: [{ id: "1", name: "Solmere" }],
        items: Array.from({ length: 85 }, (_, index) => ({
          id: index + 1,
          kind: "item",
          name: `Plank ${index}`,
          category: index < 50 ? "Wood" : "Other",
          sellOrderCount: 1,
          buyOrderCount: 1,
          lowestSellPrice: 85 - index,
          highestBuyPrice: index + 1,
          sellOrderQuantity: index + 1,
          buyOrderQuantity: index + 1,
        })),
        categories: ["Other", "Wood"],
        claims: [],
        orderScope: filters.region ? "region" : "all",
        storage: {
          totalItems: 85,
          loadedItems: 85,
          activeItems: 85,
          loadedActiveItems: 85,
          pending: 0,
          oldestAt: "1000000",
          newestAt: "2000000",
          error: "",
        },
      });
    });
    readMarketBook.mockResolvedValue(
      JSON.stringify({
        book: {
          item: { id: 7, kind: "item", name: "Plank" },
          sellOrders: [
            { entityId: "1", price: 10, quantity: 2, regionId: 1 },
            { entityId: "2", price: 1, quantity: 100, regionId: 2 },
          ],
          buyOrders: [],
        },
        observedAt: "1000000",
        pending: false,
        error: "",
      }),
    );
  });
  afterEach(() => vi.unstubAllGlobals());
  it.each(["", "Solmere"])(
    "browses all matching items in %s entirely from stored data",
    async (region) => {
      const result = await marketPage("market", {
        q: "",
        region,
        page: 2,
        hasOrders: true,
      });
      expect(result.error).toBeNull();
      expect(result.market.items).toHaveLength(85);
      expect(sortTradingRows(result.market.items).slice(0, 20)[0].id).toBe(85);
      expect(
        sortTradingRows(result.market.items, { intent: "sell" })[0].id,
      ).toBe(85);
      expect(result.market.categories).toEqual(["Other", "Wood"]);
      expect(result.cache.stored).toBe(true);
      expect(requestData).not.toHaveBeenCalled();
      expect(requestMarketRefresh).not.toHaveBeenCalled();
    },
  );
  it("returns stored search matches and queues one shared refresh without fetching order books", async () => {
    const result = await marketPage("market", {
      q: "Plank",
      region: "Solmere",
      hasOrders: true,
    });
    expect(result.market.items).toHaveLength(85);
    expect(result.market.orderBooks).toEqual({});
    expect(requestMarketRefresh).toHaveBeenCalledExactlyOnceWith({
      query: "Plank",
      itemId: "",
      kind: "item",
    });
    expect(requestData).not.toHaveBeenCalled();
    expect(readMarketBook).not.toHaveBeenCalled();
  });
  it("does not enqueue refreshes when subscription updates reload stored results", async () => {
    await marketPage("market", { q: "Plank" }, { refreshMarket: false });
    expect(requestMarketRefresh).not.toHaveBeenCalled();
    expect(requestData).not.toHaveBeenCalled();
  });
  it("keeps stored matches and regions available when refresh queuing fails", async () => {
    requestMarketRefresh.mockRejectedValueOnce(new Error("Offline"));
    const result = await marketPage("market", {
      q: "Plank",
      region: "Solmere",
    });
    expect(result.error).toBeNull();
    expect(result.market.items).toHaveLength(85);
    expect(result.regions).toHaveLength(1);
    expect(result.refresh.delayed).toBe(true);
    expect(result.market.storage.error).toContain(
      "Stored matches remain available",
    );
    expect(requestData).not.toHaveBeenCalled();
  });
  it("uses empire membership metadata to scope stored orders without fetching market data", async () => {
    requestData.mockImplementation(async (resource) => ({
      payload: JSON.stringify(
        resource === "empires"
          ? { empires: [{ name: "Builders", entityId: "42" }] }
          : { claims: [{ entityId: "100" }] },
      ),
      updatedAt: 1000000n,
      retryAt: 0n,
    }));
    const result = await marketPage("market", { empire: "Builders" });
    expect(result.error).toBeNull();
    const filters = JSON.parse(readMarket.mock.calls[0][0].filters);
    expect(filters.empireEntityId).toBe("42");
    expect(filters.claimIds).toEqual(["100"]);
    expect(requestData.mock.calls.map(([resource]) => resource)).toEqual([
      "empires",
      "empireClaims",
    ]);
    expect(requestMarketRefresh).not.toHaveBeenCalled();
  });
  it("keeps exact item links on the shared item refresh path", async () => {
    await marketPage("market", { itemId: "7", itemKind: "cargo" });
    expect(requestMarketRefresh).toHaveBeenCalledExactlyOnceWith({
      query: "",
      itemId: "7",
      kind: "cargo",
    });
    expect(requestData).not.toHaveBeenCalled();
  });
  it("opens a stored order book and scopes its orders without an API call", async () => {
    const response = await bitcraftFetch(
      "/bitcraft/market/order-book?itemId=7&itemKind=item&region=Solmere&history=0",
    );
    const data = await response.json();
    expect(response.ok).toBe(true);
    expect(data.orderBook.sellOrders).toHaveLength(1);
    expect(data.orderBook.stats.lowestSell).toBe(10);
    expect(data.orderBook.historyLoaded).toBe(false);
    expect(requestData).not.toHaveBeenCalled();
    expect(requestMarketRefresh).not.toHaveBeenCalled();
  });
  it("retains unavailable prices and collection coverage instead of manufacturing a complete ranking", async () => {
    const implementation = readMarket.getMockImplementation();
    readMarket.mockImplementation(async (args) => {
      const data = JSON.parse(await implementation(args));
      data.items[84].lowestSellPrice = null;
      data.items[84].sellOrderQuantity = null;
      data.items[84].pendingCollection = true;
      data.storage.loadedItems = 84;
      data.storage.loadedActiveItems = 84;
      return JSON.stringify(data);
    });
    const result = await marketPage("market", { q: "" });
    expect(result.market.storage.loadedItems).toBe(84);
    expect(sortTradingRows(result.market.items).at(-1).id).toBe(85);
    expect(requestData).not.toHaveBeenCalled();
  });
});
