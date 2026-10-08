function validXpSession(session) {
  return (
    session &&
    Number.isFinite(session.at) &&
    Number.isFinite(session.startedAt) &&
    Number.isFinite(session.gained) &&
    session.gained >= 0 &&
    Number.isFinite(session.activeMs) &&
    session.activeMs >= 0 &&
    session.xp &&
    typeof session.xp === "object" &&
    Object.values(session.xp).every(
      (value) => Number.isFinite(value) && value >= 0,
    )
  );
}

// Session totals come only from continuous, same-source observations.
export function updateXpSession(previous, sample) {
  if (!validXpSession(previous)) previous = null;
  const xp = Object.fromEntries(
    Object.entries(sample.xp ?? {})
      .filter(
        ([, value]) =>
          value != null && Number.isFinite(Number(value)) && Number(value) >= 0,
      )
      .map(([id, value]) => [id, Number(value)]),
  );
  if (
    !sample.playerId ||
    !sample.sourceKey ||
    !Number.isFinite(sample.at) ||
    !Object.keys(xp).length
  )
    return previous;
  const samePlayer = previous?.playerId === sample.playerId;
  const base = samePlayer
    ? previous
    : {
        playerId: sample.playerId,
        startedAt: sample.at,
        gained: 0,
        activeMs: 0,
        lastGainAt: 0,
        activeSkillId: null,
      };
  if (samePlayer && sample.at <= previous.at) return previous;
  const continuous =
    samePlayer &&
    previous.sourceKey === sample.sourceKey &&
    sample.at - previous.at <= 120000;
  let gained = 0,
    best = 0,
    skill = base.activeSkillId;
  const trusted = { ...xp };
  if (continuous)
    for (const [id, value] of Object.entries(xp)) {
      const before = previous.xp[id];
      if (before === undefined) continue;
      trusted[id] = Math.max(value, before);
      const delta = Math.max(0, value - before);
      gained += delta;
      if (delta > best) {
        best = delta;
        skill = id;
      }
    }
  return {
    ...base,
    at: sample.at,
    sourceKey: sample.sourceKey,
    xp: trusted,
    gained: base.gained + gained,
    activeMs:
      base.activeMs +
      (continuous &&
      previous.lastGainAt &&
      previous.at - previous.lastGainAt <= 30000
        ? Math.min(sample.at - previous.at, 30000)
        : 0),
    lastGainAt: best ? sample.at : continuous ? base.lastGainAt : 0,
    activeSkillId: continuous || best ? skill : null,
    continuous: Boolean(continuous),
  };
}
export function restoreXpSession(previous, current, history = []) {
  // History fills only an existing session, never gains from before opening/reset.
  if (!validXpSession(previous)) previous = null;
  let session = previous;
  if (
    previous?.playerId === current.playerId &&
    previous?.sourceKey === current.sourceKey
  ) {
    for (const sample of [...history].sort((a, b) => a.at - b.at)) {
      if (
        sample.playerId === current.playerId &&
        sample.sourceKey === current.sourceKey &&
        sample.at > session.at &&
        sample.at <= current.at
      )
        session = updateXpSession(session, { ...sample, xp: sample.xpBySkill });
    }
  }
  return updateXpSession(session, current);
}

// Bridge two ten-second polls, including snapshots taken between repeated actions.
// Anchor the grace to the action's end so repeated cached reads cannot extend it.
const ACTION_GRACE_MS = 20000;

export function trackerActivity(
  activity,
  at,
  lastGainAt = 0,
  previous = null,
  scopeKey = "",
) {
  if (activity?.delayed)
    return { label: "Last known activity", active: false, known: false };
  if (activity?.signed_in === false)
    return { label: "Signed out", active: false, known: true };
  const actions = activity?.actions ?? [];
  const namedActions = actions.filter(
    (a) =>
      a.action_type &&
      a.action_type !== "None" &&
      !a.client_cancel &&
      Number.isFinite(Number(a.ends_at_ms)),
  );
  // A current action takes precedence over a previous action still in its grace.
  const action =
    namedActions.find((a) => Number(a.ends_at_ms) > at - 2000) ??
    namedActions.find((a) => Number(a.ends_at_ms) + ACTION_GRACE_MS > at);
  if (action)
    return {
      label: String(action.action_type).replace(/([a-z])([A-Z])/g, "$1 $2"),
      active: true,
      known: true,
      actionExpiresAt: Number(action.ends_at_ms) + ACTION_GRACE_MS,
      scopeKey,
    };
  if (
    activity &&
    !actions.some((a) => a.client_cancel) &&
    previous?.scopeKey === scopeKey &&
    previous.actionExpiresAt > at
  )
    return previous;
  if (lastGainAt && at - lastGainAt <= 30000)
    return { label: "Earning XP", active: true, known: true };
  return {
    label: lastGainAt ? "Idle" : "Watching for XP",
    active: false,
    known: Boolean(lastGainAt),
  };
}
