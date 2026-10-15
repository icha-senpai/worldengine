// Additive BitCraft-only publication with in-memory preservation comparison.
// Use the managed worker lifecycle and current maintenance sealing. Successful
// runs retain no routine deployment export or previous module bundle.
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
const added = ["boss_defeat", "boss_score", "boss_totals", "boss_region"];
const targets = [
  { target: "Test", database: "space-bitcraft-checks", pid: "collector-test" },
  { target: "Main", database: "space-bitcraft-tools", pid: "collector" },
];
const states = new Map<string, any>();
const resume = targets.filter((t) =>
  existsSync(resolve(`.runtime/services/${t.pid}.pid`)),
);
function workers(action: string, target: string) {
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
}
let verified = false;
try {
  for (const target of targets) await readBinding(target.database);
  for (const target of resume) workers("Stop", target.target);
  for (const target of targets) {
    await ownerCall(target.database, "storage_lease", [0]);
    const schema = (await fetch(
      `${server}/v1/database/${target.database}/schema?version=9`,
    ).then((r) => {
      if (!r.ok) throw Error("Cannot inspect live schema.");
      return r.json();
    })) as any;
    const available = new Set(schema.tables.map((t: any) => t.name));
    states.set(
      target.database,
      await exportState(
        target.database,
        Object.keys(marketCheckpointTables).filter((t) => available.has(t)),
      ),
    );
  }
  for (const target of targets) {
    execFileSync(
      cli,
      [
        "publish",
        target.database,
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
    const after = await exportState(target.database),
      before = states.get(target.database);
    for (const table of added)
      if (!(table in before)) {
        assert.deepEqual(after[table], []);
        delete after[table];
      }
    assert.equal(
      canonicalState(after),
      canonicalState(before),
      `${target.database}: pre-existing retained fields changed`,
    );
    await sealPublishedModule(target.database);
    const binding = await readBinding(target.database);
    console.log(
      `${target.database}: additive boss migration preserved ${Object.keys(before).length - 1} retained tables; identity ${binding.identity}.`,
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
      `Preservation failed; workers remain stopped and recovery state is retained at ${path}.`,
    );
  }
  throw error;
} finally {
  if (verified) for (const target of resume) workers("Start", target.target);
}
