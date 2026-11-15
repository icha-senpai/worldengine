import { connectBitcraft } from "../bitcraft";
export { projectOpenCrafts } from "../../bitcraft/src/open-crafts";

export async function relayEmpireFilter(filters) {
  const conn = await connectBitcraft();
  const snapshot = JSON.parse(await conn.procedures.readEmpireDirectory({}));
  if (snapshot.error || !snapshot.data)
    throw Error(snapshot.error || "Empire directory is unavailable.");
  const { empires, settlements } = snapshot.data;
  const query = String(filters.empire ?? "").toLowerCase();
  const empire =
    filters.empireEntityId || /^\d+$/.test(query)
      ? empires.find(
          (row) => row.entityId === String(filters.empireEntityId || query),
        )
      : (empires.find((row) => row.name.toLowerCase() === query) ??
        empires.find((row) => row.name.toLowerCase().includes(query)));
  if (!empire)
    throw Error(
      `No empire matched '${filters.empire || filters.empireEntityId}'.`,
    );
  filters.empireEntityId = empire.entityId;
  filters.empireName = empire.name;
  filters.claimIds = settlements
    .filter((row) => String(row.empireEntityId) === empire.entityId)
    .map((row) => String(row.claimEntityId));
  return empires.filter(
    (row) =>
      !query ||
      row.name.toLowerCase().includes(query) ||
      row.entityId === query,
  );
}
export async function relayOpenCrafts(playerId = "") {
  const conn = await connectBitcraft();
  const snapshot = JSON.parse(await conn.procedures.readOpenCrafts({}));
  if (snapshot.error) throw Error(snapshot.error);
  const now = Date.now();
  const craftResults = snapshot.craftResults
    .filter(
      (row) =>
        row.lockExpiresAt <= now ||
        String(row.ownerEntityId) === String(playerId),
    )
    .map((row) => ({ ...row, isPublic: row.lockExpiresAt <= now }));
  return craftResponse(craftResults, snapshot.updatedAt);
}

function craftResponse(craftResults, updatedAt) {
  const items = new Map(),
    cargos = new Map();
  for (const craft of craftResults)
    for (const output of craft.craftedItem ?? [])
      (output.kind === "cargo" ? cargos : items).set(output.id, output);
  return {
    data: {
      craftResults,
      items: [...items.values()],
      cargos: [...cargos.values()],
    },
    refresh: { delayed: false, updatedAt },
    error: null,
  };
}
