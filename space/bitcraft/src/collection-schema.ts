import { table, t } from "spacetimedb/server";

export const collectorIdentity = table(
  { name: "collector_identity" },
  {
    identity: t.identity().primaryKey(),
  },
);
export const collectionWatch = table(
  { name: "collection_watch" },
  {
    key: t.string().primaryKey(),
    resource: t.string(),
    entityId: t.string(),
    query: t.string(),
    page: t.u32(),
    options: t.string(),
    expiresAt: t.u64(),
  },
);
export const collectionFeed = table(
  { name: "collection_feed", public: true },
  {
    key: t.string().primaryKey(),
    resource: t.string(),
    entityId: t.string(),
    source: t.string(),
    epoch: t.string(),
    payload: t.string(),
    observedAt: t.u64(),
    receivedAt: t.u64(),
    expiresAt: t.u64(),
    error: t.string(),
  },
);
export const collectionStatus = table(
  { name: "collection_status", public: true },
  {
    key: t.string().primaryKey(),
    epoch: t.string(),
    heartbeatAt: t.u64(),
    feeds: t.u32(),
    error: t.string(),
  },
);
export const collectionSample = table(
  { name: "collection_sample", public: true },
  {
    key: t.string().primaryKey(),
    scope: t.string().index("btree"),
    source: t.string(),
    epoch: t.string(),
    observedAt: t.u64(),
    payload: t.string(),
  },
);
// An indexed projection is scoped to its complete response, not the world.
export const collectedEntity = table(
  { name: "collected_entity", public: true },
  {
    key: t.string().primaryKey(),
    scope: t.string().index("btree"),
    kind: t.string().index("btree"),
    entityId: t.string().index("btree"),
    ownerId: t.string().index("btree"),
    claimId: t.string().index("btree"),
    itemKey: t.string().index("btree"),
    region: t.u32(),
    name: t.string(),
    quantity: t.f64(),
    progress: t.f64(),
    total: t.f64(),
    payload: t.string(),
    observedAt: t.u64(),
    source: t.string(),
  },
);
