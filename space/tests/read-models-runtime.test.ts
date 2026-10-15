import { describe, expect, it } from "vitest";
import { ConnectionId, Timestamp, TimeDuration } from "spacetimedb";
import {
  createModuleTestHarness,
  TestAuth,
  TestClock,
} from "spacetimedb/server/test-utils";
import claimDb, * as claims from "../bitcraft/src/index";
import gameDb, * as game from "../spacetimedb/src/index";
import { inventoryDetailRows } from "../spacetimedb/src/inventory-reads";
import { readMarketPage } from "../spacetimedb/src/market-reads";
import { referencedItems } from "../spacetimedb/src/performance";
import content from "../spacetimedb/src/content/evergather.json";

const at = 1_800_000_000_000_000n;
const owner = TestAuth.internal();
const visitor = TestAuth.fromJwtPayload(
  '{"iss":"test","sub":"visitor"}',
  new ConnectionId(1n),
);

describe("compact claim revisions", () => {
  it("backfills old rows without changing history and is safe to repeat", () => {
    const test = createModuleTestHarness(claimDb, claims, {
      clock: new TestClock(new Timestamp(at)),
    });
    test.withReducerTx(owner, (ctx) => {
      claims.init(ctx, {});
      ctx.db.claimHistoryRegion.insert({
        key: "8",
        regionId: 8,
        epoch: "old-generation",
        sequence: 12,
        observedAt: at,
        startedAt: at,
        complete: true,
        prunedAt: at,
        discarded: 0n,
        reconciling: false,
      });
      ctx.db.claimHistoryState.insert({
        key: "8:10",
        regionId: 8,
        epoch: "old-generation",
        observedAt: at,
        payload: '{"id":"10","name":"Retained"}',
      });
    });
    const before = [...test.db.claimHistoryState.iter()];
    expect(() =>
      test.withReducerTx(visitor, (ctx) =>
        claims.prepareClaimRevisions(ctx, {}),
      ),
    ).toThrow("owner");
    test.withReducerTx(owner, (ctx) => claims.prepareClaimRevisions(ctx, {}));
    const revision = claims.claimChanges(test.anonymousViewContext());
    expect(revision).toMatchObject([
      { key: "8", claims: 1, supplies: 0, sequence: 12 },
    ]);
    test.withReducerTx(owner, (ctx) => claims.prepareClaimRevisions(ctx, {}));
    expect(claims.claimChanges(test.anonymousViewContext())).toEqual(revision);
    expect([...test.db.claimHistoryState.iter()]).toEqual(before);
  });

  it("ignores quiet heartbeats but invalidates supply changes, removals and reconnects", () => {
    const test = createModuleTestHarness(claimDb, claims, {
      clock: new TestClock(new Timestamp(at)),
    });
    test.withReducerTx(owner, (ctx) => {
      claims.init(ctx, {});
      claims.storageLease(ctx, { seconds: 300 });
      ctx.db.collectorIdentity.insert({ identity: ctx.sender });
    });
    const claim = {
      id: "10",
      name: "Claim",
      ownerId: "20",
      ownerName: "Owner",
      totemId: "30",
      supplies: 100,
      tier: 6,
      waystones: 1,
      banks: 1,
      x: 1,
      z: 2,
      members: [],
    };
    const ingest = (
      sequence: number,
      initial: boolean,
      payload: unknown[],
      epoch = "revision-proof",
    ) =>
      test.withReducerTx(owner, (ctx) =>
        claims.ingestClaimHistory(ctx, {
          regionId: 8,
          epoch,
          sequence,
          initial,
          complete: true,
          observedAt: ctx.timestamp.microsSinceUnixEpoch,
          payload: JSON.stringify(payload),
        }),
      );
    ingest(1, true, [{ before: null, after: claim }]);
    const before = claims.claimChanges(test.anonymousViewContext());
    test.clock.advance(TimeDuration.fromMillis(1000));
    ingest(2, false, []);
    expect(claims.claimChanges(test.anonymousViewContext())).toEqual(before);
    const supply = {
      claimId: "10",
      supplies: 100,
      tileCount: 5,
      buildingMaintenance: 0,
      treasury: "1",
      upkeepPerHour: 2,
      tickMillis: 3600000,
    };
    const supplies = (value: typeof supply | null) =>
      test.withReducerTx(owner, (ctx) =>
        claims.ingestClaimSupplies(ctx, {
          regionId: 8,
          epoch: "revision-proof",
          observedAt: ctx.timestamp.microsSinceUnixEpoch,
          payload: JSON.stringify([{ claimId: "10", supply: value }]),
        }),
      );
    supplies(supply);
    const supplied = claims.claimChanges(test.anonymousViewContext());
    supplies(supply);
    expect(claims.claimChanges(test.anonymousViewContext())).toEqual(supplied);
    supplies({ ...supply, supplies: 99 });
    expect(
      claims.claimChanges(test.anonymousViewContext())[0].supplyVersion,
    ).not.toBe(supplied[0].supplyVersion);
    supplies(null);
    expect(claims.claimChanges(test.anonymousViewContext())[0].supplies).toBe(
      0,
    );
    ingest(3, false, [{ before: claim, after: { ...claim, banks: 0 } }]);
    expect(claims.claimChanges(test.anonymousViewContext())[0].claims).toBe(0);
    ingest(1, true, [], "reconnected-proof");
    expect(claims.claimChanges(test.anonymousViewContext())[0].epoch).toBe(
      "reconnected-proof",
    );
  });
});

describe("bounded Evergather reads", () => {
  function setup() {
    const test = createModuleTestHarness(gameDb, game, {
      clock: new TestClock(new Timestamp(at)),
    });
    test.withReducerTx(owner, (ctx) => game.init(ctx, {}));
    return test;
  }
  it("indexes existing listings without modifying them and reads deterministic cursor pages", () => {
    const test = setup();
    test.withReducerTx(owner, (ctx) => {
      for (let i = 0; i < 125; i++)
        ctx.db.listing.insert({
          id: 0n,
          seller: ctx.sender,
          sellerName: "Fixture",
          itemKey: "fishing_1",
          name: "Minnow",
          rarity: "common",
          quantity: 1n,
          unitPrice: 12n,
          createdAt: new Timestamp(at + BigInt(Math.floor(i / 3))),
          toolId: 0n,
          toolSnapshot: "",
        });
    });
    const original = [...test.db.listing.iter()];
    expect(() =>
      test.withReducerTx(visitor, (ctx) => game.prepareReadModels(ctx, {})),
    ).toThrow("owner");
    test.withReducerTx(owner, (ctx) => game.prepareReadModels(ctx, {}));
    test.withReducerTx(owner, (ctx) => game.prepareReadModels(ctx, {}));
    expect([...test.db.listing.iter()]).toEqual(original);
    expect(test.db.marketOrder.count()).toBe(125n);
    const legacy = game.marketPageListings(test.anonymousViewContext());
    const first = test.withReducerTx(owner, (ctx) =>
      readMarketPage(ctx, 1, ""),
    );
    const second = test.withReducerTx(owner, (ctx) =>
      readMarketPage(ctx, 2, first.next, first.revision),
    );
    expect(first.rows.map((row) => row.id)).toEqual(
      legacy.filter((row) => row.page === 1).map((row) => row.id),
    );
    expect(second.rows.map((row) => row.id)).toEqual(
      legacy.filter((row) => row.page === 2).map((row) => row.id),
    );
    const stale = test.withReducerTx(owner, (ctx) =>
      readMarketPage(ctx, 2, first.next, "stale"),
    );
    expect(stale.rows).toEqual(second.rows);
    expect(
      test.withReducerTx(owner, (ctx) => readMarketPage(ctx, 99, "")).rows,
    ).toHaveLength(25);
    expect(() =>
      test.withReducerTx(owner, (ctx) => readMarketPage(ctx, 2, "invalid")),
    ).toThrow("cursor");
    const bounded = test.withReducerTx(owner, (ctx) => {
      // SDK table objects are frozen. Instrument a copy without mutating them.
      const guardedDb = {
        ...ctx.db,
        listing: {
          ...ctx.db.listing,
          iter: () => {
            throw Error("No full listing scan");
          },
        },
      } as unknown as typeof ctx.db;
      return readMarketPage({ ...ctx, db: guardedDb }, 1, "");
    });
    expect(bounded.rows).toHaveLength(50);
    test.withReducerTx(owner, (ctx) => {
      ctx.db.economyState.id.delete(1);
      game.resetItemEconomy(ctx, { version: content.economy_version });
    });
    expect(test.db.marketOrder.count()).toBe(0n);
    expect(test.db.marketRevision.id.find(1)?.revision).toBe(1n);
    expect(
      test.withReducerTx(owner, (ctx) => readMarketPage(ctx, 1, "")).rows,
    ).toEqual([]);
  });

  it("keeps every item searchable while returning only selected routes and nested inputs", () => {
    const test = setup();
    const full = game.inventoryGuideDefinitions(test.anonymousViewContext());
    const directory = game.inventoryDirectory(test.anonymousViewContext());
    const allKeys = new Set(
      full
        .filter((row) => row.kind === "item_metadata")
        .map((row) => JSON.parse(row.payload).item_key),
    );
    expect(
      new Set(directory.map((row) => JSON.parse(row.payload).item_key)),
    ).toEqual(allKeys);
    expect(JSON.stringify(directory).length).toBeLessThan(
      JSON.stringify(full).length / 5,
    );
    const roots = ["smelting_1", "fishing_1"];
    const details = test.withReducerTx(owner, (ctx) =>
      inventoryDetailRows(ctx, JSON.stringify(roots)),
    );
    const expected = full.filter(
      (row) =>
        row.kind !== "item_metadata" &&
        roots.some((key) => referencedItems(JSON.parse(row.payload)).has(key)),
    );
    for (const row of expected) expect(details).toContainEqual(row);
    expect(
      details.some((row) => row.key === "item_metadata:mining_1:common"),
    ).toBe(true);
    expect(
      details.some((row) => row.key === "item_metadata:survival_1:common"),
    ).toBe(true);
    expect(details.length).toBeLessThan(full.length);
    expect(() =>
      test.withReducerTx(owner, (ctx) =>
        inventoryDetailRows(ctx, JSON.stringify(Array(33).fill("fishing_1"))),
      ),
    ).toThrow("32");
  });
});
