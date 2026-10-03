export const gatheringDefaults = {
  mode: "sustained",
  power: 33,
  gatheringSpeed: 16,
  skillSpeed: 0,
  minutes: 30,
  critChance: 0,
  critMultiplier: 1,
};

function positiveNumber(value, fallback) {
  const number = Number(value);
  return Number.isFinite(number) && number >= 0 ? number : fallback;
}

export function estimateGathering(entry, settings) {
  const minutes = positiveNumber(settings.minutes, 30);
  const totalSeconds = minutes * 60;
  const power = Math.max(1, Math.round(positiveNumber(settings.power, 1)));
  const critMultiplier = Math.max(
    1,
    positiveNumber(settings.critMultiplier, 1),
  );
  const speedFactor = Math.max(
    0.01,
    1 +
      positiveNumber(settings.gatheringSpeed, 0) / 100 +
      positiveNumber(settings.skillSpeed, 0) / 100,
  );
  const actionSeconds = Math.max(
    0.01,
    positiveNumber(entry.timeRequirement, 1) / speedFactor,
  );
  const possibleActions = Math.floor(totalSeconds / actionSeconds);
  const critChance = Math.min(
    1,
    Math.max(0, positiveNumber(settings.critChance, 0) / 100),
  );
  const averageCritMultiplier = 1 - critChance + critChance * critMultiplier;
  const averageDamagePerAction = Math.max(1, power * averageCritMultiplier);
  const baseDamagePerAction = Math.max(1, Math.round(power));
  const maxHealth = Math.max(0, Number(entry.resource?.maxHealth ?? 0));
  const isFiniteTarget =
    settings.mode === "single" &&
    !entry.resource?.ignoreDamage &&
    maxHealth > 0;
  const actionsUsed = isFiniteTarget
    ? Math.min(possibleActions, Math.ceil(maxHealth / averageDamagePerAction))
    : possibleActions;
  const outputProgress = isFiniteTarget
    ? Math.min(maxHealth, actionsUsed * averageDamagePerAction)
    : actionsUsed *
      (maxHealth > 0
        ? Math.min(maxHealth, averageDamagePerAction)
        : averageDamagePerAction);
  const experience = isFiniteTarget
    ? finiteExperience(
        actionsUsed,
        maxHealth,
        averageDamagePerAction,
        baseDamagePerAction,
        Number(entry.experiencePerProgress?.quantity ?? 0),
      )
    : actionsUsed *
      Math.ceil(
        Number(entry.experiencePerProgress?.quantity ?? 0) *
          (maxHealth > 0
            ? Math.min(maxHealth, baseDamagePerAction)
            : baseDamagePerAction),
      );
  const outputs = (entry.outputs ?? []).map((output) => ({
    ...output,
    expected:
      outputProgress *
      Number(output.quantity ?? 1) *
      Number(output.probability ?? 1),
  }));
  const inputs = (entry.consumedItems ?? []).map((input) => ({
    ...input,
    expected:
      actionsUsed *
      Number(input.quantity ?? 1) *
      Number(input.consumptionChance ?? 1),
  }));
  const depletes = isFiniteTarget && actionsUsed < possibleActions;
  return {
    actionSeconds,
    possibleActions,
    actionsUsed,
    averageDamagePerAction,
    averageCritMultiplier,
    outputProgress,
    experience,
    outputs,
    inputs,
    primaryOutput: outputs[0]?.expected ?? 0,
    depletes,
  };
}

function finiteExperience(
  actionsUsed,
  maxHealth,
  damagePerAction,
  baseDamagePerAction,
  xpPerProgress,
) {
  let remaining = maxHealth;
  let total = 0;
  for (let action = 0; action < actionsUsed && remaining > 0; action += 1) {
    total += Math.ceil(
      xpPerProgress * Math.min(remaining, baseDamagePerAction),
    );
    remaining -= Math.min(remaining, damagePerAction);
  }
  return total;
}
