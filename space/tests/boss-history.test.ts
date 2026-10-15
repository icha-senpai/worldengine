import { describe, it, expect } from "vitest";
import { ConnectionId, Timestamp, TimeDuration } from "spacetimedb";
import {
  createModuleTestHarness,
  TestAuth,
  TestClock,
} from "spacetimedb/server/test-utils";
import db, * as module from "../bitcraft/src/index";
import { pruneBossHistory, queryBossBoard } from "../bitcraft/src/boss-history";

function setup() {
  const test = createModuleTestHarness(db, module, {
    clock: new TestClock(new Timestamp(1_800_000_000_000_000n)),
    rngSeed: 42,
  });
  const owner = TestAuth.internal();
  test.withReducerTx(owner, (ctx) => {
    module.init(ctx);
    module.storageLease(ctx, { seconds: 300 });
    ctx.db.collectorIdentity.insert({ identity: ctx.sender });
  });
  const defeat = (enemyId = "100", enemyType = 32) => ({
    enemyId,
    enemyType,
    diedAt: String(test.clock.now().microsSinceUnixEpoch / 1_000_000n),
    killerId: "10",
    participants: [
      { id: "10", name: "Krutch", contribution: 75 },
      { id: "20", name: "Helper", contribution: 25 },
    ],
  });
  const ingest = (rows = [defeat()]) =>
    test.withReducerTx(owner, (ctx) =>
      module.ingestBossDefeats(ctx, {
        regionId: 9,
        epoch: "boss-proof",
        payload: JSON.stringify(rows),
      }),
    );
  const board = (enemyType = 0) =>
    test.withReducerTx(owner, (ctx) =>
      JSON.parse(
        queryBossBoard(ctx, {
          enemyType,
          query: "",
          sort: "defeats",
          page: 1,
          limit: 50,
        }),
      ),
    );
  return { test, owner, defeat, ingest, board };
}
describe("boss recording in the native database", () => {
  it("deduplicates deaths, credits all participants and only the observed final blow", () => {
    const { test, ingest, defeat, board } = setup();
    const first = defeat();
    ingest([first]);
    ingest([first]);
    ingest([{ ...defeat("200", 40), killerId: "" }]);
    expect(test.db.bossDefeat.count()).toBe(2n);
    expect(board().totals.defeats).toBe("2");
    expect(
      board().rows.map((r: any) => [r.name, r.defeats, r.finalBlows]),
    ).toEqual([
      ["Krutch", "2", "1"],
      ["Helper", "2", "0"],
    ]);
    expect(board(40).rows[0].defeats).toBe("1");
    expect(board(40).rows[0].finalBlows).toBe("0");
  });
  it("rejects visitors, expired leases, trash types and conflicting evidence atomically", () => {
    const { test, owner, ingest, defeat } = setup();
    const caller = TestAuth.fromJwtPayload(
      JSON.stringify({ iss: "test", sub: "visitor" }),
      new ConnectionId(1n),
    );
    expect(() =>
      test.withReducerTx(caller, (ctx) =>
        module.ingestBossDefeats(ctx, {
          regionId: 9,
          epoch: "fake",
          payload: JSON.stringify([defeat()]),
        }),
      ),
    ).toThrow("Collector authorization");
    expect(() => ingest([defeat("100", 33)])).toThrow("Invalid boss defeat");
    const first = defeat();
    ingest([first]);
    expect(() => ingest([{ ...first, killerId: "20" }])).toThrow(
      "Conflicting evidence",
    );
    expect(test.db.bossDefeat.count()).toBe(1n);
    test.clock.advance(TimeDuration.fromMillis(301000));
    expect(() => ingest([defeat("200")])).toThrow("storage maintenance");
    expect(test.db.bossDefeat.count()).toBe(1n);
    void owner;
  });
  it("expires encounter details without reducing totals and refuses old replay", () => {
    const { test, owner, ingest, defeat, board } = setup();
    const first = defeat();
    ingest([first]);
    test.clock.advance(TimeDuration.fromMillis(31 * 86400 * 1000));
    test.withReducerTx(owner, (ctx) => {
      module.storageLease(ctx, { seconds: 300 });
      pruneBossHistory(ctx);
    });
    expect(test.db.bossDefeat.count()).toBe(0n);
    expect(board().totals.defeats).toBe("1");
    expect(board().rows[0].defeats).toBe("1");
    expect(() => ingest([first])).toThrow("recording window");
  });
  it("records reconnect gaps without awarding baseline kills", () => {
    const { test, owner, board } = setup();
    const beat = (epoch: string, ready: boolean) =>
      test.withReducerTx(owner, (ctx) =>
        module.bossHeartbeat(ctx, {
          regionId: 9,
          epoch,
          ready,
          error: ready ? "" : "disconnected",
        }),
      );
    beat("first", true);
    beat("first", false);
    beat("second", true);
    expect(board().coverage[0].gaps).toBe(1);
    expect(board().coverage[0].ready).toBe(true);
    expect(board().totals.defeats).toBe("0");
    test.clock.advance(TimeDuration.fromMillis(71000));
    expect(board().coverage[0].ready).toBe(false);
  });
});
