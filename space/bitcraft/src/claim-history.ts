import { CURRENT_REGIONS } from "./relay-trading";
import { claimSupplyEstimate, type ClaimSupply } from "./claim-supplies";

export const HISTORY_DAYS = 30;
export const HISTORY_EVENT_LIMIT = 50_000;
export const HISTORY_STATE_LIMIT = 20_000;
export const HISTORY_MIN_TIER = 4;

// A small public invalidation feed. Clients keep paginated procedure reads;
// raw rosters, supplies and the complete event history are never replicated.
export function claimUpdates(ctx: any) {
  return [...ctx.db.claimHistoryRegion.iter()].map((region: any) => {
    const supplies = [
      ...ctx.db.claimSupplyStatus.regionId.filter(region.regionId),
    ];
    return {
      key: region.key,
      epoch: region.epoch,
      sequence: region.sequence,
      complete: region.complete,
      claims: [...ctx.db.claimHistoryState.regionId.filter(region.regionId)]
        .length,
      supplies: supplies.length,
      supplyVersion: supplies
        .reduce((sum: bigint, row: any) => sum + row.observedAt, 0n)
        .toString(),
    };
  });
}
export type ClaimMember = { id: string; name: string; permissions: string };
export type ClaimState = {
  id: string;
  name: string;
  ownerId: string;
  ownerName: string;
  totemId: string;
  supplies: number | null;
  tier: number | null;
  x: number | null;
  z: number | null;
  members: ClaimMember[];
  waystones?: number;
  banks?: number;
};
export type HistoryChange = {
  before: ClaimState | null;
  after: ClaimState | null;
};
export type HistoryEvent = {
  kind: string;
  claimId: string;
  detail: Record<string, any>;
};

export function isTrackedClaim(claim: ClaimState | null): boolean {
  return (
    !!claim &&
    claim.tier !== null &&
    claim.tier >= HISTORY_MIN_TIER &&
    (claim.waystones ?? 0) > 0 &&
    (claim.banks ?? 0) > 0
  );
}

function isTrackedEvent(row: any, qualifiedClaims: Set<string>): boolean {
  if (!row.claimId) return true;
  const detail = JSON.parse(row.payload);
  return (
    (Number(detail.tier ?? 0) >= HISTORY_MIN_TIER ||
      Number(detail.previousTier ?? 0) >= HISTORY_MIN_TIER) &&
    (detail.trackedBefore === true ||
      detail.trackedAfter === true ||
      qualifiedClaims.has(`${row.regionId}:${row.claimId}`))
  );
}

export function claimChanges(
  before: ClaimState | null,
  after: ClaimState | null,
  gap = false,
): HistoryEvent[] {
  const claim = after ?? before;
  if (!claim || (!isTrackedClaim(before) && !isTrackedClaim(after))) return [];
  const events: HistoryEvent[] = [];
  const add = (kind: string, detail: Record<string, any>) =>
    events.push({
      kind,
      claimId: claim.id,
      detail: {
        claimName: claim.name,
        tier: claim.tier,
        previousTier: before?.tier ?? null,
        waystones: claim.waystones ?? null,
        banks: claim.banks ?? null,
        trackedBefore: isTrackedClaim(before),
        trackedAfter: isTrackedClaim(after),
        totemId: claim.totemId,
        x: claim.x,
        z: claim.z,
        gap,
        ...detail,
      },
    });
  // Saved baselines from before the structure rule have no building counts.
  // Establish that baseline without fabricating hundreds of eligibility events;
  // genuine roster/owner changes found during this reconnect still carry a gap.
  const upgradingLegacyBaseline =
    gap &&
    before &&
    after &&
    before.waystones === undefined &&
    before.banks === undefined &&
    isTrackedClaim(after);
  if (
    before &&
    after &&
    isTrackedClaim(before) !== isTrackedClaim(after) &&
    !upgradingLegacyBaseline
  ) {
    add(isTrackedClaim(after) ? "tracking_started" : "tracking_stopped", {
      ownerId: after.ownerId,
      ownerName: after.ownerName,
      reason:
        after.tier === null
          ? "tier_unknown"
          : after.tier < HISTORY_MIN_TIER
            ? "tier_threshold"
            : "structures",
      previousWaystones: before.waystones ?? null,
      previousBanks: before.banks ?? null,
    });
    // Entering tracking establishes a new baseline. When leaving, retain the
    // final transaction's ownership/member changes as evidence for the claim
    // that was valuable immediately before this transaction.
    if (isTrackedClaim(after)) return events;
  }
  if (!before && after && gap) {
    add("tracking_started", {
      ownerId: after.ownerId,
      ownerName: after.ownerName,
      reason: "first_observed_after_gap",
    });
    return events;
  }
  if (!before)
    add("claim_created", {
      ownerId: after!.ownerId,
      ownerName: after!.ownerName,
    });
  else if (!after)
    add("claim_removed", {
      ownerId: before.ownerId,
      ownerName: before.ownerName,
    });
  else {
    if (before.ownerId !== after.ownerId) {
      const wasMember = before.members.some((m) => m.id === after.ownerId);
      add("ownership_changed", {
        previousOwnerId: before.ownerId,
        previousOwnerName: before.ownerName,
        ownerId: after.ownerId,
        ownerName: after.ownerName,
        ownerWasMember: wasMember,
        previousSupplies: before.supplies,
        possibleOutsiderTakeover:
          !gap &&
          !wasMember &&
          before.supplies !== null &&
          before.supplies <= 0,
        previousMemberIds: before.members.slice(0, 40).map((m) => m.id),
        previousMembers: before.members
          .slice(0, 10)
          .map((m) => ({ id: m.id, name: m.name.slice(0, 32) })),
        previousRosterSize: before.members.length,
        previousRosterTruncated: before.members.length > 40,
      });
    }
    const old = new Map(before.members.map((m) => [m.id, m])),
      next = new Map(after.members.map((m) => [m.id, m]));
    for (const m of before.members)
      if (!next.has(m.id))
        add("member_departed", { playerId: m.id, playerName: m.name });
    for (const m of after.members) {
      if (!old.has(m.id))
        add("member_joined", { playerId: m.id, playerName: m.name });
      else if (old.get(m.id)!.permissions !== m.permissions)
        add("permissions_changed", {
          playerId: m.id,
          playerName: m.name,
          before: old.get(m.id)!.permissions,
          after: m.permissions,
        });
    }
    if (!gap && before.supplies !== null && after.supplies !== null) {
      if (before.supplies > 0 && after.supplies <= 0)
        add("supplies_zero", {
          before: before.supplies,
          after: after.supplies,
        });
      else if (after.supplies > before.supplies)
        add("supplies_refilled", {
          before: before.supplies,
          after: after.supplies,
        });
    }
    if (
      before.name !== after.name ||
      before.tier !== after.tier ||
      before.totemId !== after.totemId ||
      before.x !== after.x ||
      before.z !== after.z ||
      (before.waystones !== undefined &&
        before.waystones !== after.waystones) ||
      (before.banks !== undefined && before.banks !== after.banks)
    )
      add("claim_updated", {
        previousName: before.name,
        previousTier: before.tier,
        previousTotemId: before.totemId,
        previousX: before.x,
        previousZ: before.z,
        previousWaystones: before.waystones ?? null,
        previousBanks: before.banks ?? null,
      });
  }
  return events;
}

export function validateClaimState(raw: any): ClaimState | null {
  if (raw === null) return null;
  const id = (s: any) => typeof s === "string" && /^\d{1,24}$/.test(s);
  if (
    !raw ||
    !id(raw.id) ||
    !id(raw.ownerId) ||
    !id(raw.totemId) ||
    ![raw.name, raw.ownerName].every(
      (s) => typeof s === "string" && s.length <= 160,
    ) ||
    ![raw.supplies, raw.tier, raw.x, raw.z].every(
      (v) => v === null || (typeof v === "number" && Number.isFinite(v)),
    ) ||
    ![raw.waystones, raw.banks].every(
      (v) => v === undefined || (Number.isInteger(v) && v >= 0 && v <= 10000),
    ) ||
    !Array.isArray(raw.members) ||
    raw.members.length > 500 ||
    JSON.stringify(raw).length > 64_000
  )
    throw Error("Invalid claim history state.");
  const seen = new Set<string>();
  for (const m of raw.members) {
    if (
      !id(m.id) ||
      seen.has(m.id) ||
      typeof m.name !== "string" ||
      m.name.length > 160 ||
      !/^[01]{4}$/.test(m.permissions)
    )
      throw Error("Invalid claim history member.");
    seen.add(m.id);
  }
  return raw;
}

export function ingestClaimHistory(
  ctx: any,
  args: {
    regionId: number;
    epoch: string;
    sequence: number;
    initial: boolean;
    complete: boolean;
    payload: string;
    observedAt: bigint;
  },
) {
  if (
    !CURRENT_REGIONS[args.regionId] ||
    !args.epoch ||
    args.epoch.length > 160 ||
    args.payload.length > 1_000_000 ||
    args.observedAt > ctx.timestamp.microsSinceUnixEpoch + 60_000_000n ||
    args.observedAt < ctx.timestamp.microsSinceUnixEpoch - 600_000_000n
  )
    throw Error("Invalid claim history publication.");
  const key = String(args.regionId),
    previous = ctx.db.claimHistoryRegion.key.find(key);
  if (previous?.epoch === args.epoch && args.sequence <= previous.sequence)
    return;
  if (
    args.sequence !==
      (previous?.epoch === args.epoch ? previous.sequence + 1 : 1) ||
    (previous?.epoch !== args.epoch && !args.initial) ||
    (!args.initial && !previous?.complete) ||
    (previous?.epoch === args.epoch && previous.complete && args.initial)
  )
    throw Error("Claim history sequence gap; reconnect required.");
  const changes = JSON.parse(args.payload);
  const reconciling =
    previous?.epoch === args.epoch ? previous.reconciling : !!previous;
  if (!Array.isArray(changes) || changes.length > 200)
    throw Error("Invalid claim history batch.");
  let eventIndex = 0;
  const add = (event: HistoryEvent) => {
    const payload = JSON.stringify(event.detail);
    if (payload.length > 3000) throw Error("Claim event exceeds its budget.");
    ctx.db.claimHistoryEvent.insert({
      key: `${args.epoch}:${args.sequence}:${eventIndex++}`,
      regionId: args.regionId,
      claimId: event.claimId,
      kind: event.kind,
      observedAt: args.observedAt,
      payload,
    });
  };
  if (args.sequence === 1)
    add({
      kind: previous ? "coverage_gap" : "coverage_started",
      claimId: "",
      detail: {
        gap: !!previous,
        previousObservation: previous ? String(previous.observedAt) : null,
        message: previous
          ? "Recording resumed; changes during the connection gap may be missing."
          : "Recording started. Earlier events are unavailable.",
      },
    });
  const ids = new Set<string>();
  for (const change of changes) {
    const before = validateClaimState(change.before),
      after = validateClaimState(change.after),
      claim = after ?? before;
    if (
      !claim ||
      (before && after && before.id !== after.id) ||
      ids.has(claim.id)
    )
      throw Error("Invalid claim history change.");
    ids.add(claim.id);
    const stateKey = `${args.regionId}:${claim.id}`,
      stored = ctx.db.claimHistoryState.key.find(stateKey);
    // Initial snapshots reconcile saved ownership/rosters, with explicit uncertainty.
    // The first-ever baseline creates no fictitious claim/member creation events.
    const old = args.initial
      ? stored
        ? JSON.parse(stored.payload)
        : null
      : before;
    if (!args.initial || reconciling)
      for (const event of claimChanges(old, after, args.initial)) add(event);
    if (after && isTrackedClaim(after)) {
      const row = {
        key: stateKey,
        regionId: args.regionId,
        epoch: args.epoch,
        observedAt: args.observedAt,
        payload: JSON.stringify(after),
      };
      if (stored) ctx.db.claimHistoryState.key.update(row);
      else {
        if (ctx.db.claimHistoryState.count() >= BigInt(HISTORY_STATE_LIMIT))
          throw Error("Claim baseline limit reached.");
        ctx.db.claimHistoryState.insert(row);
      }
    } else {
      ctx.db.claimHistoryState.key.delete(stateKey);
      ctx.db.claimSupplyStatus.key.delete(stateKey);
    }
  }
  if (args.initial && args.complete) {
    for (const row of ctx.db.claimHistoryState.regionId.filter(args.regionId))
      if (row.epoch !== args.epoch) {
        for (const event of claimChanges(JSON.parse(row.payload), null, true))
          add(event);
        ctx.db.claimHistoryState.key.delete(row.key);
        ctx.db.claimSupplyStatus.key.delete(row.key);
      }
  }
  const region = {
    key,
    regionId: args.regionId,
    epoch: args.epoch,
    sequence: args.sequence,
    observedAt: args.observedAt,
    startedAt: previous?.startedAt ?? args.observedAt,
    complete:
      args.complete || (previous?.epoch === args.epoch && previous.complete),
    reconciling,
    prunedAt: previous?.prunedAt ?? 0n,
    discarded: previous?.discarded ?? 0n,
  };
  if (previous) ctx.db.claimHistoryRegion.key.update(region);
  else ctx.db.claimHistoryRegion.insert(region);
  if (
    [...ctx.db.claimHistoryState.iter()].reduce(
      (n: number, r: any) => n + r.payload.length,
      0,
    ) > 20_000_000
  )
    throw Error("Claim baseline exceeds its storage budget.");
  pruneClaimHistory(ctx, eventIndex > 0);
}

export function pruneClaimHistory(ctx: any, force = false) {
  const now = ctx.timestamp.microsSinceUnixEpoch;
  const regions = [...ctx.db.claimHistoryRegion.iter()];
  const lastPrune = regions.reduce(
    (n: bigint, r: any) => (r.prunedAt > n ? r.prunedAt : n),
    0n,
  );
  if (
    force ||
    now - lastPrune >= 60_000_000n ||
    ctx.db.claimHistoryEvent.count() > BigInt(HISTORY_EVENT_LIMIT)
  ) {
    const rows = [...ctx.db.claimHistoryEvent.iter()].sort((a: any, b: any) =>
      a.observedAt < b.observedAt
        ? -1
        : a.observedAt > b.observedAt
          ? 1
          : a.key.localeCompare(b.key),
    );
    const cutoff = now - BigInt(HISTORY_DAYS) * 86_400_000_000n;
    const excess = Math.max(0, rows.length - HISTORY_EVENT_LIMIT);
    let bytes = rows.reduce((n: number, r: any) => n + r.payload.length, 0);
    const discarded = new Map<number, number>();
    rows.forEach((r: any, i) => {
      if (r.observedAt < cutoff || i < excess || bytes > 50_000_000) {
        ctx.db.claimHistoryEvent.key.delete(r.key);
        bytes -= r.payload.length;
        discarded.set(r.regionId, (discarded.get(r.regionId) ?? 0) + 1);
      }
    });
    for (const region of regions)
      ctx.db.claimHistoryRegion.key.update({
        ...region,
        prunedAt: now,
        discarded:
          region.discarded + BigInt(discarded.get(region.regionId) ?? 0),
      });
  }
}

export function readClaimHistory(
  ctx: any,
  args: {
    query: string;
    regionId: number;
    kind: string;
    before: string;
    limit: number;
    claimId?: string;
  },
) {
  if (
    args.query.length > 160 ||
    args.before.length > 200 ||
    (args.regionId && !CURRENT_REGIONS[args.regionId])
  )
    throw Error("Invalid history search.");
  const q = args.query.trim().toLowerCase(),
    cutoff =
      ctx.timestamp.microsSinceUnixEpoch -
      BigInt(HISTORY_DAYS) * 86_400_000_000n;
  const cursor = args.before ? JSON.parse(args.before) : null;
  const trackedStates = [
    ...(args.regionId
      ? ctx.db.claimHistoryState.regionId.filter(args.regionId)
      : ctx.db.claimHistoryState.iter()),
  ].filter((r: any) => isTrackedClaim(JSON.parse(r.payload)));
  const candidates = args.claimId
    ? [
        ...ctx.db.claimHistoryEvent.claimId.filter(args.claimId),
        ...ctx.db.claimHistoryEvent.claimId.filter(""),
      ].filter((r: any) => r.regionId === args.regionId)
    : [
        ...(args.regionId
          ? ctx.db.claimHistoryEvent.regionId.filter(args.regionId)
          : ctx.db.claimHistoryEvent.iter()),
      ];
  const allEvents = candidates.filter((r: any) => r.observedAt >= cutoff);
  const qualifiedClaims = new Set<string>(trackedStates.map((r: any) => r.key));
  for (const event of allEvents) {
    const detail = JSON.parse(event.payload);
    if (detail.trackedBefore === true || detail.trackedAfter === true)
      qualifiedClaims.add(`${event.regionId}:${event.claimId}`);
  }
  const rows = allEvents
    .filter(
      (r: any) =>
        r.observedAt >= cutoff &&
        isTrackedEvent(r, qualifiedClaims) &&
        (!args.claimId ||
          r.claimId === args.claimId ||
          (!r.claimId &&
            (!args.kind || args.kind === "all" || args.kind === "coverage"))) &&
        (!args.kind ||
          args.kind === "all" ||
          (args.kind === "ownership" && r.kind === "ownership_changed") ||
          (args.kind === "members" &&
            [
              "member_joined",
              "member_departed",
              "permissions_changed",
            ].includes(r.kind)) ||
          (args.kind === "supplies" && r.kind.startsWith("supplies_")) ||
          (args.kind === "structures" &&
            [
              "claim_created",
              "claim_removed",
              "claim_updated",
              "tracking_started",
              "tracking_stopped",
            ].includes(r.kind)) ||
          (args.kind === "coverage" && r.kind.startsWith("coverage_")) ||
          r.kind === args.kind ||
          (args.kind === "takeover" &&
            JSON.parse(r.payload).possibleOutsiderTakeover)) &&
        (!q || r.claimId === q || r.payload.toLowerCase().includes(q)) &&
        (!cursor ||
          r.observedAt < BigInt(cursor.at) ||
          (r.observedAt === BigInt(cursor.at) && r.key < cursor.key)),
    )
    .sort((a: any, b: any) =>
      a.observedAt > b.observedAt
        ? -1
        : a.observedAt < b.observedAt
          ? 1
          : a.key < b.key
            ? 1
            : -1,
    );
  const limit = Math.min(100, Math.max(1, args.limit)),
    page = rows.slice(0, limit);
  return JSON.stringify({
    events: page.map((r: any) => ({
      ...r,
      observedAt: String(r.observedAt),
      detail: JSON.parse(r.payload),
      payload: undefined,
    })),
    next:
      rows.length > limit
        ? JSON.stringify({
            at: String(page[page.length - 1].observedAt),
            key: page[page.length - 1].key,
          })
        : "",
    retentionDays: HISTORY_DAYS,
    eventLimit: HISTORY_EVENT_LIMIT,
    minimumTier: HISTORY_MIN_TIER,
    trackingRule: "T4+ with a waystone and a bank",
    trackedClaims: trackedStates.length,
    retainedEvents: Number(ctx.db.claimHistoryEvent.count()),
    coverage: claimRecordingCoverage(ctx),
  });
}

export function claimRecordingCoverage(ctx: any) {
  return Object.entries(CURRENT_REGIONS).map(([id, name]) => {
    const row = ctx.db.claimHistoryRegion.key.find(id),
      status = ctx.db.relayStatus.key.find(`claims:${id}`);
    return {
      regionId: Number(id),
      name,
      startedAt: row ? String(row.startedAt) : null,
      lastObservation: row ? String(row.observedAt) : null,
      ready:
        !!row?.complete &&
        !!status?.ready &&
        status.epoch === row.epoch &&
        ctx.timestamp.microsSinceUnixEpoch - status.heartbeatAt < 60_000_000n,
      discarded: row ? String(row.discarded) : "0",
    };
  });
}

export function ingestClaimSupplies(
  ctx: any,
  args: {
    regionId: number;
    epoch: string;
    payload: string;
    observedAt: bigint;
  },
) {
  const region = ctx.db.claimHistoryRegion.key.find(String(args.regionId));
  if (
    !CURRENT_REGIONS[args.regionId] ||
    !region?.complete ||
    region.epoch !== args.epoch ||
    args.payload.length > 1_000_000 ||
    args.observedAt > ctx.timestamp.microsSinceUnixEpoch + 60_000_000n ||
    args.observedAt < ctx.timestamp.microsSinceUnixEpoch - 600_000_000n
  )
    throw Error("Invalid claim supply publication.");
  const rows = JSON.parse(args.payload);
  if (!Array.isArray(rows) || rows.length > 200)
    throw Error("Invalid claim supply batch.");
  const seen = new Set();
  for (const change of rows) {
    if (!/^\d{1,24}$/.test(change.claimId) || seen.has(change.claimId))
      throw Error("Invalid claim supply ID.");
    seen.add(change.claimId);
    const key = `${args.regionId}:${change.claimId}`,
      state = ctx.db.claimHistoryState.key.find(key),
      previous = ctx.db.claimSupplyStatus.key.find(key);
    const supply = change.supply as ClaimSupply | null;
    if (!supply || !state || !isTrackedClaim(JSON.parse(state.payload))) {
      ctx.db.claimSupplyStatus.key.delete(key);
      continue;
    }
    if (
      supply.claimId !== change.claimId ||
      !Number.isInteger(supply.supplies) ||
      supply.supplies < -2_147_483_648 ||
      supply.supplies > 2_147_483_647 ||
      !Number.isInteger(supply.tileCount) ||
      supply.tileCount < 0 ||
      supply.tileCount > 100_000_000 ||
      !Number.isFinite(supply.buildingMaintenance) ||
      supply.buildingMaintenance < 0 ||
      supply.buildingMaintenance > 1e9 ||
      !/^\d{1,24}$/.test(supply.treasury) ||
      (supply.upkeepPerHour !== null &&
        (!Number.isFinite(supply.upkeepPerHour) ||
          supply.upkeepPerHour < 0 ||
          supply.upkeepPerHour > 1e9)) ||
      (supply.tickMillis !== null &&
        (!Number.isFinite(supply.tickMillis) ||
          supply.tickMillis <= 0 ||
          supply.tickMillis > 86_400_000))
    )
      throw Error("Invalid claim supplies.");
    const payload = JSON.stringify(supply);
    if (payload.length > 1000)
      throw Error("Claim supplies exceed their budget.");
    if (previous && previous.observedAt > args.observedAt) continue;
    // Quiet claims do not rewrite rows just to refresh a timestamp. Live
    // freshness comes from the recording heartbeat, separate from value age.
    if (previous?.payload === payload && previous.epoch === args.epoch)
      continue;
    const row = {
      key,
      regionId: args.regionId,
      epoch: args.epoch,
      observedAt: args.observedAt,
      payload,
    };
    if (previous) ctx.db.claimSupplyStatus.key.update(row);
    else {
      if (ctx.db.claimSupplyStatus.count() >= BigInt(HISTORY_STATE_LIMIT))
        throw Error("Claim supply limit reached.");
      ctx.db.claimSupplyStatus.insert(row);
    }
  }
}

function trackedClaimSummary(
  ctx: any,
  row: any,
  coverage: ReturnType<typeof claimRecordingCoverage>,
) {
  const state = JSON.parse(row.payload) as ClaimState,
    supplyRow = ctx.db.claimSupplyStatus.key.find(row.key),
    recording = coverage.find((r) => r.regionId === row.regionId);
  const supply = supplyRow
    ? (JSON.parse(supplyRow.payload) as ClaimSupply)
    : null;
  return {
    id: state.id,
    name: state.name,
    ownerId: state.ownerId,
    ownerName: state.ownerName,
    tier: state.tier,
    regionId: row.regionId,
    regionName: CURRENT_REGIONS[row.regionId],
    waystones: state.waystones,
    banks: state.banks,
    memberCount: state.members.length,
    totemId: state.totemId,
    x: state.x,
    z: state.z,
    ...claimSupplyEstimate(
      supply,
      supplyRow?.observedAt ?? 0n,
      ctx.timestamp.microsSinceUnixEpoch,
      !!recording?.ready &&
        supplyRow?.epoch ===
          ctx.db.claimHistoryRegion.key.find(String(row.regionId))?.epoch,
    ),
  };
}

export function readTrackedClaims(
  ctx: any,
  args: {
    query: string;
    regionId: number;
    sort: string;
    risk: string;
    page: number;
    limit: number;
  },
) {
  if (
    args.query.length > 160 ||
    (args.regionId && !CURRENT_REGIONS[args.regionId]) ||
    !["expiry", "supplies", "name", "tier"].includes(args.sort) ||
    !["", "soon", "week", "empty", "stale", "unknown"].includes(args.risk)
  )
    throw Error("Invalid claim list filters.");
  const coverage = claimRecordingCoverage(ctx),
    q = args.query.trim().toLowerCase();
  const all = [
    ...(args.regionId
      ? ctx.db.claimHistoryState.regionId.filter(args.regionId)
      : ctx.db.claimHistoryState.iter()),
  ].filter((r: any) => isTrackedClaim(JSON.parse(r.payload)));
  const rows = all
    .filter((r: any) => !args.regionId || r.regionId === args.regionId)
    .map((r: any) => trackedClaimSummary(ctx, r, coverage))
    .filter(
      (r: any) =>
        (!q ||
          [r.name, r.ownerName, r.id, r.ownerId].some((v) =>
            v.toLowerCase().includes(q),
          )) &&
        (!args.risk ||
          (args.risk === "empty" && r.supplies !== null && r.supplies <= 0) ||
          (args.risk === "stale" && r.supplyStatus === "stale") ||
          (args.risk === "unknown" &&
            (r.supplyStatus === "unknown" || r.upkeepPerHour === null)) ||
          (args.risk === "soon" &&
            r.supplyStatus === "ready" &&
            (r.supplies <= 0 ||
              (r.remainingHours !== null && r.remainingHours <= 24))) ||
          (args.risk === "week" &&
            r.supplyStatus === "ready" &&
            (r.supplies <= 0 ||
              (r.remainingHours !== null && r.remainingHours <= 168)))),
    );
  rows.sort((a: any, b: any) => {
    const rank = (r: any) =>
      r.supplyStatus !== "ready"
        ? 3
        : r.supplies <= 0
          ? 0
          : r.estimatedExpiresAt === null
            ? 2
            : 1;
    if (args.sort === "expiry")
      return (
        rank(a) - rank(b) ||
        (a.estimatedExpiresAt ?? Infinity) -
          (b.estimatedExpiresAt ?? Infinity) ||
        a.name.localeCompare(b.name)
      );
    if (args.sort === "supplies")
      return (
        (a.supplies ?? Infinity) - (b.supplies ?? Infinity) ||
        a.name.localeCompare(b.name)
      );
    if (args.sort === "tier")
      return b.tier - a.tier || a.name.localeCompare(b.name);
    return a.name.localeCompare(b.name);
  });
  const limit = Math.max(1, Math.min(100, args.limit)),
    pages = Math.max(1, Math.ceil(rows.length / limit)),
    page = Math.max(1, Math.min(pages, args.page));
  return JSON.stringify({
    claims: rows.slice((page - 1) * limit, page * limit),
    total: rows.length,
    trackedClaims: Number(ctx.db.claimHistoryState.count()),
    page,
    pages,
    coverage,
    estimatedAt: String(ctx.timestamp.microsSinceUnixEpoch),
    trackingRule: "T4+ with a waystone and a bank",
  });
}

export function readTrackedClaimHistory(
  ctx: any,
  args: {
    claimId: string;
    regionId: number;
    group: string;
    before: string;
    limit: number;
  },
) {
  if (
    !/^\d{1,24}$/.test(args.claimId) ||
    !CURRENT_REGIONS[args.regionId] ||
    ![
      "all",
      "ownership",
      "members",
      "supplies",
      "structures",
      "coverage",
    ].includes(args.group)
  )
    throw Error("Invalid claim timeline.");
  const history = JSON.parse(
    readClaimHistory(ctx, {
      query: "",
      regionId: args.regionId,
      kind: args.group,
      claimId: args.claimId,
      before: args.before,
      limit: args.limit,
    }),
  );
  const row = ctx.db.claimHistoryState.key.find(
    `${args.regionId}:${args.claimId}`,
  );
  return JSON.stringify({
    ...history,
    claim:
      row && isTrackedClaim(JSON.parse(row.payload))
        ? trackedClaimSummary(ctx, row, history.coverage)
        : null,
    members:
      row && isTrackedClaim(JSON.parse(row.payload))
        ? JSON.parse(row.payload).members
        : [],
  });
}

// A personal shortlist is supplied by the browser; no per-user server writes.
export function readStarredClaims(ctx: any, args: { claims: string }) {
  if (args.claims.length > 16_000) throw Error("Claim shortlist is too large.");
  const input = JSON.parse(args.claims);
  if (!Array.isArray(input) || input.length > 200)
    throw Error("Invalid claim shortlist.");
  const wanted = new Map<string, { id: string; regionId: number }>();
  for (const claim of input) {
    if (
      !claim ||
      typeof claim.id !== "string" ||
      !/^\d{1,24}$/.test(claim.id) ||
      !Number.isInteger(claim.regionId) ||
      !CURRENT_REGIONS[claim.regionId]
    )
      throw Error("Invalid starred claim.");
    wanted.set(`${claim.regionId}:${claim.id}`, {
      id: claim.id,
      regionId: claim.regionId,
    });
  }
  const coverage = claimRecordingCoverage(ctx),
    cutoff =
      ctx.timestamp.microsSinceUnixEpoch -
      BigInt(HISTORY_DAYS) * 86_400_000_000n;
  const activity = new Map<string, any>();
  for (const claim of wanted.values()) {
    for (const event of ctx.db.claimHistoryEvent.claimId.filter(claim.id)) {
      const key = `${event.regionId}:${event.claimId}`;
      if (!wanted.has(key) || event.observedAt < cutoff) continue;
      const previous = activity.get(key);
      if (
        !previous ||
        event.observedAt > previous.observedAt ||
        (event.observedAt === previous.observedAt && event.key > previous.key)
      )
        activity.set(key, event);
    }
  }
  return JSON.stringify({
    claims: [...wanted].map(([key, identity]) => {
      const row = ctx.db.claimHistoryState.key.find(key),
        event = activity.get(key);
      return {
        ...identity,
        claim:
          row && isTrackedClaim(JSON.parse(row.payload))
            ? trackedClaimSummary(ctx, row, coverage)
            : null,
        lastActivityAt: event ? String(event.observedAt) : null,
        lastActivityKind: event?.kind ?? null,
      };
    }),
    coverage,
    estimatedAt: String(ctx.timestamp.microsSinceUnixEpoch),
  });
}
