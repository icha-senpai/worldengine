import { describe, expect, it } from "vitest";
import { withRelayCraftTimers } from "../src/bitcraft-ui/relay";
import { passiveTracker } from "../src/bitcraft-ui/trackers";
import {
  passiveDeadlines,
  passiveSecondsLeft,
  passiveCountdownLabel,
} from "../src/bitcraft-ui/passiveCountdown";

const start = Date.parse("2026-10-09T15:00:00Z");
const craft = {
  entityId: "1234567890123456789",
  recipeId: 11,
  craftCount: 1,
  totalActionsRequired: 1,
  craftedItem: [{ item_id: 7, quantity: 3 }],
};
const state = (status = "processing", micros = String(start * 1000)) => ({
  entity_id: craft.entityId,
  recipe_id: 11,
  status: { [status]: {} },
  timestamp: { __timestamp_micros_since_unix_epoch__: micros },
});
const tracker = (states, now = start + 30000) =>
  passiveTracker(
    {},
    withRelayCraftTimers({ craftResults: [craft] }, states),
    { 11: 120 },
    now,
  );

describe("Relay passive craft batch timers", () => {
  it("uses the live batch start and keeps its deadline across reloads", () => {
    const first = tracker([state()]);
    expect(first.crafts[0].startedAt).toBe("2026-10-09T15:00:00.000Z");
    expect(first.crafts[0].estimatedRemainingSeconds).toBe(90);
    expect(first.crafts[0].timerSource).toBe("relay-start");
    const finish = passiveDeadlines(first.groups, new Map(), start + 30000)
      .values()
      .next().value.finishesAt;
    const reloaded = passiveDeadlines(
      tracker([state()], start + 60000).groups,
      new Map(),
      start + 60000,
    )
      .values()
      .next().value.finishesAt;
    expect(reloaded).toBe(finish);
    expect(passiveSecondsLeft(reloaded, start + 60000)).toBe(60);
  });
  it("does not treat a queue timestamp as a processing start", () => {
    const queued = tracker([state("queued")]);
    expect(queued.crafts[0].startedAt).toBeNull();
    expect(queued.groups[0].finishesAt).toBeNull();
    expect(queued.groups[0].waitingCount).toBe(1);
    expect(passiveCountdownLabel(null, queued.groups[0].timerSource)).toBe(
      "Waiting to start",
    );
    expect(tracker([state()]).groups[0].waitingCount).toBe(0);
  });
  it("removes crafts confirmed complete by the live state", () => {
    expect(tracker([state("complete")]).crafts).toEqual([]);
  });
  it("keeps the running timer when an expired craft shares a group with queued crafts", () => {
    const pending = { ...craft, entityId: "pending" };
    const result = passiveTracker(
      {},
      withRelayCraftTimers({ craftResults: [pending, craft] }, [
        { ...state("queued"), entity_id: "pending" },
        state(),
      ]),
      { 11: 120 },
      start + 180000,
    );
    expect(result.groups[0].timerSource).toBe("relay-start");
    expect(result.groups[0].waitingCount).toBe(1);
    expect(result.groups[0].estimatedRemainingSeconds).toBe(0);
  });
  it("falls back when live timing is absent, invalid or belongs to another recipe", () => {
    for (const states of [
      [],
      [state("processing", "invalid")],
      [{ ...state(), recipe_id: 12 }],
    ]) {
      const row = tracker(states).crafts[0];
      expect(row.startedAt).toBeNull();
      expect(row.timerSource).toBe("recipe");
    }
  });
});
