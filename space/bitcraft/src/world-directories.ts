import { CURRENT_REGIONS } from "./relay-trading";
import { SCHOOL_BY_ID } from "./fishing";

type Row = Record<string, any>;
const id = (value: unknown) => {
  if (typeof value !== "string" || !/^\d{1,24}$/.test(value))
    throw Error("Invalid directory entity ID.");
  return value;
};
export function projectFishLocations(tables: Record<string, Row[]>) {
  const locations = new Map(
    (tables.location_state ?? []).map((row) => [String(row.entity_id), row]),
  );
  return (tables.resource_state ?? []).flatMap((row) => {
    if (!SCHOOL_BY_ID.has(Number(row.resource_id))) return [];
    const location = locations.get(String(row.entity_id));
    if (!location) throw Error("Fish location metadata is incomplete.");
    if (Number(location.dimension) !== 1) return [];
    return [
      {
        entityId: String(row.entity_id),
        resourceId: Number(row.resource_id),
        x: Number(location.x),
        z: Number(location.z),
      },
    ];
  });
}
export function projectEmpireDirectory(tables: Record<string, Row[]>) {
  return {
    empires: (tables.empire_state ?? []).map((row) => ({
      entityId: String(row.entity_id),
      name: row.name,
    })),
    settlements: (tables.empire_settlement_state ?? []).map((row) => ({
      entityId: String(row.building_entity_id),
      empireEntityId: String(row.empire_entity_id),
      claimEntityId: String(row.claim_entity_id),
    })),
  };
}
export function validateWorldDirectory(
  kind: string,
  region: number,
  value: any,
) {
  if (kind === "fish" && CURRENT_REGIONS[region]) {
    if (!Array.isArray(value) || value.length > 30000)
      throw Error("Invalid fish directory.");
    const ids = new Set<string>();
    return value.map((row: Row) => {
      const entityId = id(row.entityId);
      if (
        ids.has(entityId) ||
        !SCHOOL_BY_ID.has(row.resourceId) ||
        !Number.isInteger(row.x) ||
        !Number.isInteger(row.z) ||
        Math.abs(row.x) > 1000000 ||
        Math.abs(row.z) > 1000000
      )
        throw Error("Invalid fish location.");
      ids.add(entityId);
      return { entityId, resourceId: row.resourceId, x: row.x, z: row.z };
    });
  }
  if (kind === "empires" && region === 0) {
    if (
      !Array.isArray(value?.empires) ||
      !Array.isArray(value?.settlements) ||
      value.empires.length > 10000 ||
      value.settlements.length > 40000
    )
      throw Error("Invalid empire directory.");
    const ids = new Set<string>();
    const empires = value.empires.map((row: Row) => {
      const entityId = id(row.entityId);
      if (
        ids.has(entityId) ||
        typeof row.name !== "string" ||
        row.name.length > 200
      )
        throw Error("Invalid empire.");
      ids.add(entityId);
      return { entityId, name: row.name };
    });
    const settlements = value.settlements.map((row: Row) => ({
      entityId: id(row.entityId),
      empireEntityId: id(row.empireEntityId),
      claimEntityId: id(row.claimEntityId),
    }));
    return { empires, settlements };
  }
  throw Error("Invalid world directory scope.");
}
