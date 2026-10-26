import { describe, it, expect } from "vitest";
import { buildSnapshot } from "../scripts/import-bitcraft-snapshot.mjs";
import catalog from "../bitcraft/src/content/catalog.json";
import routes from "../bitcraft/src/content/crafting-catalog.json";

describe("public BitCraft snapshot", () => {
  it("keeps current release provenance and exposes new hunting recipes", () => {
    expect(catalog.generatedAt).toBe("2026-10-22T16:47:05Z");
    expect(catalog.source.commit).toBe(
      "e92453d5c29fb4c165ee126615b08f55c8959c8d",
    );
    const lures = catalog.items.filter((item) =>
      /Animal Lure$/.test(item.name),
    );
    expect(lures).toHaveLength(10);
    for (const lure of lures) {
      expect(
        catalog.details[`item:${lure.id}`].craftingRecipes.length,
      ).toBeGreaterThan(0);
      expect(routes.recipes[`item:${lure.id}`].length).toBeGreaterThan(0);
    }
    expect(
      catalog.entries.every((entry) => entry.skill.name && entry.resource),
    ).toBe(true);
    expect(routes.omissions).toEqual([]);
  });
  it("preserves readable-schema cargo, station, skill and probabilistic extraction contracts", () => {
    const stack = { item_id: 2, item_type: "Cargo", quantity: 3 };
    const extraction = {
      id: 7,
      resource_id: 9,
      verb_phrase: "Gather",
      time_requirement: 2,
      extracted_item_stacks: [{ item_stack: stack, probability: 0.5 }],
      consumed_item_stacks: [],
      level_requirements: [{ skill_id: 2, level: 10 }],
      experience_per_progress: [{ skill_id: 2, quantity: 1.5 }],
      tool_requirements: [{ tool_type: 1, level: 1, power: 1 }],
    };
    const data = buildSnapshot(
      {
        item_desc: [{ id: 1, name: "Tool", tag: "Tool", rarity: "Common" }],
        cargo_desc: [{ id: 2, name: "Cargo", rarity: "Common" }],
        skill_desc: [{ id: 2, name: "Foraging" }],
        tool_type_desc: [{ id: 1, name: "Knife" }],
        building_type_desc: [{ id: 3, name: "Station" }],
        resource_desc: [{ id: 9, name: "Resource", max_health: 50 }],
        extraction_recipe_desc: [extraction],
        crafting_recipe_desc: [
          {
            id: 8,
            name: "Craft",
            crafted_item_stacks: [stack],
            consumed_item_stacks: [],
            level_requirements: [{ skill_id: 2 }],
            building_requirement: { building_type: 3 },
            time_requirement: 5,
          },
        ],
      },
      { commit: "fixture", generatedAt: "2026-10-22T16:47:05Z" },
    );
    const detail = data.details["cargo:2"];
    expect(detail.craftingRecipes[0]).toMatchObject({
      craftingStation: "Station",
      skillName: "Foraging",
    });
    expect(data.entries[0].outputs[0]).toMatchObject({
      kind: "cargo",
      name: "Cargo",
      quantity: 3,
      probability: 0.5,
    });
    expect(detail.extractionRecipes[0].craftedItems[0].kind).toBe("cargo");
    expect(() => buildSnapshot({}, {})).toThrow("Missing table");
  });
});
