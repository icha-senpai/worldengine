import { describe, expect, it } from "vitest";
import {
  calibrateLureSession,
  calculateHuntingXp,
  huntingDefaults,
} from "../src/bitcraft-ui/huntingXp.js";

const levels = [
  { level: 1, xp: 0 },
  { level: 2, xp: 10000 },
];
const settings = {
  ...huntingDefaults,
  currentLevel: 1,
  currentXp: 0,
  targetLevel: 2,
  method: "lure",
  lureAnimals: 10,
  lureXp: 1000,
  lureGapSeconds: 30,
  processingXp: 100,
  bonus: 20,
};

describe("lure hunting planning", () => {
  it("calibrates personal yield and XP without rounding away fractional averages", () => {
    expect(calibrateLureSession(3, 1000, 20)).toEqual({
      error: null,
      lureXp: 1000 / 3,
      lureAnimals: 20 / 3,
    });
    for (const args of [
      [0, 100, 10],
      [1.5, 100, 10],
      [1, -1, 10],
      [1, 100, 0],
      [1, 100, 1.5],
      ["", 100, 10],
      [1, Infinity, 10],
    ]) {
      expect(calibrateLureSession(...args).error).toBeTruthy();
    }
  });
  it("applies bonuses only to processing and rounds to full lures", () => {
    const result = calculateHuntingXp(levels, settings);
    expect(result.error).toBeNull();
    const [both, processing, hunting] = result.scenarios;
    expect(both.xpPerLure).toBe(2200);
    expect(both.lures).toBe(5);
    expect(both.carcasses).toBe(50);
    expect(both.huntingSeconds).toBe(720); // Five lures plus four gaps.
    expect(processing.xpPerLure).toBe(1200);
    expect(processing.lures).toBe(9);
    expect(hunting.xpPerLure).toBe(1000);
    expect(hunting.lures).toBe(10);
  });
  it("keeps observed processing XP unchanged and ignores the wild kill preset", () => {
    const result = calculateHuntingXp(levels, {
      ...settings,
      xpMode: "observed",
      killXp: 99999,
    });
    expect(result.scenarios[0].xpPerLure).toBe(2000);
    expect(result.scenarios[2].xpPerLure).toBe(1000);
  });
  it("handles zero gains and completed goals without invented time or yield", () => {
    const zero = calculateHuntingXp(levels, {
      ...settings,
      lureXp: 0,
      processingXp: 0,
    });
    expect(
      zero.scenarios.every(
        (row) =>
          row.lures === null &&
          row.carcasses === null &&
          row.huntingSeconds === null,
      ),
    ).toBe(true);
    const complete = calculateHuntingXp(levels, {
      ...settings,
      targetLevel: 1,
    });
    expect(
      complete.scenarios.every(
        (row) =>
          row.lures === 0 && row.carcasses === 0 && row.huntingSeconds === 0,
      ),
    ).toBe(true);
    const one = calculateHuntingXp(levels, { ...settings, lureXp: 10000 });
    expect(one.scenarios[2].huntingSeconds).toBe(120);
  });
  it("rejects unknown yield, negative gaps, missing measurements, and unsafe estimates", () => {
    for (const change of [
      { lureAnimals: 0 },
      { lureAnimals: "" },
      { lureXp: "" },
      { lureGapSeconds: -1 },
      { lureXp: Infinity },
      { lureAnimals: 1e308 },
    ]) {
      expect(
        calculateHuntingXp(levels, { ...settings, ...change }).error,
      ).toBeTruthy();
    }
  });
});
