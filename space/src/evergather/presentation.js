import { levelForXp, xpForLevel, itemTier } from "../../spacetimedb/src/rules";
import { achievementUnlocked } from "../../spacetimedb/src/achievements";
import toolTraits from "./toolTraits.json";

export const defaultAppearance = {
  body_style: "balanced",
  palette: "moonlit",
  hair_style: "short",
  outfit: "traveler",
};
const parse = (value, fallback = {}) => {
  try {
    return JSON.parse(value || "{}");
  } catch {
    return fallback;
  }
};
const round = (value) => Math.round(value * 100) / 100;
const n = (value) => Number(value ?? 0);
const fee = (value) => Math.ceil(value * 0.05);
const readable = (value) => String(value ?? "").replaceAll("_", " ");

export function skillProgress(skill, experience, label) {
  const level = levelForXp(experience);
  const current = xpForLevel(level);
  const next = level < 100 ? xpForLevel(level + 1) : null;
  return {
    skill,
    skill_label: label,
    level,
    experience,
    current_level_experience: current,
    next_level_experience: next,
    experience_into_level: experience - current,
    experience_to_next_level:
      next === null ? 0 : Math.max(0, next - experience),
  };
}

export function readCatalog(rows, cache = new Map()) {
  const content = {};
  for (const row of rows) {
    content[row.kind] ??= {};
    let value = cache.get(row.key);
    if (!value || value.payload !== row.payload) {
      value = { payload: row.payload, parsed: parse(row.payload) };
      cache.set(row.key, value);
    }
    content[row.kind][row.key.slice(row.kind.length + 1)] = value.parsed;
  }
  return content;
}

// This translates subscribed rows to the presentation contract already used by
// the ICHAA panels. Availability mirrors reducer guards, which remain authority.
export function presentEvergather(rows, content, now = Date.now()) {
  const current = rows.player;
  const settings = content.presentation?.settings ?? {};
  const definitions = content.skills ?? {};
  const metadata = content.item_metadata ?? {};
  const skillsByKey = Object.fromEntries(
    rows.skills.map((row) => [row.skill, n(row.experience)]),
  );
  const inventoryByKey = Object.fromEntries(
    rows.inventory.map((row) => [row.itemKey, n(row.quantity)]),
  );
  const skillName = (key) => definitions[key]?.label ?? readable(key);
  const level = (key) => levelForXp(skillsByKey[key] ?? 0);
  const progress = (key) =>
    skillProgress(key, skillsByKey[key] ?? 0, skillName(key));
  const gold = n(current?.gold);
  const metrics = parse(current?.metrics);
  const describeItem = (value, quantity = value.quantity ?? 1) => {
    const key = value.item_key ?? value.key;
    const rarity = value.rarity ?? "common";
    const meta =
      metadata[`${key}:${rarity}`] ?? metadata[`${key}:common`] ?? {};
    const owned = inventoryByKey[key] ?? 0;
    return {
      ...meta,
      ...value,
      item_key: key,
      item_name:
        value.item_name ?? value.name ?? meta.item_name ?? readable(key),
      rarity,
      quantity: n(quantity),
      owned_quantity: owned,
      has_enough: owned >= n(quantity),
      total_weight: round(n(meta.weight) * n(quantity)),
      total_vendor_value: n(meta.vendor_value) * n(quantity),
      total_npc_buy_price: n(meta.npc_buy_price) * n(quantity),
      tags: meta.tags ?? [],
    };
  };
  const requirements = (items) =>
    (items ?? []).map((item) => describeItem(item));
  const allEnough = (items) => items.every((item) => item.has_enough);
  const tools = rows.tools.map((row) => {
    const item = describeItem({
      item_key: row.itemKey,
      item_name: row.name,
      rarity: row.rarity,
    });
    const bonuses = parse(row.bonuses);
    const base =
      content.tool_upgrades?.[`${row.skill}:${Math.max(1, row.tierLevel)}`]
        ?.ingredients?.[0];
    const missing = row.maxDurability - row.durability;
    const repairMaterials = base
      ? requirements([{ ...base, quantity: Math.ceil(missing / 35) }])
      : [];
    const cost = missing * itemTier(row.tierLevel) * 2;
    const trait = toolTraits[row.skill] ?? {
      signature: "Wayfinder Handling",
      discipline: "General Fieldcraft",
    };
    const effect = settings.rarity_effects?.[row.rarity] ?? {};
    const cooldown = Math.min(
      20,
      n(effect.cooldown) + Math.floor(row.tierLevel / 20),
    );
    const preservation = Math.min(
      24,
      n(effect.preservation) +
        Math.floor((row.upgrades + row.tierUpgrades) / 4),
    );
    return {
      ...item,
      tool_id: String(row.id),
      slot: `tool_${row.skill}`,
      slot_label: `${skillName(row.skill)} Tool`,
      skill: row.skill,
      category: definitions[row.skill]?.category ?? "Tools",
      item_name: row.name,
      rarity: row.rarity,
      origin: row.origin,
      origin_label:
        row.origin === "starter" ? "Field Kit" : readable(row.origin),
      status: row.equipped ? "equipped" : "inventory",
      status_label: row.equipped ? "Equipped" : "Stored",
      durability: row.durability,
      max_durability: row.maxDurability,
      durability_percent: Math.round(
        (row.durability / row.maxDurability) * 100,
      ),
      is_broken: row.durability === 0,
      experience_bonus: row.durability ? n(bonuses.experience) : 0,
      yield_bonus: row.durability ? n(bonuses.yield) : 0,
      upgrade_count: row.upgrades,
      rarity_upgrade_attempts: row.rarityAttempts,
      tier_level: row.tierLevel,
      maker_name: row.makerName,
      market_floor_price: row.marketFloorPrice,
      market_ceiling_price: row.marketCeilingPrice,
      market_price_band: `${row.marketFloorPrice}–${row.marketCeilingPrice}g`,
      npc_buy_price: row.npcBuyPrice,
      signature_trait: row.durability ? trait.signature : null,
      discipline: row.durability ? trait.discipline : null,
      perks: row.durability
        ? [
            {
              key: `${row.skill}_signature`,
              label: trait.signature,
              description: `Profession bonuses while working ${trait.discipline}.`,
            },
            ...(cooldown
              ? [
                  {
                    key: "quickened_handling",
                    label: "Quickened Handling",
                    description: `${cooldown}% action cooldown reduction.`,
                  },
                ]
              : []),
            ...(preservation
              ? [
                  {
                    key: "careful_material_hand",
                    label: "Careful Material Hand",
                    description:
                      "Preserves one material on supported crafting paths.",
                  },
                ]
              : []),
          ]
        : [],
      tool_lifecycle: {
        repair: {
          materials: repairMaterials,
          missing_durability: missing,
          gold_cost: cost,
          has_gold: gold >= cost,
          missing_materials: !allEnough(repairMaterials),
          is_repairable: missing > 0 && Boolean(base),
          can_repair:
            missing > 0 &&
            Boolean(base) &&
            gold >= cost &&
            allEnough(repairMaterials),
        },
        salvage: {
          can_salvage: row.origin !== "starter" && Boolean(base),
          materials: base ? requirements([{ ...base, quantity: 1 }]) : [],
        },
        can_retire: row.origin !== "starter",
      },
    };
  });
  const equipment = tools.filter((tool) => tool.status === "equipped");
  const equipped = (key) => equipment.find((tool) => tool.skill === key);
  const baseEntry = (key, value) => ({
    ...value,
    key,
    skill_label: skillName(value.skill),
    skill_level: level(value.skill),
    skill_progress: progress(value.skill),
    required_level: value.required_level ?? 1,
    is_unlocked: level(value.skill) >= (value.required_level ?? 1),
    equipped_tool: equipped(value.skill),
    requires_tool_repair: equipped(value.skill)?.is_broken ?? false,
  });
  const entries = (kind) =>
    Object.entries(content[kind] ?? {}).map(([key, value]) =>
      baseEntry(key, value),
    );
  const eventRows = entries("world_events").map((event) => ({
    ...event,
    skill_label: (event.skills ?? []).map(skillName).join(" · "),
    experience_bonus: n(event.bonus?.experience),
    yield_bonus: n(event.bonus?.yield),
    gold_bonus: n(event.bonus?.gold),
  }));
  const actionsFor = (kind) =>
    entries(kind).map((action) => ({
      ...action,
      loot_preview: (action.loot ?? []).map((item) => describeItem(item)),
      active_event: eventRows.find(
        (event) =>
          event.status === "active" && event.skills.includes(action.skill),
      ),
    }));
  const actions = actionsFor("gathering_actions");
  const activities = actionsFor("skill_activities");
  const recipes = entries("crafting_recipes").map((recipe) => {
    const ingredients = requirements(recipe.ingredients);
    return {
      ...recipe,
      ingredients,
      outputs: requirements(recipe.outputs),
      gold_cost: n(recipe.gold_cost),
      can_craft:
        recipe.is_unlocked &&
        gold >= n(recipe.gold_cost) &&
        allEnough(ingredients) &&
        !recipe.requires_tool_repair,
    };
  });
  const jobs = entries("job_contracts").map((job) => {
    const contract = rows.contracts.find((row) => row.jobKey === job.key);
    const completed =
      n(contract?.day) === Math.floor(now / 86400000)
        ? n(contract?.completions)
        : 0;
    const remaining = Math.max(0, (job.completion_cap ?? 3) - completed);
    const items = requirements(job.requirements);
    const requiresAcceptance =
      job.demand_channel === "core_profession_contract";
    const required = n(job.objective?.target_count ?? 1);
    const completedProgress = n(contract?.progress);
    return {
      ...job,
      requirements: items,
      requires_acceptance: requiresAcceptance,
      is_accepted: contract?.active ?? false,
      completed_in_rotation: completed,
      remaining_completions: remaining,
      is_demand_available: remaining > 0,
      progress_required: required,
      progress_quantity: completedProgress,
      progress_percent: Math.min(
        100,
        Math.round((completedProgress / required) * 100),
      ),
      can_accept:
        requiresAcceptance &&
        !contract?.active &&
        job.is_unlocked &&
        remaining > 0,
      can_complete:
        job.is_unlocked &&
        remaining > 0 &&
        allEnough(items) &&
        (!requiresAcceptance ||
          Boolean(contract?.active && completedProgress >= required)),
    };
  });
  const expeditions = entries("expeditions").map((expedition) => {
    const supplies = requirements(expedition.supplies);
    return {
      ...expedition,
      supplies,
      rewards: requirements(expedition.rewards),
      can_start: expedition.is_unlocked && allEnough(supplies),
    };
  });
  const shop = {
    offers: entries("shop_offers").map((offer) => {
      const tool = equipped(offer.skill);
      const raw = rows.tools.find(
        (row) => row.equipped && row.skill === offer.skill,
      );
      const power = (bonuses) => n(bonuses.experience) * 10 + n(bonuses.yield);
      const isEquipped =
        offer.kind === "tool" && raw?.itemKey === offer.item_key;
      const isDowngrade =
        offer.kind === "tool" &&
        Boolean(raw && power(parse(raw.bonuses)) >= power(offer.bonuses ?? {}));
      return {
        ...describeItem(offer),
        ...offer,
        bonuses: offer.bonuses ?? {},
        current_tool: tool,
        is_equipped: isEquipped,
        is_downgrade: isDowngrade,
        ownership_status: isEquipped
          ? "Equipped"
          : isDowngrade
            ? "Weaker than equipped"
            : "Available",
        can_buy:
          offer.is_unlocked &&
          gold >= n(offer.price) &&
          !isEquipped &&
          !isDowngrade,
      };
    }),
  };
  const rarityOptions = equipment.map((tool) => {
    const rule = settings.rarity_rules?.[tool.rarity];
    const materials = requirements(settings.rarity_materials?.[tool.rarity]);
    const rank = ["common", "uncommon", "rare", "epic", "legendary", "mythic"];
    const capped = Boolean(
      rule && rank.indexOf(rule.target) > itemTier(tool.tier_level) - 1,
    );
    const craft = content.tool_families?.[tool.skill]?.craft;
    const ready = Boolean(
      rule && !capped && gold >= n(rule.gold_cost) && allEnough(materials),
    );
    const row = rows.tools.find((row) => String(row.id) === tool.tool_id);
    return {
      slot: tool.slot,
      current_rarity: tool.rarity,
      target_rarity: rule?.target,
      rarity_cap: rank[Math.min(5, itemTier(tool.tier_level) - 1)],
      rarity_progress: row?.rarityProgress ?? 0,
      is_max_rarity: !rule,
      is_tier_capped: capped,
      gold_cost: n(rule?.gold_cost),
      success_chance: Math.min(
        75,
        n(rule?.success_chance) + Math.min(10, Math.floor(level(craft) / 10)),
      ),
      progress_gain_min:
        n(rule?.progress_min) + Math.min(5, Math.floor(level(craft) / 20)),
      progress_gain_max:
        n(rule?.progress_max) + Math.min(5, Math.floor(level(craft) / 20)),
      materials,
      can_upgrade: ready,
      status: !rule
        ? "Maxed"
        : capped
          ? "Tier Up First"
          : gold < n(rule.gold_cost)
            ? `Need ${rule.gold_cost}g`
            : !allEnough(materials)
              ? "Need Materials"
              : "Ready",
    };
  });
  const tierOptions = equipment.map((tool) => {
    const next = Object.values(content.tool_tiers ?? {})
      .map((tier) => n(tier.level))
      .sort((a, b) => a - b)
      .find((level) => level > tool.tier_level);
    const value = content.tool_upgrades?.[`${tool.skill}:${next}`];
    const ingredients = requirements(value?.ingredients);
    const canLevel = Boolean(
      value && level(value.skill) >= (value.required_level ?? 1),
    );
    const canUpgrade =
      canLevel && gold >= n(value?.gold_cost) && allEnough(ingredients);
    return {
      slot: tool.slot,
      is_max_tier: !value,
      next_item_name: value?.output?.item_name,
      craft_skill_label: skillName(value?.skill),
      required_level: value?.required_level,
      experience_awarded: n(value?.experience),
      gold_cost: n(value?.gold_cost),
      ingredients,
      can_upgrade: canUpgrade,
      status: !value
        ? "Max Tier"
        : !canLevel
          ? `Need Lv ${value.required_level}`
          : gold < n(value.gold_cost)
            ? `Need ${value.gold_cost}g`
            : !allEnough(ingredients)
              ? "Need Materials"
              : "Ready",
    };
  });
  const inventory = rows.inventory
    .filter((row) => row.quantity > 0n)
    .map((row) =>
      describeItem(
        { item_key: row.itemKey, item_name: row.name, rarity: row.rarity },
        n(row.quantity),
      ),
    );
  const activeListings = rows.listings.map((row) => {
    const item = describeItem(
      { item_key: row.itemKey, item_name: row.name, rarity: row.rarity },
      n(row.quantity),
    );
    const total = n(row.quantity * row.unitPrice);
    const snapshot = parse(row.toolSnapshot);
    const mine = Boolean(current && row.seller.equals(current.owner));
    return {
      ...item,
      id: String(row.id),
      listing_type: row.toolId ? "tool" : "item",
      seller_name: row.sellerName,
      unit_price: n(row.unitPrice),
      total_price: total,
      estimated_seller_payout: total - fee(total),
      is_mine: mine,
      can_buy: !mine && gold >= total,
      tool: row.toolId
        ? {
            experience_bonus: n(snapshot.bonuses?.experience),
            yield_bonus: n(snapshot.bonuses?.yield),
            status_label: `${snapshot.durability}/${snapshot.maxDurability} durability · Tier ${itemTier(snapshot.tierLevel)}`,
          }
        : null,
    };
  });
  const transactions = rows.trades
    .map((row) => ({
      ...describeItem(
        { item_key: row.itemKey, item_name: row.itemName, rarity: row.rarity },
        n(row.quantity),
      ),
      id: String(row.id),
      buyer_name: row.buyerName,
      seller_name: row.sellerName,
      listing_type: row.toolSnapshot ? "tool" : "item",
      total_price: n(row.totalPrice),
      market_fee: n(row.marketFee),
      seller_payout: n(row.totalPrice - row.marketFee),
    }))
    .reverse();
  const sellable = inventory.filter((item) => item.tradeable);
  const saleTools = tools.filter(
    (tool) => tool.status === "inventory" && tool.origin !== "starter",
  );
  const signals = new Map(
    [...sellable, ...activeListings, ...transactions].map((item) => [
      `${item.item_key}:${item.rarity}:${item.listing_type ?? "item"}`,
      item,
    ]),
  );
  const marketRows = [...signals.values()].map((item) => {
    const matches = (entry) =>
      entry.item_key === item.item_key &&
      entry.rarity === item.rarity &&
      entry.listing_type === (item.listing_type ?? "item");
    const listings = activeListings.filter(matches);
    const sales = transactions.filter(matches);
    const supply = listings.reduce((sum, item) => sum + item.quantity, 0);
    const volume = sales.reduce((sum, item) => sum + item.total_price, 0);
    const sold = sales.reduce((sum, item) => sum + item.quantity, 0);
    const average = sold ? Math.round(volume / sold) : 0;
    return {
      ...item,
      listing_type: item.listing_type ?? "item",
      active_listing_count: listings.length,
      active_supply: supply,
      recent_sale_count: sales.length,
      average_price: average,
      recommended_price: Math.min(
        n(item.market_ceiling_price),
        Math.max(
          n(item.market_floor_price),
          average || listings[0]?.unit_price || n(item.market_floor_price),
        ),
      ),
      recent_volume: volume,
      velocity: sales.length
        ? "Trading"
        : listings.length
          ? "Listed"
          : "No sales yet",
    };
  });
  const totalXp = rows.skills.reduce((sum, row) => sum + n(row.experience), 0);
  const accountLevel = Math.floor(totalXp / 250) + 1;
  const claims = rows.claims.map((row) => {
    const achievement = content.achievements?.[row.achievementKey];
    return {
      key: row.achievementKey,
      title: row.title,
      achievement_key: row.achievementKey,
      achievement_label: achievement?.label ?? row.achievementKey,
      category: achievement?.category ?? "Account",
      reward: {
        ...achievement?.reward,
        title: row.title,
        gold: n(achievement?.reward?.gold),
      },
    };
  });
  const facts = {
    metrics,
    gold,
    inventory: rows.inventory,
    skills: rows.skills,
    equipped: equipment.length,
    categories: Object.fromEntries(
      Object.entries(definitions).map(([key, value]) => [key, value.category]),
    ),
  };
  const achievements = entries("achievements").map((entry) => {
    const claimed = claims.some((claim) => claim.key === entry.key);
    const unlocked = claimed || achievementUnlocked(entry.key, entry, facts);
    return { ...entry, claimed, unlocked, can_claim: unlocked && !claimed };
  });
  const titleClaim = claims.find((claim) => claim.title === current?.title);
  const loadout = {
    title_claim_key: titleClaim?.key ?? "",
    title_label: current?.title || null,
    has_equipped: Boolean(current?.title),
  };
  const actionCount =
    n(metrics.gathering_actions) + n(metrics.skill_activities);
  const summary = {
    total_experience: totalXp,
    account_level: accountLevel,
    inventory_quantity: inventory.reduce((sum, item) => sum + item.quantity, 0),
    inventory_weight: round(
      inventory.reduce((sum, item) => sum + item.total_weight, 0),
    ),
    action_count: actionCount,
  };
  const lastRows = [...rows.results].sort((a, b) =>
    a.createdAt.microsSinceUnixEpoch > b.createdAt.microsSinceUnixEpoch
      ? -1
      : a.createdAt.microsSinceUnixEpoch < b.createdAt.microsSinceUnixEpoch
        ? 1
        : a.id > b.id
          ? -1
          : 1,
  );
  const recent = lastRows.map((row) => {
    const details = parse(row.details);
    const contentKind = {
      gathering_actions: "gathering_actions",
      skill_activities: "skill_activities",
      crafting: "crafting_recipes",
      job: "job_contracts",
      expedition: "expeditions",
      shop: "shop_offers",
    }[row.kind];
    const matches = Object.entries(content[contentKind] ?? {}).filter(
      ([, entry]) => entry.label === row.label,
    );
    const key = details.key ?? (matches.length === 1 ? matches[0][0] : null);
    const definition = content[contentKind]?.[key] ?? {};
    const items = requirements(parse(row.items, []));
    const type =
      {
        gathering_actions: "gathering",
        skill_activities: "skill_activity",
        achievement: "achievement_claim",
        vendor_sale: "npc_sale",
      }[row.kind] ?? row.kind;
    return {
      ...details,
      id: String(row.id),
      action: ["gathering_actions", "skill_activities"].includes(row.kind)
        ? key
        : null,
      activity: row.kind === "skill_activities" ? key : null,
      recipe_key: row.kind === "crafting" ? key : null,
      job_key: row.kind === "job" ? key : null,
      expedition_key: row.kind === "expedition" ? key : null,
      offer_key: row.kind === "shop" ? key : null,
      type,
      label: row.label,
      skill_label: skillName(row.skill),
      location: definition.location,
      region: definition.region,
      skill_progress: row.skill ? progress(row.skill) : null,
      experience_awarded: row.experience,
      ...(row.gold >= 0n
        ? { gold_awarded: n(row.gold) }
        : { gold_spent: -n(row.gold) }),
      items_awarded: [
        "gathering_actions",
        "skill_activities",
        "expedition",
        "tool_salvage",
      ].includes(row.kind)
        ? items
        : [],
      items_created: row.kind === "crafting" ? items : [],
      items_delivered: row.kind === "job" ? items : [],
      items_consumed:
        row.kind === "tool_repair"
          ? items
          : requirements(details.items_consumed),
      supplies_consumed: requirements(details.supplies_consumed),
      created_at: row.createdAt.toDate().toISOString(),
      platform: "Evergather",
      gold: n(row.gold),
    };
  });
  const paths = new Map();
  const addPath = (items, collection, type, direction) => {
    for (const entry of collection)
      for (const item of items(entry)) {
        const key = item.item_key ?? item.key;
        if (!key) continue;
        const index = paths.get(key) ?? { sources: [], sinks: [] };
        index[direction].push({
          type,
          label: entry.label,
          context:
            entry.location ??
            entry.region ??
            entry.category ??
            entry.skill_label,
          required_level: entry.required_level,
        });
        paths.set(key, index);
      }
  };
  addPath(
    (entry) => entry.loot ?? [],
    [...actions, ...activities],
    "Gathering",
    "sources",
  );
  addPath((entry) => entry.outputs, recipes, "Crafting", "sources");
  addPath((entry) => entry.ingredients, recipes, "Crafting", "sinks");
  addPath((entry) => entry.requirements, jobs, "Job", "sinks");
  addPath((entry) => entry.rewards, expeditions, "Expedition", "sources");
  addPath((entry) => entry.supplies, expeditions, "Expedition", "sinks");
  addPath((entry) => [entry], shop.offers, "Shop", "sources");
  const owned = inventory.map((item) => {
    const index = paths.get(item.item_key) ?? { sources: [], sinks: [] };
    const sources = index.sources.sort(
      (a, b) => a.required_level - b.required_level,
    );
    const sinks = index.sinks.sort(
      (a, b) => a.required_level - b.required_level,
    );
    const fallback = item.tradeable
      ? [
          {
            type: "NPC Vendor",
            label: "Ledger Steward",
            context: `${item.npc_buy_price}g each`,
            required_level: 1,
          },
        ]
      : [];
    return {
      ...item,
      owned_quantity: item.quantity,
      sources,
      sinks,
      has_use: sinks.length > 0,
      fallback_sinks: fallback,
      transfer_routes: item.tradeable
        ? [{ type: "Marketplace", label: "Player Market" }]
        : [],
      best_source: sources[0],
      best_sink: sinks[0],
      best_fallback_sink: fallback[0],
    };
  });
  const skillRows = Object.entries(definitions).map(([key, definition]) => ({
    ...definition,
    ...progress(key),
    skill: key,
    label: definition.label,
    activities: [...actions, ...activities, ...recipes, ...jobs, ...expeditions]
      .filter((entry) => entry.skill === key)
      .map((entry) => ({
        type: entry.category ?? "Gathering",
        label: entry.label,
        required_level: entry.required_level,
        unlocked: entry.is_unlocked,
      })),
    unlocks: Object.entries(definition.unlocks ?? {}).map(([level, label]) => ({
      level: Number(level),
      label,
    })),
  }));
  const ranks = rows.leaders.map((row) => ({
    id: row.owner.toHexString(),
    display_name: row.name,
    gold: n(row.gold),
    experience: n(row.experience),
    score: n(row.experience),
    actions: n(row.actions),
    skills: parse(row.skillExperience),
  }));
  const boards = [
    {
      key: "wealth",
      label: "Wealth",
      description: "Gold held by each character.",
    },
    {
      key: "realm_score",
      label: "Renown",
      description: "Total experience across all professions.",
    },
    {
      key: "skills",
      label: "Skills",
      description: "Most experienced characters.",
    },
    ...Object.entries(definitions).map(([key, definition]) => ({
      key: `skill_${key}`,
      label: definition.label,
      description: `${definition.label} experience.`,
    })),
  ];
  const ranked = {
    wealth: ranks
      .map((row) => ({
        ...row,
        score_label: `${row.gold} gold`,
        score: row.gold,
      }))
      .sort((a, b) => b.gold - a.gold),
    realm_score: ranks
      .map((row) => ({ ...row, score_label: `${row.experience} XP` }))
      .sort((a, b) => b.experience - a.experience),
    skills: ranks
      .map((row) => ({ ...row, score_label: `${row.experience} XP` }))
      .sort((a, b) => b.experience - a.experience),
  };
  for (const [key, definition] of Object.entries(definitions))
    ranked[`skill_${key}`] = ranks
      .map((row) => ({
        ...row,
        skill: key,
        skill_label: definition.label,
        score: n(row.skills[key]),
        score_label: `${n(row.skills[key])} XP`,
      }))
      .sort((a, b) => b.score - a.score);
  const groups = [
    {
      key: "summary",
      label: "Summary",
      boards: ["wealth", "realm_score", "skills"],
      count: ranks.length * 3,
    },
    ...[
      ...new Set(
        Object.values(definitions).map((definition) => definition.category),
      ),
    ].map((category) => {
      const keys = Object.entries(definitions)
        .filter(([, definition]) => definition.category === category)
        .map(([key]) => `skill_${key}`);
      return {
        key: category,
        label: category,
        boards: keys,
        count: keys.reduce((sum, key) => sum + ranked[key].length, 0),
      };
    }),
  ];
  return {
    player: current
      ? {
          display_name: current.name,
          title: current.characterTitle,
          species: current.species,
          species_label:
            settings.character_options?.species?.find(
              (option) => option.key === current.species,
            )?.label ?? current.species,
          pronouns: current.pronouns ?? "",
          home_region: current.region,
          home_region_label:
            settings.character_options?.home_regions?.find(
              (option) => option.key === current.region,
            )?.label ?? readable(current.region),
          gold,
          appearance: { ...defaultAppearance, ...parse(current.appearance) },
          reward_loadout: loadout,
          next_action_at: current.nextActionAt
            ? new Date(n(current.nextActionAt) / 1000).toISOString()
            : null,
          can_act_now: n(current.nextActionAt) / 1000 <= now,
        }
      : null,
    character_options: settings.character_options ?? {
      species: [],
      home_regions: [],
      appearance: {},
    },
    actions,
    skill_activities: activities,
    equipment,
    tool_inventory: tools,
    tool_rarity_upgrades: {
      options: rarityOptions,
      ready_count: rarityOptions.filter((option) => option.can_upgrade).length,
    },
    tool_tier_upgrades: {
      options: tierOptions,
      ready_count: tierOptions.filter((option) => option.can_upgrade).length,
    },
    crafting_recipes: recipes,
    jobs,
    expeditions,
    shop,
    marketplace: {
      active_listings: activeListings,
      my_listings: activeListings.filter((listing) => listing.is_mine),
      sellable_inventory: sellable,
      sellable_tools: saleTools,
      recent_transactions: transactions,
      market_board: { rows: marketRows },
      npc_vendor: {
        name: "Ledger Steward",
        description:
          "Sell tradeable materials for the listed NPC buyback price.",
      },
      market_policy: { minimum_transaction_fee: 1, transaction_tax_rate: 0.05 },
    },
    progression: {
      account_level: accountLevel,
      next_account_level_experience: accountLevel * 250,
      achievements,
      claimed_rewards: claims,
      reward_options: {
        titles: claims.map((claim) => ({ key: claim.key, label: claim.title })),
      },
      reward_loadout: loadout,
      stats: {
        actions: actionCount,
        total_activity: Object.values(metrics).reduce(
          (sum, value) => sum + n(value),
          0,
        ),
        trade_activity:
          n(metrics.market_listing) +
          n(metrics.market_purchase) +
          n(metrics.vendor_sale),
      },
    },
    item_guide: {
      owned,
      owned_categories: [...new Set(owned.map((item) => item.item_class))].map(
        (key) => ({
          key,
          label: readable(key),
          count: owned.filter((item) => item.item_class === key).length,
        }),
      ),
      summary: {
        owned_items: owned.length,
        owned_items_with_sinks: owned.filter((item) => item.has_use).length,
        owned_items_with_fallback_sinks: owned.filter(
          (item) => item.fallback_sinks.length,
        ).length,
      },
    },
    skills: skillRows,
    skill_catalog: {
      pacing: {
        max_level: 100,
        level_100_experience: xpForLevel(100),
        calibrated_experience_per_hour: 1600,
        estimated_hours_to_level_100: 106.3,
        major_action_goal_range: [3000, 5000],
        category_targets: Object.fromEntries(
          skillRows.map((skill) => [
            skill.category,
            { target_hours_range: skill.target_hours_range },
          ]),
        ),
        target_hours_range: [50, 100],
      },
    },
    world_events: {
      active: eventRows.filter((event) => event.status === "active"),
      upcoming: eventRows.filter((event) => event.status === "upcoming"),
      categories: [...new Set(eventRows.map((event) => event.category))],
    },
    leaderboards: { boards, groups, ...ranked },
    summary,
    recent_actions: recent.filter((entry) =>
      ["gathering", "skill_activity"].includes(entry.type),
    ),
    last_result: recent[0] ?? null,
  };
}
