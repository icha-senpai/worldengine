import {
  BOSSES,
  BOSS_DETAIL_BYTES,
  BOSS_DETAIL_DAYS,
  BOSS_DETAIL_LIMIT,
  BOSS_SCORE_LIMIT,
  bossKey,
  type BossDefeat,
} from "./bosses";
import { CURRENT_REGIONS } from "./relay-trading";

const MICROS = 1_000_000n;
const json = (value: unknown) =>
  JSON.stringify(value, (_key, value) =>
    typeof value === "bigint" ? String(value) : value,
  );
const bytes = (payload: string) => BigInt(payload.length * 3 + 256);
function total(ctx: any, enemyType: number) {
  return (
    ctx.db.bossTotals.key.find(String(enemyType)) ?? {
      key: String(enemyType),
      enemyType,
      defeats: 0n,
      players: 0,
      detailCount: 0,
      detailBytes: 0n,
      startedAt: ctx.timestamp.microsSinceUnixEpoch,
      prunedAt: 0n,
      prunedThrough: 0n,
    }
  );
}
function saveTotal(ctx: any, row: any) {
  if (ctx.db.bossTotals.key.find(row.key)) ctx.db.bossTotals.key.update(row);
  else ctx.db.bossTotals.insert(row);
}
export function saveBossCoverage(
  ctx: any,
  args: { regionId: number; epoch: string; ready: boolean; error: string },
) {
  if (
    !CURRENT_REGIONS[args.regionId] ||
    !args.epoch ||
    args.epoch.length > 100 ||
    args.error.length > 240
  )
    throw Error("Invalid boss recording coverage.");
  const now = ctx.timestamp.microsSinceUnixEpoch;
  const previous = ctx.db.bossRegion.key.find(String(args.regionId));
  const interrupted = Boolean(
    previous &&
    previous.ready &&
    (!args.ready ||
      previous.epoch !== args.epoch ||
      previous.heartbeatAt + 70n * MICROS < now),
  );
  const gapStartedAt = interrupted
    ? previous.heartbeatAt
    : (previous?.gapStartedAt ?? 0n);
  const row = {
    key: String(args.regionId),
    ...args,
    startedAt: previous?.startedAt ?? now,
    connectedAt:
      args.ready && (!previous?.ready || interrupted)
        ? now
        : (previous?.connectedAt ?? 0n),
    heartbeatAt: now,
    gaps: (previous?.gaps ?? 0) + Number(interrupted),
    gapStartedAt: args.ready ? 0n : gapStartedAt,
    lastGapEndedAt:
      args.ready && gapStartedAt ? now : (previous?.lastGapEndedAt ?? 0n),
  };
  if (previous) ctx.db.bossRegion.key.update(row);
  else ctx.db.bossRegion.insert(row);
  if (!ctx.db.bossTotals.key.find("0")) saveTotal(ctx, total(ctx, 0));
  pruneBossHistory(ctx);
}
function parseDefeat(raw: any, now: bigint): BossDefeat {
  const validId = (value: unknown) =>
    typeof value === "string" && /^\d{1,24}$/.test(value);
  if (
    !raw ||
    !BOSSES[raw.enemyType] ||
    !validId(raw.enemyId) ||
    typeof raw.diedAt !== "string" ||
    !/^\d{1,12}$/.test(raw.diedAt) ||
    !Array.isArray(raw.participants) ||
    raw.participants.length > 512 ||
    typeof raw.killerId !== "string" ||
    (raw.killerId && !validId(raw.killerId))
  )
    throw Error("Invalid boss defeat.");
  const diedAt = BigInt(raw.diedAt) * MICROS;
  // Old replays cannot re-award totals after their detail/dedup rows expire.
  if (
    diedAt <= 0n ||
    diedAt > now + 5n * MICROS ||
    diedAt + 3600n * MICROS < now
  )
    throw Error("Boss defeat is outside the recording window.");
  const seen = new Set();
  const participants = raw.participants
    .map((p: any) => {
      if (
        !validId(p.id) ||
        seen.has(p.id) ||
        typeof p.name !== "string" ||
        !p.name.trim() ||
        p.name.length > 100 ||
        !Number.isFinite(p.contribution) ||
        p.contribution <= 0 ||
        p.contribution > 1e9
      )
        throw Error("Invalid boss participant.");
      seen.add(p.id);
      return { id: p.id, name: p.name.trim(), contribution: p.contribution };
    })
    .sort((a: any, b: any) => a.id.localeCompare(b.id));
  if (raw.killerId && !seen.has(raw.killerId))
    throw Error("Final blow player must be an observed contributor.");
  return {
    enemyId: raw.enemyId,
    enemyType: raw.enemyType,
    diedAt: raw.diedAt,
    participants,
    killerId: raw.killerId,
  };
}
export function saveBossDefeats(
  ctx: any,
  args: { regionId: number; epoch: string; payload: string },
) {
  if (
    !CURRENT_REGIONS[args.regionId] ||
    !args.epoch ||
    args.epoch.length > 100 ||
    args.payload.length > 1_000_000
  )
    throw Error("Invalid boss recording packet.");
  const raw = JSON.parse(args.payload);
  if (!Array.isArray(raw) || raw.length > 100)
    throw Error("Boss recording batch is too large.");
  const now = ctx.timestamp.microsSinceUnixEpoch;
  const defeats = raw.map((d) => parseDefeat(d, now));
  for (const defeat of defeats) {
    const key = bossKey(args.regionId, defeat),
      payload = json(defeat);
    const existing = ctx.db.bossDefeat.key.find(key);
    if (existing) {
      if (existing.payload !== payload)
        throw Error("Conflicting evidence for an already recorded boss death.");
      continue;
    }
    const diedAt = BigInt(defeat.diedAt) * MICROS;
    if (diedAt <= total(ctx, 0).prunedThrough)
      throw Error("Boss defeat predates the retained deduplication window.");
    ctx.db.bossDefeat.insert({
      key,
      regionId: args.regionId,
      enemyType: defeat.enemyType,
      diedAt,
      payload,
    });
    for (const enemyType of [0, defeat.enemyType]) {
      const stats = total(ctx, enemyType);
      for (const p of defeat.participants) {
        const scoreKey = `${enemyType}:${p.id}`,
          old = ctx.db.bossScore.key.find(scoreKey);
        if (!old && ctx.db.bossScore.count() >= BigInt(BOSS_SCORE_LIMIT))
          throw Error(
            "Boss leaderboard reached its player budget; collection requires review.",
          );
        const row = {
          key: scoreKey,
          enemyType,
          playerId: p.id,
          name: p.name,
          defeats: (old?.defeats ?? 0n) + 1n,
          finalBlows:
            (old?.finalBlows ?? 0n) + BigInt(p.id === defeat.killerId),
          contribution: (old?.contribution ?? 0) + p.contribution,
          lastDefeatAt: old?.lastDefeatAt > diedAt ? old.lastDefeatAt : diedAt,
        };
        if (old) ctx.db.bossScore.key.update(row);
        else ctx.db.bossScore.insert(row);
        if (!old) stats.players++;
      }
      stats.defeats++;
      stats.detailCount++;
      stats.detailBytes += bytes(payload);
      saveTotal(ctx, stats);
    }
  }
  pruneBossHistory(ctx);
}
export function pruneBossHistory(ctx: any) {
  let root = ctx.db.bossTotals.key.find("0");
  if (!root) return;
  const now = ctx.timestamp.microsSinceUnixEpoch;
  if (
    root.prunedAt + 3600n * MICROS > now &&
    root.detailCount <= BOSS_DETAIL_LIMIT &&
    root.detailBytes <= BigInt(BOSS_DETAIL_BYTES)
  )
    return;
  const cutoff = now - BigInt(BOSS_DETAIL_DAYS * 86400) * MICROS;
  const records = [...ctx.db.bossDefeat.iter()].sort((a: any, b: any) =>
    a.diedAt < b.diedAt
      ? -1
      : a.diedAt > b.diedAt
        ? 1
        : a.key.localeCompare(b.key),
  );
  for (const row of records as any[]) {
    root = total(ctx, 0);
    if (
      row.diedAt >= cutoff &&
      root.detailCount <= BOSS_DETAIL_LIMIT &&
      root.detailBytes <= BigInt(BOSS_DETAIL_BYTES)
    )
      break;
    ctx.db.bossDefeat.key.delete(row.key);
    for (const enemyType of [0, row.enemyType]) {
      const stats = total(ctx, enemyType);
      stats.detailCount--;
      stats.detailBytes -= bytes(row.payload);
      stats.prunedThrough =
        row.diedAt > stats.prunedThrough ? row.diedAt : stats.prunedThrough;
      saveTotal(ctx, stats);
    }
  }
  saveTotal(ctx, { ...total(ctx, 0), prunedAt: now });
}
export function queryBossBoard(
  ctx: any,
  args: {
    enemyType: number;
    query: string;
    sort: string;
    page: number;
    limit: number;
  },
) {
  if (
    (args.enemyType && !BOSSES[args.enemyType]) ||
    args.query.length > 100 ||
    !["defeats", "finalBlows", "contribution"].includes(args.sort)
  )
    throw Error("Invalid boss leaderboard filter.");
  const now = ctx.timestamp.microsSinceUnixEpoch,
    query = args.query.trim().toLowerCase();
  const ranked = [...ctx.db.bossScore.enemyType.filter(args.enemyType)]
    .sort((a: any, b: any) => {
      const primary =
        a[args.sort] === b[args.sort]
          ? 0
          : a[args.sort] > b[args.sort]
            ? -1
            : 1;
      return (
        primary ||
        (a.defeats === b.defeats
          ? a.playerId.localeCompare(b.playerId)
          : a.defeats > b.defeats
            ? -1
            : 1)
      );
    })
    .map((r: any, i: number) => ({ ...r, rank: i + 1 }));
  const matches = ranked.filter(
    (r: any) =>
      !query || r.name.toLowerCase().includes(query) || r.playerId === query,
  );
  const limit = Math.max(1, Math.min(args.limit || 50, 100)),
    pages = Math.max(1, Math.ceil(matches.length / limit));
  const page = Math.max(1, Math.min(args.page || 1, pages));
  const source = args.enemyType
    ? ctx.db.bossDefeat.enemyType.filter(args.enemyType)
    : ctx.db.bossDefeat.iter();
  const recent = [...source]
    .sort((a: any, b: any) =>
      a.diedAt === b.diedAt
        ? b.key.localeCompare(a.key)
        : a.diedAt > b.diedAt
          ? -1
          : 1,
    )
    .slice(0, 25)
    .map((r: any) => {
      const d: BossDefeat = JSON.parse(r.payload),
        killer = d.participants.find((p) => p.id === d.killerId);
      return {
        key: r.key,
        regionId: r.regionId,
        enemyType: r.enemyType,
        diedAt: r.diedAt,
        participants: d.participants.length,
        killerId: d.killerId,
        killerName: killer?.name ?? "",
      };
    });
  const coverage = [...ctx.db.bossRegion.iter()]
    .map((r: any) => ({
      ...r,
      ready: r.ready && r.heartbeatAt + 70n * MICROS > now,
    }))
    .sort((a: any, b: any) => a.regionId - b.regionId);
  const tracking = ctx.db.bossTotals.key.find("0") ?? {
    ...total(ctx, 0),
    startedAt: 0n,
  };
  return json({
    bosses: BOSSES,
    totals: { ...total(ctx, args.enemyType), startedAt: tracking.startedAt },
    tracking,
    rows: matches.slice((page - 1) * limit, page * limit),
    total: matches.length,
    page,
    pages,
    recent,
    coverage,
    detailDays: BOSS_DETAIL_DAYS,
    detailLimit: BOSS_DETAIL_LIMIT,
    detailByteLimit: BOSS_DETAIL_BYTES,
  });
}
export function queryBossDefeat(ctx: any, key: string) {
  if (!/^\d+:\d{1,24}:\d{1,12}$/.test(key))
    throw Error("Invalid boss encounter.");
  const row = ctx.db.bossDefeat.key.find(key);
  return json(
    row
      ? {
          key,
          regionId: row.regionId,
          diedAt: row.diedAt,
          ...JSON.parse(row.payload),
          deathAtMicros: row.diedAt,
        }
      : null,
  );
}
