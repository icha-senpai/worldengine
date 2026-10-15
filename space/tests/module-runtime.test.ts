import { describe, expect, it } from "vitest";
import { ConnectionId, Timestamp, TimeDuration } from "spacetimedb";
import {
  createModuleTestHarness,
  TestAuth,
  TestClock,
} from "spacetimedb/server/test-utils";
import db, * as module from "../bitcraft/src/index";

function setup() {
  const test = createModuleTestHarness(db, module, {
    clock: new TestClock(new Timestamp(1_800_000_000_000_000n)),
    rngSeed: 42,
  });
  const owner = TestAuth.internal();
  test.withReducerTx(owner, (ctx) => module.init(ctx));
  test.withReducerTx(owner, (ctx) =>
    module.storageLease(ctx, { seconds: 300 }),
  );
  return { test, owner };
}

describe("BitCraft module with the native datastore", () => {
  it("enforces collector authorization and rolls back rejected writes", () => {
    const { test } = setup();
    const caller = TestAuth.fromJwtPayload(
      JSON.stringify({ iss: "test", sub: "reader" }),
      new ConnectionId(1n),
    );
    const args = {
      regionId: 8,
      epoch: "runtime-proof",
      sequence: 1,
      initial: true,
      complete: true,
      payload: "[]",
      observedAt: test.clock.now().microsSinceUnixEpoch,
    };
    expect(() =>
      test.withReducerTx(caller, (ctx) => module.ingestClaimHistory(ctx, args)),
    ).toThrow("Collector authorization");
    expect(test.db.claimHistoryRegion.count()).toBe(0n);
    expect(test.db.claimHistoryEvent.count()).toBe(0n);
  });

  it("expires storage leases against a deterministic clock", () => {
    const { test, owner } = setup();
    test.withReducerTx(owner, (ctx) =>
      ctx.db.collectorIdentity.insert({ identity: ctx.sender }),
    );
    test.clock.advance(TimeDuration.fromMillis(301_000));
    expect(() =>
      test.withReducerTx(owner, (ctx) =>
        module.ingestClaimHistory(ctx, {
          regionId: 8,
          epoch: "runtime-proof",
          sequence: 1,
          initial: true,
          complete: true,
          payload: "[]",
          observedAt: test.clock.now().microsSinceUnixEpoch,
        }),
      ),
    ).toThrow("storage maintenance");
    expect(test.db.claimHistoryRegion.count()).toBe(0n);
  });

  it("publishes small revision rows and leaves them unchanged for quiet supplies", () => {
    const { test, owner } = setup();
    test.withReducerTx(owner, (ctx) => {
      ctx.db.collectorIdentity.insert({ identity: ctx.sender });
      module.ingestClaimHistory(ctx, {
        regionId: 8,
        epoch: "runtime-proof",
        sequence: 1,
        initial: true,
        complete: true,
        payload: "[]",
        observedAt: ctx.timestamp.microsSinceUnixEpoch,
      });
    });
    const before = module.claimChanges(test.anonymousViewContext());
    expect(before).toEqual([
      {
        key: "8",
        epoch: "runtime-proof",
        sequence: 1,
        complete: true,
        claims: 0,
        supplies: 0,
        supplyVersion: "0",
      },
    ]);
    test.withReducerTx(owner, (ctx) => {
      ctx.db.claimSupplyStatus.insert({
        key: "8:10",
        regionId: 8,
        epoch: "runtime-proof",
        payload: "{}",
        observedAt: ctx.timestamp.microsSinceUnixEpoch,
      });
      module.prepareClaimRevisions(ctx, {});
    });
    const after = module.claimChanges(test.anonymousViewContext());
    expect(after[0].supplyVersion).not.toBe(before[0].supplyVersion);
    expect(JSON.stringify(after)).not.toContain("payload");
    expect(module.claimChanges(test.anonymousViewContext())).toEqual(after);
  });
});
