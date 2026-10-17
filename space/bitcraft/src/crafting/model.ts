export type ItemKey = `${"item" | "cargo"}:${number}`;
export type RecipeAction =
  "craft" | "recraft" | "process" | "gather" | "unpack";
export type RecipePreference = "primary" | "normal" | "secondary";
export interface RecipeStack {
  key: ItemKey;
  quantity: number;
}
export interface RecipeDefinition {
  id: string;
  targetKey: ItemKey;
  source: "crafting" | "extraction" | "resource";
  name: string;
  action: RecipeAction;
  preference: RecipePreference;
  station: string | null;
  skill: string | null;
  duration: number | null;
  outputQuantity: number;
  averageOutput: boolean;
  usesAncientMortar: boolean;
  plannable: boolean;
  ingredients: RecipeStack[];
  outputs: RecipeStack[];
  legacyIds: string[];
}
export interface CraftingCatalog {
  version: 1;
  sources: { file: string; sha256: string }[];
  omissions: { recipeId: string; missingItems: ItemKey[] }[];
  recipes: Record<ItemKey, RecipeDefinition[]>;
}
// This small contract is shared by the server and browser. Display names never
// decide which route is selected; the importer supplies the classifications.
export interface RouteOption {
  id?: string | number;
  name?: string;
  action?: RecipeAction;
  preference?: RecipePreference;
  outputQuantity?: number;
  ingredients?: unknown[];
}
const preferenceRank = { primary: 0, normal: 1, secondary: 2 };
const actionRank = { craft: 0, process: 1, gather: 1, recraft: 2, unpack: 3 };
export function compareRecipeRoutes(a: RouteOption, b: RouteOption): number {
  return (
    preferenceRank[a.preference ?? "normal"] -
      preferenceRank[b.preference ?? "normal"] ||
    actionRank[a.action ?? "process"] - actionRank[b.action ?? "process"] ||
    (a.outputQuantity ?? 1) - (b.outputQuantity ?? 1) ||
    (a.ingredients?.length ?? 0) - (b.ingredients?.length ?? 0) ||
    (a.name ?? "").localeCompare(b.name ?? "") ||
    String(a.id ?? "").localeCompare(String(b.id ?? ""))
  );
}
export function defaultRouteIndex(options: RouteOption[]): number {
  let chosen = 0;
  for (let index = 1; index < options.length; index++) {
    if (compareRecipeRoutes(options[index], options[chosen]) < 0)
      chosen = index;
  }
  return chosen;
}
export function recipeBatches(quantity: number, output: number): number {
  const yieldQuantity = Number.isFinite(output) && output > 0 ? output : 1;
  return Math.max(1, Math.ceil(Math.max(1, quantity || 1) / yieldQuantity));
}
