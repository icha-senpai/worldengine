import { defaultRouteIndex } from "../../bitcraft/src/crafting/model";
import type { ItemKey } from "../../bitcraft/src/crafting/model";
import type { RecipeNode } from "../../bitcraft/src/crafting/catalog";

export interface CraftingPlanState {
  planVersion: 2;
  activeTab: "steps" | "tree" | "totals";
  routes: Record<string, string>;
  expansion: Record<string, boolean>;
  expandByDefault: boolean;
  materialMode: "base" | "immediate";
  completedSteps: Record<string, string>;
  groupByStation: boolean;
  hideCompleted: boolean;
}
export type LegacyRouteGroups = Record<
  string,
  { targetKey: ItemKey; routeIds: (string | null)[] }
>;
const record = (value: unknown): Record<string, unknown> =>
  value && typeof value === "object" && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {};
export const defaultCraftingPlanState = (): CraftingPlanState => ({
  planVersion: 2,
  activeTab: "steps",
  routes: {},
  expansion: {},
  expandByDefault: false,
  materialMode: "base",
  completedSteps: {},
  groupByStation: false,
  hideCompleted: false,
});
export function needsCraftingPlanMigration(serialized: string | null): boolean {
  try {
    const state = record(JSON.parse(serialized ?? "{}"));
    return (
      state.planVersion === 1 ||
      (state.planVersion == null &&
        Object.values(record(state.routes)).some(Number.isInteger))
    );
  } catch {
    return false;
  }
}
export function parseCraftingPlanState(
  serialized: string | null,
  legacy: LegacyRouteGroups = {},
): CraftingPlanState {
  const result = defaultCraftingPlanState();
  try {
    const state = record(JSON.parse(serialized ?? "{}"));
    const version = state.planVersion ?? 1;
    if (version !== 1 && version !== 2) return result;
    if (
      state.activeTab === "steps" ||
      state.activeTab === "tree" ||
      state.activeTab === "totals"
    )
      result.activeTab = state.activeTab;
    for (const [key, value] of Object.entries(record(state.routes))) {
      if (version === 2 && typeof value === "string")
        result.routes[key] = value;
      else if (version === 1 && Number.isInteger(value) && Number(value) >= 0) {
        const group = legacy[key];
        const routeId = group?.routeIds[Number(value)];
        if (routeId) result.routes[group.targetKey] = routeId;
      }
    }
    result.expansion = Object.fromEntries(
      Object.entries(record(state.expansion)).filter(
        (entry): entry is [string, boolean] => typeof entry[1] === "boolean",
      ),
    );
    result.completedSteps = Object.fromEntries(
      Object.entries(record(state.completedSteps)).filter(
        (entry): entry is [string, string] => typeof entry[1] === "string",
      ),
    );
    result.expandByDefault = state.expandByDefault === true;
    result.groupByStation = state.groupByStation === true;
    result.hideCompleted = state.hideCompleted === true;
    result.materialMode =
      state.materialMode === "immediate" ? "immediate" : "base";
  } catch {
    /* Invalid or unavailable browser state starts a fresh plan. */
  }
  return result;
}
export function applyRecipeSelections(
  recipes: RecipeNode[],
  routes: Record<string, string>,
): void {
  for (const recipe of recipes) {
    const alternatives = recipe.alternatives?.length
      ? recipe.alternatives
      : [recipe];
    const index = alternatives.findIndex(
      (option) => option.id === routes[recipe.targetKey],
    );
    recipe.selectedAlternativeIndex =
      index >= 0 ? index : defaultRouteIndex(alternatives);
    for (const option of alternatives) {
      for (const ingredient of option.ingredients)
        applyRecipeSelections(ingredient.recipes ?? [], routes);
    }
  }
}
export function selectedRouteState(
  recipe: RecipeNode,
  index: number,
): { key: ItemKey; id: string } | null {
  const option = (recipe.alternatives?.length ? recipe.alternatives : [recipe])[
    index
  ];
  return option && recipe.targetKey
    ? { key: recipe.targetKey, id: option.id }
    : null;
}
