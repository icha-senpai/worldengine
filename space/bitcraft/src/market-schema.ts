import { table, t } from "spacetimedb/server";

// Durable canonical books never expire or share the request cache's 500-scope cap.
export const marketItem = table(
  { name: "market_item" },
  {
    key: t.string().primaryKey(),
    metadata: t.string(),
    indexAt: t.u64(),
    indexHasOrders: t.bool(),
    bookHasOrders: t.bool(),
    book: t.string(),
    observedAt: t.u64(),
    requestedAt: t.u64(),
    retryAt: t.u64(),
    error: t.string(),
  },
);
export const marketMeta = table(
  { name: "market_meta" },
  {
    key: t.string().primaryKey(),
    payload: t.string(),
    observedAt: t.u64(),
    retryAt: t.u64(),
    error: t.string(),
  },
);
export const marketSummaryRow = table(
  { name: "market_summary", public: true },
  {
    key: t.string().primaryKey(),
    itemKey: t.string().index("btree"),
    regionId: t.u32().index("btree"),
    payload: t.string(),
    observedAt: t.u64(),
  },
);
export const marketStatus = table(
  { name: "market_status", public: true },
  {
    key: t.string().primaryKey(),
    revision: t.u64(),
    indexAt: t.u64(),
    updatedAt: t.u64(),
    totalItems: t.u32(),
    loadedItems: t.u32(),
    activeItems: t.u32(),
    loadedActiveItems: t.u32(),
    error: t.string(),
  },
);
