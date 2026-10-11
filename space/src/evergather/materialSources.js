// Sources use the same live presentation and guards as the main game panels.
export function sourcesForMaterial(itemKey, sources, player) {
  const matches = [];
  function add(action, entries, outputs, requirements, label) {
    for (const entry of entries ?? []) {
      const item = outputs(entry).find(
        (item) =>
          (item.item_key ?? item.key) === itemKey && !item.equipment_skill,
      );
      if (!item || (action === "shop" && entry.kind === "tool")) continue;
      const inputs = requirements(entry);
      const cost = Number(entry.gold_cost ?? entry.price ?? 0);
      const blockedReason = !entry.is_unlocked
        ? `Need ${entry.skill_label ? `${entry.skill_label} ` : ""}Lv ${entry.required_level}`
        : ["gather", "activity", "craft"].includes(action) &&
            entry.requires_tool_repair
          ? `Repair ${entry.equipped_tool?.item_name ?? "equipped tool"} first`
          : cost > Number(player?.gold ?? 0)
            ? `Need ${cost}g`
            : inputs.some((input) => !input.has_enough)
              ? "Need ingredients"
              : ["gather", "activity"].includes(action) &&
                  player?.can_act_now === false
                ? "Waiting for cooldown"
                : "";
      matches.push({
        id: `${action}:${entry.key}`,
        action,
        entry,
        item,
        requirements: inputs,
        label: label(entry),
        cost,
        blockedReason,
        ready: !blockedReason,
      });
    }
  }
  const loot = (entry) => entry.loot_preview ?? [];
  const none = () => [];
  add("gather", sources.actions, loot, none, (entry) =>
    entry.category === "Processing" ? "Process" : "Gather",
  );
  add(
    "activity",
    sources.activities,
    loot,
    (entry) => entry.supplies ?? [],
    (entry) => (entry.category === "Processing" ? "Process" : "Activity"),
  );
  add(
    "craft",
    sources.recipes,
    (entry) => entry.outputs ?? [],
    (entry) => entry.ingredients ?? [],
    (entry) => (entry.category === "Processing" ? "Process" : "Craft"),
  );
  add(
    "job",
    sources.jobs,
    (entry) => entry.rewards ?? [],
    (entry) => entry.requirements ?? [],
    () => "Commission",
  );
  add(
    "expedition",
    sources.expeditions,
    (entry) => entry.rewards ?? [],
    (entry) => entry.supplies ?? [],
    () => "Expedition",
  );
  add(
    "shop",
    sources.offers,
    (entry) => [entry],
    none,
    () => "Buy",
  );
  return matches.sort(
    (a, b) =>
      Number(b.ready) - Number(a.ready) ||
      Number(b.entry.is_unlocked) - Number(a.entry.is_unlocked) ||
      a.entry.required_level - b.entry.required_level ||
      a.cost - b.cost ||
      a.id.localeCompare(b.id),
  );
}

export function materialOutputLabel(item) {
  const quantity =
    item.min !== undefined || item.max !== undefined
      ? `${item.min ?? 0}–${item.max ?? item.min ?? 0}`
      : String(item.quantity ?? 1);
  return `${quantity} per action${item.chance !== undefined && item.chance < 100 ? ` · ${item.chance}% chance` : ""}`;
}

export function trainingSourceFor(source, sources) {
  if (source.entry.is_unlocked) return null;
  const training = sources.activities?.find(
    (entry) =>
      entry.skill === source.entry.skill &&
      entry.activity_type === "Starter Training" &&
      entry.is_unlocked,
  );
  if (!training) return null;
  return {
    id: `activity:${training.key}`,
    action: "activity",
    entry: training,
    ready: training.can_perform,
  };
}
