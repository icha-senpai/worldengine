import { readdir } from "node:fs/promises";
import {
  registerTemporaryDatabase,
  disposeStorageTest,
} from "./bitcraft-storage";
import { execFileSync } from "node:child_process";
import assert from "node:assert/strict";
import { DbConnection } from "../src/bindings/bitcraft";
const cli =
  process.env.LOCALAPPDATA + "/SpacetimeDB/bin/current/spacetimedb-cli.exe";
const server = "http://127.0.0.1:3100",
  database = `space-market-checks-${Date.now()}`;
const replicaBefore = new Set(await readdir(".runtime/data/replicas"));
execFileSync(
  cli,
  [
    "publish",
    database,
    "--module-path",
    "bitcraft",
    "--server",
    server,
    "--yes",
    "--no-config",
  ],
  { stdio: "inherit", windowsHide: true },
);
const storageBinding = await registerTemporaryDatabase(database, replicaBefore);
execFileSync(
  cli,
  ["call", database, "storage_lease", "300", "--server", server, "--no-config"],
  { stdio: "pipe", windowsHide: true },
);

async function connect() {
  return await new Promise<DbConnection>((resolve, reject) =>
    DbConnection.builder()
      .withUri("ws://127.0.0.1:3100")
      .withDatabaseName(database)
      .onConnect((c) => resolve(c))
      .onConnectError((_c, e) => reject(e))
      .build(),
  );
}
const collector = await connect(),
  reader = await connect();
try {
  const at = BigInt(Date.now() - 90000) * 1000n;
  const directory = {
    items: [
      { id: "7", name: "Plank", sellOrders: 1, buyOrders: 1, tag: "Wood" },
      {
        id: "85",
        name: "Cheap Plank",
        sellOrders: 1,
        buyOrders: 0,
        tag: "Wood",
      },
      {
        id: "7",
        itemType: 1,
        name: "Plank Package",
        sellOrders: 1,
        buyOrders: 0,
        tag: "Package",
      },
      { id: "9", name: "No Offers", sellOrders: 0, buyOrders: 0, tag: "Other" },
    ],
  };
  const ingest = {
    resource: "market",
    payload: JSON.stringify(directory),
    observedAt: at,
  };
  await assert.rejects(
    reader.reducers.ingestMarketDirectory(ingest),
    /authorization/,
  );
  await assert.rejects(
    reader.procedures.marketCollectionWork({}),
    /authorization/,
  );
  execFileSync(
    cli,
    [
      "call",
      database,
      "authorize_collector",
      JSON.stringify(collector.identity!.toHexString()),
      "true",
      "--server",
      server,
      "--no-config",
    ],
    { stdio: "pipe", windowsHide: true },
  );
  await collector.reducers.ingestMarketDirectory({
    resource: "regions",
    payload: JSON.stringify({
      regions: [
        { regionId: 1, regionName: "Solmere" },
        { regionId: 2, regionName: "Other" },
        { regionId: 3, regionName: "Southern Islands" },
        { regionId: 11, regionName: "Western Islands" },
        { regionId: 15, regionName: "Eastern Islands" },
        { regionId: 23, regionName: "Northern Islands" },
      ],
    }),
    observedAt: at,
  });
  await collector.reducers.ingestMarketDirectory(ingest);
  const read = async (filters: Record<string, unknown> = {}) =>
    JSON.parse(
      await reader.procedures.readMarket({ filters: JSON.stringify(filters) }),
    );
  const initial = await read({ hasOrders: true });
  assert.equal(
    initial.regions.length,
    2,
    "retired islands are absent from region choices",
  );
  assert.equal(initial.items.length, 3);
  assert.equal(initial.storage.loadedItems, 1);
  const beforeRepeat = await read();
  const emptyBefore = JSON.parse(
    await reader.procedures.readMarketBook({ kind: "item", id: "9" }),
  );
  await collector.reducers.ingestMarketDirectory({
    ...ingest,
    observedAt: at + 1000000n,
  });
  const afterRepeat = await read();
  assert.equal(
    JSON.parse(
      await reader.procedures.readMarketBook({ kind: "item", id: "9" }),
    ).observedAt,
    emptyBefore.observedAt,
    "Unchanged normalized empty books must not advance payload timestamps.",
  );
  assert.equal(
    afterRepeat.storage.indexAt,
    beforeRepeat.storage.indexAt,
    "Unchanged directory membership retains its generation",
  );
  assert.deepEqual(
    afterRepeat.items,
    beforeRepeat.items,
    "Directory polling must not rewrite unchanged item projections",
  );
  const full = {
    item: { id: "7", name: "Plank", tag: "Wood" },
    sellOrders: [
      ...[3, 11, 15, 23].map((regionId) => ({
        entityId: String(4000 + regionId),
        claimEntityId: String(4000 + regionId),
        claimName: "Retired island claim",
        regionId,
        priceThreshold: "0.01",
        quantity: "999999",
      })),
      ...Array.from({ length: 32 }, (_, index) => ({
        entityId: String(index + 100),
        claimEntityId: String(index + 10),
        claimName: index === 31 ? "Late Claim" : `Claim ${index}`,
        regionId: 1,
        priceThreshold: index === 31 ? "3" : "20",
        quantity: "2",
      })),
      {
        entityId: "999",
        claimEntityId: "999",
        claimName: "Other Region",
        regionId: 2,
        priceThreshold: "1",
        quantity: "100",
      },
    ],
    buyOrders: [
      {
        entityId: "1000",
        claimEntityId: "10",
        claimName: "Claim 0",
        regionId: 1,
        priceThreshold: "8",
        quantity: "5",
      },
    ],
  };
  await assert.rejects(
    reader.reducers.ingestMarketBook({
      kind: "item",
      id: "7",
      payload: JSON.stringify(full),
      observedAt: at,
    }),
    /authorization/,
  );
  await collector.reducers.ingestMarketBook({
    kind: "item",
    id: "7",
    payload: JSON.stringify(full),
    observedAt: at,
  });
  await collector.reducers.ingestMarketBook({
    kind: "item",
    id: "85",
    payload: JSON.stringify({
      item: { id: "85", name: "Cheap Plank" },
      sellOrders: [
        {
          entityId: "2000",
          claimName: "Cheap Claim",
          regionId: 2,
          quantity: 1,
          price: 0.5,
        },
      ],
      buyOrders: [],
    }),
    observedAt: at,
  });
  await collector.reducers.ingestMarketBook({
    kind: "cargo",
    id: "7",
    payload: JSON.stringify({
      item: { id: "7", name: "Plank Package" },
      sellOrders: [
        {
          entityId: "3000",
          claimName: "Package Claim",
          regionId: 1,
          quantity: 4,
          price: 12,
        },
      ],
      buyOrders: [],
    }),
    observedAt: at,
  });
  const global = await read({ hasOrders: true });
  assert.equal(
    global.items.find((row: any) => row.kind === "item" && row.id === "7")
      .lowestSellPrice,
    1,
    "retired island prices do not affect world rankings",
  );
  assert.equal(
    JSON.parse(
      await reader.procedures.readMarketBook({ kind: "item", id: "7" }),
    ).book.sellOrders.length,
    33,
    "retired orders are hidden from details",
  );
  assert.equal(global.storage.loadedItems, 4);
  assert.equal(global.items.length, 3);
  const region = await read({ region: "Solmere", hasSellOrders: true });
  assert.equal(region.items.length, 2);
  const plank = region.items.find(
    (row: any) => row.kind === "item" && row.id === "7",
  );
  assert.equal(plank.lowestSellPrice, 3);
  assert.equal(plank.sellOrderQuantity, 64);
  const empire = await read({
    empire: "Builders",
    empireEntityId: "42",
    claimIds: ["41"],
    region: "Solmere",
  });
  assert.equal(empire.items.length, 1);
  assert.equal(empire.items[0].sellOrderCount, 1);
  assert.equal(empire.items[0].lowestSellPrice, 3);
  assert.equal(
    (await read({ empireEntityId: "42", claimIds: [] })).items.length,
    0,
  );
  assert.equal(
    (await read({ region: "Solmere", hasOrders: false })).items.length,
    4,
    "all-item region browsing retains items with no local orders",
  );
  const late = await read({ region: "Solmere", claimQ: "Late" });
  assert.equal(late.items.length, 1);
  assert.equal(late.items[0].lowestSellPrice, 3);
  assert.equal(
    JSON.parse(
      await reader.procedures.readMarketBook({ kind: "item", id: "7" }),
    ).book.sellOrders.length,
    33,
  );
  await assert.rejects(
    reader.reducers.requestMarketRefresh({
      query: "",
      itemId: "",
      kind: "item",
    }),
    /Choose an item/,
  );
  await reader.reducers.requestMarketRefresh({
    query: "Plank",
    itemId: "",
    kind: "item",
  });
  const revision = (await read()).storage.revision;
  await reader.reducers.requestMarketRefresh({
    query: "Plank",
    itemId: "",
    kind: "item",
  });
  assert.equal(
    (await read()).storage.revision,
    revision,
    "duplicate searches share one refresh",
  );
  assert.equal((await read({ q: "Plank" })).storage.pending, 3);
  const work = JSON.parse(await collector.procedures.marketCollectionWork({}));
  assert.equal(work.length, 2);
  assert.ok(
    work.every((row: any) =>
      ["itemOrders", "cargoOrders"].includes(row.resource),
    ),
  );
  await collector.reducers.marketRefreshFailure({
    resource: "itemOrders",
    id: "7",
    retryAt: BigInt(Date.now() + 60000) * 1000n,
  });
  assert.equal(
    (await read({ region: "Solmere", itemId: "7", itemKind: "item" })).items[0]
      .lowestSellPrice,
    3,
    "failure preserves last good prices",
  );
  await assert.rejects(
    collector.reducers.ingestMarketBook({
      kind: "item",
      id: "7",
      payload: '{"item":{"id":"7"},"sellOrders":[]}',
      observedAt: at + 1n,
    }),
    /complete/,
  );
  await assert.rejects(
    collector.reducers.ingestMarketBook({
      kind: "item",
      id: "7",
      payload: JSON.stringify(full),
      observedAt: at - 1n,
    }),
    /out of order/,
  );
  await collector.reducers.ingestMarketBook({
    kind: "item",
    id: "7",
    payload: JSON.stringify({ item: full.item, sellOrders: [], buyOrders: [] }),
    observedAt: BigInt(Date.now()) * 1000n,
  });
  assert.equal(
    (
      await read({
        region: "Solmere",
        itemId: "7",
        itemKind: "item",
        hasOrders: true,
      })
    ).items.length,
    0,
    "successful complete empty refresh removes cancelled offers",
  );
  assert.equal(
    JSON.parse(
      await reader.procedures.readMarketBook({ kind: "item", id: "7" }),
    ).book.sellOrders.length,
    0,
  );
  const budget = execFileSync(
    cli,
    [
      "sql",
      database,
      "SELECT COUNT(*) AS count FROM provider_budget",
      "--server",
      server,
      "--no-config",
    ],
    { encoding: "utf8", windowsHide: true },
  );
  assert.match(
    budget,
    /\b0\b/,
    "stored browsing and search queuing make no upstream requests",
  );
  console.log(
    JSON.stringify({
      database,
      passed: true,
      completeWorldAndRegionReads: true,
      coalescedSearches: true,
      retainedFailures: true,
      reconciledCancellations: true,
      upstreamRequests: 0,
    }),
  );
} finally {
  collector.disconnect();
  reader.disconnect();
  await disposeStorageTest(storageBinding);
}
