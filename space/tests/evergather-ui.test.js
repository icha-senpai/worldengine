import { describe, expect, it } from "vitest";
import source from "../spacetimedb/src/content/evergather.json";
import {
  materialOutputLabel,
  sourcesForMaterial,
  trainingSourceFor,
} from "../src/evergather/materialSources";
import {
  presentEvergather,
  readCatalog,
  skillProgress,
} from "../src/evergather/presentation";

const content = {
  ...source,
  presentation: {
    settings: {
      character_options: source.character_options,
      rarity_rules: source.rarity_rules,
      rarity_materials: source.rarity_materials,
      rarity_materials_by_tier: source.rarity_materials_by_tier,
      consumables: source.consumables,
      rarity_effects: source.rarity_effects,
    },
  },
};
const owner = {
  equals: (other) => other === owner,
  toHexString: () => "player",
};
function fixture() {
  return {
    player: {
      owner,
      name: "Flow player",
      species: "human",
      region: "moonwake_coast",
      title: "",
      characterTitle: "",
      appearance: "{}",
      pronouns: "",
      gold: 500n,
      nextActionAt: 0n,
      metrics: "{}",
    },
    skills: Object.keys(source.skills).map((skill) => ({
      skill,
      experience: 0n,
    })),
    inventory: [],
    results: [],
    listings: [],
    claims: [],
    contracts: [],
    leaders: [],
    trades: [],
    tools: Object.entries(source.starter_tools).map(([skill, tool], index) => ({
      id: BigInt(index + 1),
      skill,
      itemKey: tool.item_key,
      name: tool.item_name,
      rarity: tool.rarity,
      bonuses: JSON.stringify(tool.bonuses),
      equipped: true,
      tierLevel: 0,
      durability: 100,
      maxDurability: 100,
      origin: "starter",
      upgrades: 0,
      tierUpgrades: 0,
      rarityAttempts: 0,
      rarityProgress: 0,
      marketFloorPrice: 50,
      marketCeilingPrice: 400,
      npcBuyPrice: 32,
    })),
  };
}
describe("ICHAA Evergather presentation contracts", () => {
  it("offers starter training inside a locked material source and stops at level ten", () => {
    const rows = fixture();
    const source = () => {
      const view = presentEvergather(rows, content);
      const sources = {
        recipes: view.crafting_recipes,
        activities: view.skill_activities,
      };
      const entry = sourcesForMaterial("smithing_1", sources, view.player).find(
        (source) => source.entry.key === "smithing_1",
      );
      return trainingSourceFor(entry, sources);
    };
    expect(source()).toMatchObject({
      action: "activity",
      ready: true,
      entry: { key: "training_smithing", skill_level: 1 },
    });
    rows.tools.find((tool) => tool.skill === "smithing").durability = 0;
    expect(source().ready).toBe(false);
    rows.skills.find((skill) => skill.skill === "smithing").experience = 1100n;
    expect(source()).toBeNull();
  });
  it.each([
    [0, 1, 0, 0],
    [249, 1, 249, 99],
    [250, 2, 0, 0],
    [479, 2, 229, 91],
    [500, 3, 0, 0],
  ])(
    "measures account progress within the current level at %i XP",
    (xp, level, earned, percent) => {
      const rows = fixture();
      rows.skills[0].experience = BigInt(xp);
      expect(presentEvergather(rows, content).progression).toMatchObject({
        account_level: level,
        account_experience_into_level: earned,
        account_level_experience_span: 250,
        account_progress_percent: percent,
      });
    },
  );

  it("keeps committed action labels and event feedback without gathering definitions", () => {
    const rows = fixture();
    rows.results = [
      {
        id: 1n,
        createdAt: { microsSinceUnixEpoch: 1000n, toDate: () => new Date(1) },
        kind: "gathering_actions",
        label: "Mine Copper Ore",
        skill: "mining",
        experience: 30n,
        gold: 3n,
        items: "[]",
        details: JSON.stringify({
          key: "mining_1",
          event: { label: "Meteorfall" },
        }),
      },
    ];
    const view = presentEvergather(rows, { ...content, gathering_actions: {} });
    expect(view.recent_actions[0]).toMatchObject({
      label: "Mine Copper Ore",
      event_label: "Meteorfall",
    });
  });
  it("keeps actions ready and contracts unlimited despite legacy timers and caps", () => {
    const rows = fixture();
    rows.skills.find((row) => row.skill === "trading").experience = 1100n;
    const now = Date.now();
    rows.player.nextActionAt = BigInt(now + 60000) * 1000n;
    rows.contracts = [
      {
        jobKey: "delivery_1",
        day: BigInt(Math.floor(now / 86400000)),
        completions: 100,
        active: false,
        progress: 0,
      },
    ];
    rows.inventory = [{ itemKey: "trading_1", quantity: 100n }];
    const view = presentEvergather(rows, content, now);
    expect(view.player).toMatchObject({
      next_action_at: null,
      can_act_now: true,
    });
    expect(
      [...view.actions, ...view.skill_activities].every(
        (action) => action.cooldown_seconds === 0,
      ),
    ).toBe(true);
    expect(view.jobs.find((job) => job.key === "delivery_1")).toMatchObject({
      completion_cap: null,
      remaining_completions: null,
      is_demand_available: true,
      can_complete: true,
    });
  });
  it("supplies all panel contracts, pacing numbers, and real skill unlock paths", () => {
    const view = presentEvergather(fixture(), content);
    expect(view.skills).toHaveLength(38);
    expect(view.actions).toHaveLength(70);
    expect(view.skill_activities).toHaveLength(128);
    expect(view.equipment).toHaveLength(38);
    expect(view.progression.achievements).toHaveLength(
      Object.keys(source.achievements).length,
    );
    expect(
      view.skills.every(
        (skill) => skill.activities.length && skill.unlocks.length,
      ),
    ).toBe(true);
    expect(view.skill_catalog.pacing.level_100_experience).toBe(170000);
    expect(view.skill_catalog.pacing.calibrated_experience_per_hour).toBe(1600);
    expect(view.player.appearance.palette).toBe("moonlit");
    expect(
      view.actions.find((action) => action.key === "fishing_1").equipped_tool
        .signature_trait,
    ).toBe("Tidehook Memory");
  });
  it("matches crafting guards and uses committed inventory without applying result deltas twice", () => {
    const rows = fixture();
    const recipeKey = "smelting_1";
    rows.skills.find((row) => row.skill === "smelting").experience = 1100n;
    const before = presentEvergather(rows, content).crafting_recipes.find(
      (recipe) => recipe.key === recipeKey,
    );
    expect(before.can_craft).toBe(false);
    rows.inventory = [
      {
        itemKey: "mining_1",
        name: "Copper Ore",
        rarity: "common",
        quantity: 2n,
      },
      {
        itemKey: "survival_1",
        name: "Ash Charcoal",
        rarity: "common",
        quantity: 1n,
      },
    ];
    expect(
      presentEvergather(rows, content).crafting_recipes.find(
        (recipe) => recipe.key === recipeKey,
      ).can_craft,
    ).toBe(true);
    rows.tools.find((tool) => tool.skill === "smelting").durability = 0;
    expect(
      presentEvergather(rows, content).crafting_recipes.find(
        (recipe) => recipe.key === recipeKey,
      ).can_craft,
    ).toBe(false);
    rows.tools.find((tool) => tool.skill === "smelting").durability = 100;
    rows.inventory[0].quantity = 0n;
    rows.results = [
      {
        id: 1n,
        kind: "crafting",
        label: source.crafting_recipes[recipeKey].label,
        skill: "smelting",
        experience: 28,
        gold: 0n,
        items: JSON.stringify(source.crafting_recipes[recipeKey].outputs),
        details: JSON.stringify({
          key: recipeKey,
          items_consumed: source.crafting_recipes[recipeKey].ingredients,
        }),
        createdAt: { microsSinceUnixEpoch: 1n, toDate: () => new Date(0) },
      },
    ];
    const view = presentEvergather(rows, content);
    expect(
      view.crafting_recipes.find((recipe) => recipe.key === recipeKey)
        .ingredients[0].owned_quantity,
    ).toBe(0);
    expect(view.last_result.recipe_key).toBe(recipeKey);
    expect(view.recent_actions).toEqual([]);
    expect(view.last_result.items_created[0].item_key).toBe("smelting_1");
    expect(rows.inventory[0].quantity).toBe(0n);
  });
  it("keeps unique tool ids intact and calculates listing ownership and payout", () => {
    const rows = fixture();
    const id = 9007199254740995n;
    rows.tools[0] = {
      ...rows.tools[0],
      id,
      origin: "crafted",
      equipped: false,
    };
    rows.listings = [
      {
        id,
        itemKey: "trading_1",
        name: "River Minnow",
        rarity: "common",
        seller: owner,
        sellerName: "Flow player",
        quantity: 2n,
        unitPrice: 12n,
        toolId: 0n,
        toolSnapshot: "",
      },
    ];
    const view = presentEvergather(rows, content);
    expect(view.marketplace.sellable_tools[0].tool_id).toBe(String(id));
    expect(view.marketplace.active_listings[0]).toMatchObject({
      id: String(id),
      total_price: 24,
      estimated_seller_payout: 22,
      is_mine: true,
      can_buy: false,
    });
  });
  it("maps owned materials to their actual sources, uses, and disposal paths", () => {
    const rows = fixture();
    rows.inventory = [
      {
        itemKey: "mining_1",
        name: "Copper Ore",
        rarity: "common",
        quantity: 3n,
      },
    ];
    const view = presentEvergather(rows, content);
    const item = view.item_guide.owned[0];
    expect(
      item.sources.some((source) => source.label === "Mine Copper Ore"),
    ).toBe(true);
    expect(
      item.sinks.some((sink) => sink.label === "Smelt Copper Ingots"),
    ).toBe(true);
    expect(
      item.sources.find((path) => path.label === "Mine Copper Ore"),
    ).toMatchObject({
      action: "gather",
      key: "mining_1",
    });
    expect(
      item.sinks.find((path) => path.label === "Smelt Copper Ingots"),
    ).toMatchObject({
      action: "craft",
      key: view.crafting_recipes.find(
        (recipe) => recipe.label === "Smelt Copper Ingots",
      ).key,
    });
    expect(item.best_fallback_sink.label).toBe("Ledger Steward");
    expect(item.total_weight).toBe(0.6);
  });
  it("keeps claimed reward titles separate from editable character titles", () => {
    const rows = fixture();
    rows.player.title = "Trailhand";
    rows.player.characterTitle = "Dockside artisan";
    rows.claims = [{ achievementKey: "first_steps", title: "Trailhand" }];
    const view = presentEvergather(rows, content);
    expect(view.player.title).toBe("Dockside artisan");
    expect(view.progression.reward_loadout.title_label).toBe("Trailhand");
    expect(view.progression.claimed_rewards[0]).toMatchObject({
      achievement_key: "first_steps",
      achievement_label: source.achievements.first_steps.label,
      reward: {
        title: "Trailhand",
        gold: source.achievements.first_steps.reward.gold,
      },
    });
    expect(
      view.progression.achievements.find(
        (entry) => entry.key === "first_steps",
      ),
    ).toMatchObject({ claimed: true, unlocked: true, can_claim: false });
  });
  it("updates cached catalog definitions when subscribed payloads change", () => {
    const cache = new Map();
    const row = {
      key: "skills:fishing",
      kind: "skills",
      payload: '{"label":"Fishing"}',
    };
    const initial = readCatalog([row], cache).skills.fishing;
    expect(readCatalog([row], cache).skills.fishing).toBe(initial);
    expect(
      readCatalog([{ ...row, payload: '{"label":"Fishing updated"}' }], cache)
        .skills.fishing.label,
    ).toBe("Fishing updated");
    expect(initial.label).toBe("Fishing");
  });
  it("finds live material sources and supports a processing recipe's raw ingredients", () => {
    const rows = fixture();
    rows.skills.find((row) => row.skill === "smelting").experience = 1100n;
    const getSources = (itemKey) => {
      const view = presentEvergather(rows, content);
      return sourcesForMaterial(
        itemKey,
        {
          actions: view.actions,
          activities: view.skill_activities,
          recipes: view.crafting_recipes,
          expeditions: view.expeditions,
          offers: view.shop.offers,
        },
        view.player,
      );
    };
    const ingot = () =>
      getSources("smelting_1").find(
        (entry) => entry.entry.key === "smelting_1",
      );
    expect(ingot()).toMatchObject({
      ready: false,
      label: "Process",
      blockedReason: "Need ingredients",
    });
    expect(ingot().requirements[0].item_key).toBe("mining_1");
    expect(
      getSources("mining_1").find((entry) => entry.entry.key === "mining_1"),
    ).toMatchObject({ action: "gather", ready: true });
    rows.inventory = [
      {
        itemKey: "mining_1",
        name: "Copper Ore",
        rarity: "common",
        quantity: 2n,
      },
      {
        itemKey: "survival_1",
        name: "Ash Charcoal",
        rarity: "common",
        quantity: 1n,
      },
    ];
    expect(ingot().ready).toBe(true);
    rows.tools.find((tool) => tool.skill === "smelting").durability = 0;
    expect(ingot()).toMatchObject({ ready: false });
    expect(ingot().blockedReason).toContain("Repair");
    // Gathering keeps its own tool guard and doesn't bypass it in the popup.
    rows.tools.find((tool) => tool.skill === "mining").durability = 0;
    expect(
      getSources("mining_1").find((entry) => entry.entry.key === "mining_1")
        .ready,
    ).toBe(false);
    expect(
      getSources("smelting_2").find((entry) => entry.action === "craft")
        .blockedReason,
    ).toBe("Need Smelting Lv 20");
    expect(
      getSources("tool_fishing_1").some((entry) => entry.action === "shop"),
    ).toBe(false);
    expect(materialOutputLabel({ quantity: 1, chance: 70 })).toBe(
      "1 per action · 70% chance",
    );
    expect(materialOutputLabel({ min: 0, max: 1 })).toBe("0–1 per action");
  });
  it("shows max level progress without inventing a next level", () => {
    expect(skillProgress("fishing", 170000, "Fishing")).toMatchObject({
      level: 100,
      next_level_experience: null,
      experience_to_next_level: 0,
    });
  });
  it("resolves rarity supplies through crafting while preserving gold and tier limits", () => {
    const rows = fixture();
    const rod = rows.tools.find((tool) => tool.skill === "fishing");
    rod.tierLevel = 20;
    const view = () => presentEvergather(rows, content);
    const upgrade = () =>
      view().tool_rarity_upgrades.options.find(
        (entry) => entry.slot === "tool_fishing",
      );
    expect(upgrade()).toMatchObject({
      is_tier_capped: false,
      can_upgrade: false,
      status: "Need Materials",
    });
    const snapshot = view();
    const [tonic] = sourcesForMaterial(
      upgrade().materials[0].item_key,
      { recipes: snapshot.crafting_recipes },
      snapshot.player,
    );
    expect(tonic).toMatchObject({
      action: "craft",
      label: "Craft",
      ready: false,
    });
    expect(tonic.requirements[0].item_key).toBe("foraging_2");
    rows.inventory = upgrade().materials.map((item) => ({
      itemKey: item.item_key,
      name: item.item_name,
      rarity: "common",
      quantity: BigInt(item.quantity),
    }));
    expect(upgrade().can_upgrade).toBe(true);
    rows.player.gold = 44n;
    expect(upgrade()).toMatchObject({ can_upgrade: false, status: "Need 45g" });
    rows.player.gold = 500n;
    rod.tierLevel = 10;
    expect(upgrade()).toMatchObject({
      can_upgrade: false,
      is_tier_capped: true,
      status: "Tier Up First",
    });
    rod.rarity = "mythic";
    expect(upgrade()).toMatchObject({
      can_upgrade: false,
      is_max_rarity: true,
    });
  });
});
