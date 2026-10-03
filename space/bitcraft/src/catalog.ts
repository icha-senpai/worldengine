import bundled from "./content/catalog.json";
import guides from "./content/guides.json";

type Row = Record<string, any>;
const content = bundled as unknown as {
  generatedAt: string;
  items: Row[];
  details: Record<string, Row>;
  entries: Row[];
  skills: Row[];
};
const itemMap = new Map(
  content.items.map((item) => [`${item.kind}:${item.id}`, item]),
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
function recipe(row: Row, source: string, target: Row) {
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
  const raw = content.details[`${kind}:${id}`];
  if (!raw) return null;
  const target = item(raw[kind] ?? raw.item ?? raw.cargo);
  const craftingRecipes = (raw.craftingRecipes ?? []).map((row: Row) =>
    recipe(row, "crafting", target),
  );
  if (
    kind === "item" &&
    String(target.category).toLowerCase() === "bait" &&
    /\s+bait$/i.test(target.name)
  ) {
    const bundleName = target.name.replace(/\s+bait$/i, " Bait and Shells");
    const bundle = content.items.find(
      (row) =>
        row.name.toLowerCase() === bundleName.toLowerCase() &&
        String(row.category).toLowerCase() === "bait output",
    );
    const bundleData = bundle ? content.details[key(bundle)] : null;
    const expected =
      (bundleData?.itemListPossibilities ?? [])
        .filter(
          (row: Row) =>
            String(row.targetId) === String(target.id) && !row.isCargo,
        )
        .reduce(
          (sum: number, row: Row) =>
            sum + Number(row.quantity) * Number(row.chance ?? 1),
          0,
        ) || 2;
    for (const rawRecipe of bundleData?.craftingRecipes ?? []) {
      const normalized = recipe(rawRecipe, "bait-output", bundle!);
      if (
        !normalized.outputs.some((output: Row) => key(output) === key(bundle!))
      )
        continue;
      craftingRecipes.push({
        ...normalized,
        id: `${normalized.id}:${key(target)}:bait-output`,
        outputQuantity: expected,
        outputs: [{ ...target, quantity: expected }],
      });
    }
  }
  return {
    item: target,
    craftingRecipes,
    extractionRecipes: (raw.extractionRecipes ?? []).map((row: Row) =>
      recipe(row, "extraction", target),
    ),
    marketStats: raw.marketStats ?? {},
  };
}
const special = (row: Row) =>
  /ancient mortar|dried boot|hexite|repaired shipwreck steering wheel|salvaged pirate.?s? weapon|silken hexmoth|scroll|uncharted|winter snow|\b(unpack|packs?|packages?)\b/i.test(
    [row.name, ...row.ingredients.map((item: Row) => item.name)].join(" "),
  );
function tree(
  target: Row,
  depth: number,
  seen: Set<string>,
  budget: { remaining: number },
  root = false,
): Row[] {
  if (depth >= 16 || budget.remaining <= 0) return [];
  const data = detail(target.kind, String(target.id));
  if (!data) return [];
  let recipes = [...data.craftingRecipes, ...data.extractionRecipes].filter(
    (row) =>
      row.outputs.some((output: Row) => key(output) === key(target)) &&
      !/construction materials pack/i.test(
        [row.name, ...row.ingredients.map((item: Row) => item.name)].join(" "),
      ),
  );
  const ordinary = recipes.filter((row) => !special(row));
  if (!ordinary.length && !root) return [];
  recipes = recipes
    .filter(
      (row) =>
        !row.ingredients.some((input: Row) => key(input) === key(target)),
    )
    .sort(
      (a, b) =>
        Number(special(a)) - Number(special(b)) ||
        a.outputQuantity - b.outputQuantity ||
        a.ingredients.length - b.ingredients.length ||
        a.name.localeCompare(b.name),
    );
  const nodes = recipes
    .map((row) => {
      if (budget.remaining-- <= 0) return null;
      return {
        ...row,
        ingredients: row.ingredients.map((ingredient: Row) => {
          const targetKey = key(ingredient);
          if (seen.has(targetKey))
            return { ...ingredient, recipes: [], cycle: true };
          const nextSeen = new Set(seen);
          nextSeen.add(targetKey);
          if (depth >= 2 || budget.remaining <= 0)
            return {
              ...ingredient,
              recipes: [],
              recipesDeferred: hasRecipes(ingredient),
            };
          return {
            ...ingredient,
            recipes: tree(ingredient, depth + 1, nextSeen, budget),
          };
        }),
      };
    })
    .filter(Boolean) as Row[];
  if (!nodes.length) return [];
  return [
    { ...nodes[0], ...(nodes.length > 1 ? { alternatives: nodes } : {}) },
  ];
}
function hasRecipes(item: Row) {
  const data = detail(item.kind, String(item.id));
  return Boolean(
    data &&
    [...data.craftingRecipes, ...data.extractionRecipes].some(
      (row) =>
        row.outputs.some((output: Row) => key(output) === key(item)) &&
        !/construction materials pack/i.test(row.name),
    ),
  );
}
const targets = content.items.filter(hasRecipes);
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
        const normalized = recipe(row, "crafting", target);
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
  if (kind === "items" || kind === "targets")
    return (kind === "targets" ? targets : content.items)
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
      recipeTree: tree(data.item, 0, new Set([id]), { remaining: 250 }, true),
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
        const normalized = recipe(row, "crafting", target);
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
