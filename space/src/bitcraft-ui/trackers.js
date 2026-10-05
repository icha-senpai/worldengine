import { itemKind } from "./market";
export function levelProgress(xp, levels) {
  const sorted = [...levels].sort((a, b) => a.xp - b.xp);
  const current = sorted.filter((row) => row.xp <= xp).at(-1) ?? {
      level: 1,
      xp: 0,
    },
    next = sorted.find((row) => row.xp > xp);
  return {
    level: Number(current.level),
    nextLevel: next?.level ?? null,
    nextLevelXp: next?.xp ?? null,
    xpIntoLevel: Math.max(0, xp - current.xp),
    xpForLevel: next ? Math.max(1, next.xp - current.xp) : null,
    xpRemaining: next ? Math.max(0, next.xp - xp) : null,
    progressPercent: next
      ? Math.min(
          100,
          Math.max(0, ((xp - current.xp) / (next.xp - current.xp)) * 100),
        )
      : 100,
  };
}
export function activityTracker(player, definitions, levels, filters) {
  const skillOptions = definitions.map((skill) => {
    const experience = (player.experience ?? []).find(
      (entry) => Number(entry.skill_id ?? entry.skillId) === Number(skill.id),
    );
    const xpKnown =
      experience?.quantity != null &&
      Number.isFinite(Number(experience.quantity)) &&
      Number(experience.quantity) >= 0;
    const xp = xpKnown ? Number(experience.quantity) : 0;
    return { ...skill, xp, xpKnown, ...levelProgress(xp, levels) };
  });
  const skills = skillOptions.filter(
    (skill) =>
      !filters.skill ||
      filters.skill === "all" ||
      String(skill.id) === String(filters.skill) ||
      skill.name.toLowerCase() === filters.skill.toLowerCase(),
  );
  const primary = [...skills].sort((a, b) => b.xp - a.xp)[0];
  if (!primary) throw new Error("No matching skill was found.");
  return {
    scope: filters.skill === "all" ? "all" : "skill",
    player,
    skill: primary,
    xp: primary.xp,
    skills,
    skillOptions,
    levels,
    ...levelProgress(primary.xp, levels),
  };
}
export function inventoryTracker(player, payload, catalog, filters) {
  const definitions = [
    ...catalog,
    ...Object.values(payload.items ?? {}).map((item) => ({
      ...item,
      kind: "item",
    })),
    ...Object.values(payload.cargos ?? {}).map((item) => ({
      ...item,
      kind: "cargo",
    })),
  ];
  const map = new Map(
    definitions.map((item) => [`${item.kind}:${item.id}`, item]),
  );
  const entries = [];
  const trackedKinds = [
    "inventory",
    "boat",
    "raft",
    "skiff",
    "clipper",
    "ship",
    "cart",
    "wagon",
    "bird",
    "cervus",
    "deer",
    "equous",
    "horse",
    "ox",
    "goat",
    "personal cache",
  ];
  for (const inventory of payload.inventories ?? []) {
    const sourceName =
      inventory.inventoryName ?? inventory.buildingName ?? "Inventory";
    const sourceKind = trackedKinds.find((kind) =>
      new RegExp(`\\b${kind}\\b`, "i").test(sourceName),
    );
    if (!sourceKind) continue;
    for (const pocket of inventory.items ??
      inventory.pockets ??
      inventory.inventory ??
      []) {
      const contents = pocket.contents ?? pocket.item ?? pocket;
      const id = contents.itemId ?? contents.item_id ?? contents.id;
      if (!id || Number(contents.quantity ?? 0) <= 0) continue;
      const kind = itemKind(
        contents.itemType ?? contents.item_type ?? contents.kind,
      );
      const key = `${kind}:${id}`,
        known = map.get(key) ?? contents;
      const name = known.name ?? contents.itemName ?? `Item ${id}`;
      const source = {
        id: String(inventory.entityId ?? inventory.id ?? ""),
        name: sourceName,
        kind: sourceKind,
        quantity: Number(contents.quantity ?? 0),
      };
      entries.push({
        ...known,
        key,
        id,
        kind,
        name,
        quantity: source.quantity,
        source,
      });
    }
  }
  const options = [
    ...new Map(
      [
        ...entries,
        ...catalog.filter(
          (item) =>
            !filters.itemSearch ||
            item.name.toLowerCase().includes(filters.itemSearch.toLowerCase()),
        ),
      ].map((item) => {
        const key = item.key ?? `${item.kind}:${item.id}`;
        return [
          key,
          {
            ...item,
            key,
            quantity: entries
              .filter((entry) => entry.key === key)
              .reduce((sum, entry) => sum + entry.quantity, 0),
          },
        ];
      }),
    ).values(),
  ];
  const keys = Array.isArray(filters.itemKeys)
    ? filters.itemKeys
    : String(filters.itemKeys || filters.itemKey || "")
        .split(",")
        .filter(Boolean);
  const needs =
    typeof filters.itemNeeds === "object"
      ? filters.itemNeeds
      : Object.fromEntries(
          String(filters.itemNeeds ?? "")
            .split(",")
            .map((value) => value.split("="))
            .filter(([key, value]) => key && Number(value) > 0)
            .map(([key, value]) => [key, Number(value)]),
        );
  const items = keys
    .map((key) => {
      const option = options.find((item) => item.key === key) ?? map.get(key);
      if (!option) return null;
      const rows = entries.filter((entry) => entry.key === key),
        quantity = rows.reduce((sum, row) => sum + row.quantity, 0),
        need = Number(needs[key] ?? filters.need) || null;
      const grouped = new Map();
      for (const row of rows) {
        const sourceKey = `${row.source.kind}:${row.source.name}`;
        const source = grouped.get(sourceKey) ?? { ...row.source, quantity: 0 };
        source.quantity += row.quantity;
        grouped.set(sourceKey, source);
      }
      return {
        ...option,
        key,
        quantity,
        need,
        remaining: need === null ? null : Math.max(0, need - quantity),
        progressPercent:
          need === null
            ? null
            : Math.min(100, (quantity / Math.max(1, need)) * 100),
        sources: [...grouped.values()],
      };
    })
    .filter(Boolean);
  return {
    options,
    tracker: items.length
      ? {
          player,
          items,
          item: items[0],
          quantity: items[0].quantity,
          need: items[0].need,
          remaining: items[0].remaining,
          progressPercent: items[0].progressPercent,
          sources: items[0].sources,
        }
      : null,
  };
}
export function passiveTracker(
  player,
  payload,
  recipeTimes = {},
  now = Date.now(),
) {
  const catalog = new Map([
    ...Object.values(payload.items ?? {}).map((item) => [
      `item:${item.id}`,
      item,
    ]),
    ...Object.values(payload.cargos ?? {}).map((item) => [
      `cargo:${item.id}`,
      item,
    ]),
  ]);
  const crafts = (payload.craftResults ?? [])
    .filter(
      (row) =>
        (row.isPassive ?? row.is_passive ?? true) &&
        !(row.completed || String(row.status).toLowerCase() === "complete"),
    )
    .map((row) => {
      const count = Math.max(1, Number(row.craftCount ?? row.craft_count ?? 1));
      const outputs = (
        row.craftedItem ??
        row.crafted_item ??
        row.craftedItems ??
        row.outputs ??
        []
      ).map((output) => {
        const kind = itemKind(
            output.item_type ?? output.itemType ?? output.kind,
          ),
          id = output.item_id ?? output.itemId ?? output.id,
          known = catalog.get(`${kind}:${id}`) ?? output;
        return {
          ...known,
          key: `${kind}:${id}`,
          id,
          kind,
          name: known.name ?? `Item ${id}`,
          quantity: Number(output.quantity ?? 1),
          totalQuantity: Number(output.quantity ?? 1) * count,
        };
      });
      const recipeSeconds =
        Number(recipeTimes[row.recipeId ?? row.recipe_id] ?? 0) * count;
      const started = Date.parse(row.timestamp ?? row.startedAt),
        usesTimer = Number.isFinite(started) && recipeSeconds > 0;
      const total = usesTimer
        ? recipeSeconds
        : Number(
            row.totalActionsRequired ??
              row.total_actions_required ??
              row.totalProgress ??
              0,
          );
      const progress = usesTimer
        ? Math.min(total, Math.max(0, (now - started) / 1000))
        : Math.min(total, Math.max(0, Number(row.progress ?? 0)));
      const recipeEstimate = !usesTimer && total <= 1 && recipeSeconds > 0;
      const seconds = usesTimer
        ? Math.max(0, total - progress)
        : (row.estimatedRemainingSeconds ??
          row.remainingSeconds ??
          (recipeEstimate
            ? recipeSeconds
            : total > 0
              ? total - progress
              : null));
      const finishesAt = usesTimer
        ? new Date(started + recipeSeconds * 1000).toISOString()
        : null;
      return {
        ...row,
        entityId: String(row.entityId ?? row.entity_id ?? ""),
        name: outputs[0]?.name ?? row.recipeName ?? `Recipe ${row.recipeId}`,
        outputs,
        claimEntityId: String(row.claimEntityId ?? row.claim_entity_id ?? ""),
        claim: {
          name: row.claimName ?? "Unknown claim",
          region: row.regionId,
          regionName: row.regionName,
          locationX: row.claimLocationX,
          locationZ: row.claimLocationZ,
        },
        buildingName: row.buildingName ?? "Unknown station",
        craftCount: count,
        progress,
        totalActionsRequired: total,
        estimatedTotalSeconds: recipeEstimate ? recipeSeconds : total,
        progressPercent: total > 0 ? (progress / total) * 100 : 0,
        estimatedRemainingSeconds: seconds,
        timerSource: usesTimer
          ? "api"
          : recipeEstimate
            ? "recipe"
            : total > 0
              ? "relay"
              : null,
        startedAt: usesTimer ? new Date(started).toISOString() : null,
        finishesAt,
      };
    });
  const grouped = new Map();
  for (const craft of crafts) {
    const key = `${craft.outputs[0]?.key ?? craft.name}|${craft.claimEntityId}`;
    if (!grouped.has(key)) grouped.set(key, []);
    grouped.get(key).push(craft);
  }
  const groups = [...grouped.values()]
    .map((rows) => {
      const first = rows[0],
        longest = [...rows].sort(
          (a, b) =>
            (b.estimatedRemainingSeconds ?? 0) -
            (a.estimatedRemainingSeconds ?? 0),
        )[0];
      const progress = rows.reduce((sum, row) => sum + row.progress, 0),
        total = rows.reduce((sum, row) => sum + row.totalActionsRequired, 0),
        buildings = [...new Set(rows.map((row) => row.buildingName))];
      return {
        key: `${first.outputs[0]?.key ?? first.name}|${first.claimEntityId}`,
        name: first.name,
        claim: first.claim,
        claimEntityId: first.claimEntityId,
        output: first.outputs[0],
        buildingNames: buildings,
        buildingCount: buildings.length,
        craftsCount: rows.length,
        totalQueued: rows.reduce((sum, row) => sum + row.craftCount, 0),
        totalOutputQuantity: rows.reduce(
          (sum, row) => sum + Number(row.outputs[0]?.totalQuantity ?? 0),
          0,
        ),
        progress,
        totalActionsRequired: total,
        remainingActions: Math.max(0, total - progress),
        progressPercent: total > 0 ? (progress / total) * 100 : 0,
        estimatedRemainingSeconds: longest.estimatedRemainingSeconds,
        estimatedTotalSeconds: Math.max(
          ...rows.map((row) => row.estimatedTotalSeconds),
        ),
        timerSource: longest.timerSource,
        startedAt: longest.startedAt,
        finishesAt: longest.finishesAt,
      };
    })
    .sort(
      (a, b) =>
        b.totalOutputQuantity - a.totalOutputQuantity ||
        a.name.localeCompare(b.name),
    );
  const longest = [...groups].sort(
    (a, b) =>
      (b.estimatedRemainingSeconds ?? 0) - (a.estimatedRemainingSeconds ?? 0),
  )[0];
  return {
    player,
    crafts,
    groups,
    activeCount: crafts.length,
    totalQueued: crafts.reduce((sum, row) => sum + row.craftCount, 0),
    totalOutputs: groups.reduce((sum, row) => sum + row.totalOutputQuantity, 0),
    estimatedRemainingSeconds: longest?.estimatedRemainingSeconds ?? null,
    timerSource: longest?.timerSource ?? null,
  };
}
