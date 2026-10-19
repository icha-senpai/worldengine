import { describe, expect, it } from "vitest";
import {
  advanceDailyXp,
  centralDay,
  dailyXpSummary,
  type DailyXp,
} from "../bitcraft/src/daily-xp";
const at = (value: string) => Date.parse(value);
describe("Central calendar XP", () => {
  it("uses Central midnight across both DST transitions", () => {
    expect(centralDay(at("2026-03-08T05:59:59Z"))).toBe("2026-03-07");
    expect(centralDay(at("2026-03-08T06:00:00Z"))).toBe("2026-03-08");
    expect(centralDay(at("2026-03-09T05:00:00Z"))).toBe("2026-03-09");
    expect(centralDay(at("2026-11-01T05:00:00Z"))).toBe("2026-11-01");
    expect(centralDay(at("2026-11-02T05:59:59Z"))).toBe("2026-11-01");
    expect(centralDay(at("2026-11-02T06:00:00Z"))).toBe("2026-11-02");
  });
  it("starts partial and splits boundary gains without counting them twice", () => {
    const first = advanceDailyXp(undefined, at("2026-10-18T04:59:20Z"), {
      "12": 100,
    })!.current;
    const next = advanceDailyXp(first, at("2026-10-18T05:00:20Z"), {
      "12": 120,
    })!;
    expect(next.finished).toMatchObject({
      endCovered: true,
      startCovered: false,
      closing: { "12": 120 },
    });
    expect(next.current).toMatchObject({
      startCovered: true,
      opening: { "12": 120 },
    });
    const last = advanceDailyXp(next.current, at("2026-10-18T06:00:00Z"), {
      "12": 150,
    })!.current;
    const result = dailyXpSummary(
      [next.finished!, last],
      at("2026-10-18T06:00:00Z"),
    );
    expect(result.periods[0]).toMatchObject({
      partial: false,
      xp: { "12": 30 },
    });
    expect(result.periods[1]).toMatchObject({
      partial: true,
      xp: { "12": 20 },
    });
    expect(result.periods[2].xp).toEqual({ "12": 50 });
  });
  it("does not attribute gains across an unobserved midnight", () => {
    const first = advanceDailyXp(undefined, at("2026-10-17T18:00:00Z"), {
      "12": 100,
    })!.current;
    const next = advanceDailyXp(first, at("2026-10-18T18:00:00Z"), {
      "12": 1000,
    })!;
    expect(next.finished).toBeUndefined();
    expect(next.current.startCovered).toBe(false);
    expect(
      dailyXpSummary([first, next.current], at("2026-10-18T18:00:00Z"))
        .periods[2],
    ).toMatchObject({ partial: true, xp: { "12": 0 } });
  });
  it("rejects older, incomplete, or decreased XP after a collector reconnect", () => {
    const previous = advanceDailyXp(undefined, at("2026-10-18T18:00:00Z"), {
      "12": 100,
      "6": 50,
    })!.current;
    expect(
      advanceDailyXp(previous, previous.lastAt - 1, { "12": 110, "6": 50 }),
    ).toBeNull();
    expect(
      advanceDailyXp(previous, previous.lastAt + 60000, { "12": 99, "6": 50 }),
    ).toBeNull();
    expect(
      advanceDailyXp(previous, previous.lastAt + 60000, { "12": 110 }),
    ).toBeNull();
    expect(
      advanceDailyXp(previous, previous.lastAt + 120000, { "12": 120, "6": 55 })
        ?.current.opening,
    ).toEqual({ "12": 100, "6": 50 });
  });
  it("starts weeks on Monday and reports missing days instead of fabricated zeros", () => {
    const row: DailyXp = {
      day: "2026-10-19",
      firstAt: at("2026-10-19T05:00:00Z"),
      lastAt: at("2026-10-19T06:00:00Z"),
      opening: { "6": 100 },
      closing: { "6": 200 },
      startCovered: true,
      endCovered: false,
    };
    const result = dailyXpSummary([row], row.lastAt);
    expect(result.periods[2]).toMatchObject({
      start: "2026-10-19",
      partial: false,
      xp: { "6": 100 },
    });
    expect(result.periods[1]).toMatchObject({
      available: false,
      partial: true,
    });
  });
});
