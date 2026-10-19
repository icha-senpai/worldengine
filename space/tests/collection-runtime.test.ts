import { describe, expect, it, vi } from "vitest";
import { ConnectionId, Timestamp, TimeDuration } from "spacetimedb";
import {
  createModuleTestHarness,
  TestAuth,
  TestClock,
} from "spacetimedb/server/test-utils";
import db, * as module from "../bitcraft/src/index";

function setup(start = 1_800_000_000_000_000n) {
  const test = createModuleTestHarness(db, module, {
    clock: new TestClock(new Timestamp(start)),
    rngSeed: 42,
  });
  const owner = TestAuth.internal();
  const reader = TestAuth.fromJwtPayload(
    '{"iss":"test","sub":"reader"}',
    new ConnectionId(1n),
  );
  const tx = <T>(run: Parameters<typeof test.withReducerTx<T>>[1]) =>
    test.withReducerTx(owner, run);
  tx((ctx) => {
    module.init(ctx, {});
    module.storageLease(ctx, { seconds: 300 });
    ctx.db.collectorIdentity.insert({ identity: ctx.sender });
  });
  const http = vi.fn(() => ({
    code: 200,
    headers: { entries: [] },
    version: { tag: "Http11" as const },
    body: '{"regions":[{"id":7,"name":"Fixture region"}]}',
  }));
  const request = (collector = false) =>
    module.requestData(
      test
        .procedureContextBuilder(collector ? owner : reader)
        .http(http)
        .build(),
      {
        resource: "regions",
        id: "",
        query: "",
        page: 1,
        options: collector ? '{"collector":true}' : "{}",
      },
    );
  const advance = (seconds: number) => {
    test.clock.advance(TimeDuration.fromMillis(seconds * 1000));
    tx((ctx) => module.storageLease(ctx, { seconds: 300 }));
  };
  return { test, owner, reader, tx, http, request, advance };
}

describe("collection procedures with mocked upstream HTTP", () => {
  it("keeps calendar records while enforcing the shared 6000-row sample budget", () => {
    const { test, tx } = setup();
    const key = "relaySkills|player/20/skills";
    tx((ctx) => {
      ctx.db.collectionWatch.insert({
        key,
        resource: "relaySkills",
        entityId: "20",
        query: "",
        page: 1,
        options: "{}",
        expiresAt: ctx.timestamp.microsSinceUnixEpoch + 86400000000n,
      });
      for (let n = 0; n < 6000; n++)
        ctx.db.collectionSample.insert({
          key: `rate-${n}`,
          scope: "old-rates",
          source: "relay",
          epoch: "seed",
          observedAt: 1n,
          payload: "{}",
        });
      module.ingestCollection(ctx, {
        key,
        source: "relay",
        epoch: "budget-proof-native-1",
        observedAt: ctx.timestamp.microsSinceUnixEpoch,
        payload: JSON.stringify({
          player: { entity_id: "20", username: "Fixture", region: 9 },
          skills: [{ skill_id: 12, xp: 100 }],
        }),
      });
    });
    expect(test.db.collectionSample.count()).toBe(6000n);
    expect([
      ...test.db.collectionSample.scope.filter("dailyXp|" + key),
    ]).toHaveLength(1);
    expect([...test.db.collectionSample.scope.filter(key)]).toHaveLength(1);
  });
  it("keeps the new day's opening when both boundary records share a timestamp", () => {
    const { test, reader, tx, advance } = setup(
      BigInt(Date.parse("2026-10-18T04:59:20Z")) * 1000n,
    );
    const key = "relaySkills|player/20/skills",
      epoch = "boundary-proof-native-1";
    tx((ctx) =>
      ctx.db.collectionWatch.insert({
        key,
        resource: "relaySkills",
        entityId: "20",
        query: "",
        page: 1,
        options: "{}",
        expiresAt: ctx.timestamp.microsSinceUnixEpoch + 86400000000n,
      }),
    );
    const ingest = (xp: number) =>
      tx((ctx) =>
        module.ingestCollection(ctx, {
          key,
          source: "relay",
          epoch,
          observedAt: ctx.timestamp.microsSinceUnixEpoch,
          payload: JSON.stringify({
            player: { entity_id: "20", username: "Fixture", region: 9 },
            skills: [{ skill_id: 12, xp }],
          }),
        }),
      );
    ingest(100);
    advance(61);
    ingest(120);
    advance(61);
    ingest(140);
    const history = JSON.parse(
      module.collectionHistory(test.procedureContextBuilder(reader).build(), {
        playerId: "20",
      }),
    );
    expect(history.calendar.periods[0]).toMatchObject({
      partial: false,
      xp: { "12": 20 },
    });
    expect(history.calendar.periods[1]).toMatchObject({
      partial: true,
      xp: { "12": 20 },
    });
  });
  it("retains calendar gains beyond rate history and through maintenance restoration", () => {
    const { test, owner, reader, tx, advance } = setup();
    const key = "relaySkills|player/20/skills",
      epoch = "daily-proof-native-1";
    tx((ctx) =>
      ctx.db.collectionWatch.insert({
        key,
        resource: "relaySkills",
        entityId: "20",
        query: "",
        page: 1,
        options: "{}",
        expiresAt: ctx.timestamp.microsSinceUnixEpoch + 8n * 86400000000n,
      }),
    );
    const ingest = (xp: number) =>
      tx((ctx) =>
        module.ingestCollection(ctx, {
          key,
          source: "relay",
          epoch,
          observedAt: ctx.timestamp.microsSinceUnixEpoch,
          payload: JSON.stringify({
            player: { entity_id: "20", username: "Fixture", region: 9 },
            skills: [{ skill_id: 12, xp }],
          }),
        }),
      );
    ingest(100);
    advance(61);
    ingest(150);
    const history = () =>
      JSON.parse(
        module.collectionHistory(test.procedureContextBuilder(reader).build(), {
          playerId: "20",
        }),
      );
    expect(history().calendar.periods[0]).toMatchObject({
      partial: true,
      xp: { "12": 50 },
    });
    advance(7 * 3600);
    tx((ctx) => module.collectorHeartbeat(ctx, { epoch, error: "" }));
    expect(history().samples).toHaveLength(0);
    const rows = [...test.db.collectionSample.iter()];
    expect(rows).toHaveLength(1);
    expect(rows[0].scope).toBe("dailyXp|" + key);
    const exported = module.exportAppState(
      test.procedureContextBuilder(owner).build(),
      {},
    );
    tx((ctx) => module.storageLease(ctx, { seconds: 0 }));
    tx((ctx) => module.restoreAppState(ctx, { payload: exported }));
    expect([...test.db.collectionSample.iter()]).toEqual(rows);
    expect(history().calendar.periods[0].xp).toEqual({ "12": 50 });
    advance(36 * 86400);
    tx((ctx) => module.collectorHeartbeat(ctx, { epoch, error: "" }));
    expect([...test.db.collectionSample.iter()]).toHaveLength(0);
  });
  it("serves a five-hour-old directory from collection and keeps the actual fetch time", () => {
    const { test, tx, http, request, advance } = setup();
    const first = request();
    const key = "regions|api/regions?",
      epoch = "freshness-proof";
    tx((ctx) => {
      module.collectorHeartbeat(ctx, { epoch, error: "" });
      module.ingestCollection(ctx, {
        key,
        epoch,
        source: "bitjita",
        payload: first.payload,
        observedAt: first.updatedAt,
      });
    });
    advance(5 * 3600);
    tx((ctx) => {
      module.collectorHeartbeat(ctx, { epoch, error: "" });
      module.touchCollection(ctx, { key, observedAt: first.updatedAt });
    });
    expect(request()).toMatchObject({
      payload: first.payload,
      updatedAt: first.updatedAt,
      error: "",
      retryAt: 0n,
    });
    expect(request().key).toMatch(/^collection\|/);
    expect(http).toHaveBeenCalledTimes(1);
    expect(test.db.refreshState.key.find(`feed|${key}`)?.expiresAt).toBe(
      first.updatedAt + 86_400_000_000n,
    );

    // Even with a healthy collector, an expired upstream cache needs a new fetch.
    advance(20 * 3600);
    const refreshed = request(true);
    expect(http).toHaveBeenCalledTimes(2);
    expect(refreshed.updatedAt).toBe(test.clock.now().microsSinceUnixEpoch);
    expect(refreshed.updatedAt).toBeGreaterThan(first.updatedAt);
  });

  it("updates unchanged cache freshness without rewriting the payload row", () => {
    const { test, request, http, advance } = setup();
    const first = request();
    const stored = test.db.snapshot.key.find(first.key)!;
    advance(86_401);
    const next = request(true);
    expect(http).toHaveBeenCalledTimes(2);
    expect(next.payload).toBe(first.payload);
    expect(next.updatedAt).toBeGreaterThan(first.updatedAt);
    expect(test.db.snapshot.key.find(first.key)).toEqual(stored);
    expect(test.db.refreshState.key.find(`cache|${first.key}`)?.updatedAt).toBe(
      next.updatedAt,
    );
  });

  it("does not label a valid cache as live collection after its heartbeat stops", () => {
    const { tx, request, advance, http } = setup();
    const first = request(),
      key = "regions|api/regions?",
      epoch = "heartbeat-proof";
    tx((ctx) => {
      module.collectorHeartbeat(ctx, { epoch, error: "" });
      module.ingestCollection(ctx, {
        key,
        epoch,
        source: "bitjita",
        payload: first.payload,
        observedAt: first.updatedAt,
      });
    });
    expect(request().key).toMatch(/^collection\|/);
    advance(31);
    const fallback = request();
    expect(fallback.key).not.toMatch(/^collection\|/);
    expect(fallback.payload).toBe(first.payload);
    expect(fallback.updatedAt).toBe(first.updatedAt);
    expect(http).toHaveBeenCalledTimes(1);
  });

  it("backs off failed refreshes while retaining usable cached data", () => {
    const { request, http, advance, test } = setup();
    const first = request();
    advance(86_401);
    http.mockReturnValue({
      code: 503,
      headers: { entries: [] },
      version: { tag: "Http11" },
      body: "Unavailable",
    });
    const failed = request(true);
    expect(failed.payload).toBe(first.payload);
    expect(failed.updatedAt).toBe(first.updatedAt);
    expect(failed.error).not.toBe("");
    expect(failed.retryAt).toBeGreaterThan(
      test.clock.now().microsSinceUnixEpoch,
    );
    request(true);
    expect(http).toHaveBeenCalledTimes(2);
    advance(31);
    http.mockReturnValue({
      code: 200,
      headers: { entries: [] },
      version: { tag: "Http11" },
      body: first.payload,
    });
    expect(request(true).error).toBe("");
    expect(http).toHaveBeenCalledTimes(3);
  });

  it("rejects unauthorized and backward freshness updates without changing the feed", () => {
    const { test, reader, tx, request } = setup();
    const first = request(),
      key = "regions|api/regions?",
      epoch = "freshness-proof";
    tx((ctx) =>
      module.ingestCollection(ctx, {
        key,
        epoch,
        source: "bitjita",
        payload: first.payload,
        observedAt: first.updatedAt,
      }),
    );
    const before = test.db.refreshState.key.find(`feed|${key}`);
    expect(() =>
      test.withReducerTx(reader, (ctx) =>
        module.touchCollection(ctx, { key, observedAt: first.updatedAt }),
      ),
    ).toThrow("authorization");
    expect(() =>
      tx((ctx) =>
        module.touchCollection(ctx, { key, observedAt: first.updatedAt - 1n }),
      ),
    ).toThrow("freshness");
    expect(test.db.refreshState.key.find(`feed|${key}`)).toEqual(before);
  });
});
