// Latest state only. Scheduling leases, raw frames and API responses are excluded.
export const marketCheckpointTables = {
  market_item: "marketItem",
  market_meta: "marketMeta",
  market_summary: "marketSummaryRow",
  market_status: "marketStatus",
  relay_book: "relayBook",
  relay_stall: "relayStall",
  relay_region: "relayRegion",
  trade_record: "tradeRecord",
  trade_reference: "tradeReference",
} as const;
export function checkpointPage(ctx: any, name: string, after: string) {
  const tableName =
    marketCheckpointTables[name as keyof typeof marketCheckpointTables];
  if (!tableName || after.length > 240)
    throw Error("Invalid market checkpoint table.");
  const key = name === "relay_region" ? "regionId" : "key";
  const usedReferences =
    name === "trade_reference"
      ? new Set(
          [...ctx.db.tradeRecord.iter()].flatMap((row: any) => {
            const order = JSON.parse(row.payload).order;
            return order
              ? [order._claimRef, order._ownerRef].filter(Boolean)
              : [];
          }),
        )
      : null;
  const rows = [...ctx.db[tableName].iter()]
    .map((row: any) =>
      name === "market_meta" && row.key === "market"
        ? { ...row, payload: "{}" }
        : row,
    )
    .filter(
      (row) =>
        (!usedReferences || usedReferences.has(row.key)) &&
        String(row[key]) > after,
    )
    .sort((a, b) => (String(a[key]) < String(b[key]) ? -1 : 1));
  const page = [];
  let size = 0;
  for (const row of rows) {
    const encoded = JSON.stringify(row, (_key, value) =>
      typeof value === "bigint" ? String(value) : value,
    );
    if (encoded.length > 8000000)
      throw Error("Market checkpoint row exceeds its budget.");
    if (page.length && (size + encoded.length > 1000000 || page.length >= 2000))
      break;
    page.push(row);
    size += encoded.length;
  }
  return JSON.stringify(
    {
      rows: page,
      next: page.length ? String(page[page.length - 1][key]) : "",
      done: page.length === rows.length,
    },
    (_key, value) => (typeof value === "bigint" ? String(value) : value),
  );
}
export function restoreCheckpointPage(ctx: any, name: string, payload: string) {
  const tableName =
    marketCheckpointTables[name as keyof typeof marketCheckpointTables];
  if (!tableName || payload.length > 9000000)
    throw Error("Invalid market checkpoint chunk.");
  const rows = JSON.parse(payload);
  if (!Array.isArray(rows) || rows.length > 2000)
    throw Error("Invalid checkpoint rows.");
  const table = ctx.db[tableName],
    key = name === "relay_region" ? "regionId" : "key";
  for (const raw of rows) {
    const row = { ...raw };
    for (const field of [
      "indexAt",
      "observedAt",
      "requestedAt",
      "retryAt",
      "revision",
      "updatedAt",
    ])
      if (field in row) row[field] = BigInt(row[field]);
    if (table[key].find(row[key])) table[key].update(row);
    else table.insert(row);
  }
}
