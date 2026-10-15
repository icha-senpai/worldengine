import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { cpSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { createInterface } from "node:readline";
import { DbConnection } from "../src/bindings/evergather";
import { canonicalState, ownerToken, ownerCall } from "./bitcraft-storage";
import { parseRelayJson } from "./bitcraft-relay";
import { runTemporaryDatabaseTest } from "./temporary-database";

const server = "http://127.0.0.1:3100";
const cli = `${process.env.LOCALAPPDATA}/SpacetimeDB/bin/current/spacetimedb-cli.exe`;
const database = `space-evergather-migration-checks-${Date.now()}`;
const retained = [
  "security",
  "player",
  "skill",
  "inventory",
  "tool",
  "result",
  "contract",
  "listing",
  "achievement",
  "leaderboard",
  "leaderboard_excluded",
  "trade",
  "ui_scope",
  "ui_session",
  "catalog_reference",
  "catalog",
  "economy_state",
  "buff",
];
const command = (args: string[]) =>
  execFileSync(cli, [...args, "--server", server, "--no-config"], {
    encoding: "utf8",
    windowsHide: true,
    maxBuffer: 64 * 1024 * 1024,
  });
async function sql(query: string) {
  const response = await fetch(`${server}/v1/database/${database}/sql`, {
    method: "POST",
    headers: { Authorization: `Bearer ${await ownerToken()}` },
    body: query,
  });
  if (!response.ok)
    throw Error(
      `SQL proof failed: ${response.status} ${await response.text()}`,
    );
  return parseRelayJson(await response.text())[0].rows;
}
async function snapshot() {
  return Object.fromEntries(
    await Promise.all(
      retained.map(async (table) => [
        table,
        await sql(`SELECT * FROM ${table}`),
      ]),
    ),
  );
}
await runTemporaryDatabaseTest(database, async (scope) => {
  const fixture = scope.fixture("economy-checks-module-");
  const previous = "06591f2e1b4464cb70d058d644d4499dbec65749";
  const files = execFileSync(
    "git",
    ["ls-tree", "-r", "--name-only", previous, "spacetimedb/src"],
    { encoding: "utf8" },
  )
    .trim()
    .split(/\r?\n/);
  assert.ok(
    files.length &&
      files.every(
        (file) => file.startsWith("spacetimedb/src/") && !file.includes(".."),
      ),
  );
  for (const file of files) {
    const target = join(fixture, file.slice("spacetimedb/".length));
    mkdirSync(join(target, ".."), { recursive: true });
    writeFileSync(
      target,
      execFileSync("git", ["show", `${previous}:space/${file}`], {
        maxBuffer: 64 * 1024 * 1024,
      }),
    );
  }
  for (const file of ["package.json", "tsconfig.json"])
    cpSync(join("spacetimedb", file), join(fixture, file));
  const index = join(fixture, "src/index.ts");
  writeFileSync(
    index,
    readFileSync(index, "utf8") +
      `
import { Timestamp as ProofTimestamp } from "spacetimedb";
export const seedReadProof = db.reducer((ctx) => {
  if (!ctx.db.security.id.find(1)!.owner.equals(ctx.sender)) throw new SenderError("Only owner");
  for (let i=0; i<5000; i++) ctx.db.listing.insert({ id:0n, seller:ctx.sender, sellerName:"Read fixture", itemKey:"fishing_1", name:"Minnow", rarity:"common", quantity:1n, unitPrice:12n, createdAt:new ProofTimestamp(ctx.timestamp.microsSinceUnixEpoch + BigInt(Math.floor(i/3))), toolId:0n, toolSnapshot:"" });
});
`,
  );
  command([
    "publish",
    database,
    "--module-path",
    fixture,
    "--delete-data=never",
    "--yes",
  ]);
  await ownerCall(database, "configure_local_play", [true]);
  await ownerCall(database, "create_character", ["Preserved fixture"]);
  await ownerCall(database, "seed_read_proof", []);
  const expectedAll = (await sql("SELECT * FROM listing"))
    .map((row: any[]) => String(row[0]))
    .sort((a: string, b: string) => (BigInt(a) > BigInt(b) ? -1 : 1));
  const before = canonicalState(await snapshot());
  const info = (await (
    await fetch(`${server}/v1/database/${database}`)
  ).json()) as any;
  let conn: DbConnection | undefined;
  try {
    conn = await new Promise<DbConnection>((resolve, reject) =>
      DbConnection.builder()
        .withUri("ws://127.0.0.1:3100")
        .withDatabaseName(database)
        .onConnect((c) => resolve(c))
        .onConnectError((_c, e) => reject(e))
        .build(),
    );
    const legacyStart = performance.now();
    const legacy = conn
      .subscriptionBuilder()
      .subscribe("SELECT * FROM market_page_listings WHERE page = 1");
    await new Promise<void>((resolve, reject) => {
      const deadline = Date.now() + 15000;
      const check = () => {
        if ([...conn!.db.marketPageListings.iter()].length === 50) resolve();
        else if (Date.now() > deadline)
          reject(Error("Legacy snapshot timed out"));
        else setTimeout(check, 5);
      };
      check();
    });
    const legacyMs = performance.now() - legacyStart;
    const expected = [...conn.db.marketPageListings.iter()]
      .sort((a, b) => (a.id > b.id ? -1 : 1))
      .map((row) => String(row.id));
    legacy.unsubscribe();
    conn.disconnect();
    conn = undefined;
    command([
      "publish",
      database,
      "--js-path",
      "spacetimedb/dist/bundle.js",
      "--delete-data=never",
      "--yes",
    ]);
    await ownerCall(database, "prepare_read_models", []);
    await ownerCall(database, "prepare_read_models", []);
    assert.equal(
      canonicalState(await snapshot()),
      before,
      "All original private and public fields preserved",
    );
    assert.equal(
      ((await (await fetch(`${server}/v1/database/${database}`)).json()) as any)
        .database_identity.__identity__,
      info.database_identity.__identity__,
    );
    assert.equal((await sql("SELECT * FROM market_order")).length, 5000);
    conn = await new Promise<DbConnection>((resolve, reject) =>
      DbConnection.builder()
        .withUri("ws://127.0.0.1:3100")
        .withDatabaseName(database)
        .onConnect((c) => resolve(c))
        .onConnectError((_c, e) => reject(e))
        .build(),
    );
    const read = async (page: number, after = "", revision = "") =>
      JSON.parse(
        await conn!.procedures.readMarketListings({ page, after, revision }),
      );
    const timings: number[] = [];
    for (let i = 0; i < 20; i++) {
      const start = performance.now();
      const page = await read(1);
      timings.push(performance.now() - start);
      assert.equal(page.rows.length, 50);
      assert.deepEqual(
        page.rows.map((row: any) => row.id),
        expected,
      );
    }
    const ids: string[] = [];
    let after = "",
      revision = "";
    for (let page = 1; page <= 100; page++) {
      const result = await read(page, after, revision);
      assert.equal(result.total, 5000);
      assert.equal(result.rows.length, 50);
      ids.push(...result.rows.map((row: any) => row.id));
      after = result.next;
      revision = result.revision;
    }
    assert.equal(new Set(ids).size, 5000);
    assert.deepEqual(ids, expectedAll);
    assert.equal((await read(101)).page, 100);
    assert.deepEqual(
      (
        await read(2, "00000000000000000000:00000000000000000000", "stale")
      ).rows.map((row: any) => row.id),
      ids.slice(50, 100),
    );
    const details = JSON.parse(
      await conn.procedures.readInventoryDetails({ items: '["smelting_1"]' }),
    );
    assert.ok(
      details.some((row: any) => row.key === "item_metadata:mining_1:common"),
    );
    timings.sort((a, b) => a - b);
    console.log(
      JSON.stringify({
        database,
        listings: 5000,
        retainedTables: retained.length,
        legacySnapshotMs: legacyMs,
        indexedReadMedianMs: timings[10],
        indexedReadP95Ms: timings[18],
        pagesVerified: 100,
        detailsRows: details.length,
      }),
    );
    if (process.argv.includes("--browser")) {
      console.log(
        `BROWSER_DATABASE=${database}; enter done after UI verification (15 minute maximum).`,
      );
      const input = createInterface({
        input: process.stdin,
        output: process.stdout,
      });
      await new Promise<void>((resolve, reject) => {
        const timer = setTimeout(() => {
          input.close();
          reject(Error("Browser proof timed out"));
        }, 900000);
        input.once("line", () => {
          clearTimeout(timer);
          input.close();
          resolve();
        });
      });
    }
    console.log(
      "PASS: populated host paging, exact old-field preservation, stable identity, replay-safe activation, selected nested inventory routes.",
    );
  } finally {
    conn?.disconnect();
  }
});
