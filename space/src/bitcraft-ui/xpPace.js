const MIN_SAMPLE_MS = 60000;
const WINDOW_MS = 120000;
const MAX_GAP_MS = 120000;
const XP_ACTIONS = new Set([
  "Craft",
  "Extract",
  "Attack",
  "Build",
  "Terraform",
  "PaveTile",
  "ConvertItems",
  "AbilityCustom",
  "Prospect",
  "PlacePillarShaping",
  "DestroyPillarShaping",
  "Deconstruct",
  "DestroyPaving",
  "RepairBuilding",
]);

// Store only the action evidence needed by the rate estimator, not provider rows.
export function paceActivity(activity, at, source) {
  if (!activity || activity.delayed) return { known: false };
  const actions = (activity.actions ?? []).filter(
    (a) =>
      !a.client_cancel &&
      a.action_type &&
      a.action_type !== "None" &&
      Number.isFinite(Number(a.ends_at_ms)) &&
      Number(a.ends_at_ms) >= at - 2000,
  );
  const working = actions.find((a) => XP_ACTIONS.has(a.action_type));
  return {
    known: true,
    signedOut: activity.signed_in === false,
    working: Boolean(working) && activity.signed_in !== false,
    moving:
      !working &&
      actions.some((a) =>
        ["PlayerMove", "DeployableMove"].includes(a.action_type),
      ) &&
      activity.signed_in !== false,
    resting: actions.some((a) => a.action_type === "Sleep"),
    key: working ? `${working.action_type}:${working.recipe_id ?? ""}` : "",
    kind: working?.action_type ?? "",
    skillId: source?.skillId == null ? null : String(source.skillId),
    startedAt: Number(working?.start_time_ms) || null,
    endsAt: Number(working?.ends_at_ms) || null,
    stamina:
      activity.stamina?.current == null
        ? null
        : Number(activity.stamina.current),
    staminaMax:
      activity.stamina?.max == null ? null : Number(activity.stamina.max),
  };
}

function median(values) {
  const sorted = [...values].sort((a, b) => a - b);
  return sorted.length ? sorted[Math.floor(sorted.length / 2)] : 0;
}
function valid(state) {
  return (
    state?.version === 1 &&
    Number.isFinite(state.at) &&
    state.xp &&
    state.skills &&
    Number.isFinite(state.observedMs) &&
    state.gains &&
    Object.values(state.skills).every(
      (s) =>
        s &&
        Array.isArray(s.cycles) &&
        Number.isFinite(s.pendingMs) &&
        Number.isFinite(s.idleMs) &&
        s.cycles.every(
          (c) =>
            Number.isFinite(c.ms) &&
            c.ms > 0 &&
            Number.isFinite(c.xp) &&
            c.xp > 0,
        ),
    )
  );
}

export function updateXpPace(previous, sample) {
  const activity = sample.paceActivity ?? { known: false };
  const sameSession = valid(previous) && previous.playerId === sample.playerId;
  const continuous =
    sameSession &&
    previous.sourceKey === sample.sourceKey &&
    sample.at - previous.at <= MAX_GAP_MS;
  if (continuous && sample.at <= previous.at) return previous;
  const state = continuous
    ? previous
    : {
        version: 1,
        playerId: sample.playerId,
        sourceKey: sample.sourceKey,
        at: sample.at,
        xp: sample.xp,
        skills: {},
        observedMs: sameSession ? previous.observedMs : 0,
        gains: sameSession ? { ...previous.gains } : {},
        activity,
      };
  if (!continuous) return state;
  const dt = sample.at - state.at;
  const skills = { ...state.skills },
    gains = { ...state.gains };
  for (const [id, value] of Object.entries(sample.xp)) {
    const before = state.xp[id];
    if (!Number.isFinite(before)) {
      delete skills[id];
      continue;
    }
    const delta = Math.max(0, value - before);
    gains[id] = (gains[id] ?? 0) + delta;
    const old = skills[id] ?? {
      cycles: [],
      pendingMs: 0,
      idleMs: 0,
      paused: false,
      lastGainAt: 0,
      key: "",
      kind: "",
      recovering: false,
    };
    let next = { ...old, cycles: [...old.cycles] };
    const matching = activity.skillId == null || activity.skillId === id;
    const working = activity.working && matching;
    const differentSkill = activity.working && !matching;
    const changed = working && next.key && activity.key !== next.key;
    if (changed) {
      // A new recipe/activity needs its own baseline; the transition award may
      // still belong to the previous action, so never time it as the new one.
      next = {
        ...next,
        cycles: [],
        pendingMs: 0,
        idleMs: 0,
        lastGainAt: 0,
        paused: true,
        recovering: false,
      };
    }
    if (working) {
      next.key = activity.key;
      next.kind = activity.kind;
    }
    const recovering =
      !working &&
      !activity.moving &&
      activity.known &&
      state.activity?.staminaMax > 0 &&
      state.activity.stamina / state.activity.staminaMax <= 0.35 &&
      Number.isFinite(activity.stamina) &&
      Number.isFinite(state.activity?.stamina) &&
      activity.stamina > state.activity.stamina;
    next.recovering =
      !working && !activity.moving && !delta && (recovering || next.recovering);
    const cadence = median(next.cycles.map((c) => c.ms));
    const grace =
      next.recovering || activity.resting
        ? 20000
        : activity.known && !activity.moving && next.kind === "Craft"
          ? 45000
          : Math.min(
              180000,
              Math.max(
                activity.known && !activity.moving ? 75000 : 120000,
                cadence * 2.5,
              ),
            );
    const wasPaused = next.paused;
    if (working && !changed) {
      next.paused = false;
      next.idleMs = 0;
      // First action after an idle period starts here, not at the previous gain.
      next.pendingMs = wasPaused
        ? Math.max(
            0,
            Math.min(dt, sample.at - (activity.startedAt ?? sample.at)),
          )
        : next.pendingMs + dt;
    } else if (!wasPaused) {
      next.pendingMs += dt;
      next.idleMs += dt;
    }
    if (delta) {
      if (
        !changed &&
        !differentSkill &&
        !wasPaused &&
        next.pendingMs > 0 &&
        (working || next.idleMs <= grace)
      ) {
        next.cycles.push({ ms: next.pendingMs, xp: delta });
        let elapsed = next.cycles.reduce((sum, c) => sum + c.ms, 0);
        while (
          next.cycles.length > 2 &&
          elapsed - next.cycles[0].ms >= WINDOW_MS
        )
          elapsed -= next.cycles.shift().ms;
        next.cycles = next.cycles.slice(-60);
      }
      // An unobserved restart award still counts toward session XP, but cannot
      // establish a speed. The next complete gain interval can.
      next.pendingMs = 0;
      next.idleMs = 0;
      next.paused = false;
      next.recovering = false;
      next.lastGainAt = sample.at;
    } else if (
      activity.signedOut ||
      differentSkill ||
      (!working && next.idleMs >= grace)
    ) {
      next.paused = true;
      next.pendingMs = 0;
    }
    skills[id] = next;
  }
  // Missing XP breaks that skill's continuity without disturbing other skills.
  for (const id of Object.keys(skills))
    if (!(id in sample.xp)) delete skills[id];
  return {
    ...state,
    at: sample.at,
    xp: sample.xp,
    activity,
    skills,
    gains,
    observedMs: state.observedMs + dt,
  };
}

export function skillPace(state, id) {
  const skill = state?.skills?.[id];
  const cycles = skill?.cycles ?? [];
  const ms = cycles.reduce((sum, c) => sum + c.ms, 0);
  const xpDelta = cycles.reduce((sum, c) => sum + c.xp, 0);
  const ready = ms >= MIN_SAMPLE_MS && cycles.length >= 2;
  return {
    xpDelta,
    hourRate: ready ? (xpDelta * 3600000) / ms : 0,
    minutesSampled: ready ? ms / 60000 : 0,
    paused: Boolean(skill?.paused),
    warming: !ready,
    overallHourRate:
      state?.observedMs >= MIN_SAMPLE_MS
        ? ((state.gains[id] ?? 0) * 3600000) / state.observedMs
        : null,
  };
}
