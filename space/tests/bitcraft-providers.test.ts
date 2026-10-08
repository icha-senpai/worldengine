import { describe, it, expect } from "vitest";
import {
  providerConfig,
  providerDefaults,
  providerOrder,
  relayRequest,
  validProviderPayload,
  chooseProviderResult,
  retryAfterSeconds,
} from "../bitcraft/src/providers";
import {
  relayPlayer,
  relayInventory,
  relayCrafts,
} from "../src/bitcraft-ui/relay";
import { inventoryTracker, passiveTracker } from "../src/bitcraft-ui/trackers";
import { validateGuide } from "../bitcraft/src/guides";
describe("BitCraft provider and publishing rules", () => {
  it("honors both numeric and HTTP-date retry headers", () => {
    expect(retryAfterSeconds("12", 100)).toBe(12);
    expect(retryAfterSeconds("Thu, 01 Jan 1970 00:02:00 GMT", 100)).toBe(20);
    expect(retryAfterSeconds("invalid", 100)).toBe(60);
  });
  it("uses primary player details and separates relay paths from API paths", () => {
    expect(providerOrder("player")).toEqual(["bitjita"]);
    expect(providerOrder("market")).toEqual(["bitjita"]);
    expect(providerOrder("relayInventories")).toEqual(["relay"]);
    expect(relayRequest("relayPlayers", "", "Icha & test").path).toBe(
      "player?name=Icha%20%26%20test",
    );
    expect(relayRequest("relayCrafts", "123", "").path).toBe(
      "player/123/crafts?completed=false",
    );
    expect(() => relayRequest("relayClaim", "../token", "")).toThrow();
  });
  it("validates provider limits and keeps credentials out of URLs", () => {
    expect(
      providerConfig(
        "bitjita",
        '{"ttl":{"stalls":600},"identity":"server-only"}',
      ).ttl.stalls,
    ).toBe(600);
    expect(providerDefaults.bitjita.ttl.itemOrders).toBe(30);
    expect(() =>
      providerConfig("bitjita", '{"baseUrl":"https://token@example.com"}'),
    ).toThrow();
    expect(() =>
      providerConfig("bitjita", '{"identity":"header\\nInjected: value"}'),
    ).toThrow();
    expect(() =>
      providerConfig("bitjita", '{"requestsPerMinute":300}'),
    ).toThrow();
  });
  it("accepts object player payloads and rejects invalid primary shapes", () => {
    expect(
      validProviderPayload(
        "bitjuice",
        "player",
        { player: { entityId: "123", username: "Icha", experience: [] } },
        "123",
      ),
    ).toBe(true);
    expect(
      validProviderPayload(
        "bitjuice",
        "player",
        { player: { entityId: "456", username: "Icha", experience: [] } },
        "123",
      ),
    ).toBe(false);
    expect(validProviderPayload("bitjuice", "player", { player: [] })).toBe(
      false,
    );
    expect(
      validProviderPayload("bitjuice", "inventories", {
        inventories: [],
        items: {},
        cargos: {},
      }),
    ).toBe(true);
    expect(
      validProviderPayload("bitjuice", "inventories", { message: "offline" }),
    ).toBe(false);
  });
  it("prefers fresh fallback data, then the newest available stale result", () => {
    const stale = {
      payload: "old",
      error: "delayed",
      updatedAt: 5n,
      retryAt: 100n,
    };
    const fresh = { payload: "fresh", error: "", updatedAt: 3n, retryAt: 0n };
    expect(chooseProviderResult([stale, fresh], 10n)).toBe(fresh);
    expect(
      chooseProviderResult(
        [stale, { ...stale, payload: "newer", updatedAt: 8n }],
        10n,
      ).payload,
    ).toBe("newer");
    expect(
      chooseProviderResult(
        [{ ...stale, payload: "", updatedAt: 20n }, stale],
        10n,
      ).payload,
    ).toBe("old");
  });
  it("counts relay pockets and cargo in supported mounts without counting claim stores", () => {
    const player = relayPlayer({
      entity_id: "123",
      username: "Icha",
      signed_in: true,
    });
    const raw = relayInventory({
      inventories: [
        {
          entity_id: "1",
          category: "pockets",
          name: "Pockets",
          items: [{ item_id: 7, item_type: "item", quantity: 12 }],
        },
        {
          entity_id: "2",
          name: "Cart",
          items: [{ item_id: 8, item_type: "cargo", quantity: 3 }],
        },
        {
          entity_id: "3",
          name: "Claim warehouse",
          items: [{ item_id: 7, item_type: "item", quantity: 200 }],
        },
      ],
    });
    const result = inventoryTracker(
      player,
      raw,
      [
        { id: 7, kind: "item", name: "Plank" },
        { id: 8, kind: "cargo", name: "Frames" },
      ],
      { itemKeys: ["item:7", "cargo:8"], itemNeeds: { "item:7": 20 } },
    );
    expect(result.tracker.items.map((row) => row.quantity)).toEqual([12, 3]);
    expect(result.tracker.items[0].remaining).toBe(8);
  });
  it("uses relay progress for passive crafts and public claim metadata", () => {
    const payload = relayCrafts(
      {
        crafts: [
          {
            entity_id: "1",
            recipe_id: 11,
            is_passive: true,
            completed: false,
            craft_count: 2,
            progress: 20,
            total_actions_required: 100,
            claim_entity_id: "4",
            building_name: "Kiln",
            crafted_item: [{ item_id: 7, item_type: "item", quantity: 3 }],
          },
          { entity_id: "2", is_passive: false, completed: false },
          { entity_id: "3", is_passive: true, completed: true },
        ],
      },
      {
        "4": {
          name: "Home",
          region: 2,
          region_name: "Soluna",
          location_x: 12,
          location_z: 24,
        },
      },
    );
    payload.items = { 7: { id: 7, name: "Brick" } };
    const result = passiveTracker({ username: "Icha" }, payload, { 11: 10 });
    expect(result.crafts).toHaveLength(1);
    expect(result.crafts[0].estimatedRemainingSeconds).toBe(80);
    expect(result.crafts[0].claim.name).toBe("Home");
    expect(result.crafts[0].outputs[0].totalQuantity).toBe(6);
  });
  it("rejects executable URLs and oversized guide content but accepts inline item cards", () => {
    const guide = {
      title: "Guide",
      summary: "",
      category: "",
      content: JSON.stringify({
        type: "doc",
        content: [
          {
            type: "bitcraftItem",
            attrs: { id: 7, kind: "item", name: "Plank" },
          },
        ],
      }),
    };
    expect(validateGuide(guide).title).toBe("Guide");
    expect(() =>
      validateGuide({
        ...guide,
        content: JSON.stringify({
          type: "doc",
          content: [{ type: "image", attrs: { src: "javascript:alert(1)" } }],
        }),
      }),
    ).toThrow(/URL/);
    expect(() =>
      validateGuide({ ...guide, content: "x".repeat(200001) }),
    ).toThrow(/content/);
  });
});
