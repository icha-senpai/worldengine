import { describe, expect, it } from "vitest";
import {
  marketBook,
  marketDirectory,
  marketRegions,
  marketSummary,
  marketIdentity,
  marketMatches,
} from "../bitcraft/src/market-store";
const item = {
  id: "7",
  kind: "item",
  name: "Plank",
  tag: "Wood",
  category: "Wood",
  sellOrders: 1,
  buyOrders: 0,
};
const raw = {
  item,
  sellOrders: [
    {
      entityId: "1297036692719788627",
      claimEntityId: "1297036692719788628",
      claimName: "Late Claim",
      priceThreshold: "3",
      quantity: "10",
      regionId: 1,
    },
    {
      entityId: "2",
      claimName: "Elsewhere",
      priceThreshold: "1",
      quantity: "100",
      regionId: 2,
    },
  ],
  buyOrders: [
    {
      entityId: "3",
      claimName: "Buyer",
      priceThreshold: "6",
      quantity: "4",
      regionId: 1,
    },
  ],
};
describe("canonical market snapshots", () => {
  it("keeps item and cargo identities separate and rejects lossy IDs", () => {
    expect(marketIdentity("item", "7")).not.toBe(marketIdentity("cargo", "7"));
    expect(() => marketIdentity("item", Number("1297036692719788627"))).toThrow(
      /precision/,
    );
  });
  it("requires a complete valid directory before changing coverage", () => {
    expect(
      marketDirectory({ items: [item, { ...item, itemType: 1 }] }),
    ).toHaveLength(2);
    expect(() => marketDirectory({ items: [item, item] })).toThrow(/directory/);
    expect(() => marketDirectory({ items: [item], totalPages: 2 })).toThrow(
      /complete/,
    );
    expect(() =>
      marketDirectory({ items: [{ id: "7", name: "Plank" }] }),
    ).toThrow(/availability/);
  });
  it("aggregates complete world books into exact region and claim summaries", () => {
    const book = marketBook("item", "7", raw, item);
    expect(marketRegions(book)).toEqual([0, 1, 2]);
    expect(marketSummary(book)).toMatchObject({
      lowestSellPrice: 1,
      highestBuyPrice: 6,
      sellOrderQuantity: 110,
      bestSellClaimName: "Elsewhere",
    });
    expect(marketSummary(book, { regionId: 1 })).toMatchObject({
      lowestSellPrice: 3,
      sellOrderQuantity: 10,
      bestSellClaimName: "Late Claim",
    });
    expect(marketSummary(book, { claimQ: "Late" })).toMatchObject({
      sellOrderCount: 1,
      buyOrderCount: 0,
    });
    expect(book.sellOrders[0].entityId).toBe("1297036692719788627");
  });
  it("uses package quantities only when ordinary orders are absent in the selected scope", () => {
    const book = marketBook(
      "cargo",
      "7",
      {
        ...raw,
        sellOrders: [],
        buyOrders: [],
        packageSellOrders: raw.sellOrders,
      },
      item,
    );
    expect(marketSummary(book, { regionId: 1 })).toMatchObject({
      sellOrderQuantity: 10,
      lowestSellPrice: 3,
      sellOrderCount: 1,
    });
  });
  it("preserves unknown prices while retaining available quantity", () => {
    const book = marketBook(
      "item",
      "7",
      {
        ...raw,
        sellOrders: [{ ...raw.sellOrders[0], priceThreshold: null }],
        buyOrders: [],
      },
      item,
    );
    expect(marketSummary(book)).toMatchObject({
      lowestSellPrice: null,
      sellOrderCount: 1,
      sellOrderQuantity: 10,
    });
  });
  it("rejects incomplete and mismatched books before replacing stored orders", () => {
    expect(() => marketBook("item", "8", raw)).toThrow(/match/);
    expect(() => marketBook("item", "7", { item, sellOrders: [] })).toThrow(
      /complete/,
    );
    expect(() =>
      marketBook("item", "7", {
        ...raw,
        sellOrders: [{ ...raw.sellOrders[0], quantity: -1 }],
      }),
    ).toThrow(/values/);
  });
  it("searches names and exact IDs without interpreting wildcards", () => {
    expect(marketMatches(item, "pLaNk")).toBe(true);
    expect(marketMatches(item, "7")).toBe(true);
    expect(marketMatches(item, "*")).toBe(false);
    expect(marketMatches(item, "", "7", "cargo")).toBe(false);
  });
});
