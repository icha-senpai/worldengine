import { describe, expect, it } from "vitest";
import {
  activitySkillRate,
  appendActivitySample,
  normalizeActivitySamples,
} from "../src/bitcraft-ui/activitySamples";
import { activityTracker } from "../src/bitcraft-ui/trackers";

const sample = (at, xpBySkill, overrides = {}) => ({
  at,
  xpBySkill,
  playerId: "icha",
  sourceKey: "primary|player/icha",
  ...overrides,
});

describe("XP tracker sample continuity", () => {
  it("measures gains over a minute and uses the oldest baseline within five minutes", () => {
    const samples = [
      sample(0, { 3: 1000 }),
      sample(60000, { 3: 1100 }),
      sample(300000, { 3: 1500 }),
    ];
    expect(activitySkillRate(samples, "3")).toEqual({
      xpDelta: 500,
      hourRate: 6000,
      minutesSampled: 5,
    });
    expect(activitySkillRate(samples.slice(0, 1), "3").hourRate).toBe(0);
    expect(
      activitySkillRate([samples[0], sample(59000, { 3: 1100 })], "3").hourRate,
    ).toBe(0);
    expect(
      activitySkillRate([samples[0], sample(360000, { 3: 1500 })], "3")
        .hourRate,
    ).toBe(0);
  });

  it("does not count lifetime XP when a previously missing skill arrives", () => {
    const samples = [
      sample(0, { 3: 1000 }),
      sample(60000, { 3: 1100, 12: 8398300 }),
    ];
    expect(activitySkillRate(samples, "12").hourRate).toBe(0);
    expect(activitySkillRate(samples, "3").hourRate).toBe(6000);
    samples.push(sample(120000, { 3: 1200, 12: 8398400 }));
    expect(activitySkillRate(samples, "12").hourRate).toBe(6000);
  });

  it("restarts a skill baseline after a missing intermediate observation", () => {
    const samples = [
      sample(0, { 3: 1000 }),
      sample(60000, { 12: 500 }),
      sample(120000, { 3: 1100 }),
    ];
    expect(activitySkillRate(samples, "3").hourRate).toBe(0);
  });

  it.each([
    { playerId: "someone-else" },
    { sourceKey: "fallback|player/icha" },
  ])("restarts when player or provider identity changes: %j", (overrides) => {
    const before = [sample(0, { 3: 1000 })];
    const next = sample(60000, { 3: 2204065 }, overrides);
    const result = appendActivitySample(before, next, 60000);
    expect(result).toEqual([next]);
    expect(activitySkillRate(result, "3").hourRate).toBe(0);
    expect(activitySkillRate([...before, next], "3").hourRate).toBe(0);
  });

  it("resets after XP rolls backward so its recovery is not counted as a gain", () => {
    let samples = [sample(0, { 3: 2204065 })];
    samples = appendActivitySample(samples, sample(60000, { 3: 1000 }), 60000);
    samples = appendActivitySample(
      samples,
      sample(90000, { 3: 2204065 }),
      90000,
    );
    expect(samples).toHaveLength(2);
    expect(activitySkillRate(samples, "3").hourRate).toBe(0);
    samples = appendActivitySample(
      samples,
      sample(180000, { 3: 2204165 }),
      180000,
    );
    expect(activitySkillRate(samples, "3").hourRate).toBe(2000);
  });

  it("ignores duplicate, older, invalid and future sample times", () => {
    const samples = [sample(60000, { 3: 1000 })];
    for (const at of [0, 60000, NaN, 120001]) {
      expect(
        appendActivitySample(samples, sample(at, { 3: 9999999 }), 120000),
      ).toBe(samples);
    }
    const next = sample(120000, { 3: 1100 });
    expect(appendActivitySample(samples, next, 120000)).toEqual([
      ...samples,
      next,
    ]);
  });

  it("discards old unscoped storage and invalid XP without inventing zero baselines", () => {
    const old = { at: 60000, xpBySkill: { 3: 0 } };
    const current = sample(60000, {
      3: null,
      12: Infinity,
      4: -1,
      2: "123",
      5: 0,
    });
    expect(
      normalizeActivitySamples(
        [old, current, sample(120001, { 3: 1000 })],
        120000,
      ),
    ).toEqual([sample(60000, { 5: 0 })]);
    expect(normalizeActivitySamples([sample(0, { 3: 1000 })], 360001)).toEqual(
      [],
    );
  });

  it("distinguishes unreported or malformed XP from an explicitly reported zero", () => {
    const tracker = activityTracker(
      {
        experience: [
          { skill_id: 3, quantity: 0 },
          { skill_id: 12, quantity: null },
        ],
      },
      [
        { id: 3, name: "Carpentry" },
        { id: 12, name: "Fishing" },
        { id: 2, name: "Forestry" },
      ],
      [],
      { skill: "all" },
    );
    expect(tracker.skills.map(({ xp, xpKnown }) => ({ xp, xpKnown }))).toEqual([
      { xp: 0, xpKnown: true },
      { xp: 0, xpKnown: false },
      { xp: 0, xpKnown: false },
    ]);
  });
});
