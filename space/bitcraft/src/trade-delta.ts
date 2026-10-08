const fields = [
  "sellOrders",
  "buyOrders",
  "packageSellOrders",
  "packageBuyOrders",
];
export function orderDelta(previous: any, next: any, barter = false) {
  if (!previous) return next;
  const sides = barter ? ["orders"] : fields;
  const patch = {
    ...next,
    _ordersDelta: true,
    _removedOrders: {} as Record<string, string[]>,
  };
  for (const side of sides) {
    const old = new Map(
      (previous[side] ?? []).map((row: any) => [
        String(row.entityId),
        JSON.stringify(row),
      ]),
    );
    const ids = new Set(
      (next[side] ?? []).map((row: any) => String(row.entityId)),
    );
    patch[side] = (next[side] ?? []).filter(
      (row: any) => old.get(String(row.entityId)) !== JSON.stringify(row),
    );
    patch._removedOrders[side] = [...old.keys()].filter(
      (id) => !ids.has(id),
    ) as string[];
  }
  return patch;
}
export function applyOrderDelta(previous: any, patch: any, barter = false) {
  if (!patch._ordersDelta) return patch;
  if (
    !previous ||
    !patch._removedOrders ||
    typeof patch._removedOrders !== "object"
  )
    throw Error("Order delta requires a baseline.");
  const result = { ...patch };
  for (const side of barter ? ["orders"] : fields) {
    const removed = patch._removedOrders[side];
    if (
      !Array.isArray(removed) ||
      removed.length > 100000 ||
      removed.some(
        (id: unknown) => typeof id !== "string" || !/^\d{1,24}$/.test(id),
      ) ||
      !Array.isArray(patch[side])
    )
      throw Error("Invalid order removals.");
    const rows = new Map(
      (previous[side] ?? []).map((row: any) => [String(row.entityId), row]),
    );
    for (const id of removed) rows.delete(id);
    for (const row of patch[side]) rows.set(String(row.entityId), row);
    result[side] = [...rows.values()].sort((a: any, b: any) =>
      String(a.entityId).localeCompare(String(b.entityId)),
    );
  }
  delete result._ordersDelta;
  delete result._removedOrders;
  return result;
}
