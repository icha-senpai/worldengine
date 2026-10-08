import { describe, it, expect } from "vitest";
import {
  updateXpSession,
  restoreXpSession,
  trackerActivity,
} from "../src/bitcraft-ui/xpSession";
const sample = (at, xp, sourceKey = "native-a", playerId = "20") => ({
  at,
  sourceKey,
  playerId,
  xp: { 1: xp },
});
describe("XP sessions", () => {
  const historySample = (at, xp, overrides = {}) => ({
    at,
    playerId: "20",
    sourceKey: "native-a",
    xpBySkill: { 1: xp },
    ...overrides,
  });

  it("catches up session gains after twenty minutes away without double counting history", () => {
    const baseline = updateXpSession(null, sample(0, 100));
    const history = Array.from({ length: 21 }, (_, minute) =>
      historySample(minute * 60000, 100 + minute * 10),
    );
    const current = sample(1200000, 300);
    const restored = restoreXpSession(baseline, current, history);
    expect(restored.gained).toBe(200);
    expect(restored.startedAt).toBe(0);
    expect(restoreXpSession(restored, current, history)).toBe(restored);
  });

  it("does not add pre-session history after starting, resetting, or changing sources", () => {
    const history = [
      historySample(0, 100),
      historySample(60000, 120),
      historySample(120000, 140),
    ];
    const current = sample(120000, 140);
    expect(restoreXpSession(null, current, history).gained).toBe(0);
    expect(
      restoreXpSession(
        { playerId: "20", sourceKey: "native-a", at: 0 },
        current,
        history,
      ).gained,
    ).toBe(0);
    const baseline = updateXpSession(null, sample(60000, 120));
    expect(restoreXpSession(baseline, current, history).gained).toBe(20);
    expect(
      restoreXpSession(baseline, sample(120000, 900, "api"), history).gained,
    ).toBe(0);
    expect(
      restoreXpSession(
        baseline,
        sample(120000, 900, "native-a", "other"),
        history,
      ).gained,
    ).toBe(0);
  });

  it("preserves the trusted baseline through backwards history and rebases real collector gaps", () => {
    const baseline = updateXpSession(null, sample(0, 100));
    const history = [
      historySample(60000, 50),
      historySample(120000, 100),
      historySample(180000, 120),
    ];
    expect(
      restoreXpSession(baseline, sample(180000, 120), history).gained,
    ).toBe(20);
    expect(
      restoreXpSession(baseline, sample(600000, 900), [
        historySample(540000, 800),
      ]).gained,
    ).toBe(100);
  });
  it("ignores malformed saved baselines", () => {
    expect(
      updateXpSession({ playerId: "20", at: 1000 }, sample(2000, 100)).gained,
    ).toBe(0);
  });
  it("counts only continuous gains and keeps the high-water mark across backwards observations", () => {
    let session = updateXpSession(null, sample(1000, 100));
    session = updateXpSession(session, sample(11000, 130));
    expect(session.gained).toBe(30);
    expect(session.activeSkillId).toBe("1");
    session = updateXpSession(session, sample(21000, 90));
    session = updateXpSession(session, sample(31000, 130));
    expect(session.gained).toBe(30);
    session = updateXpSession(session, sample(41000, 140));
    expect(session.gained).toBe(40);
  });
  it("rebases sources and reconnect gaps without inventing XP or elapsed work", () => {
    let session = updateXpSession(null, sample(1000, 100));
    session = updateXpSession(session, sample(11000, 110));
    session = updateXpSession(session, sample(21000, 900, "api"));
    expect(session.gained).toBe(10);
    expect(session.lastGainAt).toBe(0);
    session = updateXpSession(session, sample(200000, 1000, "api"));
    expect(session.gained).toBe(10);
    session = updateXpSession(session, sample(210000, 1010, "api"));
    expect(session.gained).toBe(20);
    expect(
      updateXpSession(session, sample(220000, 500, "api", "another")).gained,
    ).toBe(0);
  });
  it("ignores unknown and duplicate observations, and distinguishes last-known actions", () => {
    const session = updateXpSession(null, sample(1000, 100));
    expect(
      updateXpSession(session, { ...sample(2000, 200), xp: { 1: null } }),
    ).toBe(session);
    expect(updateXpSession(session, sample(1000, 999))).toBe(session);
    expect(
      trackerActivity({ delayed: true, signed_in: true }, 10000, 9999),
    ).toMatchObject({ active: false, known: false });
    expect(
      trackerActivity(
        {
          signed_in: false,
          actions: [{ action_type: "Craft", ends_at_ms: 20000 }],
        },
        10000,
      ),
    ).toMatchObject({ label: "Signed out", active: false });
    expect(
      trackerActivity(
        {
          signed_in: true,
          actions: [{ action_type: "Craft", ends_at_ms: 20000 }],
        },
        10000,
      ),
    ).toMatchObject({ active: true });
  });

  it("keeps Craft steady through polling gaps and snapshots between repeated crafts", () => {
    const scope = "20|native-a";
    const craft = (end) => ({
      signed_in: true,
      actions: [{ action_type: "Craft", ends_at_ms: end }],
    });
    let state = trackerActivity(craft(12000), 10000, 10000, null, scope);
    state = trackerActivity(craft(12000), 15000, 14000, state, scope);
    expect(state.label).toBe("Craft");
    state = trackerActivity(
      { signed_in: true, actions: [] },
      20000,
      19000,
      state,
      scope,
    );
    expect(state.label).toBe("Craft");
    state = trackerActivity(craft(34000), 30000, 29000, state, scope);
    expect(state.label).toBe("Craft");
  });

  it("lets the action grace expire without extending it on cached or empty polls", () => {
    const scope = "20|native-a";
    const craft = {
      signed_in: true,
      actions: [{ action_type: "Craft", ends_at_ms: 12000 }],
    };
    let state = trackerActivity(craft, 10000, 11000, null, scope);
    state = trackerActivity(craft, 25000, 11000, state, scope);
    state = trackerActivity(
      { signed_in: true, actions: [] },
      31000,
      11000,
      state,
      scope,
    );
    expect(state.label).toBe("Craft");
    state = trackerActivity(craft, 32000, 11000, state, scope);
    expect(state.label).toBe("Earning XP");
    expect(trackerActivity(craft, 42000, 11000, state, scope).label).toBe(
      "Idle",
    );
  });

  it("changes to a new action immediately and drops Craft on cancellation or sign-out", () => {
    const scope = "20|native-a";
    const craft = { action_type: "Craft", ends_at_ms: 12000 };
    const state = trackerActivity(
      { signed_in: true, actions: [craft] },
      10000,
      10000,
      null,
      scope,
    );
    expect(
      trackerActivity(
        {
          signed_in: true,
          actions: [craft, { action_type: "Gather", ends_at_ms: 20000 }],
        },
        15000,
        14000,
        state,
        scope,
      ).label,
    ).toBe("Gather");
    expect(
      trackerActivity(
        { signed_in: true, actions: [{ ...craft, client_cancel: true }] },
        15000,
        14000,
        state,
        scope,
      ).label,
    ).toBe("Earning XP");
    expect(
      trackerActivity({ signed_in: false }, 15000, 14000, state, scope).label,
    ).toBe("Signed out");
  });

  it("does not carry the previous action across player, source, or missing data changes", () => {
    const state = trackerActivity(
      {
        signed_in: true,
        actions: [{ action_type: "Craft", ends_at_ms: 12000 }],
      },
      10000,
      10000,
      null,
      "20|native-a",
    );
    for (const scope of ["another|native-a", "20|api"])
      expect(
        trackerActivity({ signed_in: true }, 15000, 14000, state, scope).label,
      ).toBe("Earning XP");
    expect(
      trackerActivity(null, 15000, 14000, state, "20|native-a").label,
    ).toBe("Earning XP");
    const delayed = trackerActivity(
      { delayed: true, signed_in: true },
      15000,
      14000,
      state,
      "20|native-a",
    );
    expect(delayed).toMatchObject({
      label: "Last known activity",
      active: false,
    });
    expect(
      trackerActivity({ signed_in: true }, 16000, 14000, delayed, "20|native-a")
        .label,
    ).toBe("Earning XP");
  });
});
