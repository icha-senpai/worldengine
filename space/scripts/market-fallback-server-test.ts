import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readdir } from "node:fs/promises";
import { DbConnection } from "../src/bindings/bitcraft";
import { CURRENT_REGIONS } from "../bitcraft/src/relay-trading";
import { tradingJson } from "../bitcraft/src/trade-storage";
import {
  registerTemporaryDatabase,
  disposeStorageTest,
  ownerToken,
} from "./bitcraft-storage";
import { parseRelayJson } from "./bitcraft-relay";

// The existing sealed local artifact supplies the previous version. Only this
// disposable database is published/upgraded; the live dev database is untouched.
const cli =
  process.env.LOCALAPPDATA + "/SpacetimeDB/bin/current/spacetimedb-cli.exe";
const server = "http://127.0.0.1:3100";
const database = `space-market-checks-${Date.now()}`;
const before = new Set(await readdir(".runtime/data/replicas"));
const run = (args: string[]) =>
  execFileSync(cli, args, { encoding: "utf8", windowsHide: true });
const publish = (args: string[]) =>
  run([
    "publish",
    database,
    ...args,
    "--delete-data=never",
    "--server",
    server,
    "--no-config",
    "--yes",
  ]);
const owner = (name: string, ...args: string[]) =>
  run(["call", database, name, ...args, "--server", server, "--no-config"]);
const sql = async (query: string) => {
  const response = await fetch(`${server}/v1/database/${database}/sql`, {
    method: "POST",
    headers: { Authorization: `Bearer ${await ownerToken()}` },
    body: query,
  });
  assert.ok(response.ok, "Private fixture SQL must succeed");
  return parseRelayJson(await response.text()).map((result: any) => ({
    schema: result.schema,
    rows: result.rows.sort((a: unknown, b: unknown) =>
      tradingJson(a).localeCompare(tradingJson(b)),
    ),
  }));
};
let binding: Awaited<ReturnType<typeof registerTemporaryDatabase>> | undefined;
let collector: DbConnection | undefined, reader: DbConnection | undefined;
let created = false;
const connect = (token?: string) =>
  new Promise<DbConnection>((resolve, reject) =>
    DbConnection.builder()
      .withUri("ws://127.0.0.1:3100")
      .withDatabaseName(database)
      .withToken(token)
      .onConnect((c) => resolve(c))
      .onConnectError((_c, e) => reject(e))
      .build(),
  );
try {
  assert.equal(
    (await fetch(`${server}/v1/database/${database}`)).status,
    404,
    "Proof database name must be unused",
  );
  created = true;
  publish([
    "--js-path",
    ".runtime/bitcraft-storage/space-bitcraft-tools.bundle.js",
  ]);
  binding = await registerTemporaryDatabase(database, before);
  owner("storage_lease", "300");
  collector = await connect();
  reader = await connect();
  const collectorToken = collector.token,
    readerToken = reader.token;
  owner(
    "authorize_collector",
    JSON.stringify(collector.identity!.toHexString()),
    "true",
  );
  const item = {
    id: "7",
    kind: "item",
    name: "Plank",
    sellOrders: 1,
    buyOrders: 0,
  };
  const at = BigInt(Date.now() - 90000) * 1000n;
  await collector.reducers.ingestMarketDirectory({
    resource: "regions",
    payload: JSON.stringify({
      regions: Object.entries(CURRENT_REGIONS).map(([id, name]) => ({
        id: Number(id),
        name,
      })),
    }),
    observedAt: at,
  });
  await collector.reducers.ingestMarketDirectory({
    resource: "market",
    payload: JSON.stringify({ items: [item] }),
    observedAt: at,
  });
  const heartbeat = async (regionId: number, ready = true) =>
    collector!.reducers.relayHeartbeat({
      key: `trading:${regionId}`,
      provider: "bitconnect",
      regionId,
      epoch: `test-${regionId}`,
      ready,
      rows: 1,
      error: ready ? "" : "Relay unavailable",
    });
  const book = (regionId: number, id: string, price: number) => ({
    item,
    sellOrders: [
      {
        entityId: id,
        claimEntityId: String(regionId),
        claimName: `Claim ${regionId}`,
        regionId,
        priceThreshold: price,
        quantity: 10,
      },
    ],
    buyOrders: [],
  });
  const native = async (
    regionId: number,
    books: Record<string, unknown>,
    observedAt = BigInt(Date.now()) * 1000n,
  ) => {
    await heartbeat(regionId);
    await collector!.reducers.ingestRelayTrading({
      regionId,
      epoch: `test-${regionId}`,
      payload: JSON.stringify({ books, stalls: [] }),
      observedAt,
    });
  };
  for (const regionId of Object.keys(CURRENT_REGIONS).map(Number))
    await native(
      regionId,
      regionId === 7 || regionId === 8
        ? {
            "item:7": book(
              regionId,
              String(regionId * 100),
              regionId === 7 ? 1 : 3,
            ),
          }
        : {},
    );
  await reader.reducers.saveWidget({
    kind: "tasks",
    token: "market-fallback-widget-proof",
    settings: JSON.stringify({
      source: "default",
      title: "Keep me",
      tasks: [],
    }),
  });
  const tables = [
    "market_item",
    "market_meta",
    "market_summary",
    "market_status",
    "relay_region",
    "relay_book",
    "relay_status",
    "trade_record",
    "trade_reference",
    "widget_profile",
    "collector_identity",
    "storage_state",
  ];
  const snapshot = async () =>
    tradingJson(
      Object.fromEntries(
        await Promise.all(
          tables.map(async (table) => [
            table,
            await sql(`SELECT * FROM ${table}`),
          ]),
        ),
      ),
    );
  const retained = await snapshot();
  const identity = (
    (await (await fetch(`${server}/v1/database/${database}`)).json()) as any
  ).database_identity;
  collector.disconnect();
  reader.disconnect();
  publish(["--module-path", "bitcraft"]);
  assert.equal(
    await snapshot(),
    retained,
    "Publication preserves every populated market/widget/ownership field",
  );
  assert.deepEqual(
    ((await (await fetch(`${server}/v1/database/${database}`)).json()) as any)
      .database_identity,
    identity,
  );
  collector = await connect(collectorToken);
  reader = await connect(readerToken);
  const readBook = async () =>
    JSON.parse(
      await reader!.procedures.readMarketBook({ kind: "item", id: "7" }),
    );
  const read = async (filters = {}) =>
    JSON.parse(
      await reader!.procedures.readMarket({ filters: JSON.stringify(filters) }),
    );
  const search = async () =>
    reader!.reducers.requestMarketRefresh({
      query: "Plank",
      itemId: "",
      kind: "item",
    });
  await search();
  assert.equal(
    (await read()).storage.pending,
    0,
    "Healthy relays need no API fallback",
  );
  await heartbeat(7, false);
  assert.equal(
    (
      JSON.parse(await collector.procedures.marketCollectionWork({})) as any[]
    ).filter((r) => r.kind).length,
    0,
    "An outage alone does not start a full order-book crawl",
  );
  await search();
  await search();
  assert.equal(
    (await read()).storage.pending,
    1,
    "Repeated item searches coalesce",
  );
  await native(8, { "item:7": book(8, "800", 4) });
  assert.equal(
    (await read()).storage.pending,
    1,
    "An unrelated live region cannot consume the fallback request",
  );
  const work = JSON.parse(await collector.procedures.marketCollectionWork({}));
  assert.deepEqual(
    work.filter((r: any) => r.kind).map((r: any) => [r.resource, r.id]),
    [["itemOrders", "7"]],
  );
  const api = {
    item,
    sellOrders: [
      book(7, "701", 2).sellOrders[0],
      book(8, "801", 0.1).sellOrders[0],
    ],
    buyOrders: [],
  };
  const ingestApi = (payload: unknown) =>
    collector!.reducers.ingestMarketBook({
      kind: "item",
      id: "7",
      payload: JSON.stringify(payload),
      observedAt: BigInt(Date.now()) * 1000n,
    });
  await ingestApi(api);
  const apiRecords = (
    await sql("SELECT * FROM trade_record WHERE scope = 'api:item:7'")
  )[0].rows;
  assert.equal(
    apiRecords.length,
    1,
    "Fallback storage does not duplicate healthy relay orders",
  );
  let result = await readBook();
  assert.deepEqual(
    result.book.sellOrders.map((r: any) => r.entityId).sort(),
    ["701", "800"],
    "API fills only the unavailable region; healthy relay wins",
  );
  assert.equal((await read({ regionId: 7 })).items[0].lowestSellPrice, 2);
  assert.equal(
    (await read({ claimEntityId: "7" })).items[0].lowestSellPrice,
    2,
  );
  await native(8, { "item:7": book(8, "800", 5) });
  await collector.reducers.ingestMarketDirectory({
    resource: "market",
    payload: JSON.stringify({ items: [item] }),
    observedAt: BigInt(Date.now()) * 1000n,
  });
  assert.ok(
    (await readBook()).book.sellOrders.some((r: any) => r.entityId === "701"),
    "Native/directory updates retain fallback orders even with nine historical regions",
  );
  await search();
  assert.equal(
    (await read()).storage.pending,
    0,
    "A fresh shared API fallback is not refetched on each search",
  );
  await collector.reducers.marketRefreshFailure({
    resource: "itemOrders",
    id: "7",
    retryAt: BigInt(Date.now() + 60000) * 1000n,
  });
  assert.ok(
    (await readBook()).book.sellOrders.some((r: any) => r.entityId === "701"),
    "API failure retains the last successful fallback",
  );
  await ingestApi({ item, sellOrders: [], buyOrders: [] });
  assert.deepEqual(
    (await readBook()).book.sellOrders.map((r: any) => r.entityId),
    ["800"],
    "Empty API response removes stale failed-region orders",
  );
  await native(8, {});
  await collector.reducers.ingestMarketDirectory({
    resource: "market",
    payload: JSON.stringify({ items: [{ ...item, sellOrders: 0 }] }),
    observedAt: BigInt(Date.now()) * 1000n,
  });
  await native(8, { "item:7": book(8, "800", 5) });
  assert.deepEqual(
    (await readBook()).book.sellOrders.map((r: any) => r.entityId),
    ["800"],
    "An empty directory refresh cannot resurrect old failed-region orders",
  );
  await ingestApi(api);
  await native(7, {});
  result = await readBook();
  assert.deepEqual(
    result.book.sellOrders.map((r: any) => r.entityId),
    ["800"],
    "Recovered empty relay cancels API offers",
  );
  assert.equal(result.book.apiFallbackRegions, undefined);
  await heartbeat(7, false);
  assert.deepEqual(
    (await readBook()).book.sellOrders.map((r: any) => r.entityId),
    ["800"],
    "Later outage cannot resurrect cancelled API data",
  );
  // Broad text is rejected before any requests are marked pending.
  await collector.reducers.ingestMarketDirectory({
    resource: "market",
    payload: JSON.stringify({
      items: Array.from({ length: 51 }, (_, i) => ({
        ...item,
        id: String(i + 10),
        name: `Broad ${i}`,
      })),
    }),
    observedAt: BigInt(Date.now()) * 1000n,
  });
  await assert.rejects(
    reader.reducers.requestMarketRefresh({
      query: "Broad",
      itemId: "",
      kind: "item",
    }),
    /Narrow your item search/,
  );
  assert.equal((await read()).storage.pending, 0);
  console.log(
    "PASS: populated upgrade preservation, targeted/coalesced fallback, healthy authority, empty results, recovery, broad-query limits; no upstream HTTP requests",
  );
} finally {
  collector?.disconnect();
  reader?.disconnect();
  if (
    created &&
    (await fetch(`${server}/v1/database/${database}`)).status !== 404
  ) {
    binding ??= await registerTemporaryDatabase(database, before);
    await disposeStorageTest(binding);
  }
}
