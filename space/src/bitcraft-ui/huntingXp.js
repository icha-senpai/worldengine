export function calculateHuntingXp(levels, settings) {
  const thresholds = new Map(
    levels
      .filter(
        (row) =>
          Number.isInteger(row?.level) &&
          Number.isFinite(row?.xp) &&
          row.xp >= 0,
      )
      .map((row) => [row.level, row.xp]),
  );
  const currentLevel = Number(settings.currentLevel);
  const targetLevel = Number(settings.targetLevel);
  const currentXp = Number(settings.currentXp);
  const killXp = Number(settings.killXp);
  const processingXp = Number(settings.processingXp);
  const bonus = settings.xpMode === "observed" ? 0 : Number(settings.bonus);
  const inputs = [
    settings.currentLevel,
    settings.targetLevel,
    settings.currentXp,
    settings.killXp,
    settings.processingXp,
  ];

  if (
    inputs.some(
      (value) => value === "" || value === null || value === undefined,
    ) ||
    (settings.xpMode !== "observed" &&
      (settings.bonus === "" || settings.bonus == null)) ||
    ![currentLevel, targetLevel].every(Number.isInteger) ||
    ![currentXp, killXp, processingXp, bonus].every(
      (value) => Number.isFinite(value) && value >= 0,
    )
  ) {
    return { error: "Enter valid levels and non-negative XP values." };
  }
  if (targetLevel < currentLevel) {
    return { error: "Target level must be your current level or higher." };
  }
  if (!thresholds.has(currentLevel) || !thresholds.has(targetLevel)) {
    return { error: "Level thresholds are unavailable for these levels." };
  }

  const breakdown = [];
  for (let level = currentLevel; level < targetLevel; level++) {
    const start = thresholds.get(level);
    const end = thresholds.get(level + 1);
    if (start === undefined || end === undefined || end <= start) {
      return { error: "Level thresholds are incomplete. Reload to try again." };
    }
    breakdown.push({
      level,
      target: level + 1,
      xp: end - start - (level === currentLevel ? currentXp : 0),
    });
  }
  const nextThreshold = thresholds.get(currentLevel + 1);
  if (
    (nextThreshold !== undefined &&
      currentXp >= nextThreshold - thresholds.get(currentLevel)) ||
    (nextThreshold === undefined && currentXp > 0)
  ) {
    return {
      error:
        "XP earned must be below the next-level requirement. Update your current level.",
    };
  }

  const remainingXp = breakdown.reduce((total, row) => total + row.xp, 0);
  const multiplier = 1 + bonus / 100;
  const scenarios = [
    {
      mode: "both",
      label: "Hunt and process",
      xp: (killXp + processingXp) * multiplier,
    },
    {
      mode: "processing",
      label: "Process only",
      xp: processingXp * multiplier,
    },
    { mode: "hunting", label: "Hunt only", xp: killXp * multiplier },
  ].map((row) => ({
    ...row,
    count:
      remainingXp === 0
        ? 0
        : row.xp > 0
          ? Math.ceil(remainingXp / row.xp)
          : null,
  }));

  if (
    scenarios.some(
      (row) =>
        !Number.isFinite(row.xp) ||
        (row.count !== null && !Number.isSafeInteger(row.count)),
    )
  ) {
    return {
      error:
        "These XP values are too large or too small to calculate a reliable count.",
    };
  }

  return { error: null, remainingXp, breakdown, scenarios };
}
