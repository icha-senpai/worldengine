import { describe, expect, it, vi } from "vitest";
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
