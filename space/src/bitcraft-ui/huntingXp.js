// Editable planning preset; it is not a live measurement of the selected player.
export const nubiGoatPreset = {
  killXp: 386.1,
  processingXp: 198,
  xpMode: "base",
  bonus: 0,
};
export const huntingDefaults = {
  currentLevel: 37,
  currentXp: 5080,
  targetLevel: 40,
  ...nubiGoatPreset,
  mode: "both",
  method: "wild",
  lureAnimal: "",
  lureTier: 1,
  lureAnimals: 0,
  lureXp: 0,
  lureGapSeconds: 0,
};

export function calibrateLureSession(lures, huntingXp, carcasses) {
  const values = [lures, huntingXp, carcasses];
  const [count, xp, animals] = values.map(Number);
  if (
    values.some((value) => value === "" || value == null) ||
    !Number.isSafeInteger(count) ||
    count < 1 ||
    !Number.isFinite(xp) ||
    xp < 0 ||
    !Number.isSafeInteger(animals) ||
    animals < 1
  ) {
    return {
      error:
        "Enter whole lures and carcasses above zero, and non-negative hunting XP.",
    };
  }
  return { error: null, lureXp: xp / count, lureAnimals: animals / count };
}

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
  const lure = settings.method === "lure";
  const animalsPerLure = Number(settings.lureAnimals);
  const lureXp = Number(settings.lureXp);
  const lureGap = Number(settings.lureGapSeconds);
  if (
    lure &&
    ([settings.lureAnimals, settings.lureXp, settings.lureGapSeconds].some(
      (value) => value === "" || value == null,
    ) ||
      !Number.isFinite(animalsPerLure) ||
      animalsPerLure <= 0 ||
      !Number.isFinite(lureXp) ||
      lureXp < 0 ||
      !Number.isFinite(lureGap) ||
      lureGap < 0)
  ) {
    return {
      error:
        "Enter animals collected per lure above zero, and non-negative lure XP and time between lures.",
    };
  }
  const killXp = lure ? lureXp / animalsPerLure : Number(settings.killXp);
  const processingXp = Number(settings.processingXp);
  const bonus = settings.xpMode === "observed" ? 0 : Number(settings.bonus);
  const inputs = [
    settings.currentLevel,
    settings.targetLevel,
    settings.currentXp,
    lure ? settings.lureXp : settings.killXp,
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
      xp: killXp * (lure ? 1 : multiplier) + processingXp * multiplier,
    },
    {
      mode: "processing",
      label: "Process only",
      xp: processingXp * multiplier,
    },
    {
      mode: "hunting",
      label: "Hunt only",
      xp: killXp * (lure ? 1 : multiplier),
    },
  ].map((row) => ({
    ...row,
    count:
      remainingXp === 0
        ? 0
        : row.xp > 0
          ? Math.ceil(remainingXp / row.xp)
          : null,
  }));

  if (lure) {
    for (const row of scenarios) {
      row.xpPerLure = row.xp * animalsPerLure;
      row.lures =
        remainingXp === 0
          ? 0
          : row.xpPerLure > 0
            ? Math.ceil(remainingXp / row.xpPerLure)
            : null;
      row.carcasses = row.lures === null ? null : row.lures * animalsPerLure;
      row.huntingSeconds =
        row.lures === null
          ? null
          : row.lures * 120 + Math.max(0, row.lures - 1) * lureGap;
    }
  }

  if (
    scenarios.some(
      (row) =>
        !Number.isFinite(row.xp) ||
        (lure &&
          (!Number.isFinite(row.xpPerLure) ||
            (row.lures !== null &&
              (!Number.isSafeInteger(row.lures) ||
                !Number.isFinite(row.huntingSeconds) ||
                !Number.isFinite(row.carcasses))))) ||
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
