import fs from "node:fs";

// The approved proposal is the source of item identities and assembly recipes.
const root = new URL("../", import.meta.url);
const destination = new URL("spacetimedb/src/content/evergather.json", root);
const old = JSON.parse(fs.readFileSync(destination, "utf8"));
const plan = JSON.parse(
  fs.readFileSync(
    new URL("docs/evergather-economy-proposal.json", root),
    "utf8",
  ),
);
const items = new Map(plan.catalog.map((item) => [item.key, item]));
const c = Object.fromEntries(
  [
    "skills",
    "tool_families",
    "tool_tiers",
    "gathering_actions",
    "skill_activities",
    "crafting_recipes",
    "job_contracts",
    "expeditions",
    "shop_offers",
    "world_events",
    "starter_tools",
    "tool_upgrades",
    "item_metadata",
    "achievements",
    "consumables",
    "rarity_materials_by_tier",
  ].map((key) => [key, {}]),
);
Object.assign(c, {
  economy_version: "ten-tier-v1",
  character_options: old.character_options,
  rarities: ["common", "uncommon", "rare", "epic", "legendary", "mythic"],
  rarity_rules: old.rarity_rules,
  rarity_bonuses: old.rarity_bonuses,
  rarity_effects: old.rarity_effects,
  rarity_materials: {},
});
c.tiers = plan.tiers.map((tier) => ({
  ...tier,
  item_tier: tier.tier,
  mark: tier.region,
  key_slug: `tier_${tier.tier}`,
  rarity: "common",
  station: `${tier.region} Workshop`,
}));
// Keep the event board, with bonuses tied to the new field-resource lines.
for (const [
  key,
  label,
  status,
  category,
  region,
  skills,
  experience,
  yieldBonus,
  gold,
  reward,
] of [
  [
    "meteorfall",
    "Meteorfall",
    "active",
    "Gathering Surge",
    "Ironvale",
    ["mining"],
    2,
    1,
    0,
    "Extra ore from mining targets.",
  ],
  [
    "wardens_muster",
    "Warden's Muster",
    "active",
    "Combat Call",
    "Stonewall",
    ["combat", "defense", "healing"],
    3,
    0,
    1,
    "Extra field experience and gold from skirmishes, guard duties, and treatment.",
  ],
  [
    "deep_tide",
    "Deep Tide",
    "active",
    "Coastal Surge",
    "Greenbank",
    ["fishing", "sailing"],
    3,
    1,
    1,
    "Extra fish and marine salt from coastal work.",
  ],
  [
    "greenmarch",
    "Greenmarch",
    "upcoming",
    "Wild Growth",
    "Cobalt Reach",
    ["foraging", "farming"],
    2,
    2,
    0,
    "A seasonal boost for herbs and fibers.",
  ],
  [
    "vault_lanterns",
    "Vault Lanterns",
    "upcoming",
    "Delver Window",
    "Moonhaven",
    ["dungeoneering", "exploration", "magic"],
    4,
    1,
    1,
    "Extra relic cores, woodland resin, and arcane dust.",
  ],
  [
    "craftsmen_convocation",
    "Workshop Supply Fair",
    "upcoming",
    "Workshop Supply",
    "Sunspire",
    ["woodcutting", "excavation", "farming"],
    3,
    1,
    1,
    "Stock workshop materials with extra logs, rough gems, and fibers.",
  ],
  [
    "briarwake_hunt",
    "Briarwake Hunt",
    "upcoming",
    "Hunting Season",
    "Dragonfall",
    ["hunting", "slayer", "ranged"],
    3,
    2,
    1,
    "Extra hides, scales, monster essences, and flight feathers.",
  ],
])
  c.world_events[key] = {
    label,
    status,
    category,
    region,
    skills,
    description: reward,
    reward,
    bonus: { experience, yield: yieldBonus, gold },
  };
function item(key, quantity = 1) {
  const value = items.get(key);
  if (!value) throw new Error(`Unknown item ${key}`);
  return {
    item_key: key,
    item_name: value.name,
    quantity,
    rarity: "common",
    item_tier: value.tier,
  };
}
const list = (values) =>
  values.map((value) => item(value.item, value.quantity));
const rawForSkill = {
  fishing: "fishing",
  mining: "mining",
  woodcutting: "woodcutting",
  foraging: "foraging",
  hunting: "hunting",
  farming: "farming",
  excavation: "excavation",
  smelting: "mining",
  milling: "woodcutting",
  tanning: "hunting",
  cutting: "excavation",
  weaving: "farming",
  smithing: "mining",
  carpentry: "woodcutting",
  cooking: "fishing",
  alchemy: "foraging",
  tailoring: "farming",
  leatherworking: "hunting",
  engineering: "mining",
  enchanting: "excavation",
  jewelcrafting: "excavation",
  boatbuilding: "woodcutting",
  furniture: "woodcutting",
  construction: "woodcutting",
  combat: "mining",
  slayer: "hunting",
  defense: "mining",
  healing: "farming",
  magic: "excavation",
  ranged: "woodcutting",
  exploration: "woodcutting",
  dungeoneering: "excavation",
  sailing: "fishing",
  survival: "woodcutting",
  cartography: "farming",
  reputation: "farming",
  leadership: "farming",
  trading: "woodcutting",
};
for (const line of plan.lines) {
  c.skills[line.skill] = {
    ...old.skills[line.skill],
    role: line.noun,
    description: line.purpose,
    unlocks: Object.fromEntries(
      plan.tiers.map((tier) => [
        tier.level,
        `${tier.region}: ${line.names[tier.tier - 1]}`,
      ]),
    ),
  };
  const raw = item(`${rawForSkill[line.skill]}_1`);
  c.skill_activities[`training_${line.skill}`] = {
    label: `Learn ${line.label}`,
    skill: line.skill,
    category: line.category,
    activity_type: "Starter Training",
    required_level: 1,
    max_level: 9,
    experience: 55,
    gold: 3,
    description: `Practice to level 10 and collect ${raw.item_name}. Production and improved equipment unlock at level 10.`,
    loot: [{ ...raw, chance: 100 }],
    supplies: [],
    cooldown_seconds: 0,
  };
}
for (const action of plan.activities) {
  const line = plan.lines.find((line) => line.skill === action.skill);
  const common = {
    label: action.label,
    skill: action.skill,
    category: line.category,
    required_level: action.level,
    item_tier: action.tier,
    location: plan.tiers[action.tier - 1].region,
    experience: 35 + action.tier * 12,
    gold: 3 + action.tier * 2,
    description: line.purpose,
    cooldown_seconds: 0,
  };
  if (line.mode === "commission")
    c.job_contracts[action.key] = {
      ...common,
      demand_channel: "profession_turn_in",
      archetype: "Guild Commission",
      objective_type: "delivery",
      requirements: list(action.inputs),
      rewards: list(action.outputs),
      rotation: "unlimited",
      completion_cap: null,
    };
  else
    c[line.mode === "gather" ? "gathering_actions" : "skill_activities"][
      action.key
    ] = {
      ...common,
      activity_type: line.mode === "gather" ? "Gathering" : "Field Activity",
      supplies: list(action.inputs),
      loot: action.outputs.map((value) => ({
        ...item(value.item, value.quantity),
        min: value.quantity,
        max: value.quantity + 1,
        quantity: undefined,
        chance: 100,
      })),
    };
}
for (const recipe of plan.recipes) {
  const line = plan.lines.find((line) => line.skill === recipe.skill);
  const bonus = {
    experience: 2 + recipe.tier * 3,
    yield: Math.floor((recipe.tier + 1) / 3),
  };
  const outputs = list(recipe.outputs).map((value) =>
    recipe.equipmentSkill
      ? { ...value, equipment_skill: recipe.equipmentSkill, bonuses: bonus }
      : value,
  );
  const definition = {
    label: recipe.label,
    skill: recipe.skill,
    category: line.category,
    required_level: recipe.level,
    craft_tier: recipe.tier,
    experience: 40 + recipe.tier * 15,
    gold_cost: recipe.equipmentSkill ? recipe.tier * 35 : 0,
    ingredients: list(recipe.inputs),
    outputs,
  };
  c.crafting_recipes[recipe.key] = definition;
  if (recipe.equipmentSkill)
    c.tool_upgrades[`${recipe.equipmentSkill}:${recipe.level}`] = {
      ...definition,
      equipment_skill: recipe.equipmentSkill,
      output: outputs[0],
      bonuses: bonus,
    };
}
for (const gear of plan.equipment) {
  c.tool_families[gear.skill] = {
    label: c.skills[gear.skill].label,
    noun: gear.noun,
    slot: `tool_${gear.skill}`,
    skill: gear.skill,
    craft: gear.crafter,
    starter_item_key: `starter_${gear.skill}`,
    starter_item_name: items.get(`starter_${gear.skill}`).name,
  };
  c.starter_tools[gear.skill] = {
    ...item(`starter_${gear.skill}`),
    skill: gear.skill,
    slot_label: `${c.skills[gear.skill].label} Tool`,
    durability: 100,
    bonuses: { experience: 0, yield: 0 },
  };
}
for (const tier of plan.tiers) {
  const t = tier.tier;
  c.tool_tiers[`tier_${t}`] = {
    level: tier.level,
    item_tier: t,
    name_mark: tier.region,
    rarity: "common",
    gold_cost: t * 35,
  };
  c.rarity_materials_by_tier[t] = [
    "alchemy",
    "enchanting",
    "dungeoneering",
    "reputation",
  ].map((skill) => item(`${skill}_${t}`));
  for (const [suffix, skill, requirements] of [
    ["delivery", "trading", [item(`trading_${t}`)]],
    ["medical", "healing", [item(`bandage_${t}`, 3)]],
    ["coastal", "sailing", [item(`boatbuilding_${t}`), item(`cooking_${t}`)]],
    ["survey", "cartography", [item(`cartography_${t}`, 2)]],
  ]) {
    c.job_contracts[`${suffix}_${t}`] = {
      label: `${tier.region} ${suffix[0].toUpperCase() + suffix.slice(1)} Delivery`,
      skill,
      category: c.skills[skill].category,
      required_level: tier.level,
      location: tier.region,
      demand_channel: "profession_turn_in",
      objective_type: "delivery",
      archetype: "Delivery",
      requirements,
      rewards: [],
      experience: 55 + t * 20,
      gold: 5 + t * 5,
      rotation: "unlimited",
      completion_cap: null,
    };
  }
  c.expeditions[`expedition_${t}`] = {
    label: `Survey ${tier.region}`,
    skill: "exploration",
    region: tier.region,
    required_level: tier.level,
    item_tier: t,
    experience: 80 + t * 25,
    gold: 5 + t * 5,
    supplies: [item(`leadership_${t}`), item(`cooking_${t}`, 2)],
    rewards: [
      item(`excavation_${t}`, 2),
      item(`hunting_${t}`, 2),
      item(`foraging_${t}`, 2),
    ],
  };
  for (const [key, family, effect, skill] of [
    [`cooking_${t}`, "food", "experience", ""],
    [`tonic_${t}`, "tonic", "yield", ""],
    [`arrows_${t}`, "arrows", "experience", "ranged"],
  ]) {
    c.consumables[key] = {
      item_key: key,
      item_name: items.get(key).name,
      family,
      effect,
      skill,
      required_level: tier.level,
      charges: 10,
      percent: family === "arrows" ? 15 + t * 3 : 5 + t * 2,
      description:
        family === "food"
          ? "Bonus XP on the next ten successful actions, recipes, deliveries, or expeditions."
          : family === "tonic"
            ? "Chance to collect one extra resource per loot roll on the next ten gathering or field actions."
            : "Bonus Ranged XP on the next ten successful Ranged actions.",
    };
  }
}
// One stable identity per material; tool qualities share the same identity.
const vendorValues = new Map(
  plan.catalog.map((value) => [value.key, Math.max(1, value.tier)]),
);
for (let pass = 0; pass < 20; pass++)
  for (const recipe of plan.recipes) {
    const cost = recipe.inputs.reduce(
      (sum, input) => sum + vendorValues.get(input.item) * input.quantity,
      0,
    );
    for (const output of recipe.outputs)
      vendorValues.set(
        output.item,
        Math.max(1, Math.floor((cost * 0.55) / output.quantity)),
      );
  }
for (const value of plan.catalog)
  for (const rarity of value.kind === "Equipment" ? c.rarities : ["common"]) {
    const starter = value.tier === 0;
    const base = vendorValues.get(value.key);
    const quality = 1 + c.rarities.indexOf(rarity) * 0.5;
    const vendor = starter ? 0 : Math.max(1, Math.floor(base * quality));
    c.item_metadata[`${value.key}:${rarity}`] = {
      item_key: value.key,
      item_name: value.name,
      rarity,
      quality: rarity,
      item_tier: value.tier,
      item_class:
        value.kind === "Equipment"
          ? "tool"
          : c.consumables[value.key]
            ? "consumable"
            : "material",
      material_family: value.kind,
      weight: value.kind === "Equipment" ? 1 : 0.2,
      vendor_value: vendor,
      npc_buy_price: vendor,
      market_floor_price: Math.max(1, vendor),
      market_ceiling_price: Math.max(10, vendor * 12),
      market_price_band: `${Math.max(1, vendor)}–${Math.max(10, vendor * 12)}g`,
      tradeable: !starter,
      tags: [value.skill, `tier_${value.tier}`, value.group.toLowerCase()],
    };
    // Purchased supplies cost more than their delivery/vendor returns.
    if (value.kind !== "Equipment" && rarity === "common")
      c.shop_offers[`supply_${value.key}`] = {
        label: value.name,
        kind: "item",
        category: value.group,
        skill: value.skill,
        required_level: value.level,
        ...item(value.key),
        price: Math.max(15, base * 15),
      };
  }
for (const [key, value] of Object.entries(old.achievements))
  if (!key.startsWith("skill_milestone_")) c.achievements[key] = value;
for (const line of plan.lines)
  for (const tier of plan.tiers) {
    const key = `skill_milestone_${line.skill}_${tier.level}`;
    c.achievements[key] = {
      key,
      label: `${line.label} Level ${tier.level}`,
      description: `Reach level ${tier.level} in ${line.label}.`,
      skill: line.skill,
      level: tier.level,
      category: `${line.category} Milestones`,
      category_key: `${line.category.toLowerCase()}_milestones`,
      reward: {
        title: `${line.label} ${tier.region} Master`,
        gold: tier.tier * 75,
      },
    };
  }
fs.writeFileSync(destination, JSON.stringify(c, null, 2) + "\n");
console.log(
  JSON.stringify({
    version: c.economy_version,
    uniqueItems: items.size,
    recipes: Object.keys(c.crafting_recipes).length,
    training: 38,
    gathering: Object.keys(c.gathering_actions).length,
    activities: Object.keys(c.skill_activities).length,
    jobs: Object.keys(c.job_contracts).length,
    expeditions: Object.keys(c.expeditions).length,
  }),
);
