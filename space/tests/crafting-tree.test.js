import { describe, expect, it } from "vitest";
import {
  defaultRecipeIndex,
  formatCraftDuration,
  materialTotals,
  selectedRecipe,
  craftingSteps,
  recipeRouteLabel,
} from "../src/bitcraft-ui/craftingTree.js";
import { catalogResponse } from "../bitcraft/src/catalog";

describe("wood log sourcing", () => {
  const logs = catalogResponse("targets", "", "Wood Log").filter(
    (row) => row.kind === "item" && /wood log$/i.test(row.name),
  );

  it("defaults all ten tiers to splitting gathered trunks, keeping packages as secondary routes", () => {
    expect(logs).toHaveLength(10);
    for (const log of logs) {
      const data = catalogResponse("detail", `item:${log.id}`, "");
      const root = data.recipeTree[0];
      const primary = selectedRecipe(root);
      expect(primary.primaryRoute, log.name).toBe(true);
      expect(primary.outputQuantity, log.name).toBe(6);
      expect(primary.ingredients[0].category, log.name).toBe("Trunk");
      const gathering = selectedRecipe(primary.ingredients[0].recipes[0]);
      expect(gathering.gathering, log.name).toBe(true);
      expect(gathering.primaryRoute, log.name).toBe(true);
      expect(gathering.name, log.name).toMatch(/^Chop .+Tree$/);
      expect(recipeRouteLabel(primary)).toContain("Primary");
      const packageRoute = root.alternatives.find(
        (option) => option.secondaryRoute,
      );
      expect(packageRoute.name).toMatch(/^Unpack .+Wood Log Package$/);
      expect(recipeRouteLabel(packageRoute)).toContain("Secondary");
    }
  });

  it("calculates trunk requirements while preserving an explicitly chosen package route", () => {
    const log = logs.find((row) => Number(row.tier) === 10);
    const { recipeTree } = catalogResponse("detail", `item:${log.id}`, "");
    expect(materialTotals(recipeTree, 7)).toEqual([
      expect.objectContaining({ name: "Flawless Trunk", quantity: 2 }),
    ]);
    const primarySteps = craftingSteps(recipeTree, 7, log);
    expect(primarySteps.map((step) => step.active.name)).toEqual([
      "Chop Tier 10 Tree",
      "Split Flawless Trunk into Flawless Wood Log",
    ]);
    recipeTree[0].selectedAlternativeIndex =
      recipeTree[0].alternatives.findIndex((option) => option.secondaryRoute);
    expect(materialTotals(recipeTree, 7)).toEqual([
      expect.objectContaining({
        name: "Flawless Wood Log Package",
        quantity: 1,
      }),
    ]);
    expect(craftingSteps(recipeTree, 7, log).at(-1).active.name).toBe(
      "Unpack Flawless Wood Log Package",
    );
  });
});

describe("crafting plan", () => {
  const ore = { kind: "cargo", id: 1, name: "Ore", quantity: 3 };
  const ingot = {
    kind: "item",
    id: 2,
    name: "Ingot",
    quantity: 5,
    recipes: [{ outputQuantity: 2, ingredients: [ore] }],
  };
  const recipe = { outputQuantity: 2, ingredients: [ingot] };

  it("rounds batches at each step and distinguishes immediate ingredients from base materials", () => {
    expect(materialTotals([recipe], 3, "immediate")[0].quantity).toBe(10);
    expect(materialTotals([recipe], 3, "base")[0]).toMatchObject({
      name: "Ore",
      quantity: 15,
    });
  });

  it("keeps unloaded intermediates explicit, then combines shared materials after loading", () => {
    const deferred = {
      kind: "item",
      id: 3,
      name: "Thread",
      quantity: 2,
      recipesDeferred: true,
    };
    const root = { outputQuantity: 1, ingredients: [ingot, deferred] };
    expect(materialTotals([root], 1)).toContainEqual(
      expect.objectContaining({ name: "Thread", deferred: true, quantity: 2 }),
    );
    deferred.recipes = [{ outputQuantity: 1, ingredients: [ore] }];
    deferred.recipesDeferred = false;
    expect(materialTotals([root], 1)).toEqual([
      expect.objectContaining({ name: "Ore", quantity: 15 }),
    ]);
  });

  it("prefers crafting a new item while respecting an explicit saved recraft route", () => {
    const routes = {
      alternatives: [
        {
          name: "Recraft Pickaxe",
          outputQuantity: 1,
          ingredients: [
            { kind: "item", id: 4, name: "Existing pickaxe", quantity: 1 },
          ],
        },
        { name: "Craft Pickaxe", outputQuantity: 1, ingredients: [ore] },
      ],
    };
    expect(defaultRecipeIndex(routes)).toBe(1);
    expect(selectedRecipe(routes).name).toBe("Craft Pickaxe");
    routes.selectedAlternativeIndex = 0;
    expect(materialTotals([routes], 1)[0].name).toBe("Existing pickaxe");
  });

  it("formats catalog durations without floating point noise", () => {
    expect(formatCraftDuration(1.600000023841858)).toBe("1.6s");
    expect(formatCraftDuration(3600)).toBe("1h");
    expect(formatCraftDuration(90)).toBe("1.5m");
  });

  it("combines shared steps and keeps all prerequisites ahead of their consumers", () => {
    const smelt = {
      id: 10,
      name: "Smelt ingot",
      station: "Smelter",
      outputQuantity: 2,
      ingredients: [ore],
    };
    const makePart = (id, name) => ({
      id,
      name,
      station: "Workbench",
      outputQuantity: 1,
      ingredients: [
        {
          kind: "item",
          id: 2,
          name: "Ingot",
          quantity: 1,
          recipes: [structuredClone(smelt)],
        },
      ],
    });
    const target = {
      id: 30,
      name: "Craft tool",
      station: "Workbench",
      outputQuantity: 1,
      ingredients: [
        {
          kind: "item",
          id: 11,
          name: "Handle",
          quantity: 1,
          recipes: [makePart(20, "Make handle")],
        },
        {
          kind: "item",
          id: 12,
          name: "Head",
          quantity: 1,
          recipes: [makePart(21, "Make head")],
        },
      ],
    };
    const steps = craftingSteps([target], 1);
    expect(steps.map((step) => step.active.name)).toEqual([
      "Smelt ingot",
      "Make handle",
      "Make head",
      "Craft tool",
    ]);
    expect(steps[0]).toMatchObject({
      quantity: 2,
      batches: 2,
      produced: 4,
      stage: 0,
    });
    expect(steps[0].ingredients[0].quantity).toBe(6);
    expect(materialTotals([target], 1)[0].quantity).toBe(6);
    for (const step of steps) {
      for (const dependency of step.dependencies) {
        const prerequisite = steps.find(
          (candidate) => candidate.key === dependency,
        );
        expect(prerequisite.number).toBeLessThan(step.number);
        expect(prerequisite.stage).toBeLessThan(step.stage);
      }
    }
  });

  it("includes gathering steps and prevents checklist completion carrying into a different quantity or route", () => {
    const gather = {
      id: 40,
      name: "Mine ore",
      outputQuantity: 4,
      ingredients: [],
    };
    const root = {
      id: 41,
      name: "Craft ingot",
      outputQuantity: 1,
      ingredients: [{ ...ore, recipes: [gather] }],
    };
    const first = craftingSteps([root], 1);
    expect(first[0]).toMatchObject({ batches: 1, produced: 4, quantity: 3 });
    expect(first[0].ingredients).toEqual([]);
    const next = craftingSteps([root], 2);
    expect(next[0].completionSignature).not.toBe(first[0].completionSignature);
    const alternative = {
      alternatives: [root, { ...root, id: 42, name: "Recraft ingot" }],
      selectedAlternativeIndex: 1,
    };
    expect(craftingSteps([alternative], 1).at(-1).key).not.toBe(
      first.at(-1).key,
    );
  });
});
