import { describe, it, expect } from "vitest";
import { calculateHuntingXp } from "../src/bitcraft-ui/huntingXp";
import {
  estimateGathering,
  gatheringDefaults,
} from "../src/bitcraft-ui/gatheringEstimates";
import {
  achievementUnlocked,
  type AchievementFacts,
} from "../spacetimedb/src/achievements";

describe("BitCraft calculator parity", () => {
  const levels = [
    { level: 37, xp: 227130 },
    { level: 38, xp: 253930 },
    { level: 39, xp: 283840 },
    { level: 40, xp: 317220 },
  ];
  const settings = {
    currentLevel: 37,
    targetLevel: 40,
    currentXp: 5080,
    killXp: 386.1,
    processingXp: 198,
    bonus: 0,
    xpMode: "base",
  };
  it("subtracts progress once and preserves all three hunting scenarios", () => {
    const result = calculateHuntingXp(levels, settings);
    expect(result.remainingXp).toBe(85010);
    expect(result.breakdown?.map((row) => row.xp)).toEqual([
      21720, 29910, 33380,
    ]);
    expect(result.scenarios?.map((row) => row.count)).toEqual([146, 430, 221]);
  });
  it("does not apply bonuses twice to observed XP", () => {
    const result = calculateHuntingXp(levels, {
      ...settings,
      xpMode: "observed",
      processingXp: 237.6,
      bonus: 20,
    });
    expect(result.scenarios?.[1].xp).toBe(237.6);
    expect(result.scenarios?.[1].count).toBe(358);
  });
  it("rejects incomplete thresholds and unsafe numerical counts", () => {
    expect(
      calculateHuntingXp(
        levels.filter((row) => row.level !== 38),
        settings,
      ).error,
    ).toBeTruthy();
    expect(
      calculateHuntingXp(levels, { ...settings, bonus: 1e308 }).error,
    ).toBeTruthy();
    expect(
      calculateHuntingXp(levels, { ...settings, currentXp: 26800 }).error,
    ).toBeTruthy();
  });
  it("extraction crit increases output without multiplying the XP roll", () => {
    const entry = {
      timeRequirement: 2,
      resource: { maxHealth: 1000, ignoreDamage: false },
      experiencePerProgress: { quantity: 2 },
      outputs: [{ quantity: 1, probability: 0.5 }],
      consumedItems: [],
    };
    const base = estimateGathering(entry, {
      ...gatheringDefaults,
      power: 10,
      gatheringSpeed: 0,
      minutes: 1,
    });
    const critical = estimateGathering(entry, {
      ...gatheringDefaults,
      power: 10,
      gatheringSpeed: 0,
      minutes: 1,
      critChance: 100,
      critMultiplier: 2,
    });
    expect(critical.primaryOutput).toBe(base.primaryOutput * 2);
    expect(critical.experience).toBe(base.experience);
  });
});
describe("server-owned achievement eligibility", () => {
  const facts: AchievementFacts = {
    metrics: {},
    gold: 0,
    inventory: [],
    skills: [{ skill: "fishing", experience: 0n }],
    equipped: 1,
    categories: { fishing: "Gathering" },
  };
  it("ignores eligibility flags from exported catalog fixtures", () => {
    expect(
      achievementUnlocked(
        "skill_milestone_fishing_100",
        {
          label: "Fishing mastery",
          skill: "fishing",
          level: 100,
          unlocked: true,
        },
        facts,
      ),
    ).toBe(false);
    expect(
      achievementUnlocked(
        "skill_milestone_fishing_1",
        { label: "Fishing start", skill: "fishing", level: 1 },
        facts,
      ),
    ).toBe(true);
  });
  it("uses persisted metrics rather than the truncated journal", () => {
    expect(
      achievementUnlocked(
        "field_legend",
        { label: "Field Legend" },
        { ...facts, metrics: { gathering_actions: 250 } },
      ),
    ).toBe(true);
  });
});
