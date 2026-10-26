import { createHash } from "node:crypto";
import { readFileSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";

export const snapshotTables = [
  "item_desc",
  "cargo_desc",
  "crafting_recipe_desc",
  "extraction_recipe_desc",
  "resource_desc",
  "skill_desc",
  "tool_type_desc",
  "building_type_desc",
];

// Import the readable cereal/cs public definitions directly, without Laravel or
// access to player databases. Keep the established UI catalogue contract.
export function buildSnapshot(tables, source) {
  for (const name of snapshotTables) {
    if (!Array.isArray(tables[name]) || !tables[name].length)
      throw Error(`Missing table ${name}`);
  }
  if (!source?.commit || !Number.isFinite(Date.parse(source.generatedAt)))
    throw Error("Source commit and release timestamp are required");
  const index = (name) => new Map(tables[name].map((row) => [row.id, row]));
  const skills = index("skill_desc"),
    tools = index("tool_type_desc"),
    buildings = index("building_type_desc");
  const items = ["item", "cargo"]
    .flatMap((kind) =>
      tables[`${kind}_desc`].map((row) => ({
        id: row.id,
        kind,
        name: row.name,
        category: row.tag ?? (kind === "cargo" ? "Cargo" : null),
        tag: row.tag ?? null,
        tier: row.tier,
        rarity: row.rarity,
        description: row.description,
        iconAssetName: row.icon_asset_name,
      })),
    )
    .sort(
      (a, b) =>
        a.name.localeCompare(b.name) ||
        a.kind.localeCompare(b.kind) ||
        a.id - b.id,
    );
  const metadata = new Map(items.map((row) => [`${row.kind}:${row.id}`, row]));
  const stack = (raw) => {
    const kind = String(raw.item_type).toLowerCase();
    if (
      !["item", "cargo"].includes(kind) ||
      !Number.isFinite(raw.quantity) ||
      raw.quantity < 0
    )
      throw Error("Invalid item stack");
    const item = metadata.get(`${kind}:${raw.item_id}`);
    return {
      ...item,
      id: raw.item_id,
      itemId: raw.item_id,
      itemType: kind,
      kind,
      name: item?.name ?? "Unknown",
      itemName: item?.name ?? "Unknown",
      quantity: raw.quantity,
    };
  };
  const details = Object.fromEntries(
    items.map((item) => [
      `${item.kind}:${item.id}`,
      {
        [item.kind]: item,
        craftingRecipes: [],
        extractionRecipes: [],
        marketStats: [],
      },
    ]),
  );
  const resourceRows = index("resource_desc");
  const resource = (id) => {
    const row = resourceRows.get(id);
    return row
      ? {
          id: row.id,
          name: row.name,
          category: row.tag,
          tier: row.tier,
          rarity: row.rarity,
          maxHealth: row.max_health,
          ignoreDamage: row.ignore_damage,
          showTimeLeft: row.show_time_left,
          onDestroyYieldResourceId: row.on_destroy_yield_resource_id,
          iconAssetName: row.icon_asset_name,
        }
      : null;
  };
  const add = (recipe, field) => {
    for (const output of recipe.craftedItems) {
      const detail = details[`${output.kind}:${output.id}`];
      if (detail && !detail[field].some((row) => row.id === recipe.id))
        detail[field].push(recipe);
    }
  };
  for (const row of tables.crafting_recipe_desc) {
    const outputs = row.crafted_item_stacks.map(stack);
    const inputs = row.consumed_item_stacks.map(stack);
    add(
      {
        id: row.id,
        recipeName: row.name,
        craftingStation:
          buildings.get(row.building_requirement?.building_type)?.name ?? null,
        skillName:
          skills.get(row.level_requirements[0]?.skill_id)?.name ?? null,
        timeRequirement: row.time_requirement,
        outputQuantity: outputs[0]?.quantity ?? 1,
        craftedItems: outputs,
        consumedItems: inputs,
        consumedItemStacks: inputs.map((s) => ({
          item_id: s.id,
          item_type: s.kind,
          quantity: s.quantity,
        })),
      },
      "craftingRecipes",
    );
  }
  const entries = [];
  for (const row of tables.extraction_recipe_desc) {
    const outputs = row.extracted_item_stacks.map((s) => ({
      ...stack(s.item_stack),
      probability: s.probability,
    }));
    const inputs = row.consumed_item_stacks.map((s) => ({
      ...stack(s),
      consumptionChance: s.consumption_chance ?? 1,
    }));
    // Some mining recipes operate on carried ore chunks rather than world
    // resources. Preserve their cargo identity without inventing target health.
    const cargo = metadata.get(`cargo:${row.cargo_id}`);
    const target =
      resource(row.resource_id) ??
      (cargo ? { ...cargo, maxHealth: null } : null);
    const requirement = row.level_requirements[0];
    const skillId =
      requirement?.skill_id ?? row.experience_per_progress[0]?.skill_id;
    const tool = row.tool_requirements[0];
    // Direct recipes describe action output; the typed graph resolves bundles
    // and resource destruction separately from this display catalogue.
    for (const output of outputs) {
      const detail = details[`${output.kind}:${output.id}`];
      if (!detail || detail.extractionRecipes.some((r) => r.id === row.id))
        continue;
      detail.extractionRecipes.push({
        id: row.id,
        recipeName: `${row.verb_phrase} ${output.name}`,
        craftingStation: null,
        skillName: skills.get(skillId)?.name ?? null,
        timeRequirement: row.time_requirement,
        outputQuantity: output.quantity,
        craftedItems: outputs,
        consumedItems: inputs,
        consumedItemStacks: inputs.map((s) => ({
          item_id: s.id,
          item_type: s.kind,
          quantity: s.quantity,
        })),
      });
    }
    if (!tool) continue;
    entries.push({
      id: row.id,
      resourceId: row.resource_id,
      name: `${row.verb_phrase} ${target?.name ?? "Unknown resource"}`,
      verb: row.verb_phrase,
      timeRequirement: row.time_requirement,
      staminaRequirement: row.stamina_requirement,
      toolDurabilityLost: row.tool_durability_lost,
      range: row.range,
      allowUseHands: row.allow_use_hands,
      showInProgression: row.show_in_progression,
      levelRequirement: requirement?.level ?? 0,
      skill: { id: skillId, name: skills.get(skillId)?.name ?? null },
      tool: {
        id: tool.tool_type,
        name: tools.get(tool.tool_type)?.name ?? null,
        level: tool.level,
        power: tool.power,
      },
      experiencePerProgress: {
        skill_id: row.experience_per_progress[0]?.skill_id,
        skillName:
          skills.get(row.experience_per_progress[0]?.skill_id)?.name ?? null,
        quantity: row.experience_per_progress[0]?.quantity ?? 0,
      },
      resource: target,
      spawnedResource: resource(target?.onDestroyYieldResourceId),
      outputs,
      consumedItems: inputs,
    });
  }
  return {
    generatedAt: source.generatedAt,
    source,
    items,
    details,
    skills: tables.skill_desc
      .filter((s) => s.id > 1)
      .map((s) => ({ id: s.id, name: s.name, title: s.title })),
    entries,
  };
}

if (
  process.argv[1] &&
  import.meta.url === pathToFileURL(resolve(process.argv[1])).href
) {
  const folder = process.argv[2];
  if (!folder)
    throw Error(
      "Usage: node scripts/import-bitcraft-snapshot.mjs <static directory> [--check]",
    );
  const source = JSON.parse(
    readFileSync(resolve(folder, "source.json"), "utf8"),
  );
  const tables = {},
    hashes = {};
  for (const name of snapshotTables) {
    const raw = readFileSync(resolve(folder, `${name}.json`), "utf8");
    hashes[name] = createHash("sha256").update(raw).digest("hex");
    tables[name] = JSON.parse(raw);
  }
  const result = buildSnapshot(tables, { ...source, hashes });
  const output = "bitcraft/src/content/catalog.json",
    serialized = JSON.stringify(result) + "\n";
  if (process.argv.includes("--check")) {
    if (readFileSync(output, "utf8").replace(/\r\n/g, "\n") !== serialized)
      throw Error("Snapshot is stale");
  } else writeFileSync(output, serialized);
  console.log(
    `${result.generatedAt}: ${result.items.length} items/cargo; ${result.entries.length} gathering entries`,
  );
}
