import { describe, expect, it } from "vitest";
import {
  applyRecipeSelections,
  needsCraftingPlanMigration,
  parseCraftingPlanState,
  selectedRouteState,
} from "../src/bitcraft-ui/craftingPlanState";
import legacy from "../src/bitcraft-ui/craftingLegacyRoutes.json";
import generated from "../bitcraft/src/content/crafting-catalog.json";
import type { RecipeNode } from "../bitcraft/src/crafting/catalog";

const route = (id: string, preference = "normal") =>
  ({
    id,
    targetKey: "item:1",
    name: id,
    action: "process",
    preference,
    ingredients: [],
    outputQuantity: 1,
  }) as RecipeNode;
describe("saved crafting plan identity", () => {
  it("preserves a selected route after alternatives reorder or a new preferred route appears", () => {
    const root = {
      ...route("ordinary"),
      alternatives: [route("ordinary"), route("package", "secondary")],
    };
    const choice = selectedRouteState(root, 1)!;
    const state = parseCraftingPlanState(
      JSON.stringify({ planVersion: 2, routes: { [choice.key]: choice.id } }),
    );
    root.alternatives = [
      route("new preferred", "primary"),
      route("package", "secondary"),
      route("ordinary"),
    ];
    applyRecipeSelections([root], state.routes);
    expect(root.alternatives[root.selectedAlternativeIndex!].id).toBe(
      "package",
    );
    root.alternatives.reverse();
    applyRecipeSelections([root], state.routes);
    expect(root.alternatives[root.selectedAlternativeIndex!].id).toBe(
      "package",
    );
  });
  it("migrates v1 positions using the captured old ordering, preserving tabs and valid UI preferences", () => {
    const key = Object.keys(legacy).find((key) =>
      key.includes("Split Flawless Trunk"),
    )!;
    const group = (
      legacy as Record<string, { targetKey: string; routeIds: string[] }>
    )[key];
    const index = group.routeIds.findIndex((id) =>
      id.includes("crafting:207647561:"),
    );
    expect(index).toBeGreaterThanOrEqual(0);
    const serialized = JSON.stringify({
      planVersion: 1,
      routes: { [key]: index },
      activeTab: "tree",
      groupByStation: true,
      expansion: { a: true, bad: 1 },
    });
    expect(needsCraftingPlanMigration(serialized)).toBe(true);
    const state = parseCraftingPlanState(serialized, legacy);
    expect(state.routes[group.targetKey]).toBe(group.routeIds[index]);
    expect(state).toMatchObject({
      planVersion: 2,
      activeTab: "tree",
      groupByStation: true,
      expansion: { a: true },
    });
  });
  it("maps every captured legacy choice to an existing route on the same target", () => {
    const recipes = generated.recipes as Record<string, { id: string }[]>;
    for (const group of Object.values(legacy)) {
      const ids = new Set(recipes[group.targetKey]?.map((r) => r.id));
      for (const id of group.routeIds)
        expect(ids.has(id!), group.targetKey).toBe(true);
    }
  });
  it("falls back safely when a saved route disappears or state is malformed", () => {
    const root = {
      ...route("ordinary"),
      alternatives: [route("package", "secondary"), route("ordinary")],
    };
    applyRecipeSelections([root], { "item:1": "deleted route" });
    expect(root.selectedAlternativeIndex).toBe(1);
    expect(parseCraftingPlanState("{broken").routes).toEqual({});
    expect(
      parseCraftingPlanState(
        JSON.stringify({
          planVersion: 2,
          routes: { a: null, b: 1 },
          completedSteps: { a: 1 },
        }),
      ).routes,
    ).toEqual({});
    expect(selectedRouteState(root, -1)).toBeNull();
  });
  it("also recognises unversioned older route selections", () => {
    const serialized = JSON.stringify({ routes: { old: 1 } });
    expect(needsCraftingPlanMigration(serialized)).toBe(true);
    expect(
      parseCraftingPlanState(serialized, {
        old: { targetKey: "item:1", routeIds: ["normal", "package"] },
      }).routes,
    ).toEqual({ "item:1": "package" });
  });
});
