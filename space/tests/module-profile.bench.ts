import { bench, describe } from "vitest";
import { Identity, Timestamp } from "spacetimedb";
import {
  createModuleTestHarness,
  TestAuth,
  TestClock,
} from "spacetimedb/server/test-utils";
import bitcraftDb, * as bitcraft from "../bitcraft/src/index";
import evergatherDb, * as evergather from "../spacetimedb/src/index";

// In-memory view-function measurements: no server, live identities, network,
// subscription fan-out or retained fixture databases. Setup is outside timing.
const at = 1_800_000_000_000_000n;
const options = { time: 250, iterations: 20, warmupTime: 100 };
const owner = TestAuth.internal();
for (const count of [100, 1000]) {
  const test = createModuleTestHarness(bitcraftDb, bitcraft, {
    clock: new TestClock(new Timestamp(at)),
  });
  test.withReducerTx(owner, (ctx) => {
    bitcraft.init(ctx, {});
    const regions = [7, 8, 9, 12, 13, 14, 17, 18, 19];
    for (const regionId of regions)
      ctx.db.claimHistoryRegion.insert({
        key: String(regionId),
        regionId,
        epoch: "profile-fixture",
        sequence: 1,
        observedAt: at,
        startedAt: at,
        complete: true,
        prunedAt: at,
        discarded: 0n,
        reconciling: false,
      });
    for (let i = 0; i < count; i++) {
      const regionId = regions[i % regions.length],
        key = `${regionId}:${i}`;
      const row = { key, regionId, epoch: "profile-fixture", observedAt: at };
      ctx.db.claimHistoryState.insert({
        ...row,
        payload: JSON.stringify({
          id: String(i),
          members: Array.from({ length: 8 }, (_, j) => ({
            id: String(j),
            name: "Fixture member",
            permissions: "member",
          })),
        }),
      });
      ctx.db.claimSupplyStatus.insert({
        ...row,
        payload: '{"supplies":1000,"upkeepPerHour":20}',
      });
    }
    bitcraft.prepareClaimRevisions(ctx, {});
  });
  describe(`BitCraft ${count} synthetic claims / nine regions`, () => {
    bench(
      "claim_changes",
      () => {
        bitcraft.claimChanges(test.anonymousViewContext());
      },
      options,
    );
  });
}

for (const count of [100, 1000, 5000]) {
  const test = createModuleTestHarness(evergatherDb, evergather, {
    clock: new TestClock(new Timestamp(at)),
  });
  test.withReducerTx(owner, (ctx) => {
    evergather.init(ctx, {});
    for (let i = 0; i < count; i++)
      ctx.db.listing.insert({
        id: 0n,
        seller: Identity.fromString(
          ((i % 100) + 1).toString(16).padStart(64, "0"),
        ),
        sellerName: "Fixture seller",
        itemKey: "fishing_1",
        name: "Minnow",
        rarity: "common",
        quantity: 1n,
        unitPrice: 12n,
        toolId: 0n,
        toolSnapshot: "",
        createdAt: new Timestamp(at + BigInt(i)),
      });
    evergather.prepareReadModels(ctx, {});
  });
  describe(`Evergather ${count} synthetic listings / current catalog`, () => {
    bench(
      "indexed first market page",
      () => {
        evergather.readMarketListings(test.procedureContext(owner), {
          page: 1,
          after: "",
          revision: "",
        });
      },
      options,
    );
    bench(
      "inventory directory",
      () => {
        evergather.inventoryDirectory(test.anonymousViewContext());
      },
      options,
    );
    bench(
      "market_page_listings (all materialized rows)",
      () => {
        evergather.marketPageListings(test.anonymousViewContext());
      },
      options,
    );
    bench(
      "market_summary",
      () => {
        evergather.marketSummary(test.anonymousViewContext());
      },
      options,
    );
    bench(
      "core_definitions",
      () => {
        evergather.coreDefinitions(test.anonymousViewContext());
      },
      options,
    );
    bench(
      "inventory_guide_definitions",
      () => {
        evergather.inventoryGuideDefinitions(test.anonymousViewContext());
      },
      options,
    );
  });
}
