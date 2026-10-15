import { ownerCall } from "./bitcraft-storage";
import { isTrackedClaim } from "../bitcraft/src/claim-history";
const database =
  process.argv.slice(2).find((arg) => !arg.startsWith("--")) ??
  "space-bitcraft-tools";
if (!["space-bitcraft-tools", "space-bitcraft-checks"].includes(database))
  throw Error("Choose the managed main or test database.");
const data = JSON.parse(
  (await ownerCall(database, "read_claim_history", [
    "",
    0,
    "",
    "",
    10,
  ])) as string,
);
const list = JSON.parse(
  (await ownerCall(database, "read_tracked_claims", [
    "",
    0,
    "expiry",
    "",
    1,
    5,
  ])) as string,
);
let baselineCheck, supplyCheck;
if (process.argv.includes("--verify")) {
  let after = "",
    rows = 0,
    excludedRows = 0;
  while (true) {
    const page = JSON.parse(
      (await ownerCall(database, "export_market_checkpoint", [
        "claim_history_state",
        after,
      ])) as string,
    );
    rows += page.rows.length;
    excludedRows += page.rows.filter(
      (r: any) => !isTrackedClaim(JSON.parse(r.payload)),
    ).length;
    if (page.done) break;
    after = page.next;
  }
  baselineCheck = { rows, excludedRows };
  if (excludedRows) process.exitCode = 1;
  after = "";
  let supplyRows = 0,
    unknownUpkeep = 0;
  while (true) {
    const page = JSON.parse(
      (await ownerCall(database, "export_market_checkpoint", [
        "claim_supply_status",
        after,
      ])) as string,
    );
    supplyRows += page.rows.length;
    unknownUpkeep += page.rows.filter(
      (r: any) => JSON.parse(r.payload).upkeepPerHour === null,
    ).length;
    if (page.done) break;
    after = page.next;
  }
  supplyCheck = { rows: supplyRows, unknownUpkeep, baselineRows: rows };
}
console.log(
  JSON.stringify(
    {
      database,
      checkedAt: new Date().toISOString(),
      retainedEvents: data.retainedEvents,
      minimumTier: data.minimumTier,
      trackedClaims: data.trackedClaims,
      trackingRule: data.trackingRule,
      baselineCheck,
      supplyCheck,
      earliestClaims: list.claims,
      coverage: data.coverage,
      recentKinds: data.events.map((e: any) => ({
        kind: e.kind,
        regionId: e.regionId,
        observedAt: e.observedAt,
      })),
    },
    null,
    2,
  ),
);
