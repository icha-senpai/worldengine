import bundled from "./content/catalog.json";
import additions from "./content/catalog-additions.json";
import guides from "./content/guides.json";
import craftingData from "./content/crafting-catalog.json";
import type { CraftingCatalog, ItemKey } from "./crafting/model";
import { createCraftingCatalog, type CraftingItem } from "./crafting/catalog";

type Row = Record<string, any>;
const content = bundled as unknown as {
  generatedAt: string;
  items: Row[];
  details: Record<string, Row>;
  entries: Row[];
  skills: Row[];
};
// Fill verified gaps without overriding definitions in a refreshed full export.
const itemMap = new Map<string, Row>(
  content.items.map((item) => [`${item.kind}:${item.id}`, item]),
);
for (const item of additions.items) {
  const key = `${item.kind}:${item.id}`;
  if (!itemMap.has(key)) itemMap.set(key, item);
}
const catalogItems = [...itemMap.values()];
const crafting = createCraftingCatalog(
  craftingData as unknown as CraftingCatalog,
  (key) => itemMap.get(key) as CraftingItem | undefined,
);
const key = (row: Row) =>
  `${row.kind ?? row.itemType ?? row.item_type ?? "item"}:${row.id ?? row.itemId ?? row.item_id}`;
function item(row: Row): Row {
  const known = itemMap.get(key(row));
  return {
    ...known,
    ...row,
    id: row.id ?? row.itemId ?? row.item_id,
    kind: row.kind ?? row.itemType ?? row.item_type ?? "item",
    name: row.name ?? row.itemName ?? known?.name ?? "Unknown item",
    quantity: Number(row.quantity ?? 1),
  };
}
// Activity cards retain their original upstream IDs and metadata. Planning uses
// the typed generated graph below.
function activityCardRecipe(row: Row, source: string, target: Row) {
  const outputs = (
    row.craftedItems ??
    row.outputs ??
    row.extractedItems ??
    []
  ).map(item);
  const ingredients = (
    row.consumedItems ??
    row.ingredients ??
    row.consumedItemStacks ??
    []
  ).map(item);
  const name = String(row.name ?? row.recipeName ?? target.name).replace(
    /\{(\d+)\}/g,
    (_match, index) =>
      Number(index) === 0
        ? target.name
        : (ingredients[Number(index) - 1]?.name ?? ""),
  );
  return {
    id: row.id ?? row.recipeId,
    source,
    name,
    station: row.buildingName ?? row.craftingStation ?? row.stationName ?? null,
    skill:
      row.skillName ??
      row.levelRequirements?.[0]?.skill?.name ??
      row.skill ??
      null,
    duration: row.timeRequirement ?? row.duration ?? null,
    outputQuantity:
      outputs.find((output: Row) => key(output) === key(target))?.quantity ??
      row.outputQuantity ??
      1,
    outputs,
    ingredients,
  };
}
function detail(kind: string, id: string) {
  const result = crafting.detail(`${kind}:${id}` as ItemKey);
  return result
    ? {
        ...result,
        marketStats: content.details[`${kind}:${id}`]?.marketStats ?? {},
      }
    : null;
}
function hasRecipes(row: Row) {
  return crafting.hasRecipes(key(row) as ItemKey);
}
const targets = catalogItems.filter(hasRecipes);
export function catalogResponse(kind: string, id: string, q: string) {
  if (q.length > 120 || id.length > 80)
    throw new Error("Invalid catalog lookup.");
  const needle = q.trim().toLowerCase();
  if (kind === "item-info") return itemMap.get(id) ?? null;
  if (kind === "metadata")
    return { available: true, generatedAt: content.generatedAt };
  if (kind === "entries")
    return {
      entries: content.entries,
      snapshot: { available: true, generatedAt: content.generatedAt },
    };
  if (kind === "skills") return content.skills;
  if (kind === "guide-metadata")
    return guides.map(({ id, author, published_at, updated_at }) => ({
      id,
      author,
      published_at,
      updated_at,
    }));
  if (kind === "recipe-times") {
    if (!/^\d+(,\d+)*$/.test(id)) throw new Error("Choose valid recipe IDs.");
    const ids = new Set(id.split(",")),
      found: Record<string, number> = {};
    for (const raw of Object.values(content.details))
      for (const row of raw.craftingRecipes ?? [])
        if (ids.has(String(row.id)))
          found[row.id] = Number(row.timeRequirement ?? 0);
    return found;
  }
  if (kind === "card-item") {
    if (!/^(item|cargo):\d{1,24}$/.test(id))
      throw new Error("Choose a valid catalog item.");
    const raw = content.details[id];
    const recipes = raw?.craftingRecipes ?? [];
    if (
      !recipes.length ||
      (q && !recipes.some((row: Row) => String(row.id) === q))
    )
      return null;
    const target = item(raw.item ?? raw.cargo);
    return {
      item: target,
      recipes: recipes.map((row: Row) => {
        const normalized = activityCardRecipe(row, "crafting", target);
        return {
          ...row,
          recipeName: normalized.name,
          craftedItems: normalized.outputs,
          consumedItems: normalized.ingredients,
          outputQuantity: normalized.outputQuantity,
        };
      }),
      snapshot: { available: true, generatedAt: content.generatedAt },
    };
  }
  if (kind === "inventory-items")
    return catalogItems.map(({ description: _description, ...row }) => row);
  if (kind === "items" || kind === "targets")
    return (kind === "targets" ? targets : catalogItems)
      .filter(
        (row) =>
          !needle ||
          `${row.name} ${row.category ?? ""}`.toLowerCase().includes(needle),
      )
      .slice(0, 100);
  if (kind === "detail" || kind === "branch") {
    if (!/^(item|cargo):\d{1,24}$/.test(id))
      throw new Error("Choose a valid catalog item.");
    const [itemKind, itemId] = id.split(":");
    const data = detail(itemKind, itemId);
    if (!data) return null;
    return {
      ...data,
      recipeTree: crafting.tree(id as ItemKey),
    };
  }
  if (kind === "card") {
    const selected = Number(id);
    if (q === "gathering") {
      const entry = content.entries.find((row) => Number(row.id) === selected);
      return entry
        ? {
            entry,
            snapshot: { available: true, generatedAt: content.generatedAt },
          }
        : null;
    }
    for (const raw of Object.values(content.details)) {
      const row = raw.craftingRecipes?.find(
        (row: Row) => Number(row.id) === selected,
      );
      if (row) {
        const target = item(raw.item ?? raw.cargo);
        const normalized = activityCardRecipe(row, "crafting", target);
        return {
          activity: "crafting",
          target,
          recipes: [
            {
              ...row,
              ...normalized,
              consumedItems: normalized.ingredients,
              craftedItems: normalized.outputs,
            },
          ],
          recipe: row,
        };
      }
    }
    return null;
  }
  throw new Error("Unknown catalog lookup.");
}
