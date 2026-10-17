import { describe, expect, it } from "vitest";
import {
  importCraftingCatalog,
  type ImportInputs,
} from "../scripts/import-crafting-catalog";
import {
  createCraftingCatalog,
  type CraftingItem,
} from "../bitcraft/src/crafting/catalog";
import {
  compareRecipeRoutes,
  defaultRouteIndex,
  type CraftingCatalog,
  type ItemKey,
} from "../bitcraft/src/crafting/model";
import generated from "../bitcraft/src/content/crafting-catalog.json";

function fixture(): ImportInputs {
  return {
    catalog: {
      items: [
        { id: 1, kind: "item", name: "Clay", category: "Clay" },
        { id: 2, kind: "item", name: "Clay Output", category: "Clay Output" },
        { id: 3, kind: "cargo", name: "Clay Package", category: "Package" },
        { id: 4, kind: "item", name: "Tool", category: "Tool" },
        { id: 5, kind: "cargo", name: "Trunk", category: "Trunk" },
      ],
      details: {
        "item:1": {
          craftingRecipes: [
            {
              id: 10,
              recipeName: "Unpack {1}",
              craftedItems: [{ id: 1, quantity: 100 }],
              consumedItems: [{ id: 3, kind: "cargo", quantity: 1 }],
            },
          ],
        },
        "item:2": {
          extractionRecipes: [
            {
              id: 20,
              recipeName: "Dig {0}",
              timeRequirement: 1.6,
              craftedItems: [{ id: 2, quantity: 1 }],
            },
          ],
        },
        "item:4": {
          craftingRecipes: [
            {
              id: 30,
              recipeName: "Craft {0}",
              craftedItems: [{ id: 4, quantity: 1 }],
              consumedItems: [{ id: 1, quantity: 2 }],
            },
            {
              id: 31,
              recipeName: "Recraft {0}",
              craftedItems: [{ id: 4, quantity: 1 }],
              consumedItems: [{ id: 4, quantity: 1 }],
            },
          ],
        },
      },
      entries: [],
    },
    additions: { items: [] },
    items: [{ id: 2, item_list_id: 50 }],
    lists: [
      {
        id: 50,
        possibilities: [
          {
            probability: 0.75,
            items: [{ item_id: 1, item_type: "Item", quantity: 1 }],
          },
          {
            probability: 0.25,
            items: [{ item_id: 1, item_type: "Item", quantity: 3 }],
          },
        ],
      },
    ],
    resources: [
      {
        id: 100,
        name: "Oak Tree",
        tag: "Tree",
        tier: 1,
        on_destroy_yield: [
          { item_id: 5, item_type: "Cargo", quantity: 1 },
          { item_id: 5, item_type: "Cargo", quantity: 2 },
        ],
      },
    ],
    extractions: [
      {
        id: 110,
        resource_id: 100,
        verb_phrase: "Chop",
        level_requirements: [{ skill_id: 1 }],
      },
    ],
    skills: [{ id: 1, name: "Forestry" }],
  };
}

describe("crafting catalog importer", () => {
  it("resolves weighted bundle contents and classifies routes once at import", () => {
    const data = importCraftingCatalog(fixture());
    const clay = data.recipes["item:1"];
    expect(clay[0]).toMatchObject({
      id: "extraction:20:item:1:bundle:2",
      action: "gather",
      name: "Dig Clay",
      outputQuantity: 1.5,
      averageOutput: true,
      preference: "normal",
    });
    expect(clay[1]).toMatchObject({
      action: "unpack",
      preference: "secondary",
    });
    expect(data.recipes["cargo:5"][0]).toMatchObject({
      action: "gather",
      preference: "primary",
      outputQuantity: 3,
      duration: null,
      legacyIds: ["100", "110"],
    });
    expect(data.omissions).toEqual([]);
  });
  it("keeps route identity and classifications independent of display names", () => {
    const original = importCraftingCatalog(fixture());
    const renamed = fixture();
    renamed.catalog.items[0].name = "Renamed material";
    const changed = importCraftingCatalog(renamed);
    expect(
      changed.recipes["item:1"].map((r) => [r.id, r.action, r.preference]),
    ).toEqual(
      original.recipes["item:1"].map((r) => [r.id, r.action, r.preference]),
    );
    const options = [
      {
        id: "a",
        name: "Unpack misleading label",
        action: "craft" as const,
        preference: "normal" as const,
      },
      {
        id: "b",
        name: "Craft misleading label",
        action: "unpack" as const,
        preference: "secondary" as const,
      },
    ];
    expect(defaultRouteIndex(options)).toBe(0);
  });
  it("rejects invalid quantities and probabilities, and records missing definitions", () => {
    const invalid = fixture();
    invalid.catalog.details[
      "item:4"
    ].craftingRecipes![0].consumedItems![0].quantity = -1;
    expect(() => importCraftingCatalog(invalid)).toThrow(/Invalid quantities/);
    const badChance = fixture();
    badChance.lists[0].possibilities[0].probability = -0.5;
    expect(() => importCraftingCatalog(badChance)).toThrow(
      /Invalid probabilities/,
    );
    const missing = fixture();
    missing.catalog.details["item:4"].craftingRecipes![0].consumedItems![0].id =
      999;
    expect(importCraftingCatalog(missing).omissions).toContainEqual({
      recipeId: "crafting:30:item:4",
      missingItems: ["item:999"],
    });
  });
  it("keeps the generated graph valid, ordered, and free of duplicate route identities", () => {
    const data = generated as unknown as CraftingCatalog;
    expect(data.sources.length).toBe(7);
    expect(data.omissions).toEqual([]);
    expect(Object.keys(data.recipes).length).toBeGreaterThan(6000);
    for (const [target, recipes] of Object.entries(data.recipes)) {
      expect(new Set(recipes.map((r) => r.id)).size, target).toBe(
        recipes.length,
      );
      expect(recipes[defaultRouteIndex(recipes)].id).toBe(recipes[0].id);
      for (const recipe of recipes) {
        expect(recipe.targetKey).toBe(target);
        expect(recipe.outputQuantity).toBeGreaterThan(0);
        expect(Number.isFinite(recipe.outputQuantity)).toBe(true);
        expect(
          recipe.ingredients.every(
            (s) => Number.isFinite(s.quantity) && s.quantity > 0,
          ),
        ).toBe(true);
      }
      expect([...recipes].sort(compareRecipeRoutes).map((r) => r.id)).toEqual(
        recipes.map((r) => r.id),
      );
    }
  });
  it("hydrates a bounded tree and blocks self-consuming recrafts from prerequisites", () => {
    const source = fixture();
    const data = importCraftingCatalog(source);
    const items = new Map(
      source.catalog.items.map((i) => [`${i.kind}:${i.id}`, i as CraftingItem]),
    );
    const runtime = createCraftingCatalog(data, (key: ItemKey) =>
      items.get(key),
    );
    const root = runtime.tree("item:4")[0];
    expect(root.action).toBe("craft");
    expect(root.ingredients[0].recipes![0].action).toBe("gather");
    expect(root.alternatives).toBeUndefined();
    expect(
      runtime.tree("item:4", 0, new Set(["item:4"]), { remaining: 1 })[0]
        .ingredients[0].recipesDeferred,
    ).toBe(true);
    expect(runtime.tree("item:4", 16)).toEqual([]);
  });
});
