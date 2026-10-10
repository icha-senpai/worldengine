import { describe, expect, it } from "vitest";
import {
  passiveDeadlines,
  passiveSecondsLeft,
  passiveCountdownLabel,
} from "../src/bitcraft-ui/passiveCountdown";

const group = {
  key: "item:7|claim",
  timerSource: "recipe",
  estimatedRemainingSeconds: 180,
  estimatedTotalSeconds: 180,
  progress: 0,
  totalQueued: 3,
  craftsCount: 1,
};
const now = Date.parse("2026-10-09T12:00:00Z");
describe("passive craft countdowns", () => {
  it("ticks between snapshots and does not restart an unchanged recipe estimate", () => {
    const first = passiveDeadlines([group], new Map(), now);
    expect(
      passiveSecondsLeft(first.get(group.key).finishesAt, now + 15000),
    ).toBe(165);
    const refreshed = passiveDeadlines([{ ...group }], first, now + 15000);
    expect(refreshed.get(group.key).finishesAt).toBe(now + 180000);
    expect(
      passiveSecondsLeft(refreshed.get(group.key).finishesAt, now + 60000),
    ).toBe(120);
  });
  it("prefers an absolute finish time over a stale remaining estimate", () => {
    const deadlines = passiveDeadlines(
      [{ ...group, finishesAt: "2026-10-09T12:02:00Z" }],
      new Map(),
      now,
    );
    expect(
      passiveSecondsLeft(deadlines.get(group.key).finishesAt, now + 30000),
    ).toBe(90);
  });
  it("accepts revised estimates and removes anchors for crafts no longer queued", () => {
    const first = passiveDeadlines([group], new Map(), now);
    const revised = passiveDeadlines(
      [{ ...group, estimatedRemainingSeconds: 60, progress: 120 }],
      first,
      now + 30000,
    );
    expect(revised.get(group.key).finishesAt).toBe(now + 90000);
    expect(passiveDeadlines([], revised, now + 31000).size).toBe(0);
  });
  it("never turns missing or invalid estimates into completed crafts", () => {
    for (const estimatedRemainingSeconds of [null, undefined, NaN, -1]) {
      const deadlines = passiveDeadlines(
        [{ ...group, estimatedRemainingSeconds }],
        new Map(),
        now,
      );
      expect(passiveSecondsLeft(deadlines.get(group.key).finishesAt, now)).toBe(
        null,
      );
    }
    expect(passiveCountdownLabel(null, "recipe")).toBe("Timer unavailable");
    expect(passiveSecondsLeft(now, now + 10000)).toBe(0);
    expect(passiveCountdownLabel(0, "recipe")).toBe("Estimate reached");
  });
  it("shows seconds through minute, hour, and day boundaries", () => {
    expect(passiveCountdownLabel(61, "recipe")).toBe("up to 1m 1s left");
    expect(passiveCountdownLabel(3600, "api")).toBe("~1h 0m 0s left");
    expect(passiveCountdownLabel(86401, "relay")).toBe("~1d 0h 0m 1s left");
  });
});
