export function relayPlayer(raw) {
  if (!raw) return null;
  return {
    ...raw,
    entityId: String(raw.entity_id ?? raw.entityId),
    username: raw.username,
    signedIn: raw.signed_in ?? raw.signedIn,
    regionId: raw.region ?? raw.regionId,
    lastActiveTimestamp: raw.last_active_timestamp,
    lastLoginTimestamp: raw.last_login_timestamp,
  };
}
export function relayInventory(raw) {
  return {
    ...raw,
    inventories: (raw.inventories ?? []).map((row) => ({
      ...row,
      entityId: String(row.entity_id ?? row.entityId),
      inventoryName: row.category === "pockets" ? "Inventory" : row.name,
      items: (row.items ?? []).map((item) => ({
        ...item,
        itemId: item.item_id ?? item.itemId,
        itemType: item.item_type ?? item.itemType,
      })),
    })),
  };
}
export function withRelayCraftTimers(payload, states = []) {
  const timers = new Map(states.map((row) => [String(row.entity_id), row]));
  return {
    ...payload,
    craftResults: (payload.craftResults ?? []).flatMap((row) => {
      const timer = timers.get(String(row.entityId ?? row.entity_id));
      if (
        !timer ||
        String(timer.recipe_id) !== String(row.recipeId ?? row.recipe_id)
      )
        return [row];
      const status = Object.keys(timer.status ?? {})[0];
      if (status === "complete") return [];
      const micros = Number(
        timer.timestamp?.__timestamp_micros_since_unix_epoch__,
      );
      const date = new Date(micros / 1000);
      const timestamp =
        Number.isFinite(micros) && !Number.isNaN(date.getTime())
          ? date.toISOString()
          : null;
      return [
        {
          ...row,
          status,
          startedAt: status === "processing" ? timestamp : null,
          queuedAt: status === "queued" ? timestamp : null,
          relayTiming: true,
        },
      ];
    }),
  };
}
export function relayCrafts(raw, claims = {}, passive = true) {
  return {
    ...raw,
    craftResults: (raw.crafts ?? [])
      .filter((row) => row.is_passive === passive && !row.completed)
      .map((row) => {
        const claim = claims[row.claim_entity_id] ?? {};
        return {
          ...row,
          entityId: String(row.entity_id),
          recipeId: row.recipe_id,
          ownerEntityId: String(row.owner_entity_id ?? ""),
          ownerUsername: row.owner_username,
          craftCount: row.craft_count,
          totalActionsRequired: row.total_actions_required,
          totalProgress: row.total_progress,
          craftedItem: row.crafted_item ?? [],
          isPublic: row.is_public,
          buildingName: row.building_name,
          claimEntityId: String(row.claim_entity_id ?? ""),
          claimName: row.claim_name ?? claim.name,
          regionId: claim.region ?? claim.region_id,
          regionName: claim.region_name,
          claimLocationX: claim.location_x,
          claimLocationZ: claim.location_z,
        };
      }),
  };
}
export function relaySkills(raw, player) {
  if (!Array.isArray(raw.skills))
    throw new Error("Skills are temporarily unavailable.");
  return {
    ...player,
    experience: raw.skills.map((row) => ({
      skill_id: row.skill_id,
      quantity: row.xp,
    })),
  };
}
export function storageInventories(raw, kind) {
  const buildings =
    kind === "housing"
      ? (raw.buildings ?? [])
      : (raw.dimensions ?? []).flatMap(
          (dimension) => dimension.buildings ?? [],
        );
  return relayInventory({
    inventories: buildings.map((row) => ({
      ...row,
      category: kind,
      name: row.nickname || row.name,
      storageScope: kind,
    })),
  }).inventories;
}
export function mergeInventories(...payloads) {
  const unique = new Map();
  for (const payload of payloads)
    for (const row of payload.inventories ?? []) {
      const id = String(row.entityId ?? row.entity_id ?? "");
      if (id && !unique.has(id)) unique.set(id, row);
    }
  return { inventories: [...unique.values()] };
}
export function inventoryQuantities(payload) {
  const totals = {};
  for (const inventory of mergeInventories(payload).inventories) {
    for (const pocket of inventory.items ?? inventory.pockets ?? []) {
      const item = pocket?.contents ?? pocket?.item ?? pocket;
      if (!item) continue;
      const id = item.itemId ?? item.item_id ?? item.id;
      if (!id) continue;
      const kind = [1, "1", "cargo", "Cargo"].includes(
        item.itemType ?? item.item_type,
      )
        ? "cargo"
        : "item";
      const quantity = Number(item.quantity);
      if (Number.isFinite(quantity) && quantity > 0) {
        const key = `${kind}:${id}`;
        totals[key] = (totals[key] ?? 0) + quantity;
      }
    }
  }
  return totals;
}
