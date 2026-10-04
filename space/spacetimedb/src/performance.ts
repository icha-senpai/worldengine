export const MARKET_PAGE_SIZE = 50;
export const MAX_OWN_LISTINGS = 100;
export const LEADERBOARD_SIZE = 20;

export function newestListings<
  T extends { id: bigint; createdAt: { microsSinceUnixEpoch: bigint } },
>(rows: T[]) {
  return rows.sort((a, b) => {
    const left = a.createdAt.microsSinceUnixEpoch,
      right = b.createdAt.microsSinceUnixEpoch;
    return left === right
      ? a.id > b.id
        ? -1
        : a.id < b.id
          ? 1
          : 0
      : left > right
        ? -1
        : 1;
  });
}

const panels: Record<string, string[]> = {
  "overview:progression": ["achievements"],
  "gather:actions": ["gathering_actions"],
  "gather:activities": ["skill_activities"],
  "craft:equipment": ["tool_upgrades"],
  "craft:recipes": ["crafting_recipes"],
  "craft:jobs": ["job_contracts"],
  "craft:expeditions": ["expeditions"],
  "trade:marketplace": [],
  "trade:shop": ["shop_offers"],
  "trade:inventory": [
    "gathering_actions",
    "skill_activities",
    "crafting_recipes",
    "job_contracts",
    "expeditions",
    "shop_offers",
    "tool_upgrades",
  ],
  "progress:skills": ["gathering_actions", "skill_activities"],
  "progress:events": [],
  "progress:leaderboards": [],
  "progress:recent": [],
};
export function catalogKinds(workspace: string, panel: string) {
  return [
    "presentation",
    "skills",
    "tool_families",
    "tool_tiers",
    "world_events",
    ...(panels[`${workspace}:${panel}`] ?? []),
  ];
}
export function referencedItems(
  value: unknown,
  items = new Set<string>(),
): Set<string> {
  if (Array.isArray(value))
    for (const child of value) referencedItems(child, items);
  else if (value && typeof value === "object") {
    const item = value as Record<string, unknown>;
    if (
      typeof item.key === "string" &&
      typeof item.name === "string" &&
      typeof item.rarity === "string"
    )
      items.add(item.key);
    for (const [key, child] of Object.entries(value)) {
      if (key === "item_key" && typeof child === "string") items.add(child);
      else referencedItems(child, items);
    }
  }
  return items;
}
export function pageWindow(
  total: number,
  requested: number,
  size = MARKET_PAGE_SIZE,
) {
  const pages = Math.max(1, Math.ceil(total / size));
  const page = Math.min(pages, Math.max(1, Math.floor(requested) || 1));
  return { page, pages, total, offset: (page - 1) * size };
}
export function topLeaders<
  T extends {
    owner: { toHexString(): string };
    gold: bigint;
    experience: bigint;
    skillExperience: string;
  },
>(rows: T[], skills: string[]) {
  const parsed = new Map(
    rows.map((row) => [
      row,
      JSON.parse(row.skillExperience) as Record<string, number>,
    ]),
  );
  const selected = new Map<string, T>();
  for (const key of ["gold", "experience", ...skills]) {
    const score = (row: T) =>
      key === "gold"
        ? row.gold
        : key === "experience"
          ? row.experience
          : BigInt(parsed.get(row)?.[key] ?? 0);
    for (const row of [...rows]
      .sort((a, b) => {
        const left = score(a),
          right = score(b);
        return left === right
          ? a.owner.toHexString().localeCompare(b.owner.toHexString())
          : left > right
            ? -1
            : 1;
      })
      .slice(0, LEADERBOARD_SIZE))
      selected.set(row.owner.toHexString(), row);
  }
  return [...selected.values()];
}
