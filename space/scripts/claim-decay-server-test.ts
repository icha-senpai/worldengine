import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readdir } from "node:fs/promises";
import { DbConnection } from "../src/bindings/bitcraft";
import {
  canonicalState,
  disposeStorageTest,
  exportState,
  ownerCall,
  rebuildStorage,
  registerTemporaryDatabase,
  sealPublishedModule,
} from "./bitcraft-storage";
import { marketCheckpointTables } from "../bitcraft/src/market-checkpoint";
import { spacetimeCli, spacetimeServer } from "./spacetime-config";

// Existing sealed module -> new artifact, always in a disposable database.
assert.equal(spacetimeServer, "http://127.0.0.1:3100");
const database = `space-storage-checks-${Date.now()}`;
assert.equal(
  (await fetch(`${spacetimeServer}/v1/database/${database}`)).status,
  404,
);
const beforeReplicas = new Set(await readdir(".runtime/data/replicas"));
const publish = (artifact: string) =>
  execFileSync(
    spacetimeCli,
    [
      "publish",
      database,
      "--js-path",
      artifact,
      "--server",
      spacetimeServer,
      "--delete-data=never",
      "--yes",
      "--no-config",
    ],
    { stdio: "pipe", windowsHide: true },
  );
publish(".runtime/bitcraft-storage/space-bitcraft-tools.bundle.js");
let binding = await registerTemporaryDatabase(database, beforeReplicas);
let conn: DbConnection | undefined;
try {
  const metadataBefore = (await (
    await fetch(`${spacetimeServer}/v1/database/${database}`)
  ).json()) as any;
  const app = JSON.parse(
    (await ownerCall(database, "export_app_state", [])) as string,
  );
  app.widget_profile = [
    {
      token: "decay_proof_widget_1234567890",
      owner: app.administrator[0].owner,
      kind: "activity",
      settings: '{"title":"Preserved widget"}',
      updatedAt: "100",
    },
  ];
  app.provider_settings = [{ name: "bitjita", config: '{"enabled":false}' }];
  app.collection_sample = [
    {
      key: "decay-proof",
      scope: "relaySkills|player/1/skills",
      source: "relay",
      epoch: "proof",
      observedAt: "100",
      payload: '{"1":4321}',
    },
  ];
  await ownerCall(database, "restore_app_state", [JSON.stringify(app)]);
  const epoch = "decay-proof",
    at = String(BigInt(Date.now()) * 1000n);
  const checkpoint = (table: string, rows: unknown[]) =>
    ownerCall(database, "restore_market_checkpoint", [
      table,
      JSON.stringify(rows),
    ]);
  await checkpoint("claim_history_region", [
    {
      key: "9",
      regionId: 9,
      epoch,
      sequence: 1,
      observedAt: at,
      startedAt: at,
      complete: true,
      prunedAt: "0",
      discarded: "0",
      reconciling: false,
    },
  ]);
  await checkpoint(
    "claim_history_state",
    ["1", "2", "3"].map((id) => ({
      key: `9:${id}`,
      regionId: 9,
      epoch,
      observedAt: at,
      payload: JSON.stringify({
        id,
        name: "Fixture claim",
        ownerId: "20",
        ownerName: "Owner",
        totemId: "30",
        supplies: 300,
        tier: 6,
        x: 1,
        z: 2,
        waystones: 1,
        banks: 1,
        members: [],
      }),
    })),
  );
  await checkpoint("claim_history_event", [
    {
      key: "retained-history",
      regionId: 9,
      claimId: "1",
      kind: "supplies_refilled",
      observedAt: at,
      payload: '{"tier":6,"trackedAfter":true,"before":200,"after":300}',
    },
  ]);
  const oldTables = Object.keys(marketCheckpointTables).filter(
    (name) => name !== "claim_decay_region",
  );
  const before = await exportState(database, oldTables);
  publish("bitcraft/dist/bundle.js");
  const metadataAfter = (await (
    await fetch(`${spacetimeServer}/v1/database/${database}`)
  ).json()) as any;
  assert.deepEqual(
    metadataAfter.database_identity,
    metadataBefore.database_identity,
  );
  assert.deepEqual(metadataAfter.owner_identity, metadataBefore.owner_identity);
  const after = await exportState(database);
  assert.deepEqual(after.claim_decay_region, []);
  const { claim_decay_region: _new, ...retained } = after;
  assert.equal(
    canonicalState(retained),
    canonicalState(before),
    "Every old retained value survives the additive migration",
  );
  await sealPublishedModule(database);
  conn = await new Promise<DbConnection>((resolve, reject) =>
    DbConnection.builder()
      .withUri("ws://127.0.0.1:3100")
      .withDatabaseName(database)
      .onConnect((c) => resolve(c))
      .onConnectError((_ctx, error) => reject(error))
      .build(),
  );
  const supplies = (balance: number, treasury = "1") => ({
    regionId: 9,
    epoch,
    observedAt: BigInt(Date.now()) * 1000n,
    payload: JSON.stringify(
      ["1", "2", "3"].map((id) => ({
        claimId: id,
        supply: {
          claimId: id,
          supplies: balance,
          upkeepPerHour: 100,
          tickMillis: 3_600_000,
          tileCount: 10,
          buildingMaintenance: 0,
          treasury,
        },
      })),
    ),
  });
  await ownerCall(database, "storage_lease", [300]);
  await assert.rejects(
    conn.reducers.ingestClaimSupplies(supplies(300)),
    /authorization/,
  );
  execFileSync(
    spacetimeCli,
    [
      "call",
      database,
      "authorize_collector",
      JSON.stringify(conn.identity!.toHexString()),
      "true",
      "--server",
      spacetimeServer,
      "--no-config",
    ],
    { stdio: "pipe", windowsHide: true },
  );
  await conn.reducers.ingestClaimSupplies(supplies(300));
  await new Promise((resolve) => setTimeout(resolve, 20));
  await conn.reducers.ingestClaimSupplies(supplies(200));
  await conn.reducers.relayHeartbeat({
    key: "claims:9",
    provider: "bitsync",
    regionId: 9,
    epoch,
    ready: true,
    rows: 3,
    error: "",
  });
  const read = async () =>
    JSON.parse(
      await conn!.procedures.readTrackedClaims({
        query: "",
        regionId: 9,
        sort: "expiry",
        risk: "",
        page: 1,
        limit: 50,
      }),
    );
  let list = await read();
  assert.equal(list.total, 3);
  for (const claim of list.claims) {
    assert.equal(claim.estimateBasis, "regional_tick");
    assert.equal(claim.decayConfirmations, 3);
    assert.equal(claim.estimatedExpiresAt, claim.lastDecayAt + 2 * 3_600_000);
  }
  const expiry = list.claims[0].estimatedExpiresAt;
  await conn.reducers.ingestClaimSupplies(supplies(200, "2"));
  list = await read();
  assert.equal(
    list.claims[0].estimatedExpiresAt,
    expiry,
    "Treasury edits cannot move run-out",
  );
  const learned = await exportState(database);
  assert.equal(learned.claim_decay_region.length, 1);
  assert.equal(
    learned.claim_history_event.length,
    1,
    "Ordinary decay does not flood history",
  );
  conn.disconnect();
  conn = undefined;
  binding = await rebuildStorage(binding);
  assert.equal(
    canonicalState(await exportState(database)),
    canonicalState(learned),
    "Maintenance preserves learned timing and every other retained field",
  );
  console.log(
    "PASS: additive migration, retained ownership/config/XP/history, native decay learning, authorization, treasury stability and full maintenance restoration.",
  );
} finally {
  conn?.disconnect();
  await disposeStorageTest(binding);
}
