export const orderFields = [
  "sellOrders",
  "buyOrders",
  "packageSellOrders",
  "packageBuyOrders",
];
// Parsed payloads can reconstruct properties in a different order. Compare
// values, so a complete reconnect does not rewrite unchanged book projections.
export function tradingJson(value: any) {
  return JSON.stringify(value, (_key, child) =>
    child && typeof child === "object" && !Array.isArray(child)
      ? Object.fromEntries(
          Object.keys(child)
            .sort()
            .map((key) => [key, child[key]]),
        )
      : child,
  );
}

function put(table: any, row: any) {
  const old = table.key.find(row.key);
  if (row.payload.length > 64000 || (!old && table.count() >= 250000n))
    throw Error("Trading record budget reached.");
  if (old?.payload === row.payload) return false;
  if (old) table.key.update(row);
  else table.insert(row);
  return true;
}
function compactReference(ctx: any, order: any) {
  const result = { ...order };
  for (const [kind, id, fields] of [
    [
      "claim",
      order.claimEntityId,
      ["claimName", "claimLocationX", "claimLocationZ"],
    ],
    ["owner", order.ownerEntityId, ["ownerUsername"]],
  ] as [string, any, string[]][]) {
    if (id == null) continue;
    const value: Record<string, any> = {};
    for (const field of fields)
      if (field in result) {
        value[field] = result[field];
        delete result[field];
      }
    if (!Object.keys(value).length) continue;
    const key = `${order.regionId}:${kind}:${id}`;
    put(ctx.db.tradeReference, { key, payload: JSON.stringify(value) });
    result[`_${kind}Ref`] = key;
  }
  return result;
}
function expandReference(ctx: any, order: any) {
  const result = { ...order };
  for (const kind of ["claim", "owner"]) {
    const field = `_${kind}Ref`;
    const row = result[field] && ctx.db.tradeReference.key.find(result[field]);
    if (row) Object.assign(result, JSON.parse(row.payload));
    delete result[field];
  }
  return result;
}
export function clearTradeScope(ctx: any, scope: string) {
  for (const row of ctx.db.tradeRecord.scope.filter(scope))
    ctx.db.tradeRecord.key.delete(row.key);
}
export function storeBook(ctx: any, scope: string, book: any) {
  const remaining = new Set(
    [...ctx.db.tradeRecord.scope.filter(scope)].map((row: any) => row.key),
  );
  let changed = false;
  for (const side of orderFields)
    for (const order of book[side] ?? []) {
      const key = `${scope}|${side}|${order.regionId}:${order.entityId}`;
      changed =
        put(ctx.db.tradeRecord, {
          key,
          scope,
          payload: JSON.stringify({
            side,
            order: compactReference(ctx, order),
          }),
        }) || changed;
      remaining.delete(key);
    }
  for (const key of remaining) {
    ctx.db.tradeRecord.key.delete(key);
    changed = true;
  }
  return {
    payload: JSON.stringify({
      ...book,
      ...Object.fromEntries(orderFields.map((side) => [side, []])),
      _orderScope: scope,
    }),
    changed,
  };
}
export function loadBook(ctx: any, payload: string) {
  const book = JSON.parse(payload);
  if (!book._orderScope) return book; // Gradual migration of existing books.
  for (const row of ctx.db.tradeRecord.scope.filter(book._orderScope)) {
    const { side, order } = JSON.parse(row.payload);
    book[side].push(expandReference(ctx, order));
  }
  delete book._orderScope;
  return book;
}
export function storeStall(ctx: any, scope: string, stall: any) {
  const remaining = new Set(
    [...ctx.db.tradeRecord.scope.filter(scope)].map((row: any) => row.key),
  );
  let changed = false;
  for (const order of stall.orders) {
    const key = `${scope}|${order.entityId}`;
    const compact = { ...order };
    for (const field of [
      "offerItems",
      "requiredItems",
      "offerCargo",
      "requiredCargo",
    ])
      compact[field] = order[field].map((item: any) => ({
        id: String(item.id),
        quantity: item.quantity,
      }));
    changed =
      put(ctx.db.tradeRecord, {
        key,
        scope,
        payload: JSON.stringify(compact),
      }) || changed;
    remaining.delete(key);
  }
  for (const key of remaining) {
    ctx.db.tradeRecord.key.delete(key);
    changed = true;
  }
  return {
    payload: JSON.stringify({ ...stall, orders: [], _orderScope: scope }),
    changed,
  };
}
export function loadStall(
  ctx: any,
  payload: string,
  metadata: (kind: string, id: string) => any,
) {
  const stall = JSON.parse(payload);
  if (!stall._orderScope) return stall;
  stall.orders = [...ctx.db.tradeRecord.scope.filter(stall._orderScope)].map(
    (row: any) => {
      const order = JSON.parse(row.payload);
      for (const field of [
        "offerItems",
        "requiredItems",
        "offerCargo",
        "requiredCargo",
      ])
        order[field] = order[field].map((item: any) => {
          const kind = field.endsWith("Cargo") ? "cargo" : "item";
          const known = metadata(kind, item.id);
          return {
            ...known,
            id: String(item.id),
            kind,
            name:
              known?.name ??
              `${kind === "cargo" ? "Cargo" : "Item"} ${item.id}`,
            quantity: item.quantity,
          };
        });
      return order;
    },
  );
  delete stall._orderScope;
  return stall;
}
