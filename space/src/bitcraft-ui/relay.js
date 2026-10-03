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
export function relayCrafts(raw, claims = {}) {
  return {
    ...raw,
    craftResults: (raw.crafts ?? [])
      .filter((row) => row.is_passive === true && !row.completed)
      .map((row) => {
        const claim = claims[row.claim_entity_id] ?? {};
        return {
          ...row,
          entityId: String(row.entity_id),
          recipeId: row.recipe_id,
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
