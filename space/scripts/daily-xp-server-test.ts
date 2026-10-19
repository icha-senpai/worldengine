import assert from "node:assert/strict";
import { readdir, readFile } from "node:fs/promises";
import { DbConnection } from "../src/bindings/bitcraft";
import {
  canonicalState,
  disposeStorageTest,
  exportState,
  ownerCall,
  ownerToken,
  registerTemporaryDatabase,
} from "./bitcraft-storage";
import { spacetimeServer } from "./spacetime-config";
import { centralDay } from "../bitcraft/src/daily-xp";

// No live database is published or reset by this proof.
assert.equal(spacetimeServer, "http://127.0.0.1:3100");
const database = `space-storage-checks-${Date.now()}`;
assert.equal(
  (await fetch(`${spacetimeServer}/v1/database/${database}`)).status,
  404,
);
const replicas = new Set(await readdir(".runtime/data/replicas"));
// Use the same pre-publication/publish API as the pinned CLI. This keeps the
// isolated proof runnable without launching a subprocess or allowing resets.
const headers = { Authorization: `Bearer ${await ownerToken()}` };
const publish = async (artifact: string) => {
  const body = await readFile(artifact);
  const url = `${spacetimeServer}/v1/database/${database}`;
  const pre = await fetch(url + "/pre_publish?host_type=Js", {
    method: "POST",
    headers,
    body,
  });
  let token = "";
  if (pre.status !== 404) {
    assert.ok(pre.ok, "Pre-publication inspection must succeed");
    const plan = (await pre.json()) as any;
    const automatic = plan.AutoMigrate ?? plan.auto_migrate ?? plan.Auto;
    assert.ok(
      automatic && !automatic.major_version_upgrade && !automatic.break_clients,
      "Proof requires a compatible, non-breaking publication",
    );
    token = "&token=" + encodeURIComponent(automatic.token);
  }
  const result = await fetch(url + "?host_type=Js" + token, {
    method: "PUT",
    headers,
    body,
  });
  assert.ok(result.ok, `Isolated publication failed (${result.status})`);
};
await publish(".runtime/bitcraft-storage/space-bitcraft-tools.bundle.js");
const binding = await registerTemporaryDatabase(database, replicas);
let conn: DbConnection | undefined;
try {
  const metadata = () =>
    fetch(`${spacetimeServer}/v1/database/${database}`).then((r) => r.json());
  const originalMetadata = await metadata();
  const app = JSON.parse(
    (await ownerCall(database, "export_app_state", [])) as string,
  );
  const at = Date.now();
  const day = centralDay(at);
  const scope = "dailyXp|relaySkills|player/20/skills";
  app.collection_sample = [
    {
      key: scope + "|" + day,
      scope,
      source: "relay",
      epoch: "daily-v1",
      observedAt: String(BigInt(at) * 1000n),
      payload: JSON.stringify({
        day,
        firstAt: at - 60000,
        lastAt: at,
        opening: { "12": 100 },
        closing: { "12": 150 },
        startCovered: false,
        endCovered: false,
      }),
    },
  ];
  app.widget_profile = [
    {
      token: "daily_xp_preserved_widget_123",
      owner: app.administrator[0].owner,
      kind: "activity",
      settings: '{"title":"Preserved XP widget"}',
      updatedAt: "100",
    },
  ];
  await ownerCall(database, "restore_app_state", [JSON.stringify(app)]);
  const before = canonicalState(await exportState(database));
  await publish("bitcraft/dist/bundle.js");
  const afterMetadata = await metadata();
  assert.deepEqual(
    afterMetadata.database_identity,
    originalMetadata.database_identity,
  );
  assert.deepEqual(
    afterMetadata.owner_identity,
    originalMetadata.owner_identity,
  );
  assert.equal(
    canonicalState(await exportState(database)),
    before,
    "Every retained value survives publication",
  );
  conn = await new Promise<DbConnection>((resolve, reject) =>
    DbConnection.builder()
      .withUri("ws://127.0.0.1:3100")
      .withDatabaseName(database)
      .onConnect((c) => resolve(c))
      .onConnectError((_ctx, error) => reject(error))
      .build(),
  );
  const history = JSON.parse(
    await conn.procedures.collectionHistory({ playerId: "20" }),
  );
  assert.equal(history.calendar.timeZone, "America/Chicago");
  assert.equal(history.calendar.periods[0].xp["12"], 50);
  assert.equal(
    history.samples.length,
    0,
    "Daily rows do not enter short rate history",
  );
  await ownerCall(database, "restore_app_state", [JSON.stringify(app)]);
  assert.equal(
    canonicalState(await exportState(database)),
    before,
    "Calendar state survives maintenance export/restore",
  );
  console.log(
    "PASS: previous artifact to new artifact preserves identity, owner and every retained record; daily history contract and maintenance restoration verified.",
  );
} finally {
  conn?.disconnect();
  await disposeStorageTest(binding, async (name) => {
    assert.equal(name, database);
    const result = await fetch(`${spacetimeServer}/v1/database/${name}`, {
      method: "DELETE",
      headers,
    });
    assert.ok(result.ok, "Temporary database retirement must succeed");
  });
}
