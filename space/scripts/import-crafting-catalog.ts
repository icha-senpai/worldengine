import { createHash } from "node:crypto";
import { readFileSync, writeFileSync } from "node:fs";
import { resolve, basename } from "node:path";
import { pathToFileURL } from "node:url";
import type {
  CraftingCatalog,
  ItemKey,
  RecipeDefinition,
  RecipeStack,
} from "../bitcraft/src/crafting/model";
import { compareRecipeRoutes } from "../bitcraft/src/crafting/model";

interface StackInput {
  id?: number;
  itemId?: number;
  item_id?: number;
  kind?: string;
  itemType?: string;
  item_type?: string;
  quantity?: number;
}
interface RecipeInput {
  id: number;
  recipeName?: string;
  name?: string;
  craftingStation?: string;
  buildingName?: string;
  skillName?: string;
  levelRequirements?: { skill?: { name?: string } }[];
  timeRequirement?: number;
  craftedItems?: StackInput[];
  outputs?: StackInput[];
  consumedItems?: StackInput[];
}
interface CatalogItem {
  id: number;
  kind: "item" | "cargo";
  name: string;
  category?: string;
  tier?: number;
}
interface ExportInput {
  items: CatalogItem[];
  details: Record<
    string,
    { craftingRecipes?: RecipeInput[]; extractionRecipes?: RecipeInput[] }
  >;
  entries: {
    id: number;
    name: string;
    resourceId: number;
    verb: string;
    skill?: { name: string };
  }[];
}
interface StaticItem {
  id: number;
  item_list_id: number;
}
interface ItemList {
  id: number;
  possibilities: { probability: number; items: StackInput[] }[];
}
interface Resource {
  id: number;
  name: string;
  tag: string;
  tier: number;
  on_destroy_yield: StackInput[];
}
interface Extraction {
  id: number;
  resource_id: number;
  verb_phrase: string;
  level_requirements: { skill_id: number }[];
}
export interface ImportInputs {
  catalog: ExportInput;
  additions: { items: CatalogItem[] };
  items: StaticItem[];
  lists: ItemList[];
  resources: Resource[];
  extractions: Extraction[];
  skills: { id: number; name: string }[];
}
const stackKey = (row: StackInput): ItemKey => {
  const kind = (
    row.kind ??
    row.itemType ??
    row.item_type ??
    "item"
  ).toLowerCase();
  const id = row.id ?? row.itemId ?? row.item_id;
  if (
    !["item", "cargo"].includes(kind) ||
    typeof id !== "number" ||
    !Number.isSafeInteger(id)
  )
    throw Error("Invalid item reference");
  return `${kind as "item" | "cargo"}:${id}`;
};
const round = (quantity: number) => Math.round(quantity * 1e6) / 1e6;

export function importCraftingCatalog(input: ImportInputs): CraftingCatalog {
  const items = new Map<ItemKey, CatalogItem>();
  for (const row of [...input.additions.items, ...input.catalog.items])
    items.set(`${row.kind}:${row.id}`, row);
  const recipes: CraftingCatalog["recipes"] = {};
  const omissions: CraftingCatalog["omissions"] = [];
  const add = (row: RecipeDefinition) => {
    const missingItems = [
      ...new Set(
        [row.targetKey, ...row.ingredients.map((s) => s.key)].filter(
          (key) => !items.has(key),
        ),
      ),
    ];
    if (missingItems.length) {
      omissions.push({ recipeId: row.id, missingItems });
      return;
    }
    if (
      !Number.isFinite(row.outputQuantity) ||
      row.outputQuantity <= 0 ||
      row.ingredients.some(
        (s) => !Number.isFinite(s.quantity) || s.quantity <= 0,
      )
    )
      throw Error(`Invalid quantities in ${row.id}`);
    const group = (recipes[row.targetKey] ??= []);
    if (!group.some((other) => other.id === row.id)) group.push(row);
  };
  const toStacks = (stacks: StackInput[] = []): RecipeStack[] =>
    stacks.map((s) => ({
      key: stackKey(s),
      quantity: Number(s.quantity ?? 1),
    }));
  // Source names are interpreted once at import. Runtime policy uses enums/IDs.
  const classify = (
    name: string,
    source: RecipeDefinition["source"],
    ingredients: RecipeStack[],
    target: CatalogItem,
  ) => {
    const action =
      source !== "crafting"
        ? ingredients.length
          ? "process"
          : "gather"
        : /^unpack\b/i.test(name)
          ? "unpack"
          : /^recraft\b/i.test(name)
            ? "recraft"
            : /^craft\b/i.test(name)
              ? "craft"
              : "process";
    const usesAncientMortar = ingredients.some(
      (s) => items.get(s.key)?.name === "Ancient Mortar",
    );
    const special =
      usesAncientMortar ||
      action === "unpack" ||
      /^pack\b/i.test(name) ||
      ["Package", "Dungeon Item List", "Sift"].includes(
        target.category ?? "",
      ) ||
      /ancient .*rubble/i.test(name) ||
      ingredients.some(
        (s) =>
          items.get(s.key)?.category === "Sift" ||
          /dried boot|hexite|repaired shipwreck steering wheel|salvaged pirate.?s? weapon|silken hexmoth|scroll|uncharted|winter snow/i.test(
            items.get(s.key)?.name ?? "",
          ),
      );
    const plannable = ![
      name,
      ...ingredients.map((s) => items.get(s.key)?.name ?? ""),
    ].some((value) => /construction materials pack/i.test(value));
    return {
      action,
      preference: special ? "secondary" : "normal",
      usesAncientMortar,
      plannable,
    } as const;
  };
  for (const [targetKey, detail] of Object.entries(input.catalog.details)) {
    const target = items.get(targetKey as ItemKey);
    if (!target) continue;
    for (const source of ["crafting", "extraction"] as const) {
      for (const raw of detail[`${source}Recipes`] ?? []) {
        const outputs = toStacks(raw.craftedItems ?? raw.outputs);
        const outputQuantity = outputs.find(
          (s) => s.key === targetKey,
        )?.quantity;
        if (!outputQuantity) continue;
        const ingredients = toStacks(raw.consumedItems);
        const name = String(raw.name ?? raw.recipeName ?? target.name).replace(
          /\{(\d+)\}/g,
          (_, index: string) =>
            Number(index) === 0
              ? target.name
              : (items.get(ingredients[Number(index) - 1]?.key)?.name ?? ""),
        );
        add({
          id: `${source}:${raw.id}:${targetKey}`,
          targetKey: targetKey as ItemKey,
          source,
          name,
          ...classify(name, source, ingredients, target),
          station: raw.buildingName ?? raw.craftingStation ?? null,
          skill:
            raw.skillName ?? raw.levelRequirements?.[0]?.skill?.name ?? null,
          duration: raw.timeRequirement ?? null,
          outputQuantity,
          averageOutput: false,
          ingredients,
          outputs,
          legacyIds: [String(raw.id)],
        });
      }
    }
  }
  const direct = new Map(
    Object.entries(recipes).map(([key, rows]) => [key, [...rows]]),
  );
  const lists = new Map(input.lists.map((list) => [list.id, list]));
  // Resolve all item-list bundles through the same path, including logs, bait,
  // clay, fish products and other yields omitted by the old export.
  for (const bundle of input.items) {
    const possibilities = lists.get(bundle.item_list_id)?.possibilities;
    const sources = direct.get(`item:${bundle.id}`);
    if (!possibilities?.length || !sources?.length) continue;
    if (
      possibilities.some(
        (p) => !Number.isFinite(p.probability) || p.probability < 0,
      )
    )
      throw Error(`Invalid probabilities in item list ${bundle.item_list_id}`);
    const targets = new Set(
      possibilities.flatMap((p) => p.items.map(stackKey)),
    );
    for (const targetKey of targets) {
      const target = items.get(targetKey);
      if (!target) continue;
      const quantities = possibilities.map((p) =>
        p.items
          .filter((s) => stackKey(s) === targetKey)
          .reduce((n, s) => n + Number(s.quantity ?? 1), 0),
      );
      const quantity = round(
        possibilities.reduce(
          (n, p, index) => n + p.probability * quantities[index],
          0,
        ),
      );
      if (quantity <= 0) continue;
      const averageOutput = new Set(quantities).size > 1;
      for (const source of sources) {
        const bundleName = items.get(`item:${bundle.id}`)!.name;
        const name =
          target.category === "Wood Log" && source.ingredients.length === 1
            ? `Split ${items.get(source.ingredients[0].key)!.name} into ${target.name}`
            : source.name.replace(bundleName, target.name);
        const id = source.id.split(":")[1];
        add({
          ...source,
          id: `${source.source}:${id}:${targetKey}:bundle:${bundle.id}`,
          targetKey,
          name,
          preference:
            target.category === "Wood Log" && source.preference === "normal"
              ? "primary"
              : source.preference,
          outputQuantity: round(source.outputQuantity * quantity),
          averageOutput,
          outputs: [
            {
              key: targetKey,
              quantity: round(source.outputQuantity * quantity),
            },
          ],
          legacyIds: [
            `${id}:${targetKey}:wood-log-output`,
            `${id}:${targetKey}:bait-output`,
            `${id}:${targetKey}:material-output`,
          ],
        });
      }
    }
  }
  const skills = new Map(input.skills.map((row) => [row.id, row.name]));
  const extractions = new Map(
    input.extractions.map((row) => [row.resource_id, row]),
  );
  const resourceTargets = new Set(
    [...items.keys()].filter(
      (key) =>
        items.get(key)?.category === "Trunk" ||
        !recipes[key]?.some((r) => r.preference !== "secondary"),
    ),
  );
  for (const resource of input.resources) {
    if (
      (resource.tier < 1 && resource.tag !== "Tree") ||
      /dead|fallen|stump|hexite|plant|felled/i.test(resource.name)
    )
      continue;
    const yields = new Map<ItemKey, number>();
    for (const stack of toStacks(resource.on_destroy_yield))
      yields.set(stack.key, (yields.get(stack.key) ?? 0) + stack.quantity);
    for (const [targetKey, quantity] of yields) {
      const target = items.get(targetKey);
      if (!target || !resourceTargets.has(targetKey)) continue;
      const extraction = extractions.get(resource.id);
      const normalTree = resource.tag === "Tree" && target.category === "Trunk";
      const verb =
        extraction?.verb_phrase ?? (normalTree ? "Chop" : "Gather from");
      add({
        id: `resource:${resource.id}:${targetKey}`,
        targetKey,
        source: "resource",
        name: `${verb} ${resource.name}`,
        action: "gather",
        preference: normalTree ? "primary" : "normal",
        station: null,
        skill: normalTree
          ? "Forestry"
          : (skills.get(extraction?.level_requirements[0]?.skill_id ?? 0) ??
            null),
        // Destruction rewards are per resource, not per extraction action.
        duration: null,
        outputQuantity: quantity,
        averageOutput: false,
        usesAncientMortar: false,
        plannable: true,
        ingredients: [],
        outputs: [{ key: targetKey, quantity }],
        legacyIds: [
          String(resource.id),
          ...input.extractions
            .filter((row) => row.resource_id === resource.id)
            .map((row) => String(row.id)),
        ],
      });
    }
  }
  for (const rows of Object.values(recipes)) rows.sort(compareRecipeRoutes);
  return {
    version: 1,
    sources: [],
    omissions: omissions.sort((a, b) => a.recipeId.localeCompare(b.recipeId)),
    recipes: Object.fromEntries(
      Object.entries(recipes).sort(([a], [b]) => a.localeCompare(b)),
    ) as CraftingCatalog["recipes"],
  };
}

async function main() {
  const folder = process.argv[2];
  if (!folder)
    throw Error(
      "Usage: npm run bitcraft:catalog -- <public static data directory> [--check]",
    );
  const sources: CraftingCatalog["sources"] = [];
  function read<T>(path: string): T {
    const text = readFileSync(path, "utf8")
      .replace(/^\uFEFF/, "")
      .replace(/\r\n/g, "\n");
    sources.push({
      file: basename(path),
      sha256: createHash("sha256").update(text).digest("hex"),
    });
    return JSON.parse(text) as T;
  }
  const result = importCraftingCatalog({
    catalog: read("bitcraft/src/content/catalog.json"),
    additions: read("bitcraft/src/content/catalog-additions.json"),
    items: read(resolve(folder, "item_desc.json")),
    lists: read(resolve(folder, "item_list_desc.json")),
    resources: read(resolve(folder, "resource_desc.json")),
    extractions: read(resolve(folder, "extraction_recipe_desc.json")),
    skills: read(resolve(folder, "skill_desc.json")),
  });
  result.sources = sources;
  const output = "bitcraft/src/content/crafting-catalog.json";
  const serialized = JSON.stringify(result) + "\n";
  if (process.argv.includes("--check")) {
    if (readFileSync(output, "utf8").replace(/\r\n/g, "\n") !== serialized)
      throw Error("Crafting catalog is stale; regenerate it.");
  } else writeFileSync(output, serialized);
  // A one-time compatibility map translates positions in v1 saved plans to IDs.
  // Subsequent imports leave this migration artifact unchanged.
  const legacyArg = process.argv.indexOf("--legacy-plans");
  if (legacyArg >= 0) {
    const groups = JSON.parse(
      readFileSync(process.argv[legacyArg + 1], "utf8"),
    ) as {
      key: string;
      targetKey: ItemKey;
      routes: { id: string; source: string }[];
    }[];
    const migrated: Record<
      string,
      { targetKey: ItemKey; routeIds: (string | null)[] }
    > = {};
    for (const group of groups) {
      migrated[group.key] = {
        targetKey: group.targetKey,
        routeIds: group.routes.map(
          (old) =>
            result.recipes[group.targetKey]?.find(
              (r) =>
                r.legacyIds.includes(old.id) &&
                (r.source === old.source ||
                  old.source.endsWith("-output") ||
                  old.source === "tree-trunk"),
            )?.id ?? null,
        ),
      };
    }
    writeFileSync(
      "src/bitcraft-ui/craftingLegacyRoutes.json",
      JSON.stringify(migrated) + "\n",
    );
  }
  console.log(
    `${Object.keys(result.recipes).length} targets; ${Object.values(result.recipes).reduce((n, rows) => n + rows.length, 0)} typed crafting routes; ${process.argv.includes("--check") ? "verified" : "generated"}`,
  );
  if (result.omissions.length)
    console.log(
      `${result.omissions.length} recipes with missing item definitions recorded in catalog.omissions`,
    );
}
if (
  process.argv[1] &&
  import.meta.url === pathToFileURL(resolve(process.argv[1])).href
)
  await main();
