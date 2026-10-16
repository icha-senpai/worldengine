import { describe, expect, it } from "vitest";
import { ConnectionId, Timestamp, TimeDuration } from "spacetimedb";
import {
  createModuleTestHarness,
  TestAuth,
  TestClock,
} from "spacetimedb/server/test-utils";
import db, * as module from "../bitcraft/src/index";
import {
  observeClaimDecay,
  DECAY_WINDOW_MS,
  type ClaimDecayTiming,
} from "../bitcraft/src/claim-decay";
import {
  claimSupplyEstimate,
  type ClaimSupply,
} from "../bitcraft/src/claim-supplies";

const hour = 3_600_000;
const at = 1_800_000_000_000;
const supply = (id = "1", supplies = 300): ClaimSupply => ({
  claimId: id,
  supplies,
  upkeepPerHour: 100,
  tickMillis: hour,
  tileCount: 10,
  buildingMaintenance: 0,
  treasury: "1",
});
function drop(
  timing: ClaimDecayTiming | null,
  id: string,
  when = at,
  epoch = "first",
) {
  return observeClaimDecay(
    timing,
    supply(id),
    supply(id, 200),
    epoch,
    when,
    when - hour / 2,
    when,
  );
}
function confirmed(when = at) {
  let timing: ClaimDecayTiming | null = null;
  for (const id of ["1", "2", "3"]) timing = drop(timing, id, when);
  return timing!;
}
describe("regional supply decay", () => {
  it("requires three distinct claims in a bounded window and bounds retained evidence", () => {
    const first = drop(null, "1")!;
    expect(first.lastTickAt).toBeNull();
    expect(drop(first, "1")).toBe(first);
    expect(drop(drop(first, "2"), "3")?.lastTickAt).toBe(at);
    expect(
      drop(drop(first, "2"), "3", at + DECAY_WINDOW_MS + 1)?.lastTickAt,
    ).toBeNull();
    let timing = confirmed();
    for (let i = 4; i < 100; i++) timing = drop(timing, String(i))!;
    expect(timing.candidate.claims).toHaveLength(32);
    expect(timing.cycles).toBe(1);
  });
  it("rejects refills, unrelated spending, upkeep changes, late delivery and replay", () => {
    for (const next of [
      supply("1", 400),
      supply("1", 250),
      { ...supply("1", 200), upkeepPerHour: 200 },
    ])
      expect(
        observeClaimDecay(null, supply(), next, "first", at, at - 1000, at),
      ).toBeNull();
    expect(
      observeClaimDecay(null, supply(), supply("1", 200), "first", at, at, at),
    ).toBeNull();
    expect(
      observeClaimDecay(
        null,
        supply(),
        supply("1", 200),
        "first",
        at,
        at - 1000,
        at + DECAY_WINDOW_MS + 1,
      ),
    ).toBeNull();
    expect(
      observeClaimDecay(
        null,
        supply(),
        supply("1", 200),
        "first",
        at,
        at - 2 * hour,
        at,
      ),
    ).toBeNull();
  });
  it("learns a new generation/interval afresh and reconfirms later cycles", () => {
    const timing = confirmed();
    expect(drop(timing, "4", at + 100_000)).toBe(timing);
    expect(drop(timing, "4", at + hour, "second")?.lastTickAt).toBeNull();
    let next: ClaimDecayTiming | null = timing;
    for (const id of ["1", "2", "3"]) next = drop(next, id, at + hour);
    expect(next?.lastTickAt).toBe(at + hour);
    expect(next?.cycles).toBe(2);
    const changed = { ...supply("4", 250), tickMillis: hour / 2 };
    expect(
      observeClaimDecay(
        timing,
        { ...supply("4"), tickMillis: hour / 2 },
        changed,
        "first",
        at + hour,
        at + hour / 2,
        at + hour,
      )?.lastTickAt,
    ).toBeNull();
  });
  it("aligns run-out to discrete regional ticks and falls back on stale evidence", () => {
    const timing = confirmed(),
      balance = supply("4", 250);
    const reading = (minutes: number, epoch = "first", ready = true) =>
      claimSupplyEstimate(
        balance,
        BigInt(at + minutes * 60_000) * 1000n,
        BigInt(at + minutes * 60_000) * 1000n,
        ready,
        timing,
        epoch,
      );
    expect(reading(20)).toMatchObject({
      estimatedExpiresAt: at + 3 * hour,
      nextDecayAt: at + hour,
      estimateBasis: "regional_tick",
      decayConfirmations: 3,
    });
    // Treasury observations within an interval do not slide expiry around.
    expect(reading(50).estimatedExpiresAt).toBe(at + 3 * hour);
    // A newly observed unchanged balance after a later tick may have been topped up.
    expect(reading(70).estimatedExpiresAt).toBe(at + 4 * hour);
    expect(reading(20, "reconnected").estimateBasis).toBe("hourly_rate");
    expect(reading(20, "first", false).decayTimingStatus).toBe("stale");
    expect(reading(130).estimateBasis).toBe("hourly_rate");
    expect(
      claimSupplyEstimate(
        { ...balance, supplies: 0 },
        BigInt(at) * 1000n,
        BigInt(at) * 1000n,
        true,
        timing,
        "first",
      ).estimatedExpiresAt,
    ).toBeNull();
  });
  it("preserves rounding at half-unit maintenance and supports fractional hour intervals", () => {
    const balance = {
      ...supply("4", 5),
      upkeepPerHour: 5,
      tickMillis: hour / 2,
    };
    const timing = { ...confirmed(), tickMillis: hour / 2 };
    const forecast = claimSupplyEstimate(
      balance,
      BigInt(at) * 1000n,
      BigInt(at) * 1000n,
      true,
      timing,
      "first",
    );
    expect(forecast.estimatedExpiresAt).toBe(at + (3 * hour) / 2);
  });
});

describe("decay timing through actual module reducers", () => {
  it("keeps regions isolated, rejects readers, preserves checkpoints and ignores reconnect snapshots", () => {
    const test = createModuleTestHarness(db, module, {
      clock: new TestClock(new Timestamp(BigInt(at) * 1000n)),
    });
    const owner = TestAuth.internal();
    const tx = (run: (ctx: any) => void) => test.withReducerTx(owner, run);
    tx((ctx) => {
      module.init(ctx, {});
      module.storageLease(ctx, { seconds: 300 });
      ctx.db.collectorIdentity.insert({ identity: ctx.sender });
    });
    const setup = (regionId: number, epoch: string) =>
      tx((ctx) =>
        module.ingestClaimHistory(ctx, {
          regionId,
          epoch,
          sequence: 1,
          initial: true,
          complete: true,
          observedAt: ctx.timestamp.microsSinceUnixEpoch,
          payload: JSON.stringify(
            ["1", "2", "3"].map((id) => ({
              before: null,
              after: {
                id,
                name: "Fixture",
                ownerId: "20",
                ownerName: "Owner",
                totemId: "30",
                supplies: 300,
                tier: 6,
                waystones: 1,
                banks: 1,
                x: 1,
                z: 2,
                members: [],
              },
            })),
          ),
        }),
      );
    const args = (regionId: number, epoch: string, balance: number) => ({
      regionId,
      epoch,
      observedAt: test.clock.now().microsSinceUnixEpoch,
      payload: JSON.stringify(
        ["1", "2", "3"].map((id) => ({
          claimId: id,
          supply: supply(id, balance),
        })),
      ),
    });
    for (const region of [8, 9]) {
      const epoch = region === 9 ? "first" : "other";
      setup(region, epoch);
      tx((ctx) => module.ingestClaimSupplies(ctx, args(region, epoch, 300)));
    }
    test.clock.advance(TimeDuration.fromMillis(1000));
    const reader = TestAuth.fromJwtPayload(
      '{"iss":"fixture","sub":"reader"}',
      new ConnectionId(1n),
    );
    expect(() =>
      test.withReducerTx(reader, (ctx) =>
        module.ingestClaimSupplies(ctx, args(9, "first", 200)),
      ),
    ).toThrow("Collector authorization");
    tx((ctx) => module.ingestClaimSupplies(ctx, args(9, "first", 200)));
    expect(test.db.claimDecayRegion.count()).toBe(1n);
    expect(test.db.claimDecayRegion.key.find("8")).toBeNull();
    const saved = test.db.claimDecayRegion.key.find("9")!;
    expect(JSON.parse(saved.payload).confirmedClaims).toBe(3);
    const page = JSON.parse(
      module.exportMarketCheckpoint(
        test.procedureContextBuilder(owner).build(),
        { table: "claim_decay_region", after: "" },
      ) as string,
    );
    tx((ctx) => {
      module.storageLease(ctx, { seconds: 0 });
      ctx.db.claimDecayRegion.key.delete("9");
      module.restoreMarketCheckpoint(ctx, {
        table: "claim_decay_region",
        payload: JSON.stringify(page.rows),
      });
      module.storageLease(ctx, { seconds: 300 });
    });
    expect(test.db.claimDecayRegion.key.find("9")).toEqual(saved);
    // New baseline changes are not evidence of when a missed tick occurred.
    setup(9, "second");
    tx((ctx) => module.ingestClaimSupplies(ctx, args(9, "second", 100)));
    expect(test.db.claimDecayRegion.key.find("9")).toEqual(saved);
    expect(test.db.claimHistoryEvent.count()).toBe(3n);
  });
});
