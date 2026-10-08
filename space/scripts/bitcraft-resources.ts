export interface ResourceDictionary {
  ready: boolean;
  region: number;
  dict_version: number;
  entries: Array<{
    index: number;
    resource_id: number;
    name: string;
    paving: boolean;
    harvestable: boolean;
  }>;
}
export function tileToCoordinates(x: number, z: number) {
  const q = x - (z - (z & 1)) / 2;
  const north = Math.floor((z + 1) / 3),
    Q = Math.floor((q + 1) / 3);
  return { north, east: Q + (north - (north & 1)) / 2 };
}
export function summarizeResources(
  bytes: Uint8Array,
  dictionary: ResourceDictionary,
) {
  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  if (
    bytes.length !== 320024 ||
    String.fromCharCode(...bytes.slice(0, 4)) !== "BMR1" ||
    view.getUint16(4, true) !== 1 ||
    view.getUint16(6, true) !== 400
  )
    throw new Error("Unsupported resource window.");
  const region = view.getUint32(16, true),
    version = view.getUint32(20, true);
  if (
    !dictionary.ready ||
    dictionary.region !== region ||
    dictionary.dict_version !== version
  )
    throw new Error("Resource dictionary needs resynchronization.");
  const originX = view.getInt32(8, true),
    originZ = view.getInt32(12, true);
  const entries = new Map(
    dictionary.entries
      .filter((row) => !row.paving)
      .map((row) => [row.index, row]),
  );
  const resources = new Map<
    number,
    {
      resourceId: number;
      name: string;
      count: number;
      distance: number;
      north: number;
      east: number;
    }
  >();
  const px = originX + 200,
    pz = originZ + 200,
    pq = px - (pz - (pz & 1)) / 2;
  for (let offset = 0; offset < 160000; offset++) {
    const word = view.getUint16(24 + offset * 2, true);
    // Count origin tiles once per resource instance. Paving has a separate
    // dictionary namespace and is not a gathering node.
    if (!(word & 1024) || word & 16384) continue;
    const entry = entries.get(word & 1023);
    if (!entry) throw new Error("Unknown resource index.");
    const x = originX + (offset % 400),
      z = originZ + Math.floor(offset / 400);
    const q = x - (z - (z & 1)) / 2;
    const distance =
      (Math.abs(q - pq) + Math.abs(z - pz) + Math.abs(q - pq + z - pz)) / 2;
    const row = resources.get(entry.resource_id) ?? {
      resourceId: entry.resource_id,
      name: entry.name,
      count: 0,
      distance,
      ...tileToCoordinates(x, z),
    };
    row.count++;
    if (distance < row.distance)
      Object.assign(row, { distance, ...tileToCoordinates(x, z) });
    resources.set(entry.resource_id, row);
  }
  return {
    region,
    dictVersion: version,
    originX,
    originZ,
    resources: [...resources.values()].sort((a, b) => a.distance - b.distance),
  };
}
