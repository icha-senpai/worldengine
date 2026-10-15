import type {
  AnonymousViewCtx,
  InferSchema,
  ReducerCtx,
} from "spacetimedb/server";
import db from "./schema";
import content from "./content/evergather.json";
import { referencedItems } from "./performance";

type Schema = InferSchema<typeof db>;
const sourceFields: Record<string, string[]> = {
  gathering_actions: ["loot"],
  skill_activities: ["loot"],
  crafting_recipes: ["outputs"],
  expeditions: ["rewards"],
  job_contracts: ["rewards"],
  shop_offers: ["item_key"],
};
const kinds = [...Object.keys(sourceFields), "tool_upgrades"];
const definitions = kinds.flatMap((kind) =>
  Object.entries((content as any)[kind]).map(([key, value]) => ({
    key: `${kind}:${key}`,
    value: value as any,
    references: referencedItems(value),
    outputs:
      sourceFields[kind]?.flatMap((field) =>
        field === "item_key"
          ? [String((value as any).item_key)]
          : [...referencedItems((value as any)[field])],
      ) ?? [],
  })),
);

export function inventoryDirectoryRows(ctx: AnonymousViewCtx<Schema>) {
  const items = new Map<string, any>();
  for (const row of ctx.db.catalog.kind.filter("item_metadata")) {
    const item = JSON.parse(row.payload);
    if (!items.has(item.item_key) || item.rarity === "common")
      items.set(item.item_key, item);
  }
  return [...items.values()].map((item) => ({
    key: `item_directory:${item.item_key}`,
    kind: "item_directory",
    label: item.item_name,
    skill: "",
    requiredLevel: item.item_tier,
    payload: JSON.stringify({
      item_key: item.item_key,
      item_name: item.item_name,
      item_class: item.item_class,
      material_family: item.material_family,
      item_tier: item.item_tier,
    }),
  }));
}

export function inventoryDetailRows(ctx: ReducerCtx<Schema>, input: string) {
  if (input.length > 4000) throw Error("Too many inventory details requested.");
  const roots = JSON.parse(input);
  if (
    !Array.isArray(roots) ||
    roots.length > 32 ||
    roots.some(
      (key) => typeof key !== "string" || !/^[a-zA-Z0-9_-]{1,100}$/.test(key),
    )
  )
    throw Error("Choose up to 32 valid items.");
  const selected = new Set<string>(),
    items = new Set<string>(roots),
    pending = [...items],
    visited = new Set<string>();
  // Include all sources and uses of the selected items. Follow source inputs
  // recursively so gathering/processing/crafting routes stay actionable.
  for (const entry of definitions)
    if (roots.some((key) => entry.references.has(key))) {
      selected.add(entry.key);
      for (const key of entry.references) {
        items.add(key);
        pending.push(key);
      }
    }
  while (pending.length) {
    const item = pending.pop()!;
    if (visited.has(item)) continue;
    visited.add(item);
    for (const entry of definitions)
      if (entry.outputs.includes(item)) {
        selected.add(entry.key);
        for (const key of entry.references) items.add(key);
        for (const key of referencedItems([
          entry.value.ingredients,
          entry.value.supplies,
          entry.value.requirements,
        ]))
          if (!visited.has(key)) pending.push(key);
      }
  }
  const rows = [];
  for (const key of selected) {
    const row = ctx.db.catalog.key.find(key);
    if (row) rows.push(row);
  }
  for (const key of items)
    for (const rarity of [
      "common",
      "uncommon",
      "rare",
      "epic",
      "legendary",
      "mythic",
    ]) {
      const row = ctx.db.catalog.key.find(`item_metadata:${key}:${rarity}`);
      if (row) rows.push(row);
    }
  return rows;
}
