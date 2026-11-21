import { describe, it, expect } from "vitest";
import { buildSnapshot } from "../scripts/import-bitcraft-snapshot.mjs";
import catalog from "../bitcraft/src/content/catalog.json";
import routes from "../bitcraft/src/content/crafting-catalog.json";
import {
  estimateGathering,
  gatheringDefaults,
} from "../src/bitcraft-ui/gatheringEstimates";

describe("public BitCraft snapshot", () => {
  it("uses November 19 food ingredients, removes the research funnel, and normalizes rare drops", () => {
    const recipe = (key, id) =>
      catalog.details[key].craftingRecipes.find((row) => row.id === id);
    expect(recipe("item:127337957", 2105423272).consumedItemStacks).toEqual([
      { item_id: 907267894, item_type: "item", quantity: 1 },
    ]);
    expect(recipe("item:1893904106", 113397106).consumedItemStacks).toEqual([
      { item_id: 51716987, item_type: "item", quantity: 5 },
    ]);
    expect(
      recipe("item:2220018", 220005).consumedItemStacks.some(
        (row) => row.item_id === 1210004,
      ),
    ).toBe(false);
    expect(catalog.entries.some((row) => row.id === 1673714271)).toBe(false);
    expect(
      catalog.entries.find((row) => row.resourceId === 52).resource.name,
    ).toBe("Sodalite Outcrop");
    expect(
      routes.recipes["item:706688339"].find(
        (row) =>
          row.id === "extraction:912157791:item:706688339:bundle:1952387304",
      ).outputQuantity,
    ).toBe(0.0002);
  });

  it("uses the 1.0 Hexite yields in tool-rate estimates", () => {
    const settings = {
      ...gatheringDefaults,
      power: 10,
      minutes: 30,
      gatheringSpeed: 0,
      skillSpeed: 0,
    };
    const meteor = catalog.entries.find((entry) => entry.id === 778616801);
    const reservoir = catalog.entries.find((entry) => entry.id === 836384891);
    expect(
      estimateGathering(meteor, settings).outputs.find(
        (output) => output.id === 828972621,
      ).expected,
    ).toBe(1500);
    expect(
      estimateGathering(reservoir, settings).outputs.find(
        (output) => output.id === 828972621,
      ).expected,
    ).toBe(3000);
    const stumps = catalog.entries.filter((entry) =>
      /Chop .*Hexite Infused Stump$/.test(entry.name),
    );
    expect(stumps).toHaveLength(10);
    expect(
      stumps.every((entry) =>
        entry.outputs.some(
          (output) => output.id === 828972621 && output.probability > 0,
        ),
      ),
    ).toBe(true);
    expect(
      catalog.entries
        .find((entry) => entry.id === 57)
        .outputs.some((output) => output.name === "Cooling Energy"),
    ).toBe(false);
  });
  it("keeps current release provenance and exposes new hunting recipes", () => {
    expect(catalog.generatedAt).toBe("2026-11-19T17:25:51Z");
    expect(catalog.source.commit).toBe(
      "856b4f3702785b1c32812e719b650fee077a6fac",
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
    expect(catalog.excludedRecipes).not.toContainEqual({
      kind: "extraction",
      id: 1673714271,
      reason: "No resource or cargo target",
    });
  });
  it("plans the new refined materials and field farming without disabled passive recipes", () => {
    const refined = catalog.details["item:1679904254"].craftingRecipes.find(
      (r) => r.id === 221709075,
    );
    expect(refined.consumedItemStacks).toEqual([
      { item_id: 5050001, item_type: "item", quantity: 13 },
      { item_id: 68565112, item_type: "item", quantity: 3 },
    ]);
    expect(
      catalog.items.filter((i) => /Settlement Research$/.test(i.name)),
    ).toHaveLength(10);
    expect(
      catalog.details["item:1100006"].craftingRecipes.some(
        (r) => r.id === 110002,
      ),
    ).toBe(false);
    const farm = catalog.details["item:1220020"].craftingRecipes.find(
      (r) => r.id === -1365317533,
    );
    expect(farm).toMatchObject({
      craftingStation: "Fertile Ground",
      timeRequirement: null,
      outputQuantity: 5,
    });
    expect(farm.consumedItemStacks).toEqual([
      { item_id: 1100005, item_type: "item", quantity: 5 },
      { item_id: 1100001, item_type: "item", quantity: 3 },
      { item_id: 11007, item_type: "item", quantity: 1 },
    ]);
    expect(
      routes.recipes["item:1220020"].some(
        (r) => r.id === "crafting:-1365317533:item:1220020",
      ),
    ).toBe(true);
    expect(routes.recipes["item:1220020"][0]).toMatchObject({
      id: "crafting:-1365317533:item:1220020",
      preference: "primary",
    });
    expect(
      routes.recipes["item:1100005"][0].ingredients.some(
        (s) => s.key === "item:1100005",
      ),
    ).toBe(false);
    expect(
      routes.recipes["item:1100005"].find(
        (r) => r.id === "crafting:-1365317533:item:1100005:bundle:1220020",
      ).preference,
    ).toBe("secondary");
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
        secondary_knowledge_desc: [{ id: 12345, name: "The Art of Cheating" }],
        placeable_desc: [{ id: 1, tag: "Other" }],
        placeable_placement_desc: [{ id: 1, placed_placeable_id: 1 }],
        placeable_interaction_desc: [{ id: 1, placeable_id: 1 }],
        placeable_growth_desc: [{ id: 1, placeable_id: 1 }],
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
