import { CURRENT_REGIONS } from "../../bitcraft/src/relay-trading";

// Locations use small offset hexes; terrain uses large offset hexes (3x).
export const REGIONS = Object.entries(CURRENT_REGIONS).map(([id, name]) => [
  Number(id),
  name,
]);
export { SPECIES, SCHOOL_BY_ID } from "../../bitcraft/src/fishing";
export const WORLD_SIZE = 38400;
export const HEX_SCALE = Math.sqrt(3) / 2;
const round = (n) => Math.sign(n) * Math.floor(Math.abs(n) + 0.5);
export function terrainCell(x, z) {
  const r = round(z / 3);
  const q = round((x - Math.trunc(z / 2)) / 3);
  const tx = q + Math.trunc(r / 2);
  const cx = Math.floor(tx / 32),
    cz = Math.floor(r / 32);
  return {
    x: tx,
    z: r,
    chunk: cz * 1000 + cx + 1,
    index: (r - cz * 32) * 32 + tx - cx * 32,
  };
}
export function smallCell(x, z) {
  const r = z * 3,
    q = (x - Math.trunc(z / 2)) * 3;
  return { x: q + Math.trunc(r / 2), z: r };
}
export function waterDepth(chunks, x, z) {
  const cell = terrainCell(x, z);
  if (chunks.depthAtTerrain) return chunks.depthAtTerrain(cell.x, cell.z);
  const row = chunks.get(cell.chunk);
  const elevation = row?.elevations?.[cell.index],
    water = row?.water_levels?.[cell.index];
  return Number.isFinite(elevation) && Number.isFinite(water)
    ? Math.max(0, water - elevation)
    : null;
}
export function depthColor(depth) {
  return depth === 0
    ? "#70866b"
    : depth < 13
      ? "#e0b268"
      : depth < 25
        ? "#52cbbb"
        : depth < 50
          ? "#4c9cdf"
          : "#8771d6";
}
export function spawnRing(chunks, x, z) {
  const q = x - Math.trunc(z / 2);
  let eligible = 0,
    unknown = 0,
    total = 0;
  for (let dq = -9; dq <= 9; dq++)
    for (let dr = -9; dr <= 9; dr++) {
      const distance = Math.max(Math.abs(dq), Math.abs(dr), Math.abs(dq + dr));
      if (distance < 7 || distance > 9) continue;
      const r = z + dr,
        px = q + dq + Math.trunc(r / 2);
      const depth = waterDepth(chunks, px, r);
      total++;
      if (depth === null) unknown++;
      else if (depth >= 13 && depth <= 1000) eligible++;
    }
  return { eligible, unknown, total };
}
// Bound each viewport request. Broad views use the overview, never a world subscription.
export function viewportChunks(west, south, east, north, limit = 64) {
  const a = terrainCell(Math.max(0, west - 4), Math.max(0, south - 4));
  const b = terrainCell(
    Math.min(WORLD_SIZE - 1, east + 4),
    Math.min(WORLD_SIZE - 1, north + 4),
  );
  const minX = Math.floor(a.x / 32),
    maxX = Math.floor(b.x / 32);
  const minZ = Math.floor(a.z / 32),
    maxZ = Math.floor(b.z / 32);
  if (
    maxX < minX ||
    maxZ < minZ ||
    (maxX - minX + 1) * (maxZ - minZ + 1) > limit
  )
    return [];
  const chunks = [];
  for (let z = minZ; z <= maxZ; z++)
    for (let x = minX; x <= maxX; x++) chunks.push(z * 1000 + x + 1);
  return chunks;
}
export function parseRelayJson(raw) {
  return JSON.parse(raw, (_key, value, context) => {
    if (
      typeof value === "number" &&
      Number.isInteger(value) &&
      !Number.isSafeInteger(value)
    ) {
      if (!context?.source)
        throw new Error(
          "This browser cannot preserve relay entity IDs. Please update your browser.",
        );
      return context.source;
    }
    return value;
  });
}
