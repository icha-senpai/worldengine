export function passiveDeadlines(
  groups,
  previous = new Map(),
  now = Date.now(),
) {
  const deadlines = new Map();
  for (const group of groups ?? []) {
    const signature = JSON.stringify([
      group.timerSource,
      group.startedAt,
      group.estimatedRemainingSeconds,
      group.estimatedTotalSeconds,
      group.progress,
      group.totalActionsRequired,
      group.totalQueued,
      group.craftsCount,
    ]);
    const suppliedFinish = Date.parse(group.finishesAt);
    const remaining = group.estimatedRemainingSeconds;
    let finishesAt = null;
    if (Number.isFinite(suppliedFinish)) finishesAt = suppliedFinish;
    else if (
      remaining !== null &&
      remaining !== undefined &&
      Number.isFinite(Number(remaining)) &&
      Number(remaining) >= 0
    ) {
      const saved = previous.get(group.key);
      // Re-reading an unchanged recipe estimate must not restart its countdown.
      finishesAt =
        saved?.signature === signature
          ? saved.finishesAt
          : now + Number(remaining) * 1000;
    }
    deadlines.set(group.key, { signature, finishesAt });
  }
  return deadlines;
}

export function passiveSecondsLeft(finishesAt, now = Date.now()) {
  return Number.isFinite(finishesAt)
    ? Math.max(0, Math.ceil((finishesAt - now) / 1000))
    : null;
}

export function passiveCountdownLabel(seconds, source) {
  if (source === "queued") return "Waiting to start";
  if (seconds === null || !Number.isFinite(seconds)) return "Timer unavailable";
  if (seconds <= 0) return "Estimate reached";
  const whole = Math.ceil(seconds);
  const days = Math.floor(whole / 86400);
  const hours = Math.floor((whole % 86400) / 3600);
  const minutes = Math.floor((whole % 3600) / 60);
  const remaining = whole % 60;
  const parts = [
    ...(days ? [`${days}d`] : []),
    ...(days || hours ? [`${hours}h`] : []),
    ...(days || hours || minutes ? [`${minutes}m`] : []),
    `${remaining}s`,
  ];
  return `${source === "recipe" ? "up to " : "~"}${parts.join(" ")} left`;
}
