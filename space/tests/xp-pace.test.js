import { describe, expect, it } from "vitest";
import {
  paceActivity,
  skillPace,
  updateXpPace,
} from "../src/bitcraft-ui/xpPace";
import {
  updateXpSession,
  restoreXpSession,
} from "../src/bitcraft-ui/xpSession";

const sample = (seconds, xp, activity = { known: false }, overrides = {}) => ({
  at: seconds * 1000,
  playerId: "20",
  sourceKey: "relay-a",
  xp: { 1: xp },
  paceActivity: activity,
  ...overrides,
});
const work = (key = "Craft:1", overrides = {}) => ({
  known: true,
  working: true,
  key,
  kind: key.split(":")[0],
  ...overrides,
});
function baseline() {
  let state;
  for (let t = 0; t <= 180; t += 10)
    state = updateXpPace(state, sample(t, t * 10, work()));
  return state;
}

describe("pause-aware XP pace", () => {
  it("holds through a stamina break and resumes immediately without dragging idle time into the rate", () => {
    let state = baseline();
    expect(skillPace(state, "1").hourRate).toBe(36000);
    for (let t = 190; t <= 300; t += 10)
      state = updateXpPace(
        state,
        sample(t, 1800, { known: true, stamina: t - 190 }),
      );
    expect(skillPace(state, "1")).toMatchObject({
      hourRate: 36000,
      paused: true,
    });
    expect(skillPace(state, "1").overallHourRate).toBe(21600);
    for (let t = 310; t <= 340; t += 10)
      state = updateXpPace(state, sample(t, 1800 + (t - 300) * 10, work()));
    expect(skillPace(state, "1")).toMatchObject({
      hourRate: 36000,
      paused: false,
    });
  });

  it("detects a forgotten craft without waiting for stamina to change", () => {
    let state = baseline();
    for (let t = 190; t <= 250; t += 10)
      state = updateXpPace(state, sample(t, 1800, { known: true }));
    expect(skillPace(state, "1")).toMatchObject({
      paused: true,
      hourRate: 36000,
    });
  });

  it("pauses a stopped long craft instead of applying its long action duration as idle grace", () => {
    let state;
    for (let t = 0; t <= 360; t += 10)
      state = updateXpPace(state, sample(t, Math.floor(t / 180) * 180, work()));
    for (let t = 370; t <= 420; t += 10)
      state = updateXpPace(state, sample(t, 360, { known: true }));
    expect(skillPace(state, "1")).toMatchObject({
      hourRate: 3600,
      paused: true,
    });
  });

  it("includes movement between trees or rocks even when stamina regenerates", () => {
    let state = updateXpPace(null, sample(0, 0, work("Extract:2")));
    for (let t = 10; t <= 240; t += 10) {
      const gain = t % 60 === 0;
      state = updateXpPace(
        state,
        sample(
          t,
          Math.floor(t / 60) * 100,
          gain ? work("Extract:2") : { known: true, moving: true, stamina: t },
        ),
      );
    }
    expect(skillPace(state, "1")).toMatchObject({
      hourRate: 6000,
      paused: false,
    });
  });

  it.each(["Craft:1", "Extract:2"])(
    "does not mistake a long ongoing %s action for a pause",
    (key) => {
      let state;
      for (let t = 0; t <= 360; t += 10)
        state = updateXpPace(
          state,
          sample(
            t,
            Math.floor(t / 180) * 180,
            work(key, { endsAt: (Math.floor(t / 180) + 1) * 180000 }),
          ),
        );
      expect(skillPace(state, "1")).toMatchObject({
        hourRate: 3600,
        paused: false,
      });
    },
  );

  it("holds steady between fishing catches and includes their normal wait", () => {
    let state;
    for (let t = 0; t <= 270; t += 10)
      state = updateXpPace(state, sample(t, Math.floor(t / 90) * 100));
    expect(skillPace(state, "1")).toMatchObject({
      hourRate: 4000,
      paused: false,
    });
    const rate = skillPace(state, "1").hourRate;
    for (let t = 280; t <= 310; t += 10)
      state = updateXpPace(state, sample(t, 300));
    expect(skillPace(state, "1").hourRate).toBe(rate);
  });

  it("keeps a normal gathering gap with incidental stamina regeneration in the cycle", () => {
    let state;
    for (let t = 0; t <= 240; t += 10) {
      const activity =
        t % 60 === 0
          ? work("Extract:2", { stamina: 80, staminaMax: 100 })
          : { known: true, stamina: 80 + (t % 60) / 3, staminaMax: 100 };
      state = updateXpPace(
        state,
        sample(t, Math.floor(t / 60) * 100, activity),
      );
    }
    expect(skillPace(state, "1")).toMatchObject({
      hourRate: 6000,
      paused: false,
    });
  });

  it("recognises low-stamina recovery without treating sleep or food as a new skilling recipe", () => {
    let state = baseline();
    state = updateXpPace(
      state,
      sample(190, 1800, { known: true, stamina: 5, staminaMax: 100 }),
    );
    state = updateXpPace(
      state,
      sample(200, 1800, { known: true, stamina: 10, staminaMax: 100 }),
    );
    expect(skillPace(state, "1")).toMatchObject({
      hourRate: 36000,
      paused: true,
    });
    for (const action_type of ["Sleep", "UseItem", "StationaryEmote"])
      expect(
        paceActivity(
          { signed_in: true, actions: [{ action_type, ends_at_ms: 110000 }] },
          100000,
        ).working,
      ).toBe(false);
  });

  it("resamples a changed recipe instead of preserving the old recipe's speed", () => {
    let state = baseline();
    state = updateXpPace(state, sample(190, 1900, work("Craft:2")));
    expect(skillPace(state, "1")).toMatchObject({ hourRate: 0, warming: true });
    for (let t = 200; t <= 260; t += 10)
      state = updateXpPace(
        state,
        sample(t, 1900 + (t - 190) * 5, work("Craft:2")),
      );
    expect(skillPace(state, "1").hourRate).toBe(18000);
  });

  it("resets on player, source, missing XP and observation gaps, and ignores duplicate timestamps", () => {
    const state = baseline();
    expect(updateXpPace(state, sample(180, 999999, work()))).toBe(state);
    for (const change of [
      { playerId: "other" },
      { sourceKey: "relay-b" },
      { at: 400000 },
    ])
      expect(
        skillPace(updateXpPace(state, sample(190, 999999, work(), change)), "1")
          .hourRate,
      ).toBe(0);
    const missing = updateXpPace(state, sample(190, 0, work(), { xp: {} }));
    expect(
      skillPace(updateXpPace(missing, sample(200, 999999, work())), "1")
        .hourRate,
    ).toBe(0);
  });

  it("uses current action evidence even when collector history contains the same timestamp", () => {
    let session = updateXpSession(null, sample(0, 0));
    const current = sample(10000 / 1000, 100, work());
    session = restoreXpSession(session, current, [
      {
        at: current.at,
        playerId: "20",
        sourceKey: "relay-a",
        xpBySkill: { 1: 100 },
      },
    ]);
    expect(session.pace.activity.working).toBe(true);
    expect(session.gained).toBe(100);
  });

  it("keeps backwards XP and newly known lifetime totals out of both rates", () => {
    let session;
    for (let t = 0; t <= 180; t += 10)
      session = updateXpSession(session, sample(t, t * 10, work()));
    session = updateXpSession(session, sample(190, 100, work()));
    session = updateXpSession(
      session,
      sample(200, 1800, work(), { xp: { 1: 1800, 2: 900000 } }),
    );
    expect(session.gained).toBe(1800);
    expect(session.pace.gains["2"] ?? 0).toBe(0);
  });

  it("does not let cancelled, expired, signed-out or delayed actions prove active work", () => {
    for (const row of [
      { delayed: true },
      { signed_in: false },
      { actions: [{ action_type: "Craft", ends_at_ms: 10000 }] },
      {
        actions: [
          { action_type: "Craft", ends_at_ms: 999999, client_cancel: true },
        ],
      },
    ])
      expect(paceActivity(row, 100000).working ?? false).toBe(false);
    expect(
      paceActivity(
        {
          signed_in: true,
          actions: [{ action_type: "PlayerMove", ends_at_ms: 110000 }],
        },
        100000,
      ),
    ).toMatchObject({ moving: true, working: false });
  });
});
