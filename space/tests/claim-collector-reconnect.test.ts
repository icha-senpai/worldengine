import { afterEach, expect, test, vi } from "vitest";
import { Timestamp } from "spacetimedb";
import {
  createModuleTestHarness,
  TestAuth,
  TestClock,
} from "spacetimedb/server/test-utils";
import db, * as module from "../bitcraft/src/index";
import { CollectorCycle } from "../scripts/bitcraft-collector-cycle";
import { ClaimHistoryCollector } from "../scripts/bitcraft-claim-history-collector";
import { RelaySubscription } from "../scripts/bitcraft-relay";
import { CLAIM_TABLES } from "../scripts/claim-history-projection";

const recorders: ClaimHistoryCollector[] = [];
afterEach(() => {
  recorders.splice(0).forEach((recorder) => recorder.stop());
  vi.restoreAllMocks();
});

test("a worker reconnect cannot reuse retained claim event IDs after a relay reconnect", async () => {
  const test = createModuleTestHarness(db, module, {
    clock: new TestClock(Timestamp.now()),
  });
  const owner = TestAuth.internal();
  test.withReducerTx(owner, (ctx) => {
    module.init(ctx, {});
    module.storageLease(ctx, { seconds: 300 });
    ctx.db.collectorIdentity.insert({ identity: ctx.sender });
  });
  const relays: RelaySubscription[] = [];
  vi.spyOn(RelaySubscription.prototype, "start").mockImplementation(
    async function () {
      if (!relays.includes(this)) relays.push(this);
      this.generation++;
      this.ready = true;
      for (const name of CLAIM_TABLES) this.tables.set(name, new Map());
      for (const [id, name] of [
        [205715693, "Waystone"],
        [985246037, "Town Bank"],
        [1615467546, "Ancient Bank"],
      ] as const)
        this.tables.get("building_desc")!.set(String(id), { id, name });
      // An empty real upstream snapshot still creates a coverage event. Only the
      // network is mocked: packet sequencing and transactional reducers are real.
      (this as any).changed(true, [], []);
    },
  );
  vi.spyOn(console, "error").mockImplementation(() => {});
  const conn = {
    reducers: {
      ingestClaimHistory: vi.fn(async (packet: any) =>
        test.withReducerTx(owner, (ctx) =>
          module.ingestClaimHistory(ctx, packet),
        ),
      ),
      relayHeartbeat: vi.fn(async (packet: any) =>
        test.withReducerTx(owner, (ctx) => module.relayHeartbeat(ctx, packet)),
      ),
    },
  };
  const recorder = (cycle: CollectorCycle, epoch = cycle.epoch) => {
    const recorder = new ClaimHistoryCollector(conn as any, epoch, cycle);
    recorders.push(recorder);
    return recorder;
  };
  const firstCycle = new CollectorCycle();
  const first = recorder(firstCycle);
  await first.start(7);
  await first.flush();
  await relays[0]!.start();
  await first.flush();
  first.stop();
  firstCycle.stop("Simulated database reconnect.");
  const retained = [...test.db.claimHistoryEvent.iter()];
  expect(retained).toHaveLength(2);

  // Reproduce the production bug: resetting relay counters under the process's
  // old epoch collides with generation 1, even though generation 2 is current.
  const brokenCycle = new CollectorCycle();
  const broken = recorder(brokenCycle, firstCycle.epoch);
  await broken.start(7);
  expect(
    retained.some(
      (event) => event.key === `${firstCycle.epoch}-claims-7-1:1:0`,
    ),
  ).toBe(true);
  // The portable datastore's primary-key violation has no message text.
  await expect(broken.flush()).rejects.toThrow();
  expect(brokenCycle.stopped).toBe(true);
  expect([...test.db.claimHistoryEvent.iter()]).toEqual(retained);
  expect(console.error).toHaveBeenCalledWith(
    expect.stringContaining("region 7"),
  );
  broken.stop();

  const nextCycle = new CollectorCycle();
  const next = recorder(nextCycle);
  await next.start(7);
  await next.flush();
  expect(nextCycle.stopped).toBe(false);
  expect(nextCycle.epoch).not.toBe(firstCycle.epoch);
  const events = [...test.db.claimHistoryEvent.iter()];
  expect(events).toHaveLength(3);
  expect(events.filter((event) => event.kind === "coverage_gap")).toHaveLength(
    2,
  );
  for (const event of retained)
    expect(test.db.claimHistoryEvent.key.find(event.key)).toEqual(event);
  expect(test.db.claimHistoryRegion.key.find("7")?.epoch).toBe(
    `${nextCycle.epoch}-claims-7-1`,
  );

  // A duplicate delivery within the same successful generation stays idempotent.
  const packet = conn.reducers.ingestClaimHistory.mock.calls.at(-1)![0];
  await conn.reducers.ingestClaimHistory(packet);
  expect(test.db.claimHistoryEvent.count()).toBe(3n);
});
