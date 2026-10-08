import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readdir, readFile } from "node:fs/promises";
import { resolve } from "node:path";
import { DbConnection } from "../src/bindings/bitcraft";
import {
  ownerCall,
  registerBinding,
  exportState,
  rebuildStorage,
  canonicalState,
  storageUsage,
  disposeStorageTest,
  recoverStorage,
  readBinding,
} from "./bitcraft-storage";
const cli = resolve(
  process.env.LOCALAPPDATA!,
  "SpacetimeDB/bin/current/spacetimedb-cli.exe",
);
const database = `space-storage-checks-${Date.now()}`,
  server = "http://127.0.0.1:3100";
const replicas = resolve(".runtime/data/replicas"),
  before = new Set(await readdir(replicas));
execFileSync(
  cli,
  [
    "publish",
    database,
    "--js-path",
    "bitcraft/dist/bundle.js",
    "--server",
    server,
    "--yes",
    "--no-config",
  ],
  { stdio: "pipe", windowsHide: true },
);
const created = (await readdir(replicas)).filter((name) => !before.has(name));
assert.equal(created.length, 1);
let binding = await registerBinding(database, created[0]!);
const connect = (token?: string) =>
  new Promise<DbConnection>((done, reject) =>
    DbConnection.builder()
      .withUri("ws://127.0.0.1:3100")
      .withDatabaseName(database)
      .withToken(token)
      .onConnect((conn) => done(conn))
      .onConnectError((_ctx, error) => reject(error))
      .build(),
  );
let reader = await connect();
const token = reader.token;
try {
  const widget = {
    token: "storage_test_profile_123456789",
    kind: "activity",
    settings: '{"title":"Retained widget"}',
  };
  await assert.rejects(
    reader.reducers.saveWidget(widget),
    /storage maintenance/,
  );
  await assert.rejects(
    reader.reducers.storageLease({ seconds: 300 }),
    /owner authorization/,
  );
  await assert.rejects(
    reader.procedures.exportAppState({}),
    /owner authorization/,
  );
  const seeded = await exportState(database),
    now = BigInt(Date.now()) * 1000n;
  const scope = "relaySkills|player/1/skills",
    epoch = "storage-test-generation-native-1";
  seeded.collection_watch = [
    {
      key: scope,
      resource: "relaySkills",
      entityId: "1",
      query: "",
      page: 1,
      options: "{}",
      expiresAt: String(now + 21600000000n),
    },
  ];
  seeded.collection_feed = [
    {
      key: scope,
      resource: "relaySkills",
      entityId: "1",
      source: "relay",
      epoch,
      payload: '{"skills":[{"skill_id":1,"xp":100}]}',
      observedAt: String(now),
      receivedAt: String(now),
      expiresAt: String(now + 30000000n),
      error: "",
    },
  ];
  seeded.collection_sample = [
    {
      key: scope + "|relay|" + epoch + "|" + now / 60000000n,
      scope,
      source: "relay",
      epoch,
      observedAt: String(now),
      payload: '{"1":100}',
    },
  ];
  await ownerCall(database, "restore_app_state", [JSON.stringify(seeded)]);
  await ownerCall(database, "storage_lease", [300]);
  await reader.reducers.saveWidget(widget);
  await ownerCall(database, "save_guide", [
    12345,
    "Retained private guide",
    "Test",
    "General",
    '{"type":"doc","content":[]}',
    false,
  ]);
  execFileSync(
    cli,
    [
      "call",
      database,
      "authorize_collector",
      JSON.stringify(reader.identity!.toHexString()),
      "true",
      "--server",
      server,
      "--no-config",
    ],
    { stdio: "pipe", windowsHide: true },
  );
  const marketItem = { id: "7", name: "Plank", sellOrders: 1, buyOrders: 0 };
  await reader.reducers.ingestMarketDirectory({
    resource: "market",
    payload: JSON.stringify({ items: [marketItem] }),
    observedAt: now,
  });
  await reader.reducers.relayHeartbeat({
    key: "trading:8",
    provider: "bitconnect",
    regionId: 8,
    epoch: "storage-market-generation",
    ready: true,
    rows: 1,
    error: "",
  });
  await reader.reducers.ingestRelayTrading({
    regionId: 8,
    epoch: "storage-market-generation",
    observedAt: now,
    payload: JSON.stringify({
      books: {
        "item:7": {
          item: marketItem,
          sellOrders: [
            {
              entityId: "9007199254740993",
              regionId: 8,
              quantity: 10,
              priceThreshold: 3,
              claimEntityId: "10",
              claimName: "Preserved claim",
            },
          ],
          buyOrders: [],
        },
      },
      stalls: [
        {
          entityId: "555",
          regionId: 8,
          orders: [
            {
              entityId: "556",
              remainingStock: 2,
              offerItems: [{ id: "7", name: "Plank", quantity: 3 }],
              requiredItems: [],
              offerCargo: [],
              requiredCargo: [],
            },
          ],
        },
      ],
    }),
  });
  const marketBefore = JSON.parse(
    await reader.procedures.readMarketBook({ kind: "item", id: "7" }),
  ).book;
  const barterBefore = JSON.parse(
    await reader.procedures.readRelayBarter({}),
  ).stalls;
  const state = await exportState(database),
    oldReplica = binding.replica;
  assert.equal(state.widget_profile.length, 1);
  assert.ok(state.trade_record.length > 0);
  assert.equal(state.relay_stall.length, 1);
  assert.equal(state.collection_watch.length, 1);
  assert.equal(state.collection_feed.length, 1);
  assert.equal(state.collection_sample.length, 1);
  assert.equal(
    state.guide.find((row: any) => row.id === "12345").published,
    false,
  );
  reader.disconnect();
  await assert.rejects(
    rebuildStorage(binding, () => {
      throw Error("Simulated interruption before retirement");
    }),
    /Simulated interruption/,
  );
  binding = await recoverStorage(await readBinding(database, true));
  assert.notEqual(binding.replica, oldReplica);
  await assert.rejects(
    readFile(resolve(replicas, oldReplica, "db.lock")),
    /ENOENT/,
  );
  assert.equal(
    canonicalState(await exportState(database)),
    canonicalState(state),
  );
  reader = await connect(token);
  assert.deepEqual(
    JSON.parse(
      await reader.procedures.readMarketBook({ kind: "item", id: "7" }),
    ).book,
    marketBefore,
  );
  assert.deepEqual(
    JSON.parse(await reader.procedures.readRelayBarter({})).stalls,
    barterBefore,
  );
  assert.equal(
    JSON.parse(await reader.procedures.readRelayBarter({})).coverage.length,
    0,
    "Restored data remains available while fresh relay readiness is established.",
  );
  assert.equal(
    JSON.parse(
      await reader.procedures.readWidget({
        token: widget.token,
        kind: widget.kind,
      }),
    ).editable,
    true,
  );
  await assert.rejects(
    reader.reducers.saveWidget(widget),
    /storage maintenance/,
  );
  await ownerCall(database, "storage_lease", [1]);
  await reader.reducers.saveWidget(widget);
  await new Promise((done) => setTimeout(done, 1300));
  await assert.rejects(
    reader.reducers.saveWidget(widget),
    /storage maintenance/,
  );
  await ownerCall(database, "storage_lease", [0]);
  const bad = {
    ...state,
    widget_profile: [{ ...state.widget_profile[0], owner: "bad-identity" }],
  };
  await assert.rejects(
    ownerCall(database, "restore_app_state", [JSON.stringify(bad)]),
    /failed/,
  );
  const after = await exportState(database);
  assert.equal(
    after.widget_profile.length,
    1,
    "Failed restoration must roll back atomically",
  );
  console.log(
    "Storage server checks passed: owner-only export, frozen writes, exact restore, widget ownership, identity preservation, disk reclamation, lease expiry, and atomic rollback.",
  );
  console.log(await storageUsage(binding));
} finally {
  reader.disconnect();
  await disposeStorageTest(binding);
}
