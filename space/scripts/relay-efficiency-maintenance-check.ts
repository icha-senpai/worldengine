import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { resolve } from "node:path";
import { writeFile } from "node:fs/promises";
import {
  ownerCall,
  readBinding,
  rebuildStorage,
  exportState,
  canonicalState,
  storageUsage,
} from "./bitcraft-storage";
const database = "space-bitcraft-tools";
const worker = (action: string) =>
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
      "Main",
    ],
    { stdio: "inherit", windowsHide: true },
  );
worker("Stop");
let verified = false;
try {
  await ownerCall(database, "storage_lease", [0]);
  const before = await exportState(database);
  const started = Date.now();
  const binding = await rebuildStorage(await readBinding(database));
  const after = await exportState(database);
  assert.equal(
    canonicalState(after),
    canonicalState(before),
    "Every retained application and latest-market field must survive the real rebuild.",
  );
  verified = true;
  const result = {
    passed: true,
    database,
    rebuildAndVerificationSeconds: (Date.now() - started) / 1000,
    guides: after.guide.length,
    widgets: after.widget_profile.length,
    xpSamples: after.collection_sample.length,
    marketItems: after.market_item.length,
    nativeOrders: after.trade_record.filter((row: any) =>
      row.scope.startsWith("native:"),
    ).length,
    barterOrders: after.trade_record.filter((row: any) =>
      row.scope.startsWith("barter:"),
    ).length,
    barterStalls: after.relay_stall.length,
    retainedRegions: after.relay_region.length,
    usage: await storageUsage(binding),
  };
  await writeFile(
    "output/relay-maintenance-after-efficiency-20261008.json",
    JSON.stringify(result, null, 2) + "\n",
  );
  console.log(JSON.stringify(result, null, 2));
} finally {
  if (verified) worker("Start");
}
