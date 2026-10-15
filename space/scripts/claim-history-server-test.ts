import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readdir } from "node:fs/promises";
import { resolve } from "node:path";
import { DbConnection } from "../src/bindings/bitcraft";
import {
  ownerCall,
  registerTemporaryDatabase,
  disposeStorageTest,
  exportState,
  canonicalState,
  rebuildStorage,
} from "./bitcraft-storage";
import { marketCheckpointTables } from "../bitcraft/src/market-checkpoint";
import { type ClaimState } from "../bitcraft/src/claim-history";

const cli = resolve(
    process.env.LOCALAPPDATA!,
    "SpacetimeDB/bin/current/spacetimedb-cli.exe",
  ),
  server = "http://127.0.0.1:3100";
const database = `space-storage-checks-${Date.now()}`,
  beforeReplicas = new Set(await readdir(".runtime/data/replicas"));
const run = (args: string[]) =>
  execFileSync(cli, args, { stdio: "pipe", windowsHide: true });
const publish = (artifact: string) =>
  run([
    "publish",
    database,
    "--js-path",
    artifact,
    "--server",
    server,
    "--delete-data=never",
    "--yes",
    "--no-config",
  ]);
const historyTables = new Set([
  "claim_supply_status",
  "claim_history_state",
  "claim_history_event",
  "claim_history_region",
]);
async function legacyExport() {
  const state = JSON.parse(
    (await ownerCall(database, "export_app_state", [])) as string,
  );
  for (const table of Object.keys(marketCheckpointTables).filter(
    (t) => !historyTables.has(t),
  )) {
    state[table] = [];
    let after = "";
    while (true) {
      const page = JSON.parse(
        (await ownerCall(database, "export_market_checkpoint", [
          table,
          after,
        ])) as string,
      );
      state[table].push(...page.rows);
      if (page.done) break;
      after = page.next;
    }
  }
  return state;
}
const connect = () =>
  new Promise<DbConnection>((done, reject) =>
    DbConnection.builder()
      .withUri("ws://127.0.0.1:3100")
      .withDatabaseName(database)
      .onConnect((c) => done(c))
      .onConnectError((_c, e) => reject(e))
      .build(),
  );
publish(".runtime/bitcraft-storage/space-bitcraft-tools.bundle.js");
let binding = await registerTemporaryDatabase(database, beforeReplicas);
let conn: DbConnection | undefined;
try {
  const state = JSON.parse(
    (await ownerCall(database, "export_app_state", [])) as string,
  );
  state.widget_profile = [
    {
      token: "claim_proof_widget_1234567890",
      owner: state.administrator[0].owner,
      kind: "activity",
      settings: '{"title":"Preserved widget"}',
      updatedAt: "100",
    },
  ];
  state.provider_settings = [{ name: "bitjita", config: '{"enabled":false}' }];
  state.guide.push({
    id: "987654",
    title: "Private preservation fixture",
    summary: "Private",
    category: "General",
    content: '{"type":"doc","content":[]}',
    published: false,
  });
  state.collection_sample = [
    {
      key: "proof",
      scope: "relaySkills|player/1/skills",
      source: "relay",
      epoch: "proof",
      observedAt: "100",
      payload: '{"1":4321}',
    },
  ];
  await ownerCall(database, "restore_app_state", [JSON.stringify(state)]);
  await ownerCall(database, "restore_market_checkpoint", [
    "relay_region",
    JSON.stringify([
      {
        regionId: 8,
        payload: '{"books":{}}',
        epoch: "preserved-market",
        observedAt: "123",
      },
    ]),
  ]);
  const prior = await legacyExport();
  publish("bitcraft/dist/bundle.js");
  const migrated = await exportState(database);
  for (const table of historyTables) {
    assert.deepEqual(migrated[table], []);
    delete migrated[table];
  }
  assert.equal(
    canonicalState(migrated),
    canonicalState(prior),
    "Every pre-existing retained field survives the additive migration",
  );
  // Seal only the disposable proof binding for its maintenance test.
  const { sealPublishedModule } = await import("./bitcraft-storage");
  await sealPublishedModule(database);
  conn = await connect();
  await new Promise<void>((resolve, reject) =>
    conn!
      .subscriptionBuilder()
      .onApplied(() => resolve())
      .onError((ctx) => reject(ctx.event))
      .subscribe(["SELECT * FROM claim_changes"]),
  );
  const claim: ClaimState = {
    id: "10",
    name: "Proof claim",
    ownerId: "20",
    ownerName: "Previous owner",
    totemId: "30",
    supplies: 0,
    tier: 6,
    waystones: 1,
    banks: 1,
    x: 100,
    z: 200,
    members: [{ id: "20", name: "Previous owner", permissions: "1111" }],
  };
  const args = {
    regionId: 8,
    epoch: "proof-live",
    sequence: 1,
    initial: true,
    complete: true,
    payload: JSON.stringify([
      { before: null, after: claim },
      { before: null, after: { ...claim, id: "11", tier: 3 } },
      { before: null, after: { ...claim, id: "12", banks: 0 } },
    ]),
    observedAt: BigInt(Date.now()) * 1000n,
  };
  await assert.rejects(
    conn.reducers.ingestClaimHistory(args),
    /storage maintenance/,
  );
  await ownerCall(database, "storage_lease", [300]);
  await assert.rejects(conn.reducers.ingestClaimHistory(args), /authorization/);
  run([
    "call",
    database,
    "authorize_collector",
    JSON.stringify(conn.identity!.toHexString()),
    "true",
    "--server",
    server,
    "--no-config",
  ]);
  await conn.reducers.ingestClaimHistory(args);
  assert.equal(
    [...conn.db.claimChanges.iter()][0]?.claims,
    1,
    "live revision reports tracked claims",
  );
  const supply = {
    claimId: "10",
    supplies: 2400,
    upkeepPerHour: 100,
    tileCount: 1000,
    buildingMaintenance: 0,
    treasury: "5000",
    tickMillis: 3_600_000,
  };
  await conn.reducers.ingestClaimSupplies({
    regionId: 8,
    epoch: args.epoch,
    observedAt: args.observedAt,
    payload: JSON.stringify([{ claimId: "10", supply }]),
  });
  assert.notEqual(
    [...conn.db.claimChanges.iter()][0]?.supplyVersion,
    "0",
    "supply changes push a revision",
  );
  await conn.reducers.relayHeartbeat({
    key: "claims:8",
    provider: "bitsync",
    regionId: 8,
    epoch: args.epoch,
    ready: true,
    rows: 1,
    error: "",
  });
  const list = JSON.parse(
    await conn.procedures.readTrackedClaims({
      query: "",
      regionId: 0,
      sort: "expiry",
      risk: "",
      page: 1,
      limit: 50,
    }),
  );
  assert.equal(list.total, 1);
  assert.equal(list.claims[0].supplies, 2400);
  assert.equal(list.claims[0].supplyStatus, "ready");
  assert.ok(
    list.claims[0].remainingHours > 23.9 && list.claims[0].remainingHours <= 24,
  );
  assert.equal(
    (await exportState(database)).claim_history_state.length,
    1,
    "Only T4+ claims with both required structures receive persistent baselines",
  );
  const next = {
    ...claim,
    ownerId: "21",
    ownerName: "New owner",
    members: [{ id: "21", name: "New owner", permissions: "1111" }],
  };
  const takeover = {
    ...args,
    sequence: 2,
    initial: false,
    complete: false,
    payload: JSON.stringify([{ before: claim, after: next }]),
  };
  await conn.reducers.ingestClaimHistory(takeover);
  await conn.reducers.ingestClaimHistory(takeover);
  const read = { query: "", regionId: 0, kind: "", before: "", limit: 100 };
  let result = JSON.parse(await conn.procedures.readClaimHistory(read));
  assert.equal(result.retainedEvents, 4);
  assert.equal(
    result.events.find((e: any) => e.kind === "ownership_changed").detail
      .possibleOutsiderTakeover,
    true,
  );
  assert.equal(
    JSON.parse(
      await conn.procedures.readClaimHistory({
        ...read,
        query: "Previous owner",
      }),
    ).events.length,
    2,
  );
  await assert.rejects(
    conn.reducers.ingestClaimHistory({ ...takeover, sequence: 4 }),
    /sequence gap/,
  );
  await assert.rejects(
    conn.reducers.ingestClaimHistory({ ...args, regionId: 3 }),
    /Invalid claim/,
  );
  const ignored = { ...claim, id: "11", tier: 3 };
  await conn.reducers.ingestClaimHistory({
    ...takeover,
    sequence: 3,
    payload: JSON.stringify([
      { before: ignored, after: { ...ignored, ownerId: "99" } },
    ]),
  });
  assert.equal(
    JSON.parse(await conn.procedures.readClaimHistory(read)).retainedEvents,
    4,
    "Unqualified claims generate no history",
  );
  const snapshot = await exportState(database);
  const starred = JSON.parse(
    await conn.procedures.readStarredClaims({
      claims: JSON.stringify([
        { id: "10", regionId: 8 },
        { id: "99", regionId: 8 },
      ]),
    }),
  );
  assert.equal(starred.claims[0].claim.ownerId, "21");
  assert.ok(starred.claims[0].lastActivityAt);
  assert.equal(starred.claims[1].claim, null);
  const memberTab = JSON.parse(
    await conn.procedures.readTrackedClaimHistory({
      claimId: "10",
      regionId: 8,
      group: "members",
      before: "",
      limit: 100,
    }),
  );
  assert.deepEqual(memberTab.events.map((e: any) => e.kind).sort(), [
    "member_departed",
    "member_joined",
  ]);
  conn.disconnect();
  conn = undefined;
  binding = await rebuildStorage(binding);
  assert.equal(
    canonicalState(await exportState(database)),
    canonicalState(snapshot),
    "Claim baseline, evidence, coverage and all other retained fields survive maintenance",
  );
  conn = await connect();
  result = JSON.parse(await conn.procedures.readClaimHistory(read));
  const restoredList = JSON.parse(
    await conn.procedures.readTrackedClaims({
      query: "",
      regionId: 0,
      sort: "expiry",
      risk: "",
      page: 1,
      limit: 50,
    }),
  );
  assert.equal(restoredList.claims[0].supplies, 2400);
  assert.equal(restoredList.claims[0].supplyStatus, "stale");
  assert.equal(result.retainedEvents, 4);
  assert.equal(
    result.coverage.find((r: any) => r.regionId === 8).ready,
    false,
    "Maintenance is a coverage gap until the recorder reconnects",
  );
  console.log(
    "PASS: additive old-to-new migration, full retained-field preservation, authorization, ordering, retry deduplication, search, outsider evidence and maintenance restoration.",
  );
} finally {
  conn?.disconnect();
  await disposeStorageTest(binding);
}
