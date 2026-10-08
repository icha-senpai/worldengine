export interface EntityProjection {
  kind: string;
  entityId: string;
  ownerId: string;
  claimId: string;
  itemKey: string;
  region: number;
  name: string;
  quantity: number;
  progress: number;
  total: number;
  payload: string;
}
const id = (value: unknown) => {
  if (typeof value === "number" && !Number.isSafeInteger(value))
    throw new Error("An entity id lost precision.");
  const result = String(value ?? "");
  if (!/^\d{1,24}$/.test(result))
    throw new Error("Invalid collected entity id.");
  return result;
};
const optionalId = (value: unknown) =>
  value == null || value === "" ? "" : id(value);
const number = (value: unknown, fallback = 0) => {
  if (value == null) return fallback;
  const result = Number(value);
  if (!Number.isFinite(result) || result < 0)
    throw new Error("Invalid collected quantity.");
  return result;
};
export function collectionInterval(resource: string) {
  if (resource === "relayNearby") return 60;
  if (resource === "relaySession") return 2;
  if (["player", "relayPlayer", "relaySkills"].includes(resource)) return 10;
  if (
    /Inventories|Housing|ClaimInventory|Crafts/.test(resource) ||
    ["inventories", "passive", "crafts"].includes(resource)
  )
    return 15;
  if (/Orders|Listings|History/.test(resource) || resource === "market")
    return 60;
  if (["levels", "regions", "empires"].includes(resource)) return 3600;
  return 300;
}
export function collectionKey(resource: string, path: string) {
  return `${resource}|${path}`;
}
export function collectionWatchSeconds(resource: string) {
  // XP collection outlives a suspended browser tab, bounded by history retention.
  return resource === "relaySkills" ? 6 * 60 * 60 : 10 * 60;
}
export function observedRates(
  samples: Array<{
    at: number;
    source: string;
    epoch: string;
    xp: Record<string, number>;
  }>,
) {
  const ordered = [...samples].sort((a, b) => a.at - b.at);
  const last = ordered[ordered.length - 1];
  if (!last) return [];
  let start = ordered.length - 1;
  while (start > 0) {
    const previous = ordered[start - 1],
      current = ordered[start];
    if (
      previous.source !== last.source ||
      previous.epoch !== last.epoch ||
      current.at - previous.at > 120000
    )
      break;
    start--;
  }
  // Preserve the trusted high-water mark through backward samples. Recovery
  // to an already observed value is not a new gain.
  const trusted: Record<string, number> = {};
  const continuous = ordered.slice(start).map((row) => {
    for (const [id, xp] of Object.entries(row.xp))
      trusted[id] = Math.max(trusted[id] ?? xp, xp);
    return { ...row, xp: { ...trusted } };
  });
  const current = continuous[continuous.length - 1];
  const first = continuous.find(
    (row) => last.at - row.at >= 60000 && last.at - row.at <= 300000,
  );
  if (!first) return [];
  return Object.entries(current.xp)
    .filter(([id]) => first.xp[id] != null && last.xp[id] != null)
    .map(([id, xp]) => ({
      skillId: Number(id),
      xpDelta: xp - first.xp[id],
      hourRate: ((xp - first.xp[id]) * 3600000) / (last.at - first.at),
      minutesSampled: (last.at - first.at) / 60000,
    }));
}

export function projectCollection(
  resource: string,
  raw: unknown,
  owner = "",
): EntityProjection[] {
  const body = (raw as any)?.data ?? raw;
  if (!body || typeof body !== "object")
    throw new Error("Invalid collection response.");
  const rows: EntityProjection[] = [];
  const add = (
    kind: string,
    row: any,
    values: Partial<EntityProjection> = {},
  ) => {
    const entityId = id(row.entity_id ?? row.entityId ?? row.id);
    const region = number(row.region ?? row.regionId);
    if (!Number.isInteger(region) || region > 1000)
      throw new Error("Invalid collected region.");
    rows.push({
      kind,
      entityId,
      ownerId: optionalId(
        row.owner_entity_id ??
          row.ownerEntityId ??
          (kind === "market-order" ? "" : owner),
      ),
      claimId: optionalId(row.claim_entity_id ?? row.claimEntityId),
      itemKey: "",
      region,
      name: String(
        row.username ?? row.name ?? row.building_name ?? row.buildingName ?? "",
      ).slice(0, 240),
      quantity: 0,
      progress: 0,
      total: 0,
      payload: JSON.stringify(row),
      ...values,
    });
  };
  if (resource === "relayPlayer") add("player", body);
  else if (["players", "relayPlayers"].includes(resource)) {
    const players = resource === "relayPlayers" ? body : body.players;
    if (!Array.isArray(players)) throw new Error("Missing player snapshot.");
    for (const player of players) add("player", player);
  } else if (resource === "player" && body.player) {
    add("player", body.player);
    for (const skill of body.player.experience ?? [])
      if (skill.quantity != null)
        add(
          "skill",
          { id: skill.skill_id ?? skill.skillId },
          {
            ownerId: id(body.player.entityId),
            quantity: number(skill.quantity),
          },
        );
  } else if (resource === "relaySkills") {
    if (!Array.isArray(body.skills)) throw new Error("Missing skill snapshot.");
    if (body.player) add("player", body.player);
    for (const skill of body.skills)
      if (skill.xp != null)
        add(
          "skill",
          { ...skill, id: skill.skill_id },
          { quantity: number(skill.xp) },
        );
  } else if (
    [
      "relayInventories",
      "inventories",
      "relayHousing",
      "relayClaimInventory",
    ].includes(resource)
  ) {
    const inventories =
      resource === "relayHousing"
        ? body.buildings
        : resource === "relayClaimInventory"
          ? body.dimensions?.flatMap((row: any) => row.buildings ?? [])
          : body.inventories;
    if (!Array.isArray(inventories))
      throw new Error("Missing inventory snapshot.");
    for (const inventory of inventories) {
      add("inventory", inventory);
      const inventoryId = id(inventory.entity_id ?? inventory.entityId);
      const amounts = new Map<string, number>();
      for (const pocket of inventory.items ?? inventory.pockets ?? []) {
        const item = pocket.contents ?? pocket.item ?? pocket;
        if (!item || (item.item_id ?? item.itemId ?? item.id) == null) continue;
        const itemId = id(item.item_id ?? item.itemId ?? item.id);
        const kind = [1, "1", "Cargo", "cargo"].includes(
          item.item_type ?? item.itemType,
        )
          ? "cargo"
          : "item";
        const key = `${kind}:${itemId}`;
        amounts.set(key, (amounts.get(key) ?? 0) + number(item.quantity));
      }
      for (const [itemKey, quantity] of amounts)
        add(
          "inventory-item",
          { id: inventoryId },
          { itemKey, quantity, payload: JSON.stringify({ itemKey, quantity }) },
        );
    }
  } else if (
    ["relayCrafts", "relayClaimCrafts", "crafts", "passive"].includes(resource)
  ) {
    const crafts = body.crafts ?? body.craftResults;
    if (!Array.isArray(crafts)) throw new Error("Missing craft snapshot.");
    for (const craft of crafts)
      add("craft", craft, {
        progress: number(craft.progress),
        total: number(
          craft.total_actions_required ??
            craft.total_progress ??
            craft.totalProgress ??
            craft.totalActionsRequired,
        ),
        quantity: number(craft.craft_count ?? craft.craftCount, 1),
      });
  } else if (["relayClaim", "claim"].includes(resource))
    add("claim", body.claim ?? body);
  else if (resource === "claims")
    for (const claim of body.claims ?? []) add("claim", claim);
  else if (["itemOrders", "cargoOrders", "claimListings"].includes(resource)) {
    const orders = body.listings ?? [
      ...(body.buyOrders ?? []),
      ...(body.sellOrders ?? []),
    ];
    for (const order of orders)
      add("market-order", order, {
        quantity: number(order.quantity),
        itemKey: `${[1, "1", "cargo", "Cargo"].includes(order.itemType) || resource === "cargoOrders" ? "cargo" : "item"}:${id(order.itemId ?? owner)}`,
      });
  }
  if (rows.length > 5000) throw new Error("Collection scope is too large.");
  const unique = new Map<string, EntityProjection>();
  for (const row of rows)
    unique.set(`${row.kind}|${row.entityId}|${row.itemKey}`, row);
  return [...unique.values()];
}
