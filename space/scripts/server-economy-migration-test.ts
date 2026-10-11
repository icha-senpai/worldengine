import { execFileSync } from "node:child_process";
import {
  cpSync,
  mkdirSync,
  mkdtempSync,
  readdirSync,
  existsSync,
  readFileSync,
} from "node:fs";
import { join, resolve } from "node:path";
import assert from "node:assert/strict";
const cli = `${process.env.LOCALAPPDATA}/SpacetimeDB/bin/current/spacetimedb-cli.exe`;
const server = "http://127.0.0.1:3100",
  database = `space-evergather-migration-checks-${Date.now()}`;
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
const base = resolve(".runtime/backups");
const legacy = readdirSync(base)
  .map((name) => join(base, name, "module-src"))
  .find(
    (path) =>
      existsSync(join(path, "content/evergather.json")) &&
      !JSON.parse(readFileSync(join(path, "content/evergather.json"), "utf8"))
        .economy_version,
  );
if (!legacy)
  throw Error(
    "A pre-replacement Evergather backup is required for the migration check.",
  );
mkdirSync(".runtime", { recursive: true });
const fixture = mkdtempSync(join(".runtime", "legacy-economy-module-"));
cpSync(legacy, join(fixture, "src"), { recursive: true });
for (const file of ["package.json", "tsconfig.json"])
  cpSync(join("spacetimedb", file), join(fixture, file));
command(["publish", database, "--module-path", fixture, "--yes"]);
command(["call", database, "configure_local_play", "true"]);
command(["call", database, "create_character", '"Migration Player"']);
for (let count = 0; count < 5; count++)
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
assert.equal(sql("listing").length, 1, "publishing does not reset the market");
command(["call", database, "refresh_content"]);
assert.ok(
  sql("catalog").every((row) => !String(row[0]).includes("candlemark")),
  "refresh removes stale definitions",
);
command(["call", database, "reset_item_economy", '"ten-tier-v1"']);
assert.deepEqual(
  sql("player"),
  beforePlayer,
  "replacement preserves all character fields and gold",
);
assert.deepEqual(sql("skill"), beforeSkills, "replacement preserves exact XP");
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
