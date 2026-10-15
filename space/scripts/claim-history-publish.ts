// One-time additive activation with in-memory field-by-field preservation checks.
// Uses existing managed worker lifecycle and sealed module registration. No
// deployment backup is retained after success; failed checks keep recovery data.
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { existsSync } from "node:fs";
import { writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { createHash } from "node:crypto";
import {
  ownerCall,
  readBinding,
  sealPublishedModule,
  exportState,
  canonicalState,
} from "./bitcraft-storage";
import { marketCheckpointTables } from "../bitcraft/src/market-checkpoint";

const cli = resolve(
  process.env.LOCALAPPDATA!,
  "SpacetimeDB/bin/current/spacetimedb-cli.exe",
);
const server = "http://127.0.0.1:3100";
const historyTables = new Set([
  "claim_supply_status",
  "claim_history_state",
  "claim_history_event",
  "claim_history_region",
]);
const targets = [
  { target: "Test", database: "space-bitcraft-checks", pid: "collector-test" },
  { target: "Main", database: "space-bitcraft-tools", pid: "collector" },
];
const states = new Map<string, any>();
const resume = targets.filter((t) =>
  existsSync(resolve(`.runtime/services/${t.pid}.pid`)),
);
const workers = (action: string, target: string) =>
  execFileSync(
    "powershell.exe",
    [
      "-NoProfile",
      "-ExecutionPolicy",
      "Bypass",
      "-File",
      resolve("scripts/collector-services.ps1"),
      "-Action",
      action,
      "-Target",
      target,
    ],
    { stdio: "inherit", windowsHide: true },
  );
async function beforeState(database: string) {
  const schema = (await fetch(
    `${server}/v1/database/${database}/schema?version=9`,
  ).then((r) => {
    if (!r.ok) throw Error("Cannot read live schema");
    return r.json();
  })) as any;
  const available = new Set(schema.tables.map((t: any) => t.name));
  return exportState(
    database,
    Object.keys(marketCheckpointTables).filter((t) => available.has(t)),
  );
}
const digest = (state: any) =>
  createHash("sha256").update(canonicalState(state)).digest("hex");
let verified = false;
try {
  for (const t of targets) await readBinding(t.database);
  for (const t of resume) workers("Stop", t.target);
  for (const t of targets) {
    await ownerCall(t.database, "storage_lease", [0]);
    states.set(t.database, await beforeState(t.database));
  }
  for (const t of targets) {
    execFileSync(
      cli,
      [
        "publish",
        t.database,
        "--js-path",
        "bitcraft/dist/bundle.js",
        "--server",
        server,
        "--delete-data=never",
        "--yes",
        "--no-config",
      ],
      { stdio: "inherit", windowsHide: true },
    );
    const after = await exportState(t.database),
      before = states.get(t.database);
    for (const table of historyTables)
      if (!(table in before)) {
        assert.deepEqual(after[table], []);
        delete after[table];
      }
    assert.equal(
      digest(after),
      digest(before),
      `${t.database}: retained fields changed unexpectedly`,
    );
    await sealPublishedModule(t.database);
    const binding = await readBinding(t.database);
    console.log(
      `${t.database}: additive migration verified; identity ${binding.identity}; retained-state SHA-256 ${digest(before)}.`,
    );
  }
  verified = true;
} catch (error) {
  for (const [database, state] of states) {
    const path = resolve(
        `.runtime/bitcraft-storage/${database}-${Date.now()}.json`,
      ),
      payload = JSON.stringify(state);
    await writeFile(path, payload, { mode: 0o600 });
    await writeFile(
      path + ".sha256",
      createHash("sha256").update(payload).digest("hex"),
      { mode: 0o600 },
    );
    console.error(
      `Preservation failed: collection remains stopped; private recovery state saved to ${path}.`,
    );
  }
  throw error;
} finally {
  if (verified) for (const t of resume) workers("Start", t.target);
}
