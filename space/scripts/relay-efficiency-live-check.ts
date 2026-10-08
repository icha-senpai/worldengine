import assert from "node:assert/strict";
import { DbConnection } from "../src/bindings/bitcraft";
import { ownerToken } from "./bitcraft-storage";
const database = "space-bitcraft-tools";
const conn = await new Promise<DbConnection>((done, reject) =>
  DbConnection.builder()
    .withUri("ws://127.0.0.1:3100")
    .withDatabaseName(database)
    .onConnect((c) => done(c))
    .onConnectError((_ctx, error) => reject(error))
    .build(),
);
try {
  const market = JSON.parse(
    await conn.procedures.readMarket({ filters: '{"hasOrders":true}' }),
  );
  const barter = JSON.parse(await conn.procedures.readRelayBarter({}));
  assert.equal(barter.regions.length, 9);
  assert.equal(barter.coverage.filter((row: any) => row.ready).length, 9);
  assert.ok(market.items.length > 1000);
  assert.ok(barter.stalls.length > 1000);
  const item = market.items.find((row: any) => row.sellOrderCount > 0);
  const book = JSON.parse(
    await conn.procedures.readMarketBook({
      kind: item.kind,
      id: String(item.id),
    }),
  ).book;
  assert.ok(book.sellOrders.length > 0);
  assert.ok(book.sellOrders.some((row: any) => row.claimName));
  let targetedRefresh = "not requested";
  if (process.argv.includes("--refresh")) {
    const prior = JSON.parse(await conn.procedures.readMarketBook({ kind: item.kind, id: String(item.id) }));
    await conn.reducers.requestMarketRefresh({ query: "", itemId: String(item.id), kind: item.kind });
    const deadline = Date.now() + 45000;
    let fresh = prior;
    do {
      await new Promise(done => setTimeout(done, 500));
      fresh = JSON.parse(await conn.procedures.readMarketBook({ kind: item.kind, id: String(item.id) }));
      if (!fresh.pending && (BigInt(fresh.observedAt) > BigInt(prior.observedAt) || Number(prior.observedAt) / 1000 > Date.now() - 30000)) break;
    } while (Date.now() < deadline);
    assert.ok(!fresh.pending && (BigInt(fresh.observedAt) > BigInt(prior.observedAt) || Number(prior.observedAt) / 1000 > Date.now() - 30000), "Targeted item refresh must complete or already be recent.");
    assert.ok(fresh.book.sellOrders.length > 0);
    targetedRefresh = "passed";
  }
  const regional = JSON.parse(
    await conn.procedures.readMarket({
      filters: '{"regionId":"8","hasOrders":true}',
    }),
  );
  assert.ok(regional.items.length > 100);
  const response = await fetch(
    `http://127.0.0.1:3100/v1/database/${database}/sql`,
    {
      method: "POST",
      headers: {
        Authorization: `Bearer ${await ownerToken()}`,
        "Content-Type": "text/plain",
      },
      body: "SELECT resource, observed_at FROM collection_feed",
    },
  );
  assert.ok(response.ok);
  const feeds = (await response.json())[0].rows;
  const xp = feeds.find((row: any[]) => row[0] === "relaySkills");
  const xpAgeSeconds = xp ? (Date.now() - Number(xp[1]) / 1000) / 1000 : null;
  console.log(
    JSON.stringify({
      passed: true,
      readyRegions: 9,
      worldMarketItemsWithOrders: market.items.length,
      regionalItemsWithOrders: regional.items.length,
      barterStalls: barter.stalls.length,
      barterOrders: barter.stalls.reduce(
        (n: number, row: any) => n + row.orders.length,
        0,
      ),
      xpFeedAgeSeconds: xpAgeSeconds,
      targetedRefresh,
    }),
  );
} finally {
  conn.disconnect();
}
