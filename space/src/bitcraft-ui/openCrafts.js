import { levelProgress } from "./trackers";
import { itemKind } from "./market";
export function openCraftRows(payload, player, levels, skills) {
  if (!Array.isArray(payload.craftResults))
    throw new Error("Open crafts are temporarily unavailable.");
  const catalog = new Map([
    ...(payload.items ?? []).map((item) => [`item:${item.id}`, item]),
    ...(payload.cargos ?? []).map((item) => [`cargo:${item.id}`, item]),
  ]);
  const skillMap = new Map(
    skills.map((skill) => [Number(skill.id), skill.name]),
  );
  const xpFor = (id) =>
    Number(
      (player?.experience ?? []).find(
        (entry) => Number(entry.skill_id ?? entry.skillId) === id,
      )?.quantity ?? 0,
    );
  return payload.craftResults.map((craft) => {
    const total = Math.max(
        1,
        Number(craft.totalActionsRequired ?? craft.totalProgress ?? 1),
      ),
      progress = Math.max(0, Math.min(total, Number(craft.progress ?? 0)));
    const experience = Array.isArray(craft.experiencePerProgress)
        ? craft.experiencePerProgress[0]
        : (craft.experiencePerProgress ?? {}),
      skillId = Number(
        experience?.skill_id ??
          experience?.skillId ??
          experience?.[0] ??
          craft.skillId ??
          0,
      );
    const quantity = experience?.quantity ?? experience?.[1];
    const perProgress =
      quantity === undefined || quantity === null ? null : Number(quantity);
    const remainingXp =
        perProgress === null ? null : (total - progress) * perProgress,
      fullXp = perProgress === null ? null : total * perProgress;
    const currentXp = player ? xpFor(skillId) : null,
      currentLevel =
        player && levels.length && skillId > 1
          ? levelProgress(currentXp, levels).level
          : null;
    const afterLevel =
        currentLevel !== null && remainingXp !== null
          ? levelProgress(currentXp + remainingXp, levels).level
          : null,
      fullLevel =
        currentLevel !== null && fullXp !== null
          ? levelProgress(currentXp + fullXp, levels).level
          : null;
    const requirements = (craft.levelRequirements ?? []).map((row) => ({
      skill:
        skillMap.get(Number(row.skill_id ?? row[0])) ??
        `Skill ${row.skill_id ?? row[0]}`,
      level: Number(row.level ?? row[1]),
    }));
    const meetsLevel =
      player && levels.length
        ? (craft.levelRequirements ?? []).every(
            (row) =>
              levelProgress(xpFor(Number(row.skill_id ?? row[0])), levels)
                .level >= Number(row.level ?? row[1]),
          )
        : null;
    const outputs = (craft.craftedItem ?? craft.craftedItems ?? []).map(
      (output) => {
        const id = output.item_id ?? output.itemId ?? output.id,
          kind = itemKind(output.item_type ?? output.itemType),
          item = catalog.get(`${kind}:${id}`) ?? {};
        return {
          ...item,
          id,
          kind,
          name: item.name ?? output.name ?? `Item ${id}`,
          quantity:
            Number(output.quantity ?? 1) * Number(craft.craftCount ?? 1),
        };
      },
    );
    return {
      id: String(craft.entityId ?? craft.id),
      name:
        outputs.map((output) => output.name).join(", ") ||
        `Recipe ${craft.recipeId}`,
      outputs,
      claim: craft.claimName ?? "Unknown claim",
      owner: craft.ownerUsername ?? "Unknown player",
      ownerId: String(craft.ownerEntityId ?? ""),
      building: craft.buildingName ?? "",
      region: Number(craft.regionId ?? 0),
      x: craft.claimLocationX ?? null,
      z: craft.claimLocationZ ?? null,
      count: Number(craft.craftCount ?? 1),
      progressPercent: Math.round((progress / total) * 1000) / 10,
      remainingProgress: total - progress,
      skillId,
      skill: skillMap.get(skillId) ?? `Skill ${skillId}`,
      remainingXp,
      fullXp,
      currentXp,
      currentLevel,
      afterLevel,
      fullLevel,
      levelsGained: afterLevel === null ? null : afterLevel - currentLevel,
      requirements,
      meetsLevel,
      toolRequirements: craft.toolRequirements ?? [],
    };
  });
}
export function filterCrafts(rows, filters, player) {
  const filtered = rows.filter(
    (row) =>
      (!filters.q ||
        [row.name, row.claim, row.owner, row.building]
          .join(" ")
          .toLowerCase()
          .includes(filters.q.toLowerCase())) &&
      (!filters.skill || row.skillId === Number(filters.skill)) &&
      (!filters.region || row.region === Number(filters.region)) &&
      (!filters.levelUps || (row.levelsGained ?? 0) > 0) &&
      (!filters.meetsLevel || row.meetsLevel === true) &&
      (!filters.mine || row.ownerId === String(player?.entityId)),
  );
  filtered.sort((a, b) =>
    filters.sort === "name"
      ? a.name.localeCompare(b.name)
      : filters.sort === "progress"
        ? b.progressPercent - a.progressPercent
        : filters.sort === "levels"
          ? (b.levelsGained ?? 0) - (a.levelsGained ?? 0) ||
            (b.remainingXp ?? -1) - (a.remainingXp ?? -1)
          : (b.remainingXp ?? -1) - (a.remainingXp ?? -1),
  );
  const lastPage = Math.max(1, Math.ceil(filtered.length / 25)),
    page = Math.max(1, Math.min(Number(filters.page) || 1, lastPage));
  return {
    crafts: filtered.slice((page - 1) * 25, page * 25),
    pagination: { page, lastPage, total: filtered.length, all: rows.length },
    skillOptions: [
      ...new Map(
        rows.map((row) => [row.skillId, { id: row.skillId, name: row.skill }]),
      ).values(),
    ],
    regionOptions: [...new Set(rows.map((row) => row.region))].sort(
      (a, b) => a - b,
    ),
  };
}
