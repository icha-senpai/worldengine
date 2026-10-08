import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readFile } from "node:fs/promises";
import { resolve } from "node:path";
import { DbConnection } from "../src/bindings/bitcraft";
import { ownerToken, readBinding, storageUsage } from "./bitcraft-storage";
import { CollectorCycle } from "./bitcraft-collector-cycle";

const service = (action: string) =>
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
      "Test",
    ],
    { stdio: "inherit", windowsHide: true },
  );
const mainPid = await readFile(".runtime/services/collector.pid", "utf8");
const cycle = new CollectorCycle();
let reader: DbConnection | undefined;
try {
  service("Stop");
  // Allow the last 20-second owner lease to expire naturally, as it would after
  // a crashed/hung test monitor. The main collector remains running throughout.
  await new Promise((done) => setTimeout(done, 21000));
  const token = await ownerToken();
  reader = await cycle.wait(
    () =>
      new Promise<DbConnection>((done, reject) => {
        const connection = DbConnection.builder()
          .withUri("ws://127.0.0.1:3100")
          .withDatabaseName("space-bitcraft-checks")
          .withToken(token)
          .onConnect((conn) => {
            if (cycle.stopped) {
              conn.disconnect();
              return;
            }
            done(conn);
          })
          .onConnectError((_ctx, error) => reject(error))
          .build();
        cycle.signal.addEventListener("abort", () => connection.disconnect(), {
          once: true,
        });
      }),
    10000,
  );
  await assert.rejects(
    cycle.wait(
      () =>
        reader!.procedures.requestData({
          resource: "regions",
          id: "",
          query: "",
          page: 1,
          options: "{}",
        }),
      10000,
    ),
    /Updates are paused for storage maintenance/,
  );
  const market = JSON.parse(
    await reader.procedures.readMarket({ filters: "{}" }),
  );
  assert.ok(market.storage, "Stored market reads remain available.");
  assert.equal(
    await readFile(".runtime/services/collector.pid", "utf8"),
    mainPid,
  );
} finally {
  cycle.stop("Test finished.");
  reader?.disconnect();
  service("Start");
}
const binding = await readBinding("space-bitcraft-checks");
const usage = await storageUsage(binding);
assert.ok(usage.bytes < usage.targetBytes);
const ready = JSON.parse(
  await readFile(".runtime/services/collector-test.ready.json", "utf8"),
);
assert.equal(ready.database, "space-bitcraft-checks");
assert.equal(ready.mode, "storage");
console.log(
  "Test guard verified: expired leases block writes, reads survive, main collector stays running, and the test guard restarts.",
);
