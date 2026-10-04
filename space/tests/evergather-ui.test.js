import { describe, expect, it } from "vitest";
import source from "../spacetimedb/src/content/evergather.json";
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
        label: "Mining",
        skill: "mining",
        experience: 30n,
        gold: 3n,
        items: "[]",
        details: JSON.stringify({
          key: "mine",
          event: { label: "Meteorfall" },
        }),
      },
    ];
    const view = presentEvergather(rows, { ...content, gathering_actions: {} });
    expect(view.recent_actions[0]).toMatchObject({
      label: "Mining",
      event_label: "Meteorfall",
    });
  });
  it("keeps actions ready and contracts unlimited despite legacy timers and caps", () => {
    const rows = fixture();
    const now = Date.now();
    rows.player.nextActionAt = BigInt(now + 60000) * 1000n;
    rows.contracts = [
      {
        jobKey: "fishing_starter_contract",
        day: BigInt(Math.floor(now / 86400000)),
        completions: 100,
        active: false,
        progress: 0,
      },
    ];
    rows.inventory = [{ itemKey: "river_minnow", quantity: 100n }];
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
    expect(
      view.jobs.find((job) => job.key === "fishing_starter_contract"),
    ).toMatchObject({
      completion_cap: null,
      remaining_completions: null,
      is_demand_available: true,
      can_complete: true,
    });
  });
  it("supplies all panel contracts, pacing numbers, and real skill unlock paths", () => {
    const view = presentEvergather(fixture(), content);
    expect(view.skills).toHaveLength(38);
    expect(view.actions).toHaveLength(91);
    expect(view.skill_activities).toHaveLength(310);
    expect(view.equipment).toHaveLength(38);
    expect(view.progression.achievements).toHaveLength(451);
    expect(
      view.skills.every(
        (skill) => skill.activities.length && skill.unlocks.length,
      ),
    ).toBe(true);
    expect(view.skill_catalog.pacing.level_100_experience).toBe(170000);
    expect(view.skill_catalog.pacing.calibrated_experience_per_hour).toBe(1600);
    expect(view.player.appearance.palette).toBe("moonlit");
    expect(
      view.actions.find((action) => action.key === "fish").equipped_tool
        .signature_trait,
    ).toBe("Tidehook Memory");
  });
  it("matches crafting guards and uses committed inventory without applying result deltas twice", () => {
    const rows = fixture();
    const recipeKey = "smelting_candlemark_ingot";
    const before = presentEvergather(rows, content).crafting_recipes.find(
      (recipe) => recipe.key === recipeKey,
    );
    expect(before.can_craft).toBe(false);
    rows.inventory = [
      { itemKey: "iron_ore", name: "Iron Ore", rarity: "common", quantity: 2n },
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
    expect(view.last_result.items_created[0].item_key).toBe(
      "smelting_candlemark_ingot",
    );
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
        itemKey: "river_minnow",
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
      { itemKey: "iron_ore", name: "Iron Ore", rarity: "common", quantity: 3n },
    ];
    const item = presentEvergather(rows, content).item_guide.owned[0];
    expect(item.sources.some((source) => source.label === "Mining")).toBe(true);
    expect(item.sinks.some((sink) => sink.label === "Candlemark Ingot")).toBe(
      true,
    );
    expect(item.best_fallback_sink.label).toBe("Ledger Steward");
    expect(item.total_weight).toBe(3.75);
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
  it("shows max level progress without inventing a next level", () => {
    expect(skillProgress("fishing", 170000, "Fishing")).toMatchObject({
      level: 100,
      next_level_experience: null,
      experience_to_next_level: 0,
    });
  });
});
