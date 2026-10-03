import {
  t,
  SenderError,
  type InferSchema,
  type ReducerCtx,
} from "spacetimedb/server";
import db, {
  player,
  skill,
  inventory,
  tool,
  result,
  contract,
  achievement,
} from "./schema";
import source from "./content/evergather.json";
import {
  levelForXp,
  maxDurability,
  assertQuantity,
  itemTier,
  type Definition,
  type Item,
} from "./rules";
import { achievementUnlocked } from "./achievements";
import { toolPrices } from "./market";
import type { Identity } from "spacetimedb";
export default db;
type Ctx = ReducerCtx<InferSchema<typeof db>>;
const content = source as unknown as Record<string, Record<string, Definition>>;

function seedContent(ctx: Ctx) {
  for (const kind of [
    "skills",
    "tiers",
    "tool_families",
    "tool_tiers",
    "gathering_actions",
    "skill_activities",
    "crafting_recipes",
    "job_contracts",
    "expeditions",
    "shop_offers",
    "world_events",
    "tool_upgrades",
    "achievements",
  ]) {
    for (const [key, value] of Object.entries(content[kind])) {
      const row = {
        key: `${kind}:${key}`,
        kind,
        label: String(value.label ?? value.name_mark ?? value.name ?? key),
        skill: String(value.skill ?? (kind === "skills" ? key : "")),
        requiredLevel: Number(value.required_level ?? value.level ?? 1),
        payload: JSON.stringify(value),
      };
      if (ctx.db.catalog.key.find(row.key)) ctx.db.catalog.key.update(row);
      else ctx.db.catalog.insert(row);
    }
  }
  const presentation = {
    character_options: content.character_options,
    rarity_rules: content.rarity_rules,
    rarity_materials: content.rarity_materials,
    rarity_effects: content.rarity_effects,
  };
  const settings = {
    key: "presentation:settings",
    kind: "presentation",
    label: "Settings",
    skill: "",
    requiredLevel: 1,
    payload: JSON.stringify(presentation),
  };
  if (ctx.db.catalog.key.find(settings.key))
    ctx.db.catalog.key.update(settings);
  else ctx.db.catalog.insert(settings);
  for (const [key, metadata] of Object.entries(content.item_metadata)) {
    const fields = [
      "item_key",
      "item_name",
      "rarity",
      "quality",
      "item_tier",
      "item_class",
      "material_family",
      "weight",
      "vendor_value",
      "npc_buy_price",
      "market_floor_price",
      "market_ceiling_price",
      "market_price_band",
      "tradeable",
      "tags",
    ];
    const row = {
      key: `item_metadata:${key}`,
      kind: "item_metadata",
      label: String(metadata.item_name),
      skill: "",
      requiredLevel: 1,
      payload: JSON.stringify(
        Object.fromEntries(fields.map((field) => [field, metadata[field]])),
      ),
    };
    if (ctx.db.catalog.key.find(row.key)) ctx.db.catalog.key.update(row);
    else ctx.db.catalog.insert(row);
  }
  for (const row of ctx.db.player.iter()) refreshLeaderboard(ctx, row.owner);
}
export const init = db.init((ctx) => {
  ctx.db.security.insert({
    id: 1,
    owner: ctx.sender,
    issuer: "",
    audience: "",
    localPlay: false,
  });
  seedContent(ctx);
});
export const refreshContent = db.reducer((ctx) => {
  if (!ctx.db.security.id.find(1)!.owner.equals(ctx.sender))
    throw new SenderError("Only the database owner can import content.");
  seedContent(ctx);
});
export const configureAuth = db.reducer(
  { issuer: t.string(), audience: t.string() },
  (ctx, args) => {
    const current = ctx.db.security.id.find(1)!;
    if (!current.owner.equals(ctx.sender))
      throw new SenderError("Only the database owner can configure login.");
    const disabled = args.issuer === "" && args.audience === "";
    const validIssuer =
      args.issuer.startsWith("https://") || args.issuer === "localhost";
    if (!disabled && (!validIssuer || !args.audience.trim()))
      throw new SenderError("A HTTPS issuer and client ID are required.");
    ctx.db.security.id.update({ ...current, ...args });
  },
);
export const configureLocalPlay = db.reducer(
  { enabled: t.bool() },
  (ctx, { enabled }) => {
    const settings = ctx.db.security.id.find(1)!;
    if (!settings.owner.equals(ctx.sender))
      throw new SenderError(
        "Only the database owner can configure local play.",
      );
    ctx.db.security.id.update({ ...settings, localPlay: enabled });
  },
);
function authenticated(ctx: Ctx) {
  const settings = ctx.db.security.id.find(1)!;
  if (settings.localPlay) return;
  const jwt = ctx.senderAuth.jwt;
  if (
    !settings.issuer ||
    !jwt ||
    jwt.issuer !== settings.issuer ||
    !jwt.audience.includes(settings.audience)
  )
    throw new SenderError("Sign in with Discord to play Evergather.");
}
export const onConnect = db.clientConnected((ctx) => {
  if (ctx.db.security.id.find(1)!.owner.equals(ctx.sender)) return;
  authenticated(ctx);
});
function character(ctx: Ctx) {
  authenticated(ctx);
  const row = ctx.db.player.owner.find(ctx.sender);
  if (!row) throw new SenderError("Create your character first.");
  return row;
}
function definition(ctx: Ctx, kind: string, key: string): Definition {
  const row = ctx.db.catalog.key.find(`${kind}:${key}`);
  if (!row) throw new SenderError("That action is unavailable.");
  return JSON.parse(row.payload) as Definition;
}
function ownerKey(ctx: Ctx, key: string) {
  return `${ctx.sender.toHexString()}:${key}`;
}
function xp(ctx: Ctx, name: string) {
  return Number(ctx.db.skill.key.find(ownerKey(ctx, name))?.experience ?? 0n);
}
function checkLevel(ctx: Ctx, value: Definition) {
  if (levelForXp(xp(ctx, value.skill!)) < (value.required_level ?? 1))
    throw new SenderError(
      `Level ${value.required_level} ${value.skill} is required.`,
    );
}
function grantXp(ctx: Ctx, name: string, amount: number) {
  const key = ownerKey(ctx, name);
  const current = ctx.db.skill.key.find(key);
  const next = {
    key,
    owner: ctx.sender,
    skill: name,
    experience: (current?.experience ?? 0n) + BigInt(amount),
  };
  if (current) ctx.db.skill.key.update(next);
  else ctx.db.skill.insert(next);
}
function grantItem(ctx: Ctx, item: Item) {
  if (item.quantity < 1) return;
  const key = ownerKey(ctx, item.item_key);
  const current = ctx.db.inventory.key.find(key);
  const next = {
    key,
    owner: ctx.sender,
    itemKey: item.item_key,
    name: item.item_name,
    rarity: item.rarity ?? "common",
    quantity: (current?.quantity ?? 0n) + BigInt(item.quantity),
  };
  if (current) ctx.db.inventory.key.update(next);
  else ctx.db.inventory.insert(next);
}
function consume(ctx: Ctx, items: Item[]) {
  const totals = new Map<string, number>();
  for (const item of items)
    totals.set(item.item_key, (totals.get(item.item_key) ?? 0) + item.quantity);
  for (const [key, amount] of totals) {
    const current = ctx.db.inventory.key.find(ownerKey(ctx, key));
    if (!current || current.quantity < BigInt(amount))
      throw new SenderError(
        `Not enough ${items.find((i) => i.item_key === key)!.item_name}.`,
      );
    ctx.db.inventory.key.update({
      ...current,
      quantity: current.quantity - BigInt(amount),
    });
  }
}
function refreshLeaderboard(ctx: Ctx, owner: Identity) {
  const current = ctx.db.player.owner.find(owner)!;
  const metrics = JSON.parse(current.metrics) as Record<string, number>;
  const summary = {
    owner,
    name: current.name,
    gold: current.gold,
    experience: [...ctx.db.skill.byOwner.filter(owner)].reduce(
      (sum, row) => sum + row.experience,
      0n,
    ),
    actions: BigInt(
      (metrics.gathering_actions ?? 0) + (metrics.skill_activities ?? 0),
    ),
    skillExperience: JSON.stringify(
      Object.fromEntries(
        [...ctx.db.skill.byOwner.filter(owner)].map((row) => [
          row.skill,
          Number(row.experience),
        ]),
      ),
    ),
  };
  if (ctx.db.leaderboard.owner.find(owner))
    ctx.db.leaderboard.owner.update(summary);
  else ctx.db.leaderboard.insert(summary);
}
function log(
  ctx: Ctx,
  kind: string,
  value: Definition,
  experience: number,
  gold: number,
  items: Item[],
  details: Record<string, unknown> = {},
) {
  ctx.db.result.insert({
    id: 0n,
    owner: ctx.sender,
    kind,
    label: value.label,
    skill: value.skill ?? "",
    experience,
    gold: BigInt(gold),
    items: JSON.stringify(items),
    createdAt: ctx.timestamp,
    details: JSON.stringify(details),
  });
  const history = [...ctx.db.result.byOwner.filter(ctx.sender)].sort((a, b) =>
    a.createdAt.microsSinceUnixEpoch < b.createdAt.microsSinceUnixEpoch
      ? -1
      : a.createdAt.microsSinceUnixEpoch > b.createdAt.microsSinceUnixEpoch
        ? 1
        : Number(a.id - b.id),
  );
  for (const row of history.slice(0, -30)) ctx.db.result.id.delete(row.id);
  const current = ctx.db.player.owner.find(ctx.sender)!;
  const metrics = JSON.parse(current.metrics) as Record<string, number>;
  metrics[kind] = (metrics[kind] ?? 0) + 1;
  ctx.db.player.owner.update({ ...current, metrics: JSON.stringify(metrics) });
  for (const contract of ctx.db.contract.byOwner.filter(ctx.sender)) {
    if (!contract.active) continue;
    const job = definition(ctx, "job_contracts", contract.jobKey);
    const objective = job.objective ?? {};
    const matchesSkill = !objective.skill || objective.skill === value.skill;
    const objectiveType = String(job.objective_type ?? objective.type ?? "");
    const matchesKind =
      objectiveType === "gather"
        ? kind === "gathering_actions"
        : objectiveType === "activity"
          ? kind === "skill_activities"
          : objectiveType === "craft"
            ? kind === "crafting"
            : objectiveType === "expedition"
              ? kind === "expedition"
              : false;
    if (matchesSkill && matchesKind)
      ctx.db.contract.key.update({
        ...contract,
        progress: Math.min(
          Number(objective.target_count ?? 1),
          contract.progress + 1,
        ),
      });
  }
  refreshLeaderboard(ctx, ctx.sender);
}
function equipped(ctx: Ctx, name: string) {
  return [...ctx.db.tool.byOwner.filter(ctx.sender)].find(
    (row) => row.equipped && row.skill === name,
  );
}
function ownedTool(ctx: Ctx, id: bigint) {
  const row = ctx.db.tool.id.find(id);
  if (!row || !row.owner.equals(ctx.sender))
    throw new SenderError("That tool is not yours.");
  if (row.listed)
    throw new SenderError("Cancel this tool's listing before using it.");
  return row;
}
type Tool = ReturnType<typeof ownedTool>;
function pricesForTool(row: Tool) {
  return toolPrices(
    row,
    content.item_metadata[`${row.itemKey}:${row.rarity}`] ?? {},
    Number(content.rarity_effects[row.rarity]?.market ?? 0),
  );
}
function listedTool(ctx: Ctx, id: bigint, seller: Identity) {
  const row = ctx.db.tool.id.find(id);
  if (!row || !row.owner.equals(seller) || !row.listed || row.equipped)
    throw new SenderError("This tool listing is unavailable.");
  return row;
}
function toolModifiers(row: Tool | undefined) {
  const bonuses = JSON.parse(row?.bonuses ?? "{}") as Record<string, number>;
  const effect = content.rarity_effects[row?.rarity ?? "common"] as unknown as {
    critical: number;
    cooldown: number;
    preservation: number;
  };
  const history = Math.floor(
    ((row?.upgrades ?? 0) +
      (row?.tierUpgrades ?? 0) +
      (row?.rarityAttempts ?? 0)) /
      3,
  );
  const critical = Math.min(
    35,
    effect.critical +
      Math.floor((row?.tierLevel ?? 0) / 10) +
      Math.floor(history / 2),
  );
  return {
    experience: (bonuses.experience ?? 0) + Math.floor(critical / 5),
    yield: (bonuses.yield ?? 0) + Math.floor(critical / 12),
    gold: Math.floor(critical / 8),
    critical,
    cooldown: Math.min(
      20,
      effect.cooldown + Math.floor((row?.tierLevel ?? 0) / 20),
    ),
    preservation: Math.min(
      24,
      effect.preservation +
        Math.floor(((row?.upgrades ?? 0) + (row?.tierUpgrades ?? 0)) / 4),
    ),
  };
}
function baseMaterial(row: Tool): Item {
  const upgrade =
    content.tool_upgrades[`${row.skill}:${Math.max(1, row.tierLevel)}`];
  if (!upgrade?.ingredients?.[0])
    throw new SenderError("This tool has no material recipe.");
  return { ...upgrade.ingredients[0], quantity: 1 };
}
function restoreStarter(ctx: Ctx, row: Tool) {
  if (!row.equipped) return;
  const starter = [...ctx.db.tool.byOwner.filter(ctx.sender)].find(
    (value) => value.skill === row.skill && value.origin === "starter",
  );
  if (starter) ctx.db.tool.id.update({ ...starter, equipped: true });
  else addTool(ctx, content.starter_tools[row.skill], row.skill, 0, "starter");
}
function addTool(
  ctx: Ctx,
  value: Definition | Item,
  skillName: string,
  tierLevel: number,
  origin: string,
) {
  const maximum = maxDurability(tierLevel, value.rarity ?? "common");
  for (const current of ctx.db.tool.byOwner.filter(ctx.sender))
    if (current.skill === skillName && current.equipped)
      ctx.db.tool.id.update({ ...current, equipped: false });
  ctx.db.tool.insert({
    id: 0n,
    owner: ctx.sender,
    itemKey: value.item_key!,
    name: value.item_name!,
    skill: skillName,
    rarity: value.rarity ?? "common",
    tierLevel,
    durability: maximum,
    maxDurability: maximum,
    equipped: true,
    origin,
    bonuses: JSON.stringify(value.bonuses ?? {}),
    upgrades: 0,
    tierUpgrades: 0,
    rarityAttempts: 0,
    rarityProgress: 0,
    listed: false,
    makerName:
      origin === "crafted" ? ctx.db.player.owner.find(ctx.sender)!.name : "",
  });
}
export const createCharacter = db.reducer(
  { name: t.string() },
  (ctx, { name }) => {
    authenticated(ctx);
    name = name.trim();
    if (name.length < 2 || name.length > 40)
      throw new SenderError(
        "Use a character name between 2 and 40 characters.",
      );
    if (ctx.db.player.owner.find(ctx.sender))
      throw new SenderError("You already have a character.");
    ctx.db.player.insert({
      owner: ctx.sender,
      name,
      species: "human",
      region: "moonwake_coast",
      title: "",
      appearance: "{}",
      gold: 0n,
      nextActionAt: 0n,
      createdAt: ctx.timestamp,
      metrics: "{}",
      pronouns: "",
      characterTitle: "",
    });
    for (const name of Object.keys(content.skills)) grantXp(ctx, name, 0);
    for (const [skillName, starter] of Object.entries(content.starter_tools)) {
      addTool(ctx, starter, skillName, 0, "starter");
    }
    refreshLeaderboard(ctx, ctx.sender);
  },
);
export const updateCharacter = db.reducer(
  {
    name: t.string(),
    species: t.string(),
    region: t.string(),
    title: t.string(),
  },
  (ctx, args) => {
    const current = character(ctx);
    if (
      args.name.trim().length < 2 ||
      args.name.length > 40 ||
      args.title.length > 80
    )
      throw new SenderError("Check your character name and title.");
    if (
      args.title &&
      ![...ctx.db.achievement.byOwner.filter(ctx.sender)].some(
        (row) => row.title === args.title,
      )
    )
      throw new SenderError(
        "Claim this achievement title before equipping it.",
      );
    if (
      !["human", "sylvan", "tideborn", "stonekin", "drifter"].includes(
        args.species,
      ) ||
      ![
        "moonwake_coast",
        "emberdeep_quarry",
        "whisperbough_stand",
        "glimmerfen_trail",
      ].includes(args.region)
    )
      throw new SenderError("Choose a listed species and home region.");
    ctx.db.player.owner.update({ ...current, ...args, name: args.name.trim() });
    refreshLeaderboard(ctx, ctx.sender);
    log(
      ctx,
      "reward_loadout",
      { label: args.title ? `Equipped ${args.title}` : "Cleared earned title" },
      0,
      0,
      [],
    );
  },
);
export const customizeCharacter = db.reducer(
  {
    name: t.string(),
    title: t.string(),
    species: t.string(),
    pronouns: t.string(),
    region: t.string(),
    appearance: t.string(),
  },
  (ctx, args) => {
    const current = character(ctx);
    if (
      args.name.trim().length < 2 ||
      args.name.trim().length > 40 ||
      args.title.length > 80 ||
      args.pronouns.length > 40
    )
      throw new SenderError("Check your name, title, and pronouns.");
    const options = content.character_options as unknown as {
      species: { key: string }[];
      home_regions: { key: string }[];
      appearance: Record<string, { key: string }[]>;
    };
    if (
      !options.species.some((option) => option.key === args.species) ||
      !options.home_regions.some((option) => option.key === args.region)
    )
      throw new SenderError("Choose a listed species and home region.");
    let appearance: Record<string, string>;
    try {
      appearance = JSON.parse(args.appearance);
    } catch {
      throw new SenderError("Choose a valid appearance.");
    }
    for (const [field, values] of Object.entries(options.appearance))
      if (!values.some((option) => option.key === appearance[field]))
        throw new SenderError(`Choose a listed ${field.replaceAll("_", " ")}.`);
    ctx.db.player.owner.update({
      ...current,
      name: args.name.trim(),
      characterTitle: args.title.trim(),
      species: args.species,
      pronouns: args.pronouns.trim(),
      region: args.region,
      appearance: JSON.stringify(
        Object.fromEntries(
          Object.keys(options.appearance).map((field) => [
            field,
            appearance[field],
          ]),
        ),
      ),
    });
    refreshLeaderboard(ctx, ctx.sender);
  },
);
export const acceptJob = db.reducer({ key: t.string() }, (ctx, { key }) => {
  character(ctx);
  const value = definition(ctx, "job_contracts", key);
  checkLevel(ctx, value);
  if (value.demand_channel !== "core_profession_contract")
    throw new SenderError("This contract can be turned in directly.");
  const recordKey = ownerKey(ctx, key);
  const previous = ctx.db.contract.key.find(recordKey);
  const day = ctx.timestamp.microsSinceUnixEpoch / 86_400_000_000n;
  const completions = previous?.day === day ? previous.completions : 0;
  if (previous?.active)
    throw new SenderError("This contract is already active.");
  if (completions >= (value.completion_cap ?? 3))
    throw new SenderError(
      "This contract has reached its daily completion limit.",
    );
  const row = {
    key: recordKey,
    owner: ctx.sender,
    jobKey: key,
    acceptedAt: ctx.timestamp,
    progress: 0,
    completions,
    day,
    active: true,
  };
  if (previous) ctx.db.contract.key.update(row);
  else ctx.db.contract.insert(row);
  log(ctx, "job_acceptance", value, 0, 0, [], { key, kind: "job_contracts" });
});
export const performAction = db.reducer(
  { kind: t.string(), key: t.string() },
  (ctx, { kind, key }) => {
    const current = character(ctx);
    if (!["gathering_actions", "skill_activities"].includes(kind))
      throw new SenderError("Choose a gathering action or activity.");
    const value = definition(ctx, kind, key);
    checkLevel(ctx, value);
    const now = ctx.timestamp.microsSinceUnixEpoch;
    if (current.nextActionAt > now)
      throw new SenderError("Your next action is still cooling down.");
    const equipment = equipped(ctx, value.skill!);
    if (equipment && !equipment.durability)
      throw new SenderError("Repair your equipped tool first.");
    const bonuses = toolModifiers(equipment);
    const matchingEvent = Object.values(content.world_events).find(
      (event) =>
        event.status === "active" &&
        (event.skills as string[]).includes(value.skill!),
    );
    const eventBonus = (matchingEvent?.bonus ?? {}) as Record<string, number>;
    const roll = (range: number | { min: number; max: number } | undefined) =>
      typeof range === "number"
        ? range
        : ctx.random.integerInRange(range?.min ?? 0, range?.max ?? 0);
    const experience =
      roll(value.experience) +
      bonuses.experience +
      (eventBonus.experience ?? 0);
    const gold = roll(value.gold) + bonuses.gold + (eventBonus.gold ?? 0);
    const items: Item[] = [];
    for (const item of value.loot ?? []) {
      if (
        item.chance !== undefined &&
        ctx.random.integerInRange(1, 100) > item.chance
      )
        continue;
      const amount =
        item.quantity ??
        ctx.random.integerInRange(item.min ?? 0, item.max ?? 0);
      if (!amount) continue;
      items.push({
        item_key: item.item_key ?? item.key!,
        item_name: item.item_name ?? item.name!,
        rarity: item.rarity ?? "common",
        quantity:
          amount +
          ((item.min ?? 1) > 0 ? bonuses.yield + (eventBonus.yield ?? 0) : 0),
      });
    }
    for (const item of items) grantItem(ctx, item);
    grantXp(ctx, value.skill!, experience);
    ctx.db.player.owner.update({
      ...current,
      gold: current.gold + BigInt(gold),
      nextActionAt:
        now +
        BigInt(
          Math.max(
            1,
            Math.floor(
              ((value.cooldown_seconds ?? 60) * (100 - bonuses.cooldown)) / 100,
            ),
          ),
        ) *
          1_000_000n,
    });
    if (
      equipment &&
      [
        bonuses.experience,
        bonuses.yield,
        bonuses.gold,
        bonuses.critical,
        bonuses.cooldown,
      ].some((v) => v > 0)
    )
      ctx.db.tool.id.update({
        ...equipment,
        durability: Math.max(0, equipment.durability - 1),
      });
    log(ctx, kind, value, experience, gold, items, {
      key,
      kind,
      event: matchingEvent
        ? { label: matchingEvent.label, ...eventBonus }
        : undefined,
    });
  },
);
export const craft = db.reducer({ key: t.string() }, (ctx, { key }) => {
  const current = character(ctx);
  const value = definition(ctx, "crafting_recipes", key);
  checkLevel(ctx, value);
  const equipment = equipped(ctx, value.skill!);
  if (equipment && !equipment.durability)
    throw new SenderError("Repair your equipped tool first.");
  const cost = value.gold_cost ?? 0;
  if (current.gold < BigInt(cost)) throw new SenderError("Not enough gold.");
  consume(ctx, value.ingredients ?? []);
  const preserved =
    Math.max(1, equipment?.tierLevel ?? 0) >= (value.required_level ?? 1) &&
    toolModifiers(equipment).preservation > 0
      ? value.ingredients?.find((item) => item.quantity > 1)
      : undefined;
  if (preserved) {
    grantItem(ctx, { ...preserved, quantity: 1 });
    ctx.db.tool.id.update({
      ...equipment!,
      durability: Math.max(0, equipment!.durability - 1),
    });
  }
  for (const item of value.outputs ?? []) {
    if (item.equipment_skill)
      addTool(
        ctx,
        item,
        item.equipment_skill,
        value.required_level ?? 1,
        "crafted",
      );
    else grantItem(ctx, item);
  }
  const experience = Number(value.experience ?? 0);
  grantXp(ctx, value.skill!, experience);
  ctx.db.player.owner.update({ ...current, gold: current.gold - BigInt(cost) });
  log(ctx, "crafting", value, experience, -cost, value.outputs ?? [], {
    key,
    kind: "crafting_recipes",
    items_consumed: value.ingredients ?? [],
  });
});
export const buyShopOffer = db.reducer({ key: t.string() }, (ctx, { key }) => {
  const current = character(ctx);
  const value = definition(ctx, "shop_offers", key);
  checkLevel(ctx, value);
  const cost = value.price ?? 0;
  if (current.gold < BigInt(cost)) throw new SenderError("Not enough gold.");
  if (value.kind === "tool") {
    const currentTool = equipped(ctx, value.skill!);
    const existing = JSON.parse(currentTool?.bonuses ?? "{}") as Record<
      string,
      number
    >;
    const power = (bonuses: Record<string, number>) =>
      (bonuses.experience ?? 0) * 10 + (bonuses.yield ?? 0);
    if (
      currentTool &&
      (currentTool.itemKey === value.item_key ||
        power(existing) >= power(value.bonuses ?? {}))
    )
      throw new SenderError(
        "Your equipped tool is already as strong as this offer.",
      );
    addTool(ctx, value, value.skill!, value.required_level ?? 1, "purchased");
  } else
    grantItem(ctx, {
      item_key: value.item_key!,
      item_name: value.item_name!,
      quantity: value.quantity ?? 1,
      rarity: value.rarity,
    });
  ctx.db.player.owner.update({ ...current, gold: current.gold - BigInt(cost) });
  log(ctx, "shop", value, 0, -cost, [], {
    key,
    kind: "shop_offers",
    item_key: value.item_key,
    item_name: value.item_name,
    rarity: value.rarity,
    quantity: value.quantity ?? 1,
  });
});
export const runExpedition = db.reducer({ key: t.string() }, (ctx, { key }) => {
  const current = character(ctx);
  const value = definition(ctx, "expeditions", key);
  checkLevel(ctx, value);
  consume(ctx, value.supplies ?? []);
  for (const item of value.rewards ?? []) grantItem(ctx, item);
  const gold = Number(value.gold ?? 0);
  const experience = Number(value.experience ?? 0);
  grantXp(ctx, value.skill!, experience);
  ctx.db.player.owner.update({ ...current, gold: current.gold + BigInt(gold) });
  log(ctx, "expedition", value, experience, gold, value.rewards ?? [], {
    key,
    kind: "expeditions",
    supplies_consumed: value.supplies ?? [],
  });
});
export const listItem = db.reducer(
  { itemKey: t.string(), quantity: t.u32(), unitPrice: t.u32() },
  (ctx, args) => {
    const current = character(ctx);
    assertQuantity(args.quantity);
    assertQuantity(args.unitPrice);
    const stack = ctx.db.inventory.key.find(ownerKey(ctx, args.itemKey));
    if (!stack || stack.quantity < BigInt(args.quantity))
      throw new SenderError("Not enough items.");
    const metadata = content.item_metadata[`${stack.itemKey}:${stack.rarity}`];
    if (
      !metadata?.tradeable ||
      args.unitPrice < Number(metadata.market_floor_price) ||
      args.unitPrice > Number(metadata.market_ceiling_price)
    )
      throw new SenderError(
        `Price must be between ${metadata?.market_floor_price ?? 1} and ${metadata?.market_ceiling_price ?? 1} gold.`,
      );
    ctx.db.inventory.key.update({
      ...stack,
      quantity: stack.quantity - BigInt(args.quantity),
    });
    ctx.db.listing.insert({
      id: 0n,
      seller: ctx.sender,
      sellerName: current.name,
      itemKey: stack.itemKey,
      name: stack.name,
      rarity: stack.rarity,
      quantity: BigInt(args.quantity),
      unitPrice: BigInt(args.unitPrice),
      toolId: 0n,
      toolSnapshot: "",
      createdAt: ctx.timestamp,
    });
    log(ctx, "market_listing", { label: `Listed ${stack.name}` }, 0, 0, [], {
      listing_type: "item",
      item_key: stack.itemKey,
      item_name: stack.name,
      rarity: stack.rarity,
      quantity: args.quantity,
      unit_price: args.unitPrice,
    });
  },
);
export const listTool = db.reducer(
  { id: t.u64(), unitPrice: t.u32() },
  (ctx, args) => {
    const current = character(ctx);
    const row = ownedTool(ctx, args.id);
    if (row.equipped || row.origin === "starter")
      throw new SenderError("Only stored, non-starter tools can be listed.");
    assertQuantity(args.unitPrice);
    const prices = pricesForTool(row);
    if (
      args.unitPrice < prices.marketFloorPrice ||
      args.unitPrice > prices.marketCeilingPrice
    )
      throw new SenderError(
        `Price must be between ${prices.marketFloorPrice} and ${prices.marketCeilingPrice} gold.`,
      );
    const snapshot = {
      skill: row.skill,
      rarity: row.rarity,
      tierLevel: row.tierLevel,
      durability: row.durability,
      maxDurability: row.maxDurability,
      origin: row.origin,
      makerName: row.makerName,
      bonuses: JSON.parse(row.bonuses),
      upgrades: row.upgrades,
      tierUpgrades: row.tierUpgrades,
      rarityAttempts: row.rarityAttempts,
      rarityProgress: row.rarityProgress,
    };
    ctx.db.tool.id.update({ ...row, listed: true });
    ctx.db.listing.insert({
      id: 0n,
      seller: ctx.sender,
      sellerName: current.name,
      itemKey: row.itemKey,
      name: row.name,
      rarity: row.rarity,
      quantity: 1n,
      unitPrice: BigInt(args.unitPrice),
      toolId: row.id,
      toolSnapshot: JSON.stringify(snapshot),
      createdAt: ctx.timestamp,
    });
    log(ctx, "market_listing", { label: `Listed ${row.name}` }, 0, 0, [], {
      listing_type: "tool",
      item_key: row.itemKey,
      item_name: row.name,
      quantity: 1,
      tool_id: String(row.id),
    });
  },
);
export const buyListing = db.reducer({ id: t.u64() }, (ctx, { id }) => {
  const current = character(ctx);
  const row = ctx.db.listing.id.find(id);
  if (!row) throw new SenderError("That listing has already sold.");
  if (row.seller.equals(ctx.sender))
    throw new SenderError("Cancel your own listing to reclaim it.");
  const cost = row.quantity * row.unitPrice;
  if (current.gold < cost) throw new SenderError("Not enough gold.");
  const seller = ctx.db.player.owner.find(row.seller)!;
  const fee = (cost + 19n) / 20n;
  ctx.db.player.owner.update({ ...seller, gold: seller.gold + cost - fee });
  ctx.db.player.owner.update({ ...current, gold: current.gold - cost });
  if (row.toolId) {
    const instance = listedTool(ctx, row.toolId, row.seller);
    ctx.db.tool.id.update({
      ...instance,
      owner: ctx.sender,
      listed: false,
      equipped: false,
    });
  } else {
    grantItem(ctx, {
      item_key: row.itemKey,
      item_name: row.name,
      rarity: row.rarity,
      quantity: Number(row.quantity),
    });
  }
  ctx.db.listing.id.delete(id);
  refreshLeaderboard(ctx, row.seller);
  ctx.db.trade.insert({
    id: 0n,
    sellerName: row.sellerName,
    buyerName: current.name,
    itemKey: row.itemKey,
    itemName: row.name,
    rarity: row.rarity,
    quantity: row.quantity,
    totalPrice: cost,
    marketFee: fee,
    toolSnapshot: row.toolSnapshot,
    createdAt: ctx.timestamp,
  });
  const trades = [...ctx.db.trade.iter()].sort((a, b) =>
    a.createdAt.microsSinceUnixEpoch < b.createdAt.microsSinceUnixEpoch
      ? -1
      : 1,
  );
  for (const trade of trades.slice(0, -100)) ctx.db.trade.id.delete(trade.id);
  log(
    ctx,
    "market_purchase",
    { label: `Bought ${row.name}` },
    0,
    -Number(cost),
    [],
    {
      item_key: row.itemKey,
      item_name: row.name,
      rarity: row.rarity,
      quantity: Number(row.quantity),
      total_price: Number(cost),
      listing_type: row.toolId ? "tool" : "item",
    },
  );
});
export const cancelListing = db.reducer({ id: t.u64() }, (ctx, { id }) => {
  character(ctx);
  const row = ctx.db.listing.id.find(id);
  if (!row || !row.seller.equals(ctx.sender))
    throw new SenderError("That listing is not yours.");
  if (row.toolId) {
    const instance = listedTool(ctx, row.toolId, ctx.sender);
    ctx.db.tool.id.update({ ...instance, listed: false });
  } else {
    grantItem(ctx, {
      item_key: row.itemKey,
      item_name: row.name,
      rarity: row.rarity,
      quantity: Number(row.quantity),
    });
  }
  ctx.db.listing.id.delete(id);
  log(ctx, "market_cancel", { label: `Cancelled ${row.name}` }, 0, 0, []);
});
export const sellToolToVendor = db.reducer({ id: t.u64() }, (ctx, { id }) => {
  const current = character(ctx);
  const row = ownedTool(ctx, id);
  if (row.equipped || row.origin === "starter")
    throw new SenderError("Only stored, non-starter tools can be sold.");
  const gold = pricesForTool(row).npcBuyPrice;
  ctx.db.tool.id.delete(id);
  ctx.db.player.owner.update({ ...current, gold: current.gold + BigInt(gold) });
  log(ctx, "vendor_sale", { label: `Sold ${row.name}` }, 0, gold, []);
});
export const sellToVendor = db.reducer(
  { itemKey: t.string(), quantity: t.u32() },
  (ctx, args) => {
    const current = character(ctx);
    assertQuantity(args.quantity);
    const stack = ctx.db.inventory.key.find(ownerKey(ctx, args.itemKey));
    if (!stack || stack.quantity < BigInt(args.quantity))
      throw new SenderError("Not enough items.");
    const metadata = content.item_metadata[`${stack.itemKey}:${stack.rarity}`];
    if (!metadata?.tradeable || Number(metadata.npc_buy_price) < 1)
      throw new SenderError("The vendor cannot buy this item.");
    const gold = Number(metadata.npc_buy_price) * args.quantity;
    ctx.db.inventory.key.update({
      ...stack,
      quantity: stack.quantity - BigInt(args.quantity),
    });
    ctx.db.player.owner.update({
      ...current,
      gold: current.gold + BigInt(gold),
    });
    log(ctx, "vendor_sale", { label: `Sold ${stack.name}` }, 0, gold, [], {
      item_key: stack.itemKey,
      item_name: stack.name,
      rarity: stack.rarity,
      quantity: args.quantity,
    });
  },
);
export const completeJob = db.reducer({ key: t.string() }, (ctx, { key }) => {
  const current = character(ctx);
  const value = definition(ctx, "job_contracts", key);
  checkLevel(ctx, value);
  const day = ctx.timestamp.microsSinceUnixEpoch / 86_400_000_000n;
  const recordKey = ownerKey(ctx, key);
  const previous = ctx.db.contract.key.find(recordKey);
  const completions = previous?.day === day ? previous.completions : 0;
  if (completions >= (value.completion_cap ?? 3))
    throw new SenderError(
      "This contract has reached its daily completion limit.",
    );
  if (
    value.demand_channel === "core_profession_contract" &&
    (!previous?.active ||
      previous.progress < Number(value.objective?.target_count ?? 1))
  )
    throw new SenderError(
      "Accept this contract and finish its objectives first.",
    );
  consume(ctx, value.requirements ?? []);
  const gold = Number(value.gold ?? 0);
  const experience = Number(value.experience ?? 0);
  grantXp(ctx, value.skill!, experience);
  ctx.db.player.owner.update({ ...current, gold: current.gold + BigInt(gold) });
  const record = {
    key: recordKey,
    owner: ctx.sender,
    jobKey: key,
    acceptedAt: previous?.acceptedAt ?? ctx.timestamp,
    progress: previous?.progress ?? 0,
    completions: completions + 1,
    day,
    active: false,
  };
  if (previous) ctx.db.contract.key.update(record);
  else ctx.db.contract.insert(record);
  log(ctx, "job", value, experience, gold, value.requirements ?? [], {
    key,
    kind: "job_contracts",
  });
});
export const equipTool = db.reducer(
  { id: t.u64(), equipped: t.bool() },
  (ctx, args) => {
    character(ctx);
    const row = ownedTool(ctx, args.id);
    if (!args.equipped && row.origin === "starter")
      throw new SenderError("Field kit tools stay equipped until replaced.");
    if (args.equipped) {
      for (const current of ctx.db.tool.byOwner.filter(ctx.sender))
        if (current.skill === row.skill && current.equipped)
          ctx.db.tool.id.update({ ...current, equipped: false });
    } else restoreStarter(ctx, row);
    ctx.db.tool.id.update({ ...row, equipped: args.equipped });
    log(
      ctx,
      args.equipped ? "tool_equip" : "tool_unequip",
      {
        label: `${args.equipped ? "Equipped" : "Stored"} ${row.name}`,
        skill: row.skill,
      },
      0,
      0,
      [],
      { tool_id: String(row.id), slot: `tool_${row.skill}` },
    );
  },
);
export const repairTool = db.reducer({ id: t.u64() }, (ctx, { id }) => {
  const current = character(ctx);
  const row = ownedTool(ctx, id);
  const missing = row.maxDurability - row.durability;
  if (missing < 1) throw new SenderError("This tool does not need repair.");
  const cost = missing * itemTier(row.tierLevel) * 2;
  if (current.gold < BigInt(cost))
    throw new SenderError(`Repair costs ${cost} gold.`);
  const material = { ...baseMaterial(row), quantity: Math.ceil(missing / 35) };
  consume(ctx, [material]);
  ctx.db.player.owner.update({ ...current, gold: current.gold - BigInt(cost) });
  ctx.db.tool.id.update({ ...row, durability: row.maxDurability });
  log(
    ctx,
    "tool_repair",
    { label: `Repaired ${row.name}`, skill: row.skill },
    0,
    -cost,
    [material],
  );
});
export const removeTool = db.reducer(
  { id: t.u64(), salvage: t.bool() },
  (ctx, args) => {
    character(ctx);
    const row = ownedTool(ctx, args.id);
    if (row.origin === "starter")
      throw new SenderError("Field kit tools cannot be removed.");
    const items = args.salvage ? [baseMaterial(row)] : [];
    restoreStarter(ctx, row);
    ctx.db.tool.id.delete(row.id);
    for (const item of items) grantItem(ctx, item);
    log(
      ctx,
      args.salvage ? "tool_salvage" : "tool_retire",
      { label: row.name, skill: row.skill },
      0,
      0,
      items,
    );
  },
);
export const upgradeToolTier = db.reducer({ id: t.u64() }, (ctx, { id }) => {
  const current = character(ctx);
  const row = ownedTool(ctx, id);
  if (!row.equipped)
    throw new SenderError("Equip this tool before upgrading it.");
  const nextLevel = Object.values(content.tool_tiers)
    .map((value) => Number(value.level))
    .sort((a, b) => a - b)
    .find((level) => level > row.tierLevel);
  if (!nextLevel)
    throw new SenderError("This tool is already at its highest tier.");
  const value = definition(ctx, "tool_upgrades", `${row.skill}:${nextLevel}`);
  checkLevel(ctx, value);
  const cost = value.gold_cost ?? 0;
  if (current.gold < BigInt(cost))
    throw new SenderError(`Tier upgrade costs ${cost} gold.`);
  consume(ctx, value.ingredients ?? []);
  const maximum = maxDurability(nextLevel, row.rarity);
  const output = value.output as Item;
  const bonuses = JSON.parse(row.bonuses) as Record<string, number>;
  ctx.db.tool.id.update({
    ...row,
    itemKey: output.item_key,
    name: output.item_name,
    tierLevel: nextLevel,
    maxDurability: maximum,
    durability: Math.min(maximum, row.durability + maximum - row.maxDurability),
    origin: row.origin === "starter" ? "upgraded" : row.origin,
    bonuses: JSON.stringify({
      experience: Math.max(
        bonuses.experience ?? 0,
        value.bonuses?.experience ?? 0,
      ),
      yield: Math.max(bonuses.yield ?? 0, value.bonuses?.yield ?? 0),
    }),
    upgrades: row.upgrades + 1,
    tierUpgrades: row.tierUpgrades + 1,
  });
  grantXp(ctx, value.skill!, Number(value.experience));
  ctx.db.player.owner.update({ ...current, gold: current.gold - BigInt(cost) });
  log(ctx, "tool_tier_upgrade", value, Number(value.experience), -cost, [], {
    slot: `tool_${row.skill}`,
  });
});
export const upgradeToolRarity = db.reducer({ id: t.u64() }, (ctx, { id }) => {
  const current = character(ctx);
  const row = ownedTool(ctx, id);
  if (!row.equipped)
    throw new SenderError("Equip this tool before upgrading it.");
  const rule = content.rarity_rules[row.rarity] as unknown as
    | {
        target: string;
        success_chance: number;
        progress_min: number;
        progress_max: number;
        gold_cost: number;
      }
    | undefined;
  if (!rule)
    throw new SenderError("This tool is already at its highest rarity.");
  const rarities = [
    "common",
    "uncommon",
    "rare",
    "epic",
    "legendary",
    "mythic",
  ];
  if (rarities.indexOf(rule.target) > itemTier(row.tierLevel) - 1)
    throw new SenderError(`Tier up before attempting ${rule.target} rarity.`);
  if (current.gold < BigInt(rule.gold_cost))
    throw new SenderError(`Rarity attempt costs ${rule.gold_cost} gold.`);
  consume(ctx, content.rarity_materials[row.rarity] as unknown as Item[]);
  const family = content.tool_families[row.skill];
  const craftLevel = levelForXp(xp(ctx, String(family.craft)));
  const success =
    ctx.random.integerInRange(1, 100) <=
    Math.min(
      75,
      rule.success_chance + Math.min(10, Math.floor(craftLevel / 10)),
    );
  const progressBonus = Math.min(5, Math.floor(craftLevel / 20));
  const progress = success
    ? row.rarityProgress
    : Math.min(
        100,
        row.rarityProgress +
          ctx.random.integerInRange(
            rule.progress_min + progressBonus,
            rule.progress_max + progressBonus,
          ),
      );
  const upgraded = success || progress >= 100;
  const rarity = upgraded ? rule.target : row.rarity;
  const maximum = maxDurability(row.tierLevel, rarity);
  const bonuses = JSON.parse(row.bonuses) as Record<string, number>;
  if (upgraded)
    for (const key of ["experience", "yield"])
      bonuses[key] =
        (bonuses[key] ?? 0) +
        Number(content.rarity_bonuses[rarity][key]) -
        Number(content.rarity_bonuses[row.rarity][key]);
  ctx.db.tool.id.update({
    ...row,
    rarity,
    maxDurability: maximum,
    durability: Math.min(maximum, row.durability + maximum - row.maxDurability),
    rarityProgress: upgraded ? 0 : progress,
    rarityAttempts: row.rarityAttempts + 1,
    upgrades: row.upgrades + (upgraded ? 1 : 0),
    bonuses: JSON.stringify(bonuses),
  });
  ctx.db.player.owner.update({
    ...current,
    gold: current.gold - BigInt(rule.gold_cost),
  });
  log(
    ctx,
    "tool_rarity_upgrade",
    {
      label: `${row.name}: ${upgraded ? rarity : `${progress}% progress`}`,
      skill: row.skill,
    },
    0,
    -rule.gold_cost,
    [],
    { slot: `tool_${row.skill}` },
  );
});
export const claimAchievement = db.reducer(
  { key: t.string() },
  (ctx, { key }) => {
    const current = character(ctx);
    const value = definition(ctx, "achievements", key);
    const recordKey = ownerKey(ctx, key);
    if (ctx.db.achievement.key.find(recordKey))
      throw new SenderError("This reward has already been claimed.");
    const unlocked = achievementUnlocked(key, value, {
      metrics: JSON.parse(current.metrics),
      gold: Number(current.gold),
      inventory: [...ctx.db.inventory.byOwner.filter(ctx.sender)],
      skills: [...ctx.db.skill.byOwner.filter(ctx.sender)],
      equipped: [...ctx.db.tool.byOwner.filter(ctx.sender)].filter(
        (row) => row.equipped,
      ).length,
      categories: Object.fromEntries(
        Object.entries(content.skills).map(([key, value]) => [
          key,
          String(value.category),
        ]),
      ),
    });
    if (!unlocked)
      throw new SenderError(
        "Complete this achievement before claiming its reward.",
      );
    const reward = value.reward as { title: string; gold: number };
    ctx.db.achievement.insert({
      key: recordKey,
      owner: ctx.sender,
      achievementKey: key,
      title: reward.title,
      claimedAt: ctx.timestamp,
    });
    ctx.db.player.owner.update({
      ...current,
      gold: current.gold + BigInt(reward.gold),
    });
    log(ctx, "achievement", value, 0, reward.gold, []);
  },
);
export const myPlayer = db.view(
  { name: "my_player", public: true },
  t.option(player.rowType),
  (ctx) => ctx.db.player.owner.find(ctx.sender) ?? undefined,
);
export const mySkills = db.view(
  { name: "my_skills", public: true },
  t.array(skill.rowType),
  (ctx) => [...ctx.db.skill.byOwner.filter(ctx.sender)],
);
export const myInventory = db.view(
  { name: "my_inventory", public: true },
  t.array(inventory.rowType),
  (ctx) => [...ctx.db.inventory.byOwner.filter(ctx.sender)],
);
export const myTools = db.view(
  { name: "my_tools", public: true },
  t.array(
    t.row("OwnedTool", {
      ...tool.rowType.row,
      marketFloorPrice: t.u32(),
      marketCeilingPrice: t.u32(),
      npcBuyPrice: t.u32(),
    }),
  ),
  (ctx) =>
    [...ctx.db.tool.byOwner.filter(ctx.sender)]
      .filter((row) => !row.listed)
      .map((row) => ({ ...row, ...pricesForTool(row) })),
);
export const myResults = db.view(
  { name: "my_results", public: true },
  t.array(result.rowType),
  (ctx) => [...ctx.db.result.byOwner.filter(ctx.sender)],
);
export const myContracts = db.view(
  { name: "my_contracts", public: true },
  t.array(contract.rowType),
  (ctx) => [...ctx.db.contract.byOwner.filter(ctx.sender)],
);
export const myAchievements = db.view(
  { name: "my_achievements", public: true },
  t.array(achievement.rowType),
  (ctx) => [...ctx.db.achievement.byOwner.filter(ctx.sender)],
);
