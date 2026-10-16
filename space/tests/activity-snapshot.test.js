import { describe, expect, it } from "vitest";
import { retainActivityTracker } from "../src/bitcraft-ui/activitySnapshot";

const previous = {
  player: { entityId: "1" },
  skills: [{ xp: 100 }],
  activity: {
    signed_in: true,
    stamina: { current: 40, max: 100 },
    delayed: false,
  },
};
describe("XP tracker recovery", () => {
  it("keeps activity and stamina marked stale when a supplemental read fails", () => {
    const current = {
      player: { entityId: "1" },
      skills: [{ xp: 110 }],
      activity: null,
    };
    const retained = retainActivityTracker(previous, current);
    expect(retained.skills[0].xp).toBe(110);
    expect(retained.activity).toEqual({ ...previous.activity, delayed: true });
    expect(previous.activity.delayed).toBe(false);
    expect(current.activity).toBeNull();
  });
  it("keeps the last successful tracker during a complete failed snapshot", () => {
    const retained = retainActivityTracker(previous, null);
    expect(retained.skills).toBe(previous.skills);
    expect(retained.activity.stamina.current).toBe(40);
    expect(retained.activity.delayed).toBe(true);
    expect(retainActivityTracker(null, null)).toBeNull();
  });
  it("never carries another character's status or overwrites a fresh signed-out observation", () => {
    const other = { player: { entityId: "2" }, activity: null };
    expect(retainActivityTracker(previous, other)).toBe(other);
    const fresh = {
      ...previous,
      activity: { signed_in: false, stamina: null, delayed: false },
    };
    expect(retainActivityTracker(previous, fresh)).toBe(fresh);
  });
});
