import { table, t } from "spacetimedb/server";

export const bossDefeat = table(
  { name: "boss_defeat" },
  {
    key: t.string().primaryKey(),
    regionId: t.u32().index("btree"),
    enemyType: t.u32().index("btree"),
    diedAt: t.u64().index("btree"),
    payload: t.string(),
  },
);
export const bossScore = table(
  { name: "boss_score" },
  {
    key: t.string().primaryKey(),
    enemyType: t.u32().index("btree"),
    playerId: t.string(),
    name: t.string(),
    defeats: t.u64(),
    finalBlows: t.u64(),
    contribution: t.f64(),
    lastDefeatAt: t.u64(),
  },
);
export const bossTotals = table(
  { name: "boss_totals", public: true },
  {
    key: t.string().primaryKey(),
    enemyType: t.u32(),
    defeats: t.u64(),
    players: t.u32(),
    detailCount: t.u32(),
    detailBytes: t.u64(),
    startedAt: t.u64(),
    prunedAt: t.u64(),
    prunedThrough: t.u64(),
  },
);
export const bossRegion = table(
  { name: "boss_region", public: true },
  {
    key: t.string().primaryKey(),
    regionId: t.u32(),
    epoch: t.string(),
    ready: t.bool(),
    startedAt: t.u64(),
    connectedAt: t.u64(),
    heartbeatAt: t.u64(),
    gapStartedAt: t.u64(),
    lastGapEndedAt: t.u64(),
    gaps: t.u32(),
    error: t.string(),
  },
);
