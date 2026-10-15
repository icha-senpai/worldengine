import assert from "node:assert/strict";
import { execFileSync, spawn } from "node:child_process";
import { readdir, writeFile, unlink, stat } from "node:fs/promises";
import { resolve } from "node:path";
import { DbConnection } from "../src/bindings/bitcraft";
import { marketCheckpointTables } from "../bitcraft/src/market-checkpoint";
import {
  registerTemporaryDatabase,
  disposeStorageTest,
  ownerCall,
  exportState,
  canonicalState,
  sealPublishedModule,
  rebuildStorage,
} from "./bitcraft-storage";

const cli = resolve(
  process.env.LOCALAPPDATA!,
  "SpacetimeDB/bin/current/spacetimedb-cli.exe",
);
const server = "http://127.0.0.1:3100",
  database = `space-storage-checks-${Date.now()}`;
const added = new Set([
  "boss_defeat",
  "boss_score",
  "boss_totals",
  "boss_region",
]);
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
const beforeReplicas = new Set(await readdir(".runtime/data/replicas"));
let binding: Awaited<ReturnType<typeof registerTemporaryDatabase>> | undefined;
let conn: DbConnection | undefined;
let ui: ReturnType<typeof spawn> | undefined;
const connect = () =>
  new Promise<DbConnection>((done, reject) =>
    DbConnection.builder()
      .withUri("ws://127.0.0.1:3100")
      .withDatabaseName(database)
      .onConnect((c) => done(c))
      .onConnectError((_ctx, e) => reject(e))
      .build(),
  );
try {
  publish(".runtime/bitcraft-storage/space-bitcraft-tools.bundle.js");
  binding = await registerTemporaryDatabase(database, beforeReplicas);
  const legacySchema = (await fetch(
    `${server}/v1/database/${database}/schema?version=9`,
  ).then((r) => r.json())) as any;
  const available = new Set(legacySchema.tables.map((t: any) => t.name));
  const oldTables = Object.keys(marketCheckpointTables).filter((t) =>
    available.has(t),
  );
  const state = JSON.parse(
    (await ownerCall(database, "export_app_state", [])) as string,
  );
  state.widget_profile = [
    {
      token: "boss-proof-widget-1234567890",
      owner: state.administrator[0].owner,
      kind: "activity",
      settings: '{"title":"Preserved widget"}',
      updatedAt: "100",
    },
  ];
  state.provider_settings = [{ name: "bitjita", config: '{"enabled":false}' }];
  state.guide.push({
    id: "987654",
    title: "Private boss migration fixture",
    summary: "Retained",
    category: "General",
    content: '{"type":"doc","content":[]}',
    published: false,
  });
  await ownerCall(database, "restore_app_state", [JSON.stringify(state)]);
  const before = await exportState(database, oldTables);
  publish("bitcraft/dist/bundle.js");
  const after = await exportState(database);
  for (const table of added)
    if (!available.has(table)) {
      assert.deepEqual(after[table], []);
      delete after[table];
    }
  assert.equal(
    canonicalState(after),
    canonicalState(before),
    "All retained fields survive the additive migration",
  );
  await sealPublishedModule(database);
  conn = await connect();
  const diedAt = String(Math.floor(Date.now() / 1000));
  const defeats = [
    {
      enemyId: "100",
      enemyType: 32,
      diedAt,
      killerId: "648518346354619877",
      participants: [
        { id: "648518346354619877", name: "Krutch", contribution: 175 },
        { id: "20", name: "Proof helper", contribution: 225 },
      ],
    },
    {
      enemyId: "200",
      enemyType: 40,
      diedAt,
      killerId: "",
      participants: [
        { id: "648518346354619877", name: "Krutch", contribution: 40 },
      ],
    },
  ];
  const args = {
    regionId: 9,
    epoch: "boss-proof",
    payload: JSON.stringify(defeats),
  };
  await assert.rejects(
    conn.reducers.ingestBossDefeats(args),
    /storage maintenance/,
  );
  await ownerCall(database, "storage_lease", [300]);
  await assert.rejects(conn.reducers.ingestBossDefeats(args), /authorization/);
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
  await conn.reducers.bossHeartbeat({
    regionId: 9,
    epoch: "boss-proof",
    ready: true,
    error: "",
  });
  await conn.reducers.ingestBossDefeats(args);
  await conn.reducers.ingestBossDefeats(args);
  const read = { enemyType: 0, query: "", sort: "defeats", page: 1, limit: 50 };
  let board = JSON.parse(await conn.procedures.readBossBoard(read));
  assert.equal(board.totals.defeats, "2");
  assert.equal(board.rows[0].name, "Krutch");
  assert.equal(board.rows[0].defeats, "2");
  assert.equal(board.rows[0].finalBlows, "1");
  assert.equal(
    JSON.parse(await conn.procedures.readBossBoard({ ...read, enemyType: 40 }))
      .rows[0].finalBlows,
    "0",
  );
  const encounter = JSON.parse(
    await conn.procedures.readBossDefeat({ key: `9:100:${diedAt}` }),
  );
  assert.equal(encounter.participants.length, 2);
  const snapshot = await exportState(database);
  conn.disconnect();
  conn = undefined;
  binding = await rebuildStorage(binding);
  assert.equal(
    canonicalState(await exportState(database)),
    canonicalState(snapshot),
    "Boss details, scores, coverage and existing application state survive storage maintenance",
  );
  conn = await connect();
  board = JSON.parse(await conn.procedures.readBossBoard(read));
  assert.equal(board.rows[0].defeats, "2");
  console.log(
    "PASS: old-to-new migration, retained fields, authorization, storage lease, duplicate replay, T3/T7 rankings, encounter contributors and maintenance restoration.",
  );
  if (process.argv.includes("--ui")) {
    const marker = resolve(".runtime/boss-ui-proof.json"),
      stop = resolve(".runtime/boss-ui-proof.stop");
    await unlink(stop).catch(() => {});
    ui = spawn(
      process.execPath,
      [
        "node_modules/vite/bin/vite.js",
        "--host",
        "127.0.0.1",
        "--port",
        "5182",
        "--strictPort",
      ],
      {
        cwd: process.cwd(),
        env: { ...process.env, VITE_BITCRAFT_DATABASE: database },
        windowsHide: true,
        stdio: "ignore",
      },
    );
    await writeFile(
      marker,
      JSON.stringify({
        database,
        url: "http://127.0.0.1:5182/bitcraft/bosses",
        pid: ui.pid,
      }),
    );
    console.log(
      `UI_READY http://127.0.0.1:5182/bitcraft/bosses — fixture database ${database}`,
    );
    const deadline = Date.now() + 600000;
    while (
      Date.now() < deadline &&
      !(await stat(stop).then(
        () => true,
        () => false,
      ))
    )
      await new Promise((r) => setTimeout(r, 1000));
    await unlink(marker).catch(() => {});
    await unlink(stop).catch(() => {});
  }
} finally {
  ui?.kill();
  conn?.disconnect();
  if (binding) await disposeStorageTest(binding);
  else {
    // Registration also verifies ownership if initial setup failed after publish.
    try {
      binding = await registerTemporaryDatabase(database, beforeReplicas);
      await disposeStorageTest(binding);
    } catch (error) {
      console.error(
        `Temporary setup cleanup needs review: ${(error as Error).message}`,
      );
    }
  }
}
