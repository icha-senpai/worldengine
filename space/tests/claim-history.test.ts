import { describe, expect, it } from "vitest";
import {
  claimChanges,
  ingestClaimHistory,
  pruneClaimHistory,
  readClaimHistory,
  ingestClaimSupplies,
  readTrackedClaims,
  readTrackedClaimHistory,
  readStarredClaims,
  HISTORY_EVENT_LIMIT,
  type ClaimState,
} from "../bitcraft/src/claim-history";
import { ClaimHistoryProjection } from "../scripts/claim-history-projection";
import {
  calculateClaimUpkeep,
  claimSupplyEstimate,
} from "../bitcraft/src/claim-supplies";
import {
  checkpointPage,
  restoreCheckpointPage,
} from "../bitcraft/src/market-checkpoint";

const state = (changes: Partial<ClaimState> = {}): ClaimState => ({
  id: "10",
  name: "Test claim",
  ownerId: "20",
  ownerName: "Owner",
  totemId: "30",
  tier: 6,
  waystones: 1,
  banks: 1,
  x: 1,
  z: 2,
  supplies: 0,
  members: [{ id: "20", name: "Owner", permissions: "1111" }],
  ...changes,
});
function table() {
  const rows = new Map<string, any>();
  return {
    rows,
    iter: () => rows.values(),
    count: () => BigInt(rows.size),
    insert: (r: any) => rows.set(r.key, r),
    key: {
      find: (k: string) => rows.get(k),
      update: (r: any) => rows.set(r.key, r),
      delete: (k: string) => rows.delete(k),
    },
    regionId: {
      filter: (id: number) =>
        [...rows.values()].filter((r) => r.regionId === id),
    },
    claimId: {
      filter: (id: string) =>
        [...rows.values()].filter((r) => r.claimId === id),
    },
  };
}
function context() {
  return {
    timestamp: { microsSinceUnixEpoch: 1_800_000_000_000_000n },
    db: {
      claimHistoryState: table(),
      claimHistoryEvent: table(),
      claimHistoryRegion: table(),
      claimSupplyStatus: table(),
      claimRevision: table(),
      relayStatus: table(),
    },
  };
}
describe("claim history evidence", () => {
  it("reads a bounded exact shortlist across regions and retains activity when a claim leaves tracking", () => {
    const ctx = context(),
      at = ctx.timestamp.microsSinceUnixEpoch;
    for (const regionId of [8, 9])
      ctx.db.claimHistoryState.insert({
        key: `${regionId}:10`,
        regionId,
        payload: JSON.stringify(state()),
        epoch: "test",
        observedAt: at,
      });
    for (const [key, regionId, claimId, observedAt] of [
      ["wanted", 8, "10", at],
      ["other-region", 9, "10", at + 1n],
      ["gap", 8, "", at + 2n],
      ["expired", 8, "10", at - 31n * 86_400_000_000n],
    ] as const)
      ctx.db.claimHistoryEvent.insert({
        key,
        regionId,
        claimId,
        observedAt,
        kind: key === "gap" ? "coverage_gap" : "ownership_changed",
        payload: "{}",
      });
    const args = {
      claims: JSON.stringify([
        { id: "10", regionId: 8 },
        { id: "10", regionId: 8 },
        { id: "99", regionId: 8 },
      ]),
    };
    let result = JSON.parse(readStarredClaims(ctx, args));
    expect(result.claims).toHaveLength(2);
    expect(result.claims[0].lastActivityAt).toBe(String(at));
    expect(result.claims[0].claim.regionId).toBe(8);
    expect(result.claims[1].claim).toBeNull();
    ctx.db.claimHistoryEvent.iter = () => {
      throw Error("Shortlists must use the claim index");
    };
    ctx.db.claimHistoryState.key.delete("8:10");
    result = JSON.parse(readStarredClaims(ctx, args));
    expect(result.claims[0].claim).toBeNull();
    expect(result.claims[0].lastActivityKind).toBe("ownership_changed");
    expect(() =>
      readStarredClaims(ctx, {
        claims: JSON.stringify([{ id: "10", regionId: 3 }]),
      }),
    ).toThrow();
    expect(() =>
      readStarredClaims(ctx, {
        claims: JSON.stringify(Array(201).fill({ id: "10", regionId: 8 })),
      }),
    ).toThrow();
  });
  it("calculates hourly upkeep including size boundaries, shape and building maintenance", () => {
    const costs = [
        { tile_count: 1, cost_per_tile: 0.01 },
        { tile_count: 1001, cost_per_tile: 0.0125 },
      ],
      params = {
        claim_stability_param_m: -300,
        claim_stability_param_b: 1300,
        building_decay_tick_millis: 3_600_000,
      };
    expect(
      calculateClaimUpkeep(
        { num_tiles: 1000, num_tile_neighbors: 6000, building_maintenance: 2 },
        costs,
        params,
      ),
    ).toBe(12);
    expect(
      calculateClaimUpkeep(
        { num_tiles: 1001, num_tile_neighbors: 6006, building_maintenance: 0 },
        costs,
        params,
      ),
    ).toBeCloseTo(12.5125);
    expect(
      calculateClaimUpkeep(
        { num_tiles: 1000, num_tile_neighbors: 4000, building_maintenance: 2 },
        costs,
        params,
      ),
    ).toBe(1002);
    expect(
      calculateClaimUpkeep(
        { num_tiles: 1000, num_tile_neighbors: 6000, building_maintenance: 2 },
        costs,
        { ...params, building_decay_tick_millis: 1_800_000 },
      ),
    ).toBe(24);
    expect(calculateClaimUpkeep({}, costs, params)).toBeNull();
    expect(
      calculateClaimUpkeep(
        { num_tiles: 1, num_tile_neighbors: 6, building_maintenance: 0 },
        [],
        params,
      ),
    ).toBeNull();
  });
  it("separates unknown/stale readings from forecasts, and stores compact supplies without creating history", () => {
    const ctx = context(),
      at = ctx.timestamp.microsSinceUnixEpoch;
    ingestClaimHistory(ctx, {
      regionId: 8,
      epoch: "first",
      sequence: 1,
      initial: true,
      complete: true,
      observedAt: at,
      payload: JSON.stringify([{ before: null, after: state() }]),
    });
    const supply = {
      claimId: "10",
      supplies: 2400,
      upkeepPerHour: 100,
      tileCount: 1000,
      buildingMaintenance: 0,
      treasury: "5000",
      tickMillis: 3_600_000,
    };
    const args = {
      regionId: 8,
      epoch: "first",
      payload: JSON.stringify([{ claimId: "10", supply }]),
      observedAt: at,
    };
    ingestClaimSupplies(ctx, args);
    const original = ctx.db.claimSupplyStatus.key.find("8:10");
    ingestClaimSupplies(ctx, { ...args, observedAt: at + 1n });
    expect(ctx.db.claimSupplyStatus.key.find("8:10")).toBe(original);
    expect(ctx.db.claimHistoryEvent.count()).toBe(1n);
    expect(claimSupplyEstimate(supply, at, at, true).remainingHours).toBe(24);
    expect(claimSupplyEstimate(supply, at, at, false).supplyStatus).toBe(
      "stale",
    );
    expect(
      claimSupplyEstimate(null, at, at, true).estimatedExpiresAt,
    ).toBeNull();
    expect(
      claimSupplyEstimate({ ...supply, upkeepPerHour: 0 }, at, at, true)
        .estimatedExpiresAt,
    ).toBeNull();
    const list = JSON.parse(
      readTrackedClaims(ctx, {
        query: "",
        regionId: 0,
        sort: "expiry",
        risk: "",
        page: 1,
        limit: 50,
      }),
    );
    expect(list.claims[0].supplyStatus).toBe("stale");
    expect(list.claims[0].supplies).toBe(2400);
    expect(() => ingestClaimSupplies(ctx, { ...args, epoch: "wrong" })).toThrow(
      "Invalid claim supply",
    );
    const expired = claimSupplyEstimate(
      supply,
      at,
      at + 25n * 3_600_000_000n,
      true,
    );
    expect(expired.remainingHours).toBe(0);
    expect(expired.supplies).toBe(2400);
  });
  it("keeps per-claim tabs isolated while including regional recording gaps", () => {
    const ctx = context(),
      at = ctx.timestamp.microsSinceUnixEpoch;
    for (const [key, claimId, kind] of [
      ["a", "10", "member_joined"],
      ["b", "11", "member_joined"],
      ["c", "10", "supplies_refilled"],
      ["d", "", "coverage_gap"],
    ])
      ctx.db.claimHistoryEvent.insert({
        key,
        regionId: 8,
        claimId,
        kind,
        observedAt: at,
        payload: JSON.stringify({ tier: 6, trackedBefore: true }),
      });
    const args = {
      claimId: "10",
      regionId: 8,
      group: "members",
      before: "",
      limit: 100,
    };
    expect(
      JSON.parse(readTrackedClaimHistory(ctx, args)).events.map(
        (e: any) => e.key,
      ),
    ).toEqual(["a"]);
    expect(
      JSON.parse(
        readTrackedClaimHistory(ctx, { ...args, group: "all" }),
      ).events.map((e: any) => e.key),
    ).toEqual(["d", "c", "a"]);
    expect(
      JSON.parse(
        readTrackedClaimHistory(ctx, { ...args, group: "coverage" }),
      ).events.map((e: any) => e.key),
    ).toEqual(["d"]);
  });
  it("requires T4 or above plus both structures, and names eligibility changes accurately", () => {
    for (const excluded of [
      state({ tier: 3 }),
      state({ banks: 0 }),
      state({ waystones: 0 }),
      state({ tier: null }),
    ]) {
      expect(
        claimChanges(excluded, { ...excluded, ownerId: "21", supplies: 500 }),
      ).toEqual([]);
    }
    const enter = claimChanges(state({ tier: 3 }), state({ tier: 4 }));
    expect(enter.map((e) => e.kind)).toEqual(["tracking_started"]);
    const exit = claimChanges(state(), state({ banks: 0, ownerId: "21" }));
    expect(exit[0].kind).toBe("tracking_stopped");
    expect(
      exit.find((e) => e.kind === "ownership_changed")!.detail
        .possibleOutsiderTakeover,
    ).toBe(true);
    expect(exit.some((e) => e.kind === "claim_removed")).toBe(false);
    expect(
      claimChanges(
        state({ waystones: undefined, banks: undefined }),
        state(),
        true,
      ),
    ).toEqual([]);
  });
  it("keeps only eligible baselines and handles a reconnect downgrade without a false disappearance", () => {
    const ctx = context(),
      at = ctx.timestamp.microsSinceUnixEpoch;
    const base = {
      regionId: 8,
      epoch: "first",
      sequence: 1,
      initial: true,
      complete: true,
      observedAt: at,
      payload: JSON.stringify([
        { before: null, after: state() },
        { before: null, after: state({ id: "11", tier: 3 }) },
        { before: null, after: state({ id: "12", banks: 0 }) },
      ]),
    };
    ingestClaimHistory(ctx, base);
    expect(ctx.db.claimHistoryState.count()).toBe(1n);
    ingestClaimHistory(ctx, {
      ...base,
      epoch: "second",
      payload: JSON.stringify([{ before: null, after: state({ tier: 3 }) }]),
    });
    expect(ctx.db.claimHistoryState.count()).toBe(0n);
    const kinds = [...ctx.db.claimHistoryEvent.iter()].map((e) => e.kind);
    expect(kinds).toContain("tracking_stopped");
    expect(kinds).not.toContain("claim_removed");
  });
  it("uses the preceding roster even when the outsider joins during the ownership change", () => {
    const events = claimChanges(
      state(),
      state({
        ownerId: "21",
        ownerName: "New owner",
        members: [{ id: "21", name: "New owner", permissions: "1111" }],
      }),
    );
    expect(events.map((e) => e.kind)).toEqual([
      "ownership_changed",
      "member_departed",
      "member_joined",
    ]);
    expect(events[0].detail).toMatchObject({
      ownerWasMember: false,
      possibleOutsiderTakeover: true,
      previousMemberIds: ["20"],
    });
    expect(
      claimChanges(state(), state({ ownerId: "21" }), true)[0].detail
        .possibleOutsiderTakeover,
    ).toBe(false);
    expect(
      claimChanges(state({ supplies: 100 }), state({ ownerId: "21" }))[0].detail
        .possibleOutsiderTakeover,
    ).toBe(false);
  });
  it("does not flag a member transfer and does not store routine supply decreases", () => {
    const before = state({
      members: [
        { id: "20", name: "Owner", permissions: "1111" },
        { id: "21", name: "Member", permissions: "1100" },
      ],
    });
    expect(
      claimChanges(before, state({ ...before, ownerId: "21" }))[0].detail
        .ownerWasMember,
    ).toBe(true);
    expect(
      claimChanges(state({ supplies: 100 }), state({ supplies: 99 })),
    ).toEqual([]);
    expect(
      claimChanges(state({ supplies: 1 }), state()).map((e) => e.kind),
    ).toEqual(["supplies_zero"]);
    expect(
      claimChanges(state(), state({ supplies: 100 })).map((e) => e.kind),
    ).toEqual(["supplies_refilled"]);
    expect(claimChanges(state(), state({ supplies: 100 }), true)).toEqual([]);
  });
  it("starts with a baseline, rejects out-of-order batches, deduplicates retries and marks reconnect differences", () => {
    const ctx = context(),
      base = {
        regionId: 8,
        epoch: "first",
        sequence: 1,
        initial: true,
        complete: true,
        observedAt: ctx.timestamp.microsSinceUnixEpoch,
        payload: JSON.stringify([{ before: null, after: state() }]),
      };
    ingestClaimHistory(ctx, base);
    expect([...ctx.db.claimHistoryEvent.iter()].map((e) => e.kind)).toEqual([
      "coverage_started",
    ]);
    ingestClaimHistory(ctx, base);
    expect(ctx.db.claimHistoryEvent.count()).toBe(1n);
    expect(() =>
      ingestClaimHistory(ctx, { ...base, sequence: 3, initial: false }),
    ).toThrow("sequence gap");
    ingestClaimHistory(ctx, {
      ...base,
      epoch: "second",
      payload: JSON.stringify([
        { before: null, after: state({ ownerId: "21" }) },
      ]),
    });
    const ownership = [...ctx.db.claimHistoryEvent.iter()].find(
      (e) => e.kind === "ownership_changed",
    );
    expect(JSON.parse(ownership.payload)).toMatchObject({
      gap: true,
      possibleOutsiderTakeover: false,
    });
    expect(() => ingestClaimHistory(ctx, { ...base, regionId: 3 })).toThrow(
      "Invalid claim",
    );
  });
  it("does not fabricate initial claim events across multiple baseline chunks", () => {
    const ctx = context(),
      base = {
        regionId: 8,
        epoch: "first",
        sequence: 1,
        initial: true,
        complete: false,
        observedAt: ctx.timestamp.microsSinceUnixEpoch,
        payload: JSON.stringify([{ before: null, after: state() }]),
      };
    ingestClaimHistory(ctx, base);
    ingestClaimHistory(ctx, {
      ...base,
      sequence: 2,
      complete: true,
      payload: JSON.stringify([{ before: null, after: state({ id: "11" }) }]),
    });
    expect([...ctx.db.claimHistoryEvent.iter()].map((e) => e.kind)).toEqual([
      "coverage_started",
    ]);
  });
  it("expires old events and retains only the newest bounded event count", () => {
    const ctx = context();
    for (let i = 0; i < HISTORY_EVENT_LIMIT + 2; i++)
      ctx.db.claimHistoryEvent.insert({
        key: String(i).padStart(8, "0"),
        regionId: 8,
        claimId: "10",
        kind: "member_joined",
        observedAt: ctx.timestamp.microsSinceUnixEpoch + BigInt(i),
        payload: "{}",
      });
    ctx.db.claimHistoryEvent.insert({
      key: "expired",
      regionId: 8,
      claimId: "10",
      kind: "member_joined",
      observedAt: 0n,
      payload: "{}",
    });
    pruneClaimHistory(ctx, true);
    expect(ctx.db.claimHistoryEvent.count()).toBe(BigInt(HISTORY_EVENT_LIMIT));
    expect(ctx.db.claimHistoryEvent.key.find("expired")).toBeUndefined();
    expect(ctx.db.claimHistoryEvent.key.find("00000000")).toBeUndefined();
  });
  it("paginates same-time events without omissions and preserves history in checkpoints", () => {
    const ctx = context();
    for (const key of ["a", "b", "c"])
      ctx.db.claimHistoryEvent.insert({
        key,
        regionId: 8,
        claimId: "10",
        kind: "member_departed",
        observedAt: ctx.timestamp.microsSinceUnixEpoch,
        payload: '{"playerName":"Vel","tier":6,"trackedBefore":true}',
      });
    const args = { query: "vel", regionId: 8, kind: "", before: "", limit: 2 };
    const first = JSON.parse(readClaimHistory(ctx, args)),
      second = JSON.parse(
        readClaimHistory(ctx, { ...args, before: first.next }),
      );
    expect([...first.events, ...second.events].map((e) => e.key)).toEqual([
      "c",
      "b",
      "a",
    ]);
    const saved = JSON.parse(checkpointPage(ctx, "claim_history_event", ""));
    const restored = context();
    restoreCheckpointPage(
      restored,
      "claim_history_event",
      JSON.stringify(saved.rows),
    );
    expect([...restored.db.claimHistoryEvent.iter()]).toEqual([
      ...ctx.db.claimHistoryEvent.iter(),
    ]);
  });
  it("projects rapid transactions separately and keeps the actual supply level between retained events", () => {
    const rows = (items: any[]) =>
      new Map(items.map((r) => [String(r.entity_id ?? r.id), r]));
    const tables = new Map([
      [
        "claim_state",
        rows([
          {
            entity_id: "10",
            name: "Claim",
            owner_player_entity_id: "20",
            owner_building_entity_id: "30",
            neutral: false,
          },
        ]),
      ],
      ["claim_local_state", rows([{ entity_id: "10", supplies: 100 }])],
      [
        "claim_member_state",
        rows([
          {
            entity_id: "40",
            claim_entity_id: "10",
            player_entity_id: "20",
            user_name: "Owner",
          },
        ]),
      ],
      ["claim_tech_state", rows([{ entity_id: "10", learned: [600] }])],
      ["claim_tech_desc", rows([{ id: 600, tier: 6 }])],
      ["player_username_state", rows([{ entity_id: "20", username: "Owner" }])],
      [
        "building_state",
        rows([
          {
            entity_id: "50",
            claim_entity_id: "10",
            building_description_id: 205715693,
          },
          {
            entity_id: "51",
            claim_entity_id: "10",
            building_description_id: 985246037,
          },
        ]),
      ],
      [
        "building_desc",
        rows([
          { id: 205715693, name: "Waystone" },
          { id: 985246037, name: "Town Bank" },
          { id: 1615467546, name: "Ancient Bank" },
        ]),
      ],
    ]);
    const p = new ClaimHistoryProjection();
    expect(p.apply(true, tables, [])[0].after!.tier).toBe(6);
    const local = tables.get("claim_local_state")!;
    local.set("10", { entity_id: "10", supplies: 50 });
    expect(
      p.apply(false, tables, [
        { table: "claim_local_state", row: local.get("10") },
      ]),
    ).toEqual([]);
    local.set("10", { entity_id: "10", supplies: 60 });
    const refill = p.apply(false, tables, [
      { table: "claim_local_state", row: local.get("10") },
    ]);
    expect(refill[0].before!.supplies).toBe(50);
    local.set("10", { entity_id: "10", supplies: 0 });
    const empty = p.apply(false, tables, [
      { table: "claim_local_state", row: local.get("10") },
    ]);
    expect(empty[0].before!.supplies).toBe(60);
    const buildings = tables.get("building_state")!;
    const removedBank = buildings.get("51");
    buildings.delete("51");
    const lostBank = p.apply(false, tables, [
      { table: "building_state", row: removedBank },
    ]);
    expect(lostBank[0].before!.banks).toBe(1);
    expect(lostBank[0].after!.banks).toBe(0);
    expect(claimChanges(lostBank[0].before, lostBank[0].after)[0].kind).toBe(
      "tracking_stopped",
    );
    const refillIgnored = p.apply(false, tables, [
      { table: "claim_local_state", row: local.get("10") },
    ]);
    expect(refillIgnored).toEqual([]);
    buildings.set("51", removedBank);
    const regained = p.apply(false, tables, [
      { table: "building_state", row: removedBank },
    ])[0];
    expect(claimChanges(regained.before, regained.after)[0].kind).toBe(
      "tracking_started",
    );
  });
});
