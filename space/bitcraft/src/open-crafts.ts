import { CURRENT_REGIONS } from "./relay-trading";
export function projectOpenCrafts(
  data: Record<string, any[]>,
  region: number,
  recipes: Record<string, any>,
  playerId = "",
  now = Date.now(),
  includeLocked = false,
) {
  const byId = (table: string) =>
    new Map((data[table] ?? []).map((row) => [String(row.entity_id), row]));
  const buildings = byId("building_state"),
    claims = byId("claim_state"),
    locals = byId("claim_local_state"),
    players = byId("player_username_state");
  return (data.progressive_action_state ?? []).flatMap((row) => {
    const recipe = recipes[row.recipe_id];
    const total = Number(recipe?.actionsRequired) * Number(row.craft_count);
    const lock =
      Number(row.lock_expiration?.__timestamp_micros_since_unix_epoch__ ?? 0) /
      1000;
    const mine = String(row.owner_entity_id) === String(playerId);
    if (row.preparation || (!includeLocked && !mine && lock > now)) return [];
    if (!recipe || !Number.isFinite(total) || total <= 0)
      throw Error(`Crafting definition ${row.recipe_id} is unavailable.`);
    if (Number(row.progress) >= total) return [];
    const building = buildings.get(String(row.building_entity_id));
    if (!building) throw Error("Crafting building metadata is incomplete.");
    const claim = claims.get(String(building.claim_entity_id));
    const location = locals.get(String(building.claim_entity_id))?.location
      ?.some;
    return [
      {
        entityId: String(row.entity_id),
        recipeId: row.recipe_id,
        progress: row.progress,
        craftCount: row.craft_count,
        totalActionsRequired: total,
        ownerEntityId: String(row.owner_entity_id),
        ownerUsername: players.get(String(row.owner_entity_id))?.username,
        claimEntityId: String(building.claim_entity_id),
        claimName: claim?.name,
        regionId: region,
        regionName: CURRENT_REGIONS[region],
        claimLocationX: location?.x,
        claimLocationZ: location?.z,
        buildingName: recipe.craftingStation,
        craftedItem: recipe.craftedItems,
        experiencePerProgress: recipe.experiencePerProgress,
        levelRequirements: recipe.levelRequirements,
        toolRequirements: recipe.toolRequirements,
        isPublic: lock <= now,
        lockExpiresAt: lock,
      },
    ];
  });
}
