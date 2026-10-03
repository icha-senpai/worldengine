import { describe, it, expect } from "vitest";
import {
  inventoryTracker,
  passiveTracker,
  activityTracker,
} from "../src/bitcraft-ui/trackers";
import {
  barterRows,
  normalizeOrderBook,
  stallClaims,
} from "../src/bitcraft-ui/market";
import { openCraftRows, filterCrafts } from "../src/bitcraft-ui/openCrafts";
import { upstreamRequest } from "../bitcraft/src/requests";
import { validateWidget } from "../bitcraft/src/widgets";
import { catalogResponse } from "../bitcraft/src/catalog";

describe("ICHAA BitCraft contracts", () => {
  const player = {
    entityId: "1224979098725428189",
    username: "Icha",
    experience: [{ skill_id: 3, quantity: 120 }],
  };
  const levels = [
    { level: 1, xp: 0 },
    { level: 2, xp: 100 },
    { level: 3, xp: 300 },
  ];
  it("counts populated nested pockets and combines sources without counting empty pockets", () => {
    const payload = {
      items: { 7: { id: 7, name: "Plank" } },
      cargos: { 8: { id: 8, name: "Frames" } },
      inventories: [
        {
          entityId: "1",
          inventoryName: "Inventory",
          pockets: [
            { contents: null },
            { contents: { itemId: 7, itemType: 0, quantity: 12 } },
            { contents: { itemId: 7, itemType: 0, quantity: 8 } },
          ],
        },
        {
          entityId: "2",
          inventoryName: "Cart",
          pockets: [{ contents: { itemId: 8, itemType: 1, quantity: 3 } }],
        },
        {
          inventoryName: "Crafting station",
          pockets: [{ contents: { itemId: 7, itemType: 0, quantity: 999 } }],
        },
      ],
    };
    const { tracker, options } = inventoryTracker(player, payload, [], {
      itemKeys: "item:7,cargo:8",
      itemNeeds: "item:7=50,cargo:8=2",
    });
    expect(
      tracker.items.map((row) => [
        row.name,
        row.quantity,
        row.remaining,
        row.progressPercent,
      ]),
    ).toEqual([
      ["Plank", 20, 30, 40],
      ["Frames", 3, 0, 100],
    ]);
    expect(tracker.items[0].sources).toHaveLength(1);
    expect(options.some((row) => row.id === undefined)).toBe(false);
  });
  it("reads skill/value XP pairs, remaining effort, requirements and pagination", () => {
    const rows = openCraftRows(
      {
        items: [{ id: 7, name: "Plank" }],
        craftResults: [
          {
            entityId: "9007199254740993",
            skillId: 3,
            progress: 25,
            totalActionsRequired: 100,
            experiencePerProgress: [[3, 2]],
            levelRequirements: [[3, 2]],
            craftedItem: [{ item_id: 7, item_type: "item", quantity: 2 }],
            craftCount: 4,
            ownerEntityId: player.entityId,
          },
        ],
      },
      player,
      levels,
      [{ id: 3, name: "Carpentry" }],
    );
    expect(rows[0]).toMatchObject({
      id: "9007199254740993",
      remainingXp: 150,
      fullXp: 200,
      currentLevel: 2,
      afterLevel: 2,
      fullLevel: 3,
      meetsLevel: true,
      skill: "Carpentry",
    });
    expect(rows[0].outputs[0].quantity).toBe(8);
    expect(
      filterCrafts(rows, { skill: "", mine: true, page: 1 }, player).pagination
        .total,
    ).toBe(1);
  });
  it("maps coin barter bundles and cargo while excluding non-coin exchanges", () => {
    const result = barterRows(
      {
        stalls: [
          {
            entityId: "9",
            regionId: 8,
            ownerName: "Boss",
            orders: [
              {
                entityId: "10",
                remainingStock: 40,
                offerCargo: [{ itemId: 8, itemName: "Frames", quantity: 2 }],
                requiredItems: [
                  { itemId: 1, itemName: "Hex Coin", quantity: 12 },
                ],
              },
              {
                entityId: "11",
                offerItems: [{ itemId: 7, itemName: "Plank", quantity: 2 }],
                requiredItems: [{ itemId: 6, itemName: "Clay", quantity: 3 }],
              },
            ],
          },
        ],
      },
      { itemKind: "cargo", regionId: "8" },
    );
    expect(result.listings).toHaveLength(1);
    expect(result.listings[0]).toMatchObject({
      itemType: "cargo",
      price: 6,
      bundlePrice: 12,
      quantity: 40,
      offerSummary: "2x Frames",
      requiredSummary: "12x Hex Coin",
    });
    expect(result.items[0]).toMatchObject({
      kind: "cargo",
      lowestSellPrice: 6,
      sellOrderQuantity: 40,
    });
  });
  it("keeps only scoped market orders and computes statistics from those orders", () => {
    const result = normalizeOrderBook(
      {
        item: { id: 7, name: "Plank" },
        sellOrders: [
          { regionId: 8, claimEntityId: "10", priceThreshold: 4, quantity: 30 },
          {
            regionId: 9,
            claimEntityId: "11",
            priceThreshold: 1,
            quantity: 900,
          },
        ],
        buyOrders: [
          { regionId: 8, claimEntityId: "10", priceThreshold: 3, quantity: 12 },
        ],
      },
      { regionId: "8", claimEntityId: "10" },
    );
    expect(result.stats).toMatchObject({
      lowestSell: 4,
      highestBuy: 3,
      buyOrderCount: 1,
      highestBuyLineTotal: 36,
    });
    expect(result.sellOrders).toHaveLength(1);
  });
  it("groups barter locations without inventing numeric claim IDs and preserves known claims", () => {
    const stalls = [
      { claimName: "Home", regionId: 8, orders: [{}, {}] },
      { claimName: "HOME", regionId: 8, orders: [{}] },
      { claimName: "Town", regionId: 9, orders: [{}] },
    ];
    const claims = stallClaims(stalls, [
      { name: "Home", entityId: "9007199254740993", tier: 4 },
    ]);
    expect(claims[0]).toMatchObject({
      entityId: "9007199254740993",
      tier: 4,
      tradeBuildingCount: 2,
      tradeOrderCount: 3,
    });
    expect(claims[1].entityId).toBe("stall-claim:Town");
  });
  it("groups passive output by claim, estimates timers from public recipes and skips completed jobs", () => {
    const tracker = passiveTracker(
      player,
      {
        items: { 7: { id: 7, name: "Plank", tier: 1 } },
        craftResults: [
          {
            entityId: "1",
            recipeId: 8,
            claimEntityId: "2",
            claimName: "Home",
            craftCount: 2,
            timestamp: "2026-10-03T00:00:00Z",
            craftedItem: [{ item_id: 7, item_type: "item", quantity: 3 }],
          },
          { entityId: "3", completed: true, recipeId: 8 },
        ],
      },
      { 8: 120 },
      Date.parse("2026-10-03T00:01:00Z"),
    );
    expect(tracker.groups[0]).toMatchObject({
      totalQueued: 2,
      totalOutputQuantity: 6,
      estimatedRemainingSeconds: 180,
      progressPercent: 25,
      claim: { name: "Home" },
      output: { name: "Plank" },
    });
    expect(tracker.crafts).toHaveLength(1);
  });
  it("calculates actual skill XP and level progress", () => {
    expect(
      activityTracker(player, [{ id: 3, name: "Carpentry" }], levels, {
        skill: "all",
      }),
    ).toMatchObject({
      xp: 120,
      level: 2,
      xpRemaining: 180,
      progressPercent: 10,
    });
  });
  it("whitelists upstream resources and filters without accepting external URLs", () => {
    expect(
      upstreamRequest("claimListings", "123", "", 1, {
        itemId: 7,
        side: "buy",
        injected: "bad",
      }).path,
    ).toBe("api/claims/123/market/listings?itemId=7&side=buy&page=1&limit=200");
    expect(() =>
      upstreamRequest("player", "https://example.com", "", 1),
    ).toThrow();
    expect(() => upstreamRequest("market", "", "", 1, null)).toThrow();
  });
  it("validates shareable widget profiles and bounded tasks", () => {
    expect(
      JSON.parse(
        validateWidget(
          "12345678-1234-1234-1234-123456789012",
          "tasks",
          JSON.stringify({
            title: "Stream tasks",
            tasks: [{ id: "task-1", text: "Gather", done: false }],
          }),
        ),
      ).title,
    ).toBe("Stream tasks");
    expect(() =>
      validateWidget(
        "12345678-1234-1234-1234-123456789012",
        "tasks",
        '{"user":"someone"}',
      ),
    ).toThrow();
    expect(() => validateWidget("short", "tasks", "{}")).toThrow();
  });
  it("serves public guide metadata and complete activity-card recipes from the same catalog", () => {
    const target = catalogResponse("targets", "", "plank").find(
      (row) => row.name === "Rough Plank",
    );
    const card = catalogResponse("card-item", `item:${target.id}`, "");
    expect(card.item.name).toBe("Rough Plank");
    expect(card.recipes[0].consumedItems.length).toBeGreaterThan(0);
    expect(catalogResponse("guide-metadata", "", "")[0].updated_at).toMatch(
      /^2026-/,
    );
    expect(
      catalogResponse("detail", `item:${target.id}`, "").recipeTree.length,
    ).toBeGreaterThan(0);
  });
  it("includes bait outputs from fish processing in target search and planning", () => {
    const target = catalogResponse("targets", "", "Basic Bait").find(
      (row) => row.name === "Basic Bait",
    );
    expect(target).toBeDefined();
    const data = catalogResponse("detail", `item:${target.id}`, "");
    expect(
      data.craftingRecipes.some(
        (row) =>
          row.source === "bait-output" &&
          row.outputQuantity === 2 &&
          row.ingredients.length > 0,
      ),
    ).toBe(true);
    expect(data.recipeTree.length).toBeGreaterThan(0);
    expect(data.craftingRecipes.some((row) => /\{\d+\}/.test(row.name))).toBe(
      false,
    );
  });
});
