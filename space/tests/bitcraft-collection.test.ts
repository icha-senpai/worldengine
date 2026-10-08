import { describe, expect, it } from "vitest";
import {
  projectCollection,
  collectionKey,
  collectionInterval,
  collectionWatchSeconds,
  observedRates,
} from "../bitcraft/src/collection";
import {
  mergeInventories,
  storageInventories,
  relaySkills,
  relayCrafts,
} from "../src/bitcraft-ui/relay";
import { inventoryTracker } from "../src/bitcraft-ui/trackers";

describe("shared BitCraft collection", () => {
  it("keeps XP collecting for a bounded background session without extending other feeds", () => {
    expect(collectionWatchSeconds("relaySkills")).toBe(21600);
    expect(collectionWatchSeconds("relaySession")).toBe(600);
    expect(collectionWatchSeconds("relayInventories")).toBe(600);
  });
  it("does not carry XP gains across reconnects, gaps or decreases", () => {
    const sample = (at: number, xp: number, epoch = "one") => ({
      at,
      xp: { "3": xp },
      epoch,
      source: "relay",
    });
    expect(
      observedRates([sample(0, 100), sample(60000, 160)])[0].hourRate,
    ).toBe(3600);
    expect(observedRates([sample(0, 100), sample(60000, 160, "two")])).toEqual(
      [],
    );
    expect(observedRates([sample(0, 100), sample(180000, 160)])).toEqual([]);
    expect(
      observedRates([sample(0, 100), sample(60000, 50), sample(120000, 100)])[0]
        .xpDelta,
    ).toBe(0);
    expect(
      observedRates([sample(0, 100), sample(60000, 50), sample(120000, 120)])[0]
        .xpDelta,
    ).toBe(20);
  });
  it("keeps large IDs exact and rejects lossy IDs before reconciliation", () => {
    const id = "1297036692719788627";
    expect(
      projectCollection(
        "relaySkills",
        { player: { entity_id: id }, skills: [{ skill_id: 3, xp: 120 }] },
        id,
      ).find((row) => row.kind === "skill"),
    ).toMatchObject({ entityId: "3", ownerId: id, quantity: 120 });
    expect(() =>
      projectCollection("relayPlayer", { entity_id: Number(id) }),
    ).toThrow(/precision/);
    expect(() =>
      projectCollection(
        "relaySkills",
        { skills: [{ skill_id: 3, xp: -1 }] },
        id,
      ),
    ).toThrow();
    expect(() => projectCollection("relayInventories", {})).toThrow(/snapshot/);
    expect(
      projectCollection(
        "relaySkills",
        { skills: [{ skill_id: 3, xp: null }] },
        id,
      ),
    ).toEqual([]);
  });
  it("aggregates pockets without conflating items and cargo", () => {
    const rows = projectCollection(
      "relayInventories",
      {
        inventories: [
          {
            entity_id: "20",
            items: [
              { item_id: 7, quantity: 2, item_type: "Item" },
              { item_id: 7, quantity: 3, item_type: "Item" },
              { item_id: 7, quantity: 4, item_type: "Cargo" },
            ],
          },
        ],
      },
      "10",
    );
    expect(
      rows
        .filter((row) => row.kind === "inventory-item")
        .map((row) => [row.itemKey, row.quantity]),
    ).toEqual([
      ["item:7", 5],
      ["cargo:7", 4],
    ]);
  });
  it("counts opted-in storage once and keeps the source location", () => {
    const building = {
      entity_id: "20",
      name: "Chest",
      items: [{ item_id: 7, quantity: 4, item_type: "Item" }],
    };
    const housing = storageInventories({ buildings: [building] }, "housing");
    const merged = mergeInventories(
      { inventories: housing },
      { inventories: housing },
    );
    expect(merged.inventories).toHaveLength(1);
    const result = inventoryTracker(
      { entityId: "10" },
      merged,
      [{ id: 7, kind: "item", name: "Plank" }],
      { itemKeys: ["item:7"] },
    );
    expect(result.tracker.items[0].quantity).toBe(4);
    expect(result.tracker.items[0].sources[0].kind).toBe("housing");
  });
  it("separates active craft jobs and maintains unknown XP", () => {
    const result = relayCrafts(
      {
        crafts: [
          {
            entity_id: "20",
            is_passive: false,
            completed: false,
            total_actions_required: 100,
            progress: 30,
            owner_entity_id: "10",
            recipe_id: 7,
            crafted_item: [],
          },
        ],
      },
      {},
      false,
    );
    expect(result.craftResults[0]).toMatchObject({
      ownerEntityId: "10",
      totalActionsRequired: 100,
    });
    expect(
      relaySkills({ skills: [{ skill_id: 3, xp: 0 }] }, { entityId: "10" })
        .experience,
    ).toEqual([{ skill_id: 3, quantity: 0 }]);
  });
  it("scopes pages independently and uses different refresh intervals", () => {
    expect(collectionKey("claims", "api/claims?page=1")).not.toBe(
      collectionKey("claims", "api/claims?page=2"),
    );
    expect(collectionInterval("relaySession")).toBeLessThan(
      collectionInterval("regions"),
    );
  });
});
