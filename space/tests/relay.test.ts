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
});
