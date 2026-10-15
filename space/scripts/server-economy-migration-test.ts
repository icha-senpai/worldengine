import { runTemporaryDatabaseTest } from "./temporary-database";
import { execFileSync } from "node:child_process";
import { cpSync, mkdirSync, writeFileSync } from "node:fs";
import { join, resolve } from "node:path";
import assert from "node:assert/strict";
const cli = `${process.env.LOCALAPPDATA}/SpacetimeDB/bin/current/spacetimedb-cli.exe`;
const server = "http://127.0.0.1:3100",
  database = `space-evergather-migration-checks-${Date.now()}`;
await runTemporaryDatabaseTest(database, async (scope) => {
  const command = (args: string[]) =>
    execFileSync(cli, [...args, "--server", server, "--no-config"], {
      encoding: "utf8",
      windowsHide: true,
      maxBuffer: 64 * 1024 * 1024,
      stdio: "pipe",
    });
  const sql = (table: string) =>
    JSON.parse(
      command(["sql", database, `SELECT * FROM ${table}`, "--format", "json"]),
    )[0].rows as unknown[][];
  mkdirSync(".runtime", { recursive: true });
  const fixture = scope.fixture("legacy-economy-module-");
  // Git owns the legacy source; the test owns this temporary working fixture.
  const revision = "106b6142189d478579ab6db37fb4a880ffe7b56c";
  const files = execFileSync(
    "git",
    ["ls-tree", "-r", "--name-only", revision, "spacetimedb/src"],
    { encoding: "utf8", windowsHide: true },
  )
    .trim()
    .split(/\r?\n/);
  if (
    !files.length ||
    files.some(
      (file) => !file.startsWith("spacetimedb/src/") || file.includes(".."),
    )
  )
    throw Error("Legacy module source is unavailable in Git history.");
  for (const file of files) {
    const target = join(fixture, file.slice("spacetimedb/".length));
    mkdirSync(resolve(target, ".."), { recursive: true });
    writeFileSync(
      target,
      execFileSync("git", ["show", `${revision}:space/${file}`], {
        windowsHide: true,
        maxBuffer: 64 * 1024 * 1024,
      }),
    );
  }
  for (const file of ["package.json", "tsconfig.json"])
    cpSync(join("spacetimedb", file), join(fixture, file));
  command(["publish", database, "--module-path", fixture, "--yes"]);
  command(["call", database, "configure_local_play", "true"]);
  command(["call", database, "create_character", '"Migration Player"']);
  // One real action seeds inventory and XP without bypassing the legacy cooldown.
  command([
    "call",
    database,
    "perform_action",
    '"gathering_actions"',
    '"fish"',
  ]);
  command(["call", database, "list_item", '"river_minnow"', "1", "1"]);
  const beforePlayer = sql("player"),
    beforeSkills = sql("skill");
  assert.ok(sql("inventory").length > 0);
  assert.equal(sql("listing").length, 1);
  command(["publish", database, "--module-path", "spacetimedb", "--yes"]);
  assert.deepEqual(
    sql("player"),
    beforePlayer,
    "publishing preserves the character",
  );
  assert.deepEqual(sql("skill"), beforeSkills, "publishing preserves XP");
  assert.equal(
    sql("listing").length,
    1,
    "publishing does not reset the market",
  );
  command(["call", database, "refresh_content"]);
  assert.ok(
    sql("catalog").every((row) => !String(row[0]).includes("candlemark")),
    "refresh removes stale definitions",
  );
  command(["call", database, "reset_item_economy", '"ten-tier-v1"']);
  assert.deepEqual(
    sql("player"),
    beforePlayer.map((row) =>
      row.map((value, index) => (index === 7 ? 0 : value)),
    ),
    "replacement preserves character fields and gold while resetting the old action cooldown",
  );
  assert.deepEqual(
    sql("skill"),
    beforeSkills,
    "replacement preserves exact XP",
  );
  for (const table of ["inventory", "listing", "contract", "result", "trade"])
    assert.equal(sql(table).length, 0, `${table} is cleared`);
  const tools = sql("tool");
  assert.equal(tools.length, 38);
  assert.ok(tools.every((row) => String(row[2]).startsWith("starter_")));
  assert.throws(() =>
    command(["call", database, "reset_item_economy", '"ten-tier-v1"']),
  );
  console.log(
    "PASS: actual legacy module publication, schema migration, obsolete catalog reconciliation, explicit owner reset, legacy inventory/listing cleanup and exact character/XP/gold preservation.",
  );
});
