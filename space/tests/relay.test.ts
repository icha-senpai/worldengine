import { describe, it, expect } from "vitest";
import {
  parseRelayJson,
  decodeRelayRow,
  RelaySubscription,
} from "../scripts/bitcraft-relay";
import {
  assembleRelayTrading,
  mergeRegionalBook,
  validateRelayTrading,
} from "../bitcraft/src/relay-trading";
import { catalogResponse } from "../bitcraft/src/catalog";
import { barterRows } from "../src/bitcraft-ui/market";
const table = (rows: any[]) =>
  new Map(rows.map((r) => [r.entity_id ?? r.building_entity_id, r]));
function fixture() {
  return new Map([
    [
      "sell_order_state",
      table([
        {
          entity_id: "1297036692719788627",
          owner_entity_id: "20",
          claim_entity_id: "10",
          item_id: 7,
          item_type: 0,
          price_threshold: 3,
          quantity: 50,
        },
      ]),
    ],
    [
      "buy_order_state",
      table([
        {
          entity_id: "31",
          owner_entity_id: "20",
          claim_entity_id: "10",
          item_id: 7,
          item_type: 1,
          price_threshold: 8,
          quantity: 5,
        },
      ]),
    ],
    [
      "claim_state",
      table([
        { entity_id: "10", name: "A claim", owner_player_entity_id: "20" },
      ]),
    ],
    [
      "claim_local_state",
      table([{ entity_id: "10", location: { some: { x: 100, z: 200 } } }]),
    ],
    ["player_username_state", table([{ entity_id: "20", username: "Player" }])],
    [
      "marketplace_state",
      table([
        {
          building_entity_id: "30",
          claim_entity_id: "10",
          coordinates: { x: 100, z: 200 },
        },
      ]),
    ],
    [
      "barter_stall_state",
      table([{ entity_id: "30", market_mode_enabled: false }]),
    ],
    [
      "building_state",
      table([
        {
          entity_id: "30",
          claim_entity_id: "10",
          constructed_by_player_entity_id: "20",
        },
      ]),
    ],
    ["building_nickname_state", table([])],
    [
      "trade_order_state",
      table([
        {
          entity_id: "32",
          shop_entity_id: "30",
          remaining_stock: 2,
          offer_items: [{ item_id: 7, quantity: 3 }],
          offer_cargo_id: [],
          required_items: [{ item_id: 1, quantity: 4 }],
          required_cargo_id: [],
          traveler_trade_order_id: { none: {} },
        },
        {
          entity_id: "33",
          shop_entity_id: "99",
          remaining_stock: 2147483647,
          offer_items: [],
          required_items: [],
          traveler_trade_order_id: { some: 1 },
        },
      ]),
    ],
  ]);
}
describe("native relay projection", () => {
  it("decodes platform footprints without shifting claim building fields", () => {
    const wire = [
      205715693,
      [],
      "Waystone",
      "",
      0,
      0,
      "",
      "",
      false,
      false,
      [
        [2, 3, [4, []]],
        [0, 0, [0, []]],
      ],
      100,
      false,
      0,
      0.5,
      12.5,
      [1, []],
      [3, []],
      false,
      true,
      false,
      false,
      true,
    ];
    const row = decodeRelayRow("building_desc", wire, "bitsync");
    expect(row).toMatchObject({
      id: 205715693,
      name: "Waystone",
      footprint: [
        { x: 2, z: 3, footprint_type: { platform: {} } },
        { x: 0, z: 0, footprint_type: { hitbox: {} } },
      ],
      maintenance: 12.5,
      build_permission: { claim: {} },
      interact_permission: { all: {} },
      destroy_on_unclaim: true,
    });
    wire[10] = [[2, 3, [5, []]]];
    expect(() => decodeRelayRow("building_desc", wire, "bitsync")).toThrow(
      "Unknown relay variant",
    );
  });
  it("preserves exact u64 wire IDs and decodes positional rows", () => {
    const parsed = parseRelayJson(
      "[1297036692719788627,20,10,7,0,3,50,[1791410000000000],0]",
    );
    expect(parsed[0]).toBe("1297036692719788627");
    const row = decodeRelayRow("sell_order_state", parsed);
    expect(row.entity_id).toBe("1297036692719788627");
    expect(row.quantity).toBe(50);
    expect(() => decodeRelayRow("sell_order_state", parsed.slice(1))).toThrow(
      /width/,
    );
  });
  it("joins claims and players, separates cargo and items, and excludes NPC rows", () => {
    const data = assembleRelayTrading(fixture(), 8, (_kind, id) => ({
      name: `Item ${id}`,
    }));
    expect(data.books["item:7"].sellOrders[0]).toMatchObject({
      claimName: "A claim",
      ownerUsername: "Player",
      regionId: 8,
      claimLocationX: 100,
    });
    expect(data.books["cargo:7"].buyOrders).toHaveLength(1);
    expect(data.stalls).toHaveLength(1);
    expect(data.stalls[0].orders).toHaveLength(1);
    expect(data.stalls[0].orders[0].offerItems[0].quantity).toBe(3);
    expect(validateRelayTrading(8, data).stalls).toHaveLength(1);
    data.books["item:7"].sellOrders[0].regionId = 9;
    expect(() => validateRelayTrading(8, data)).toThrow(/region boundary/);
  });
  it("preserves typed cargo quantities and separates overlapping item IDs in barter bundles", () => {
    const tables = fixture();
    const order = tables.get("trade_order_state")!.get("32")!;
    // Real wire decoding produces these Item/Cargo tagged objects. Both kinds
    // may occur inside *_items, in addition to the legacy cargo ID arrays.
    order.offer_items = [
      { item_id: 7000, quantity: 5, item_type: { Cargo: {} } },
      { item_id: 1, quantity: 2, item_type: { Cargo: {} } },
      { item_id: 1, quantity: 3, item_type: { Item: {} } },
    ];
    order.offer_cargo_id = [1200];
    order.required_items = [
      { item_id: 1201, quantity: 4, item_type: { Cargo: {} } },
    ];
    const data = assembleRelayTrading(tables, 8, (kind, id) =>
      catalogResponse("item-info", `${kind}:${id}`, ""),
    );
    const trade = data.stalls[0].orders[0];
    expect(trade.offerItems).toMatchObject([
      { id: "1", kind: "item", name: "Hex Coin", quantity: 3 },
    ]);
    expect(trade.offerCargo).toMatchObject([
      { id: "7000", kind: "cargo", name: "Rough Cloth Tarp", quantity: 5 },
      { id: "1", kind: "cargo", quantity: 2 },
      { id: "1200", kind: "cargo", name: "Rough Timber", quantity: 1 },
    ]);
    expect(trade.requiredItems).toEqual([]);
    expect(trade.requiredCargo).toMatchObject([
      { id: "1201", kind: "cargo", name: "Simple Timber", quantity: 4 },
    ]);
    expect(validateRelayTrading(8, data).stalls).toHaveLength(1);
    const listing = barterRows(
      { stalls: data.stalls },
      { q: "Rough Cloth Tarp" },
    );
    expect(listing.exchanges).toHaveLength(1);
    expect(listing.exchanges[0].offerStacks).toContainEqual(
      expect.objectContaining({ kind: "cargo", id: "7000", quantity: 5 }),
    );
    expect(listing.exchanges[0].exchangeType).toBe("mixed");
  });
  it("resolves the Titan Emperor catalog gaps without guessing cargo from an item ID", () => {
    expect(catalogResponse("items", "", "Titan Emperor")).toHaveLength(6);
    expect(catalogResponse("item-info", "item:1197952569", "")).toMatchObject({
      name: "Titan Emperor Tunic",
      category: "Premium Cosmetic",
      iconAssetName: "PremiumIcons/TitanEmperorTorso",
    });
    expect(catalogResponse("item-info", "item:7000", "")).toBeNull();
    expect(catalogResponse("item-info", "cargo:7000", "")).toMatchObject({
      name: "Rough Cloth Tarp",
    });
  });
  it("retains the native authority against late API responses and reconciles cancellations", () => {
    const base = {
      item: { id: "7" },
      sellOrders: [
        { entityId: "old", regionId: 8 },
        { entityId: "elsewhere", regionId: 9 },
      ],
      buyOrders: [],
    };
    const fresh = {
      regionId: 8,
      books: {
        "item:7": {
          sellOrders: [{ entityId: "new", regionId: 8 }],
          buyOrders: [],
        },
      },
    };
    expect(
      mergeRegionalBook("item:7", base, [fresh]).sellOrders.map(
        (r: any) => r.entityId,
      ),
    ).toEqual(["elsewhere", "new"]);
    expect(
      mergeRegionalBook("item:7", base, [
        { regionId: 8, books: {} },
      ]).sellOrders.map((r: any) => r.entityId),
    ).toEqual(["elsewhere"]);
  });
  it("uses targeted API orders for unavailable regions until a live snapshot recovers", () => {
    const base = {
      item: { id: "7" },
      apiFallbackRegions: [7],
      sellOrders: [
        { entityId: "api7", regionId: 7 },
        { entityId: "api8", regionId: 8 },
      ],
    };
    const regions = [
      {
        regionId: 7,
        ready: false,
        books: {
          "item:7": { sellOrders: [{ entityId: "stale7", regionId: 7 }] },
        },
      },
      {
        regionId: 8,
        ready: true,
        books: {
          "item:7": { sellOrders: [{ entityId: "live8", regionId: 8 }] },
        },
      },
    ];
    const book = mergeRegionalBook("item:7", base, regions);
    expect(book.sellOrders.map((r: any) => r.entityId)).toEqual([
      "api7",
      "live8",
    ]);
    expect(book.apiFallbackRegions).toEqual([7]);
    const emptyApi = mergeRegionalBook(
      "item:7",
      { ...base, sellOrders: [] },
      regions,
    );
    expect(emptyApi.sellOrders.map((r: any) => r.entityId)).toEqual(["live8"]);
    const recovered = mergeRegionalBook("item:7", book, [
      { regionId: 7, ready: true, books: {} },
      regions[1]!,
    ]);
    expect(recovered.sellOrders.map((r: any) => r.entityId)).toEqual(["live8"]);
    expect(recovered.apiFallbackRegions).toBeUndefined();
    // A later outage must not resurrect a previously cancelled API offer.
    expect(
      mergeRegionalBook("item:7", recovered, [
        { regionId: 7, ready: false, books: {} },
      ]).sellOrders,
    ).toEqual(recovered.sellOrders);
  });
});
