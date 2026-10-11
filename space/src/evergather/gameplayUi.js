export function resultFeedback(result) {
  if (!result) return "";
  const changes = [];
  if (result.type === "tool_rarity_upgrade") {
    const progress = result.label.match(/(\d+)% progress$/);
    changes.push(
      progress
        ? `Rarity attempt failed · progress now ${progress[1]}%`
        : `Rarity upgraded · ${result.label}`,
    );
  } else changes.push(result.label);
  for (const item of [
    ...(result.items_awarded ?? []),
    ...(result.items_created ?? []),
  ]) {
    changes.push(`+${item.quantity} ${item.item_name}`);
  }
  const xp = Number(result.experience_awarded ?? 0);
  if (xp) changes.push(`+${xp} XP`);
  const gold = Number(
    result.gold ??
      Number(result.gold_awarded ?? 0) - Number(result.gold_spent ?? 0),
  );
  if (gold) changes.push(`${gold > 0 ? "+" : ""}${gold}g`);
  return changes.join(" · ");
}
export function nearestOffers(offers) {
  return [...offers].sort(
    (a, b) =>
      Number(b.can_buy) - Number(a.can_buy) ||
      Number(b.is_unlocked) - Number(a.is_unlocked) ||
      Math.max(0, (a.required_level ?? 1) - (a.skill_level ?? 1)) -
        Math.max(0, (b.required_level ?? 1) - (b.skill_level ?? 1)) ||
      a.price - b.price ||
      a.label.localeCompare(b.label),
  );
}
export function repairTarget(tool) {
  const repair = tool?.tool_lifecycle?.repair;
  if (!repair) return null;
  return {
    label: "Repair",
    toolName: tool.item_name,
    materials: repair.materials,
    goldCost: repair.gold_cost,
    ready: repair.can_repair,
    status:
      repair.missing_durability <= 0
        ? "Fully repaired"
        : !repair.is_repairable
          ? "Repair unavailable"
          : repair.missing_materials
            ? "Need materials"
            : !repair.has_gold
              ? `Need ${repair.gold_cost}g`
              : "Ready",
  };
}
