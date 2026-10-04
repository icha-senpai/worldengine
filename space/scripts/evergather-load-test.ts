import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdirSync, writeFileSync } from "node:fs";
import { DbConnection } from "../src/bindings/evergather";
import { marketListingsFor, marketPageInfo } from "../src/evergather/market";
import { readServerMetrics, summarizeServerMetrics } from "./load-metrics";

const option = (name: string, fallback: number) =>
  Number(process.argv[process.argv.indexOf(name) + 1]) || fallback;
const users = option("--users", 30);
const seconds = option("--seconds", 90);
const legacyMarket = process.argv.includes("--legacy-market");
const legacyCatalog = process.argv.includes("--legacy-catalog");
assert.ok(
  Number.isInteger(users) && users >= 3 && users <= 100,
  "Use 3–100 clients.",
);
assert.ok(seconds >= 10 && seconds <= 600, "Use a 10–600 second workload.");
// Every run gets a new database. This script never publishes over an existing
// game database, imports a user's identity or bypasses game rules.
const database = `space-evergather-loadtest-${Date.now()}`;
const server = "http://127.0.0.1:3100";
const cli = `${process.env.LOCALAPPDATA}/SpacetimeDB/bin/current/spacetimedb-cli.exe`;
function command(args: string[]) {
  return execFileSync(cli, [...args, "--server", server, "--no-config"], {
    stdio: "pipe",
    windowsHide: true,
    encoding: "utf8",
  });
}
console.log(
  `Publishing isolated fixture ${database}; ${users} clients, ${seconds}s workload.`,
);
const published = command([
  "publish",
  database,
  "--module-path",
  "spacetimedb",
  "--yes",
]);
const databaseIdentity = /identity:\s*([a-f0-9]{64})/.exec(published)?.[1];
assert.ok(databaseIdentity, "publisher returned the fixture database identity");
command(["call", database, "configure_local_play", "true"]);

const clients: DbConnection[] = [];
const publicPageSubscriptions = new Map<
  DbConnection,
  { unsubscribe(): void }
>();
const samples = new Map<string, number[]>();
let unexpectedFailures = 0;
let expectedRejections = 0;
let inFlight = 0;
let peakInFlight = 0;
const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));
async function eventually(check: () => boolean) {
  const deadline = Date.now() + 10000;
  while (!check()) {
    if (Date.now() > deadline)
      throw new Error("Committed state did not synchronize within 10s.");
    await sleep(10);
  }
}
async function measured<T>(name: string, action: () => Promise<T>): Promise<T> {
  const start = performance.now();
  peakInFlight = Math.max(peakInFlight, ++inFlight);
  try {
    const result = await action();
    const values = samples.get(name) ?? [];
    values.push(performance.now() - start);
    samples.set(name, values);
    return result;
  } catch (error) {
    unexpectedFailures++;
    throw error;
  } finally {
    inFlight--;
  }
}
async function connect() {
  return measured(
    "connect_and_initial_snapshot",
    () =>
      new Promise<DbConnection>((resolve, reject) => {
        const conn = DbConnection.builder()
          .withUri("ws://127.0.0.1:3100")
          .withDatabaseName(database)
          .onConnect((connection) =>
            connection
              .subscriptionBuilder()
              .onApplied(async () => {
                try {
                  if (!legacyMarket) await setPublicPage(connection, 1);
                  resolve(connection);
                } catch (error) {
                  reject(error);
                }
              })
              .onError(reject)
              .subscribe([
                "SELECT * FROM my_player",
                "SELECT * FROM my_inventory",
                "SELECT * FROM my_skills",
                "SELECT * FROM my_tools",
                "SELECT * FROM my_results",
                "SELECT * FROM my_achievements",
                ...(legacyCatalog
                  ? [
                      `SELECT * FROM my_catalog WHERE connection_key = '${connection.connectionId.toHexString()}'`,
                    ]
                  : [
                      "SELECT * FROM core_definitions",
                      "SELECT * FROM my_reference_catalog",
                    ]),
                ...(legacyMarket
                  ? [
                      `SELECT * FROM market_listings WHERE connection_key = '${connection.connectionId.toHexString()}'`,
                      `SELECT * FROM my_market_page WHERE connection_key = '${connection.connectionId.toHexString()}'`,
                    ]
                  : [
                      "SELECT * FROM my_market_listings",
                      "SELECT * FROM market_summary",
                    ]),
                "SELECT * FROM trade",
              ]),
          )
          .onConnectError((_ctx, error) => reject(error))
          .build();
        clients.push(conn);
      }),
  );
}
const player = (conn: DbConnection) => [...conn.db.myPlayer.iter()][0];
const marketRows = (conn: DbConnection) =>
  legacyMarket ? [...conn.db.marketListings.iter()] : marketListingsFor(conn);
const marketInfo = (conn: DbConnection, page = 1) =>
  legacyMarket
    ? [...conn.db.myMarketPage.iter()][0]
    : marketPageInfo(conn, page);
async function subscribePage(conn: DbConnection, page: number) {
  return await new Promise<{ unsubscribe(): void }>((resolve, reject) => {
    const subscription = conn
      .subscriptionBuilder()
      .onApplied(() => resolve(subscription))
      .onError(reject)
      .subscribe(`SELECT * FROM market_page_listings WHERE page = ${page}`);
  });
}
async function setPublicPage(conn: DbConnection, page: number) {
  const next = await subscribePage(conn, page);
  publicPageSubscriptions.get(conn)?.unsubscribe();
  publicPageSubscriptions.set(conn, next);
  await eventually(() =>
    [...conn.db.marketPageListings.iter()].every((row) => row.page === page),
  );
}
const gold = () =>
  clients.reduce((total, conn) => total + player(conn).gold, 0n);
function totals() {
  const result = new Map<string, bigint>();
  for (const conn of clients)
    for (const row of conn.db.myInventory.iter())
      result.set(row.itemKey, (result.get(row.itemKey) ?? 0n) + row.quantity);
  // Own escrow appears in every market page; count each listing only once.
  const listings = new Map(
    clients.flatMap((conn) =>
      marketRows(conn).map((row) => [row.id, row] as const),
    ),
  );
  for (const row of listings.values())
    if (!row.toolId)
      result.set(row.itemKey, (result.get(row.itemKey) ?? 0n) + row.quantity);
  return [...result.entries()].sort(([a], [b]) => a.localeCompare(b));
}
async function rejection(action: () => Promise<unknown>, pattern: RegExp) {
  await assert.rejects(action, pattern);
  expectedRejections++;
}

try {
  await Promise.all(Array.from({ length: users }, () => connect()));
  await Promise.all(
    clients.map(async (conn, index) => {
      await measured("create_character", () =>
        conn.reducers.createCharacter({ name: `Load player ${index + 1}` }),
      );
      assert.equal(
        [...conn.db.myPlayer.iter()].length,
        1,
        "only the caller's character is visible",
      );
      // Legitimate, already-unlocked level-one achievements fund the fixture.
      const rewardSkills = [
        "fishing",
        "mining",
        "foraging",
        "smelting",
        "milling",
        "tanning",
        "cutting",
        "weaving",
      ];
      const rewardsNeeded = Math.max(
        8,
        Math.ceil((Math.ceil(seconds / 5) + 50) / 15),
      );
      for (const row of conn.db.mySkills.iter())
        if (!rewardSkills.includes(row.skill)) rewardSkills.push(row.skill);
      for (const skill of rewardSkills.slice(0, rewardsNeeded))
        await measured("claim_reward", () =>
          conn.reducers.claimAchievement({ key: `skill_milestone_${skill}_1` }),
        );
      await measured("shop", () =>
        conn.reducers.buyShopOffer({ key: "bundle_ore_crate" }),
      );
      await measured("craft", () =>
        conn.reducers.craft({ key: "smelting_candlemark_ingot" }),
      );
      await measured("gather", () =>
        conn.reducers.performAction({ kind: "gathering_actions", key: "fish" }),
      );
      await measured("panel", () =>
        conn.reducers.setUiScope({
          workspace: "trade",
          panel: "marketplace",
          page: 1,
        }),
      );
      await measured("gather", () =>
        conn.reducers.performAction({ kind: "gathering_actions", key: "fish" }),
      );
      assert.equal(
        player(conn).nextActionAt,
        0n,
        "actions are immediately repeatable",
      );
    }),
  );
  console.log(
    `All ${clients.length} independent characters are connected; gathering, crafting and shop setup passed.`,
  );

  // More than one market page, with own listings preserved on every page.
  await Promise.all(
    clients.map(async (conn) => {
      for (let i = 0; i < 2; i++)
        await conn.reducers.listItem({
          itemKey: "river_minnow",
          quantity: 1,
          unitPrice: 12,
        });
    }),
  );
  await eventually(() =>
    clients.every((conn) => marketInfo(conn)?.total === users * 2),
  );
  assert.ok(
    clients.every((conn) => marketRows(conn).length <= 52),
    "market subscription is bounded to 50 public listings plus own escrow",
  );
  await clients[0].reducers.setUiScope({
    workspace: "trade",
    panel: "marketplace",
    page: 2,
  });
  const pages = Math.ceil((users * 2) / 50);
  if (!legacyMarket && pages > 1) {
    await setPublicPage(clients[0], 2);
    assert.ok(
      [...clients[0].db.marketPageListings.iter()].some(
        (row) => row.page === 2,
      ),
      "second public page arrived",
    );
    assert.ok(
      marketRows(clients[0]).length <= users * 2 - 50 + 2,
      "second page is bounded plus own escrow",
    );
  }
  assert.equal(marketInfo(clients[0], 2).page, Math.min(2, pages));
  assert.equal(
    marketRows(clients[0]).filter((row) =>
      row.seller.equals(clients[0].identity!),
    ).length,
    2,
  );
  await Promise.all(
    clients.map(async (conn) => {
      const own = marketRows(conn).filter((row) =>
        row.seller.equals(conn.identity!),
      );
      for (const row of own) await conn.reducers.cancelListing({ id: row.id });
    }),
  );
  await clients[0].reducers.setUiScope({
    workspace: "trade",
    panel: "marketplace",
    page: 1,
  });
  if (!legacyMarket) await setPublicPage(clients[0], 1);
  await eventually(() =>
    clients.every((conn) => marketInfo(conn)?.total === 0),
  );
  console.log(
    "Paged market: bounded snapshots, second page, own escrow and cancellation passed.",
  );

  const serverMetricsBefore = await readServerMetrics(server, databaseIdentity);
  const start = performance.now();
  const deadline = Date.now() + seconds * 1000;
  let rounds = 0,
    purchases = 0,
    gatherings = users * 2,
    crafts = users,
    repairs = 0;
  const quantity = (conn: DbConnection, key: string) =>
    [...conn.db.myInventory.iter()].find((row) => row.itemKey === key)
      ?.quantity ?? 0n;
  async function gather(conn: DbConnection, key: string) {
    await measured("gather", () =>
      conn.reducers.performAction({ kind: "gathering_actions", key }),
    );
    gatherings++;
  }
  while (Date.now() < deadline) {
    await Promise.all(
      clients.map(async (conn) => {
        const tool = [...conn.db.myTools.iter()].find(
          (row) => row.skill === "fishing" && row.equipped,
        )!;
        if (tool.durability <= 1) {
          const missing = tool.maxDurability - tool.durability;
          const boardsNeeded = Math.ceil(missing / 35);
          while (
            quantity(conn, "milling_candlemark_board") < BigInt(boardsNeeded)
          ) {
            while (quantity(conn, "ashwood_log") < 2n)
              await gather(conn, "chop");
            await measured("craft", () =>
              conn.reducers.craft({ key: "milling_candlemark_board" }),
            );
            crafts++;
          }
          const shortage = BigInt(missing * 2) - player(conn).gold;
          if (shortage > 0n) {
            assert.ok(
              quantity(conn, "river_minnow") >= shortage + 2n,
              "repair supplies leave enough fish to trade",
            );
            await measured("vendor", () =>
              conn.reducers.sellToVendor({
                itemKey: "river_minnow",
                quantity: Number(shortage),
              }),
            );
          }
          await measured("repair", () =>
            conn.reducers.repairTool({ id: tool.id }),
          );
          repairs++;
        }
        await gather(conn, "fish");
      }),
    );
    const goldBefore = gold();
    const inventoryBefore = totals();
    await Promise.all(
      clients.map((conn) =>
        measured("list", () =>
          conn.reducers.listItem({
            itemKey: "river_minnow",
            quantity: 1,
            unitPrice: 12,
          }),
        ),
      ),
    );
    await eventually(() =>
      clients.every((conn) =>
        marketRows(conn).some((row) => row.seller.equals(conn.identity!)),
      ),
    );
    const offers = clients.map((conn) =>
      marketRows(conn).find((row) => row.seller.equals(conn.identity!))!,
    );
    await Promise.all(
      clients.map((conn, index) =>
        measured("buy", () =>
          conn.reducers.buyListing({ id: offers[(index + 1) % users].id }),
        ),
      ),
    );
    purchases += users;
    // A reducer promise synchronizes its caller, not every other subscriber.
    // Wait for all market snapshots before combining private inventory and escrow.
    await eventually(
      () =>
        gold() === goldBefore - BigInt(users) &&
        clients.every((conn) => marketRows(conn).length === 0),
    );
    assert.deepEqual(
      totals(),
      inventoryBefore,
      "trading conserves every item including escrow",
    );
    await rejection(
      () => clients[0].reducers.buyListing({ id: offers[1].id }),
      /already sold/,
    );
    await Promise.all(
      clients.map(async (conn) => {
        await measured("list", () =>
          conn.reducers.listItem({
            itemKey: "river_minnow",
            quantity: 1,
            unitPrice: 12,
          }),
        );
        const listing = marketRows(conn).find((row) =>
          row.seller.equals(conn.identity!),
        )!;
        await measured("cancel", () =>
          conn.reducers.cancelListing({ id: listing.id }),
        );
      }),
    );
    await eventually(() =>
      clients.every((conn) => marketRows(conn).length === 0),
    );
    assert.deepEqual(
      totals(),
      inventoryBefore,
      "cancellation returns the complete escrow",
    );
    assert.equal(
      gold(),
      goldBefore - BigInt(users),
      "only the rounded 5% trade fees consume gold",
    );
    rounds++;
    if (rounds % 4 === 0)
      console.log(
        `Round ${rounds}: ${purchases} purchases; balances, item conservation and cancellations passed.`,
      );
    // Next round starts as soon as the transactions finish: no think-time delay.
  }

  // Both callers race for one listing. Exactly one wins, and a rejected buy
  // cannot consume money or duplicate the item.
  await clients[0].reducers.listItem({
    itemKey: "river_minnow",
    quantity: 1,
    unitPrice: 12,
  });
  const contested = marketRows(clients[0]).find((row) =>
    row.seller.equals(clients[0].identity!),
  )!;
  const goldBeforeRace = gold(),
    itemsBeforeRace = totals();
  const outcomes = await Promise.allSettled(
    [clients[1], clients[2]].map((conn) =>
      conn.reducers.buyListing({ id: contested.id }),
    ),
  );
  assert.equal(outcomes.filter((row) => row.status === "fulfilled").length, 1);
  const loser = outcomes.find(
    (row) => row.status === "rejected",
  ) as PromiseRejectedResult;
  assert.match(String(loser.reason), /already sold/);
  expectedRejections++;
  await eventually(
    () =>
      gold() === goldBeforeRace - 1n &&
      clients.every((conn) => marketRows(conn).length === 0),
  );
  assert.deepEqual(totals(), itemsBeforeRace);
  const workloadSeconds = Math.round((performance.now() - start) / 1000);
  const serverProfile = summarizeServerMetrics(
    serverMetricsBefore,
    await readServerMetrics(server, databaseIdentity),
  );
  const metrics = Object.fromEntries(
    [...samples].map(([action, values]) => {
      const sorted = [...values].sort((a, b) => a - b);
      const percentile = (p: number) =>
        Math.round(
          sorted[
            Math.min(sorted.length - 1, Math.ceil(sorted.length * p) - 1)
          ] * 100,
        ) / 100;
      return [
        action,
        {
          count: values.length,
          medianMs: percentile(0.5),
          p95Ms: percentile(0.95),
          p99Ms: percentile(0.99),
          maxMs: percentile(1),
        },
      ];
    }),
  );
  const report = {
    catalogMode: legacyCatalog
      ? "per-player scoped catalog"
      : "shared definitions and stable ownership references",
    database,
    users,
    workloadSeconds,
    marketMode: legacyMarket
      ? "legacy per-viewer pages"
      : "shared public pages and own escrow",
    serverProfile,
    rounds,
    purchases: purchases + 1,
    gatherings,
    crafts,
    repairs,
    peakInFlight,
    unexpectedFailures,
    expectedRejections,
    actionCooldowns: false,
    thinkTimeMs: 0,
    invariants:
      "PASS: ownership, item conservation, exact fees, cancellation, immediate repeat actions, stale purchases and competing buyers",
    metrics,
  };
  mkdirSync("output/performance", { recursive: true });
  const path = `output/performance/${database}.json`;
  writeFileSync(path, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
  console.log(`Report: ${path}. Fixture database retained for inspection.`);
} finally {
  for (const conn of clients) conn.disconnect();
}
