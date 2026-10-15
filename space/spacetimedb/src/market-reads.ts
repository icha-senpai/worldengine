import { Range, type InferSchema, type ReducerCtx } from "spacetimedb/server";
import db from "./schema";
import { MARKET_PAGE_SIZE, pageWindow } from "./performance";

type Ctx = ReducerCtx<InferSchema<typeof db>>;
const maxU64 = (1n << 64n) - 1n;

// B-tree scans ascend. Inverting timestamp and id gives newest-first order,
// including deterministic id tie-breaking, without sorting the whole market.
export function listingOrderKey(micros: bigint, id: bigint) {
  return `${(maxU64 - micros).toString().padStart(20, "0")}:${(maxU64 - id).toString().padStart(20, "0")}`;
}
export function bumpMarketRevision(ctx: Ctx) {
  const previous = ctx.db.marketRevision.id.find(1);
  const row = { id: 1, revision: (previous?.revision ?? 0n) + 1n };
  if (previous) ctx.db.marketRevision.id.update(row);
  else ctx.db.marketRevision.insert(row);
}
export function prepareMarketReads(ctx: Ctx) {
  for (const row of ctx.db.listing.iter()) {
    indexListing(ctx, row);
  }
  for (const row of ctx.db.marketOrder.iter())
    if (!ctx.db.listing.id.find(row.id)) ctx.db.marketOrder.id.delete(row.id);
  if (!ctx.db.marketRevision.id.find(1))
    ctx.db.marketRevision.insert({ id: 1, revision: 0n });
}
export function indexListing(
  ctx: Ctx,
  listing: { id: bigint; createdAt: { microsSinceUnixEpoch: bigint } },
) {
  const row = {
    id: listing.id,
    sortKey: listingOrderKey(
      listing.createdAt.microsSinceUnixEpoch,
      listing.id,
    ),
  };
  const previous = ctx.db.marketOrder.id.find(row.id);
  if (!previous) ctx.db.marketOrder.insert(row);
  else if (previous.sortKey !== row.sortKey) ctx.db.marketOrder.id.update(row);
}
export function readMarketPage(
  ctx: Ctx,
  requested: number,
  after: string,
  expectedRevision = "",
) {
  if (after && !/^\d{20}:\d{20}$/.test(after))
    throw Error("Invalid market cursor.");
  const total = Number(ctx.db.listing.count());
  const window = pageWindow(total, requested);
  // A cursor is only useful while the caller's market revision remains current.
  const revision = String(ctx.db.marketRevision.id.find(1)?.revision ?? 0n);
  const cursor =
    window.page === requested && expectedRevision === revision ? after : "";
  const range = new Range<string>(
    cursor
      ? { tag: "excluded", value: cursor }
      : { tag: "excluded", value: "" },
  );
  const rows = [];
  let skipped = 0;
  let next = "";
  for (const indexed of ctx.db.marketOrder.sortKey.filter(range)) {
    if (!cursor && skipped++ < window.offset) continue;
    const row = ctx.db.listing.id.find(indexed.id);
    if (!row) continue;
    rows.push({
      ...row,
      page: window.page,
      metadata:
        ctx.db.catalog.key.find(`item_metadata:${row.itemKey}:${row.rarity}`)
          ?.payload ?? "{}",
    });
    next = indexed.sortKey;
    if (rows.length >= MARKET_PAGE_SIZE) break;
  }
  return { ...window, revision, rows, next };
}
