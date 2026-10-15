import { table, t } from "spacetimedb/server";

export const claimSupplyStatus = table(
  { name: "claim_supply_status" },
  {
    key: t.string().primaryKey(),
    regionId: t.u32().index("btree"),
    payload: t.string(),
    epoch: t.string(),
    observedAt: t.u64(),
  },
);

export const claimHistoryState = table(
  { name: "claim_history_state" },
  {
    key: t.string().primaryKey(),
    regionId: t.u32().index("btree"),
    payload: t.string(),
    epoch: t.string(),
    observedAt: t.u64(),
  },
);
export const claimHistoryEvent = table(
  { name: "claim_history_event" },
  {
    key: t.string().primaryKey(),
    regionId: t.u32().index("btree"),
    claimId: t.string().index("btree"),
    kind: t.string(),
    observedAt: t.u64().index("btree"),
    payload: t.string(),
  },
);
export const claimHistoryRegion = table(
  { name: "claim_history_region" },
  {
    key: t.string().primaryKey(),
    regionId: t.u32(),
    epoch: t.string(),
    sequence: t.u32(),
    observedAt: t.u64(),
    startedAt: t.u64(),
    complete: t.bool(),
    prunedAt: t.u64(),
    discarded: t.u64(),
    reconciling: t.bool(),
  },
);
