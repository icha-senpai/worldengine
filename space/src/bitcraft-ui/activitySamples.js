export const MIN_RATE_SAMPLE_MS = 60 * 1000;
export const RATE_SAMPLE_MS = 5 * 60 * 1000;
export const SAMPLE_WINDOW_MS = RATE_SAMPLE_MS + MIN_RATE_SAMPLE_MS;

export function collectedActivitySamples(
  history,
  playerId,
  sourceKey,
  now = Date.now(),
) {
  if (!history?.fresh || !playerId || !sourceKey) return [];
  const scope = `relaySkills|player/${playerId}/skills`;
  return normalizeActivitySamples(
    (history.samples ?? [])
      .filter(
        (row) => `collection|${row.source}|${row.epoch}|${scope}` === sourceKey,
      )
      .map((row) => ({
        at: row.at,
        playerId: String(playerId),
        sourceKey,
        xpBySkill: row.xp,
      })),
    now,
    6 * 60 * 60 * 1000,
  );
}

export function normalizeActivitySamples(
  value,
  now = Date.now(),
  windowMs = SAMPLE_WINDOW_MS,
) {
  if (!Array.isArray(value)) return [];
  return value
    .filter(
      (sample) =>
        Number.isFinite(sample?.at) &&
        sample.at <= now &&
        now - sample.at <= windowMs &&
        typeof sample.playerId === "string" &&
        sample.playerId &&
        typeof sample.sourceKey === "string" &&
        sample.sourceKey &&
        sample.xpBySkill &&
        typeof sample.xpBySkill === "object",
    )
    .map((sample) => ({
      ...sample,
      xpBySkill: Object.fromEntries(
        Object.entries(sample.xpBySkill).filter(
          ([, xp]) => Number.isFinite(xp) && xp >= 0,
        ),
      ),
    }))
    .sort((a, b) => a.at - b.at);
}

export function restoreActivitySamples(
  samples,
  history,
  current,
  now = Date.now(),
) {
  const next = normalizeActivitySamples([current], now)[0];
  if (!next || !Object.keys(next.xpBySkill).length) return samples;
  const previous = samples.at(-1);
  if (
    previous?.playerId === next.playerId &&
    previous?.sourceKey === next.sourceKey &&
    next.at < previous.at
  )
    return samples;
  const merged = new Map();
  for (const sample of normalizeActivitySamples(
    [...history, next, ...samples],
    now,
  )) {
    if (
      sample.playerId === next.playerId &&
      sample.sourceKey === next.sourceKey
    )
      merged.set(sample.at, sample);
  }
  return [...merged.values()]
    .filter((sample) => sample.at <= next.at)
    .sort((a, b) => a.at - b.at)
    .reduce((result, sample) => appendActivitySample(result, sample, now), []);
}

export function appendActivitySample(samples, sample, now = Date.now()) {
  const next = normalizeActivitySamples([sample], now)[0];
  if (!next || !Object.keys(next.xpBySkill).length) return samples;
  const previous = samples.at(-1);
  if (!previous) return [next];
  // A different player or provider has a different baseline, even at the same time.
  if (
    previous.playerId !== next.playerId ||
    previous.sourceKey !== next.sourceKey
  )
    return [next];
  // Cached or out-of-order responses are not new observations.
  if (next.at <= previous.at) return samples;
  if (next.at - previous.at > 120000) return [next];
  // Keep the last trusted XP baseline; rebasing on a lower total would count
  // its recovery as new XP on the next refresh.
  if (
    Object.entries(next.xpBySkill).some(
      ([id, xp]) =>
        Number.isFinite(previous.xpBySkill[id]) && xp < previous.xpBySkill[id],
    )
  )
    return [previous];
  return [...samples, next].filter(
    (item) => next.at - item.at <= SAMPLE_WINDOW_MS,
  );
}

export function activitySkillRate(samples, skillId) {
  const last = samples.at(-1);
  const empty = { xpDelta: 0, hourRate: 0, minutesSampled: 0 };
  if (!last || !Number.isFinite(last.xpBySkill[skillId])) return empty;
  // Only compare a continuous run of known XP from the same player and source.
  let start = samples.length - 1;
  while (start > 0) {
    const previous = samples[start - 1];
    const current = samples[start];
    if (
      previous.playerId !== last.playerId ||
      previous.sourceKey !== last.sourceKey ||
      !Number.isFinite(previous.xpBySkill[skillId]) ||
      previous.xpBySkill[skillId] > current.xpBySkill[skillId] ||
      previous.at >= current.at ||
      current.at - previous.at > 120000
    )
      break;
    start--;
  }
  const first = samples.slice(start).find((sample) => {
    const elapsed = last.at - sample.at;
    return elapsed >= MIN_RATE_SAMPLE_MS && elapsed <= RATE_SAMPLE_MS;
  });
  if (!first) return empty;
  const elapsedHours = (last.at - first.at) / 3600000;
  const xpDelta = last.xpBySkill[skillId] - first.xpBySkill[skillId];
  return {
    xpDelta,
    hourRate: xpDelta / elapsedHours,
    minutesSampled: elapsedHours * 60,
  };
}
