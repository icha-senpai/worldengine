import { pageState } from "../navigation";
export function filtersFor(tool, query, setup) {
  const defaults = {
    source: "default",
    character:
      pageState.props.bitcraft.player?.entityId ||
      localStorage.getItem("space.bitcraft.player") ||
      "icha",
    skill: "all",
    title: {
      activity: "XP Goals",
      inventory: "Fishing Day!",
      "passive-crafts": "Passive Crafts",
      tasks: "Stream Tasks",
    }[tool],
    icons: {
      activity: "✨ 🏆",
      inventory: "🐟 🎣",
      "passive-crafts": "🧵 🔨",
      tasks: "✅ ✨",
    }[tool],
    itemSearch: "",
    skillSearch: "",
    itemKeys: "",
    skillKeys: "",
    skillGoalLevels: "",
    skillGoalXp: "",
    itemNeeds: "",
    includeHousing: false,
    storageClaimId: "",
    need: null,
    taskText: "",
    tasks: [],
    q: "",
    category: "",
    claimQ: "",
    claimEntityId: "",
    empire: "",
    empireEntityId: "",
    region: "",
    side: "",
    hasOrders: null,
    hasSellOrders: false,
    hasBuyOrders: false,
    itemId: null,
    itemKind: "item",
    quantity: 1,
    levelUps: false,
    meetsLevel: false,
    mine: false,
    sort: "xp",
    page: 1,
    setup,
  };
  const filters = {
    ...defaults,
    ...(tool === "open-crafts" ? { skill: "" } : {}),
    ...(["market", "barter-stalls"].includes(tool) ? { itemKind: "" } : {}),
    ...query,
    setup,
  };
  for (const key of [
    "hasOrders",
    "hasSellOrders",
    "hasBuyOrders",
    "levelUps",
    "meetsLevel",
    "mine",
  ])
    filters[key] =
      filters[key] === null
        ? null
        : filters[key] === true ||
          filters[key] === "1" ||
          filters[key] === "true";
  if (typeof filters.tasks === "string") {
    try {
      filters.tasks = JSON.parse(filters.tasks);
    } catch {
      filters.tasks = [];
    }
  }
  filters.tasks = Array.isArray(filters.tasks)
    ? filters.tasks
        .filter(
          (task) =>
            task &&
            typeof task.text === "string" &&
            typeof task.id === "string",
        )
        .slice(0, 200)
    : [];
  return filters;
}
