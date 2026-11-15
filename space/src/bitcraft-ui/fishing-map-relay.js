import { connectBitcraft } from "../bitcraft";

// The collector owns the regional sockets. Stopping invalidates unfinished reads.
export function subscribeMap(region, callbacks) {
  let stopped = false,
    timer,
    updatedAt;
  callbacks.status?.("connecting");
  async function refresh() {
    try {
      const conn = await connectBitcraft();
      if (stopped) return;
      const snapshot = JSON.parse(
        await conn.procedures.readFishLocations({ regionId: Number(region) }),
      );
      if (stopped) return;
      if (snapshot.data && snapshot.updatedAt !== updatedAt) {
        const changes = snapshot.data.flatMap((row) => [
          {
            table: "resource_state",
            action: "inserts",
            row: { entity_id: row.entityId, resource_id: row.resourceId },
          },
          {
            table: "location_state",
            action: "inserts",
            row: { entity_id: row.entityId, x: row.x, z: row.z, dimension: 1 },
          },
        ]);
        callbacks.rows(changes, true, snapshot.updatedAt);
        updatedAt = snapshot.updatedAt;
      }
      callbacks.status?.(
        snapshot.delayed ? "reconnecting" : "live",
        snapshot.error ?? undefined,
      );
    } catch (error) {
      if (!stopped)
        callbacks.status?.("reconnecting", error.message || String(error));
    } finally {
      if (!stopped) timer = setTimeout(refresh, 5000);
    }
  }
  void refresh();
  return () => {
    stopped = true;
    clearTimeout(timer);
  };
}
