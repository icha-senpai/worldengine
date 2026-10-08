import { table, t } from "spacetimedb/server";
export const relayBook = table(
  { name: "relay_book" },
  {
    key: t.string().primaryKey(),
    regionId: t.u32().index("btree"),
    itemKey: t.string().index("btree"),
    payload: t.string(),
  },
);
export const relayStall = table(
  { name: "relay_stall" },
  {
    key: t.string().primaryKey(),
    regionId: t.u32().index("btree"),
    payload: t.string(),
  },
);
export const relayRegion = table(
  { name: "relay_region" },
  {
    regionId: t.u32().primaryKey(),
    payload: t.string(),
    epoch: t.string(),
    observedAt: t.u64(),
  },
);
export const relayStatus = table(
  { name: "relay_status", public: true },
  {
    key: t.string().primaryKey(),
    provider: t.string(),
    regionId: t.u32(),
    epoch: t.string(),
    ready: t.bool(),
    heartbeatAt: t.u64(),
    observedAt: t.u64(),
    rows: t.u32(),
    error: t.string(),
  },
);
