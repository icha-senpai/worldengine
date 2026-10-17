import type {
  CraftingCatalog,
  ItemKey,
  RecipeDefinition,
  RecipeStack,
} from "./model";
import { compareRecipeRoutes } from "./model";

export interface CraftingItem {
  id: number;
  kind: "item" | "cargo";
  name: string;
  quantity?: number;
  category?: string;
  tier?: number;
  rarity?: string;
  iconAssetName?: string;
}
export interface CraftingIngredient extends CraftingItem {
  quantity: number;
  recipes?: RecipeNode[];
  recipesDeferred?: boolean;
  cycle?: boolean;
}
export interface RecipeNode extends Omit<
  RecipeDefinition,
  "ingredients" | "outputs"
> {
  ingredients: CraftingIngredient[];
  outputs: CraftingIngredient[];
  alternatives?: RecipeNode[];
  selectedAlternativeIndex?: number;
  gathering: boolean;
  primaryRoute: boolean;
  secondaryRoute: boolean;
}

export function createCraftingCatalog(
  content: CraftingCatalog,
  lookup: (key: ItemKey) => CraftingItem | undefined,
) {
  const stack = (row: RecipeStack): CraftingIngredient => {
    const item = lookup(row.key);
    if (!item) throw Error(`Missing crafting item ${row.key}`);
    return { ...item, quantity: row.quantity };
  };
  const definitions = (key: ItemKey) => content.recipes[key] ?? [];
  const hasRecipes = (key: ItemKey) =>
    definitions(key).some(
      (row) =>
        row.plannable && !row.ingredients.some((input) => input.key === key),
    );
  const hydrate = (row: RecipeDefinition): RecipeNode => ({
    ...row,
    ingredients: row.ingredients.map(stack),
    outputs: row.outputs.map(stack),
    gathering: row.action === "gather",
    primaryRoute: row.preference === "primary",
    secondaryRoute: row.preference === "secondary",
  });
  function tree(
    targetKey: ItemKey,
    depth = 0,
    seen = new Set<ItemKey>([targetKey]),
    budget = { remaining: 250 },
    root = true,
  ): RecipeNode[] {
    if (depth >= 16 || budget.remaining <= 0) return [];
    const rows = definitions(targetKey).filter(
      (row) =>
        row.plannable &&
        !row.ingredients.some((input) => input.key === targetKey),
    );
    if (!root && !rows.some((row) => row.preference !== "secondary")) return [];
    const nodes: RecipeNode[] = [];
    for (const definition of [...rows].sort(compareRecipeRoutes)) {
      if (budget.remaining-- <= 0) break;
      const node = hydrate(definition);
      node.ingredients = definition.ingredients.map((input) => {
        const ingredient = stack(input);
        if (seen.has(input.key))
          return { ...ingredient, recipes: [], cycle: true };
        if (depth >= 2 || budget.remaining <= 0)
          return {
            ...ingredient,
            recipes: [],
            recipesDeferred: hasRecipes(input.key),
          };
        return {
          ...ingredient,
          recipes: tree(
            input.key,
            depth + 1,
            new Set([...seen, input.key]),
            budget,
            false,
          ),
        };
      });
      nodes.push(node);
    }
    return nodes.length
      ? [{ ...nodes[0], ...(nodes.length > 1 ? { alternatives: nodes } : {}) }]
      : [];
  }
  return {
    hasRecipes,
    tree,
    detail(key: ItemKey) {
      const item = lookup(key);
      if (!item) return null;
      const routes = definitions(key).map(hydrate);
      return {
        item,
        craftingRecipes: routes.filter((row) => row.source === "crafting"),
        extractionRecipes: routes.filter((row) => row.source !== "crafting"),
      };
    },
  };
}
