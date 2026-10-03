import { levelForXp, type Definition } from "./rules";

export interface AchievementFacts {
  metrics: Record<string, number>;
  gold: number;
  inventory: { quantity: bigint; rarity: string }[];
  skills: { skill: string; experience: bigint }[];
  equipped: number;
  categories: Record<string, string>;
}

export function achievementUnlocked(
  key: string,
  value: Definition,
  facts: AchievementFacts,
): boolean {
  const skills = facts.skills.map((row) => ({
    ...row,
    level: levelForXp(Number(row.experience)),
    category: facts.categories[row.skill],
  }));
  const active = (category?: string) =>
    skills.filter(
      (row) => row.experience > 0n && (!category || row.category === category),
    ).length;
  const highest = (category?: string) =>
    Math.max(
      1,
      ...skills
        .filter((row) => !category || row.category === category)
        .map((row) => row.level),
    );
  const atLevel = (level: number) =>
    skills.filter((row) => row.level >= level).length;
  const metric = (name: string) => facts.metrics[name] ?? 0;
  const actions = metric("gathering_actions");
  const crafts = metric("crafting");
  const jobs = metric("job");
  const expeditions = metric("expedition");
  const listings = metric("market_listing");
  const purchases = metric("market_purchase");
  const vendor = metric("vendor_sale");
  const total =
    actions + metric("skill_activities") + crafts + jobs + expeditions;
  const inventory = facts.inventory.filter((row) => row.quantity > 0n);
  const quantity = inventory.reduce(
    (sum, row) => sum + Number(row.quantity),
    0,
  );
  const accountLevel =
    Math.floor(
      skills.reduce((sum, row) => sum + Number(row.experience), 0) / 250,
    ) + 1;
  if (key.startsWith("account_level_"))
    return accountLevel >= Number(value.level);
  if (key.startsWith("skill_milestone_"))
    return (
      (skills.find((row) => row.skill === value.skill)?.level ?? 1) >=
      Number(value.level)
    );
  const checks: Record<string, boolean> = {
    first_steps: actions >= 1,
    working_hands: crafts >= 1,
    contract_hand: jobs >= 1,
    pathfinder: expeditions >= 1,
    market_voice: listings + purchases >= 1,
    full_pockets: facts.gold >= 100,
    packed_satchel: quantity >= 25,
    skill_spark: active() >= 1,
    ready_toolbelt: facts.equipped >= 7,
    steady_hands: actions >= 10,
    route_runner: actions >= 50,
    field_legend: actions >= 250,
    gathering_circle: active("Gathering") >= 3,
    wilds_initiate: highest("Gathering") >= 5,
    wilds_specialist: highest("Gathering") >= 30,
    bench_warm: crafts >= 5,
    workshop_shift: crafts >= 25,
    artisan_season: crafts >= 100,
    profession_sampler: active("Crafting") + active("Processing") >= 5,
    apprentice_artisan:
      Math.max(highest("Crafting"), highest("Processing")) >= 5,
    master_artisan: Math.max(highest("Crafting"), highest("Processing")) >= 50,
    reliable_hand: jobs >= 5,
    guild_worker: jobs >= 25,
    contract_legend: jobs >= 100,
    caravaner: expeditions >= 5,
    far_runner: expeditions >= 25,
    worldwalker: expeditions >= 100,
    world_skill_spark: active("World") >= 2,
    trail_authority: highest("World") >= 30,
    vendor_regular: vendor >= 1,
    ledger_friend: vendor >= 10,
    market_stall: listings >= 5,
    buyer_eye: purchases >= 5,
    trade_regular: listings + purchases + vendor >= 25,
    coin_chest: facts.gold >= 500,
    treasury_key: facts.gold >= 2500,
    realm_fortune: facts.gold >= 10000,
    quartermaster: quantity >= 100,
    warehouse_mind: quantity >= 250,
    collector_shelf: inventory.length >= 10,
    rare_keeper: inventory.some((row) =>
      ["uncommon", "rare", "epic", "legendary", "mythic"].includes(row.rarity),
    ),
    apprentice_spark: highest() >= 5,
    journeyman_spark: highest() >= 10,
    expert_spark: highest() >= 30,
    master_spark: highest() >= 50,
    legend_spark: highest() >= 80,
    level_100_oath: highest() >= 100,
    broad_training: active() >= 5,
    polymath_path: active() >= 10,
    full_slate: active() >= Object.keys(facts.categories).length,
    skill_quiver: atLevel(10) >= 5,
    mastery_circle: atLevel(50) >= 5,
    realm_mastery: atLevel(100) >= 5,
    combat_recruit: highest("Combat") >= 5,
    threat_breaker: highest("Combat") >= 30,
    battle_company: active("Combat") >= 3,
    hunter_edge: skills.some(
      (row) =>
        ["hunting", "combat", "slayer"].includes(row.skill) && row.level >= 10,
    ),
    social_foothold: active("Social") >= 1,
    known_name: highest("Social") >= 10,
    realm_regular: total >= 25,
    realm_veteran: total >= 250,
  };
  return checks[key] ?? false;
}
