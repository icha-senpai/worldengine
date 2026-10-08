import { describe, expect, it } from "vitest";
import {
  barterRows,
  estimateBarterFill,
  estimateMarketFill,
  itemsFromListings,
} from "../src/bitcraft-ui/market";

const stack = (id, name, quantity) => ({
  itemId: id,
  itemName: name,
  quantity,
});
const stalls = [
  {
    entityId: "8",
    claimName: "Home",
    regionId: 9,
    orders: [
      {
        entityId: "coin",
        remainingStock: 8,
        offerItems: [stack(7, "Plank", 20)],
        requiredItems: [stack(1, "Hex Coin", 160)],
      },
      {
        entityId: "swap",
        remainingStock: 3,
        offerItems: [stack(7, "Plank", 5)],
        requiredItems: [stack(6, "Clay", 10)],
      },
      {
        entityId: "mixed",
        remainingStock: 6,
        offerItems: [stack(7, "Plank", 10)],
        requiredItems: [stack(6, "Clay", 2), stack(1, "Hex Coin", 40)],
      },
      {
        entityId: "cargo",
        offerCargo: [stack(1, "Log cargo", 2)],
        requiredItems: [stack(6, "Clay", 3)],
      },
    ],
  },
];

describe("exact barter exchanges", () => {
  it("excludes retired island stalls from listings, totals and claim choices", () => {
    const retired = [3, 11, 15, 23].map((regionId) => ({
      ...stalls[0],
      regionId,
      claimName: `Retired ${regionId}`,
    }));
    const result = barterRows({ stalls: [...stalls, ...retired] });
    expect(result.stalls).toHaveLength(1);
    expect(result.exchanges).toEqual(barterRows({ stalls }).exchanges);
  });
  it("labels listings with a missing side separately from complete item exchanges", () => {
    const result = barterRows({
      stalls: [
        {
          entityId: "one",
          orders: [
            { entityId: "donation", requiredItems: [stack(7, "Plank", 1)] },
            { entityId: "free", offerItems: [stack(7, "Plank", 1)] },
          ],
        },
      ],
    });
    expect(result.exchanges.map((row) => row.exchangeType)).toEqual([
      "one-sided",
      "one-sided",
    ]);
    expect(result.exchanges[0].offerStacks).toEqual([]);
    expect(result.exchanges[1].requiredStacks).toEqual([]);
  });
  it("keeps coin-free swaps, all mixed costs and cargo ID 1, with one row per exchange", () => {
    const result = barterRows({ stalls });
    expect(result.exchanges).toHaveLength(4);
    expect(result.exchanges.map((row) => row.exchangeType)).toEqual([
      "coins",
      "items",
      "mixed",
      "items",
    ]);
    expect(
      result.listings.find((row) => row.orderEntityId === "coin"),
    ).toMatchObject({ price: 8, bundlePrice: 160 });
    expect(
      result.listings
        .filter((row) => row.orderEntityId === "swap")
        .map((row) => row.price),
    ).toEqual([null, null]);
    expect(
      result.listings
        .filter((row) => row.orderEntityId === "mixed")
        .every((row) => row.price === null),
    ).toBe(true);
    expect(
      result.exchanges[2].requiredStacks.map((row) => row.quantity),
    ).toEqual([2, 40]);
    expect(
      result.listings.find(
        (row) => row.orderEntityId === "cargo" && row.side === "sell",
      ),
    ).toMatchObject({
      itemId: 1,
      itemKind: "cargo",
      price: null,
      remainingStock: null,
    });
  });
  it("searches both give and get stacks, keeps scope and does not duplicate an exchange", () => {
    expect(barterRows({ stalls }, { q: "Clay" }).exchanges).toHaveLength(3);
    expect(
      barterRows({ stalls }, { q: "Plank", side: "buy" }).exchanges,
    ).toHaveLength(0);
    expect(
      barterRows({ stalls }, { q: "Plank", side: "sell", regionId: "9" })
        .exchanges,
    ).toHaveLength(3);
    expect(barterRows({ stalls }, { regionId: "8" }).exchanges).toHaveLength(0);
    expect(
      barterRows({ stalls }, { itemKind: "cargo" }).exchanges,
    ).toHaveLength(1);
  });
  it("multiplies every stack by whole bundles and caps estimates at known stock", () => {
    const exchange = barterRows({ stalls }).exchanges[2];
    const estimate = estimateBarterFill(exchange, 7.9);
    expect(estimate).toMatchObject({
      requested: 7,
      bundles: 6,
      stock: 6,
      unverified: false,
    });
    expect(estimate.give.map((row) => row.quantity)).toEqual([12, 240]);
    expect(estimate.get.map((row) => row.quantity)).toEqual([60]);
    expect(
      estimateBarterFill({ ...exchange, remainingStock: 0 }, 2).bundles,
    ).toBe(0);
    expect(
      estimateBarterFill({ ...exchange, remainingStock: null }, 2),
    ).toMatchObject({ bundles: 2, stock: null, unverified: true });
  });
  it("does not turn an unpriced swap into zero coin market statistics", () => {
    const items = itemsFromListings(
      barterRows({ stalls }).listings.filter(
        (row) => row.orderEntityId === "swap",
      ),
    );
    expect(
      items.every(
        (row) =>
          row.lowestSellPrice === null &&
          row.highestBuyPrice === null &&
          row.highestBuyLineTotal === null,
      ),
    ).toBe(true);
  });
});
describe("market quantity estimates", () => {
  const orders = [
    { price: 12, quantity: 100 },
    { price: 10, quantity: 80 },
    { price: null, quantity: 1000 },
    { price: 0, quantity: 0 },
  ];
  it("fills cheapest sellers first and reports the unfilled remainder", () => {
    expect(estimateMarketFill(orders, 100)).toMatchObject({
      filled: 100,
      total: 1040,
      average: 10.4,
      remaining: 0,
    });
    expect(estimateMarketFill(orders, 200)).toMatchObject({
      filled: 180,
      total: 2000,
      remaining: 20,
    });
  });
  it("fills highest buyers first and excludes unknown prices", () => {
    expect(estimateMarketFill(orders, 120, "sell")).toMatchObject({
      filled: 120,
      total: 1400,
      remaining: 0,
    });
    expect(
      estimateMarketFill([{ price: null, quantity: 10 }], 5),
    ).toMatchObject({ filled: 0, total: 0, average: null, remaining: 5 });
  });
});
