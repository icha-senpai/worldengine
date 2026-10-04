import { beforeEach, describe, expect, it, vi } from "vitest";
const { requestData } = vi.hoisted(() => ({ requestData: vi.fn() }));
vi.mock("../src/bitcraft", () => ({
  requestData,
  connectBitcraft: async () => ({
    procedures: {
      providerPolicy: async () =>
        JSON.stringify({ poolConcurrency: 8, marketCacheSeconds: 60 }),
    },
  }),
}));
vi.mock("../src/bitcraft-ui/navigation", () => ({
  pageState: { props: { bitcraft: {} } },
  route: () => "/bitcraft/market",
}));
import { marketPage } from "../src/bitcraft-ui/api";

describe("market request volume", () => {
  beforeEach(() => {
    requestData.mockReset();
    requestData.mockImplementation(async (resource, id) => {
      let data;
      if (resource === "regions")
        data = { regions: [{ regionId: 1, regionName: "Solmere" }] };
      else if (resource === "market")
        data = {
          items: Array.from({ length: 85 }, (_, index) => ({
            id: index + 1,
            name: `Plank ${index}`,
            category: index < 50 ? "Wood" : "Other",
            sellOrders: 1,
            buyOrders: 0,
          })),
        };
      else if (resource === "itemOrders")
        data = {
          item: { id, name: "Plank" },
          sellOrders: [{ id: id + "0", price: 10, quantity: 2 }],
          buyOrders: [],
        };
      else if (resource === "claims")
        data = {
          claims: Array.from({ length: 32 }, (_, index) => ({
            entityId: String(index + 1),
            regionId: 1,
            name: `Claim ${index}`,
          })),
          totalPages: 1,
        };
      else if (resource === "claimListings")
        data = { listings: [], totalPages: 1 };
      else throw Error(`Unexpected request ${resource}`);
      return {
        payload: JSON.stringify(data),
        updatedAt: 0n,
        retryAt: 0n,
        error: "",
      };
    });
  });
  it("prices only the current result page and retains categories from all results", async () => {
    const result = await marketPage("market", {
      q: "Plank",
      page: 2,
      hasOrders: true,
    });
    expect(result.error).toBeNull();
    expect(result.market.pagination).toMatchObject({
      page: 2,
      pages: 5,
      total: 85,
    });
    expect(result.market.items.map((row) => row.id)).toEqual(
      Array.from({ length: 20 }, (_, index) => index + 21),
    );
    expect(result.market.categories).toEqual(["Other", "Wood"]);
    expect(
      requestData.mock.calls.filter(([resource]) => resource === "itemOrders"),
    ).toHaveLength(20);
    expect(
      requestData.mock.calls.every(
        (call) => !("page" in call[4]) && !("scopePage" in call[4]),
      ),
    ).toBe(true);
  });
  it("loads a group of claims rather than querying every matching claim", async () => {
    const result = await marketPage("market", {
      region: "Solmere",
      scopePage: 2,
    });
    expect(result.error).toBeNull();
    expect(result.market.scopePagination).toMatchObject({
      page: 2,
      pages: 4,
      total: 32,
    });
    expect(
      requestData.mock.calls
        .filter(([resource]) => resource === "claimListings")
        .map((call) => call[1]),
    ).toEqual(Array.from({ length: 10 }, (_, index) => String(index + 11)));
  });
});
