import { execFileSync } from "node:child_process";
import assert from "node:assert/strict";
import { randomUUID } from "node:crypto";
import { DbConnection } from "../src/bindings/bitcraft";
const cli =
  process.env.LOCALAPPDATA + "/SpacetimeDB/bin/current/spacetimedb-cli.exe";
const server = "http://127.0.0.1:3100",
  database = "space-bitcraft-checks";
execFileSync(
  process.execPath,
  ["--import", "tsx", "scripts/spacetime.ts", "publish-bitcraft-test"],
  { stdio: "inherit", windowsHide: true },
);
execFileSync(cli, ["call", database, "storage_lease", "300", "--server", server, "--no-config"], { stdio: "pipe", windowsHide: true });

async function connect(
  token?: string,
): Promise<{ conn: DbConnection; token: string }> {
  return new Promise((resolve, reject) =>
    DbConnection.builder()
      .withUri("ws://127.0.0.1:3100")
      .withDatabaseName(database)
      .withToken(token)
      .onConnect((conn, _identity, token) => resolve({ conn, token }))
      .onConnectError((_ctx, error) => reject(error))
      .build(),
  );
}
const first = await connect(),
  second = await connect();
let reconnect: Awaited<ReturnType<typeof connect>> | undefined;
try {
  const token = randomUUID(),
    settings = JSON.stringify({
      source: "default",
      title: "Stream tasks",
      tasks: [{ id: "task-1", text: "Gather materials", done: false }],
    });
  await first.conn.reducers.saveWidget({ token, kind: "tasks", settings });
  const publicWidget = JSON.parse(
    await second.conn.procedures.readWidget({ token, kind: "tasks" }),
  );
  assert.equal(
    JSON.stringify(publicWidget.settings),
    settings,
    "share link can be read without the owner identity",
  );
  assert.equal(publicWidget.editable, false);
  await assert.rejects(
    second.conn.reducers.saveWidget({ token, kind: "tasks", settings }),
    /belongs to another browser/,
  );
  await assert.rejects(
    first.conn.reducers.saveWidget({ token, kind: "inventory", settings }),
    /belongs to another browser/,
  );
  await assert.rejects(
    second.conn.procedures.readWidget({ token, kind: "activity" }),
    /unavailable/,
  );
  reconnect = await connect(first.token);
  const updated = JSON.stringify({
    source: "default",
    title: "Updated tasks",
    tasks: [{ id: "task-1", text: "Gather materials", done: true }],
  });
  await reconnect.conn.reducers.saveWidget({
    token,
    kind: "tasks",
    settings: updated,
  });
  assert.equal(
    JSON.parse(
      await second.conn.procedures.readWidget({ token, kind: "tasks" }),
    ).settings.tasks[0].done,
    true,
    "same identity can reconnect and update",
  );
  await assert.rejects(
    second.conn.reducers.saveGuide({
      id: 999n,
      title: "Unauthorized",
      summary: "",
      category: "",
      content: '{"type":"doc","content":[]}',
      published: true,
    }),
    /database owner/,
  );
  const guides = await new Promise<any[]>((resolve, reject) => {
    first.conn
      .subscriptionBuilder()
      .onApplied(() => resolve([...first.conn.db.publishedGuides.iter()]))
      .onError((ctx) => reject(ctx.event))
      .subscribe(["SELECT * FROM published_guides"]);
  });
  assert.equal(guides.length, 19);
  const metadata = JSON.parse(
    await first.conn.procedures.publicCatalog({
      kind: "guide-metadata",
      id: "",
      q: "",
    }),
  );
  assert.ok(metadata.every((row: any) => row.updated_at && row.author.name));
  const targets = JSON.parse(
    await first.conn.procedures.publicCatalog({
      kind: "targets",
      id: "",
      q: "plank",
    }),
  );
  assert.ok(targets.length > 0);
  await assert.rejects(
    second.conn.procedures.requestData({
      resource: "player",
      id: "https://example.com",
      query: "",
      page: 1,
      options: "{}",
    }),
  );
  await assert.rejects(
    second.conn.reducers.configureProvider({ name: "bitjita", config: "{}" }),
    /database owner/,
  );
  const id = BigInt("0x" + randomUUID().replaceAll("-", "").slice(0, 15));
  const draft = {
    id,
    title: "Private editor check",
    summary: "Draft privacy",
    category: "Checks",
    content:
      '{"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"Editor fixture"}]}]}',
    published: false,
  };
  assert.equal(await first.conn.procedures.guideAccess({}), false);
  await assert.rejects(
    first.conn.reducers.grantGuideAdministrator({
      identity: first.conn.identity!,
      name: "Editor",
      enabled: true,
    }),
    /database owner/,
  );
  execFileSync(
    cli,
    [
      "call",
      database,
      "grant_guide_administrator",
      JSON.stringify(first.conn.identity!.toHexString()),
      JSON.stringify("Editor"),
      "true",
      "--server",
      server,
      "--no-config",
    ],
    { stdio: "pipe", windowsHide: true },
  );
  assert.equal(await first.conn.procedures.guideAccess({}), true);
  await first.conn.reducers.saveGuide(draft);
  async function readGuides(conn: DbConnection) {
    return await new Promise<{
      editable: any[];
      published: any[];
      metadata: any[];
    }>((resolve, reject) => {
      let subscription: any;
      subscription = conn
        .subscriptionBuilder()
        .onApplied(() => {
          const result = {
            editable: [...conn.db.editableGuides.iter()],
            published: [...conn.db.publishedGuides.iter()],
            metadata: [...conn.db.visibleGuideMetadata.iter()],
          };
          queueMicrotask(() => subscription.unsubscribe());
          resolve(result);
        })
        .onError((ctx) => reject(ctx.event))
        .subscribe([
          "SELECT * FROM editable_guides",
          "SELECT * FROM published_guides",
          "SELECT * FROM visible_guide_metadata",
        ]);
    });
  }
  const editor = await readGuides(first.conn),
    publicDraft = await readGuides(second.conn);
  assert.ok(editor.editable.some((row) => row.id === id));
  assert.equal(publicDraft.editable.length, 0);
  assert.ok(!publicDraft.published.some((row) => row.id === id));
  assert.ok(!publicDraft.metadata.some((row) => row.id === id));
  await first.conn.reducers.saveGuide({ ...draft, published: true });
  const published = await readGuides(second.conn);
  assert.ok(published.published.some((row) => row.id === id));
  assert.equal(
    published.metadata.find((row) => row.id === id)?.authorName,
    "Editor",
  );
  await first.conn.reducers.saveGuide({ ...draft, published: false });
  const unpublished = await readGuides(second.conn);
  assert.ok(!unpublished.published.some((row) => row.id === id));
  execFileSync(
    cli,
    ["call", database, "migrate_guides", "--server", server, "--no-config"],
    { stdio: "pipe", windowsHide: true },
  );
  assert.equal(
    (await readGuides(first.conn)).editable.find((row) => row.id === id)?.title,
    draft.title,
    "import preserves edited content",
  );
  execFileSync(
    cli,
    [
      "call",
      database,
      "grant_guide_administrator",
      JSON.stringify(first.conn.identity!.toHexString()),
      JSON.stringify("Editor"),
      "false",
      "--server",
      server,
      "--no-config",
    ],
    { stdio: "pipe", windowsHide: true },
  );
  assert.equal(await first.conn.procedures.guideAccess({}), false);
  assert.equal((await readGuides(first.conn)).editable.length, 0);
  await assert.rejects(
    first.conn.reducers.saveGuide(draft),
    /guide administrator/,
  );
  // Public HTTP requests use the shared BitJita cache.
  const playerRequest = {
    resource: "players",
    id: "",
    query: "Icha",
    page: 1,
    options: "{}",
  };
  const fallback = await first.conn.procedures.requestData(playerRequest);
  assert.ok(fallback.key.startsWith("bitjita|"), "public search uses BitJita");
  assert.ok(JSON.parse(fallback.payload).players.length > 0);
  assert.equal(fallback.error, "");
  const repeated = await second.conn.procedures.requestData(playerRequest);
  assert.equal(
    repeated.updatedAt,
    fallback.updatedAt,
    "another browser reuses the same shared cache",
  );
  await assert.rejects(
    second.conn.reducers.collectorHeartbeat({
      epoch: "test-epoch-1",
      error: "",
    }),
    /authorization/,
  );
  await assert.rejects(
    second.conn.procedures.requestData({
      ...playerRequest,
      options: '{"collector":true}',
    }),
    /authorization/,
  );
  await assert.rejects(
    second.conn.reducers.authorizeCollector({
      identity: second.conn.identity!,
      enabled: true,
    }),
    /database owner/,
  );
  execFileSync(
    cli,
    [
      "call",
      database,
      "authorize_collector",
      JSON.stringify(second.conn.identity!.toHexString()),
      "true",
      "--server",
      server,
      "--no-config",
    ],
    { stdio: "pipe", windowsHide: true },
  );
  const watch = await new Promise<any>((resolve, reject) =>
    second.conn
      .subscriptionBuilder()
      .onApplied(() =>
        resolve(
          [...second.conn.db.collectorWatches.iter()].find(
            (row) => row.resource === "players" && row.query === "Icha",
          ),
        ),
      )
      .onError((ctx) => reject(ctx.event))
      .subscribe(["SELECT * FROM collector_watches"]),
  );
  assert.ok(watch);
  await second.conn.reducers.collectorHeartbeat({
    epoch: "test-epoch-1",
    error: "",
  });
  await second.conn.reducers.ingestCollection({
    key: watch.key,
    source: "bitjita",
    epoch: "test-epoch-1",
    payload: fallback.payload,
    observedAt: BigInt(Date.now()) * 1000n,
  });
  const shared = await first.conn.procedures.requestData(playerRequest);
  assert.ok(shared.key.startsWith("collection|bitjita|test-epoch-1|"));
  assert.equal(shared.payload, fallback.payload);
  await assert.rejects(
    second.conn.reducers.ingestCollection({
      key: watch.key,
      source: "bitjita",
      epoch: "test-epoch-1",
      payload: "{}",
      observedAt: BigInt(Date.now()) * 1000n,
    }),
    /Invalid collection/,
  );
  assert.equal(
    (await first.conn.procedures.requestData(playerRequest)).payload,
    fallback.payload,
    "Malformed snapshots preserve the previous complete scope",
  );
  await assert.rejects(
    first.conn.procedures.reserveCollectionMap({ playerId: "10" }),
    /authorization/,
  );
  const playerId = "9" + Date.now();
  await second.conn.procedures.requestData({
    resource: "relaySkills",
    id: playerId,
    query: "",
    page: 1,
    options: "{}",
  });
  const skillKey = `relaySkills|player/${playerId}/skills`;
  const skillWatch = [...second.conn.db.collectorWatches.iter()].find(
    (row) => row.key === skillKey,
  );
  assert.ok(skillWatch);
  assert.ok(
    Number(skillWatch.expiresAt / 1000n) - Date.now() > 5 * 60 * 60 * 1000,
    "XP watches must keep collecting while the browser is suspended",
  );
  const sampleAt = BigInt(Date.now()) * 1000n;
  for (const [age, xp] of [
    [120, 100],
    [60, 50],
    [0, 100],
  ]) {
    await second.conn.reducers.ingestCollection({
      key: skillKey,
      source: "relay",
      epoch: "test-epoch-1",
      payload: JSON.stringify({
        player: { entity_id: playerId },
        skills: [{ skill_id: 3, xp }],
      }),
      observedAt: sampleAt - BigInt(age!) * 1000000n,
    });
  }
  const history = JSON.parse(
    await first.conn.procedures.collectionHistory({ playerId }),
  );
  assert.equal(history.fresh, true);
  assert.equal(
    history.rates.find((row: any) => row.skillId === 3).xpDelta,
    0,
    "Backward XP does not create recovery gains",
  );
  await second.conn.reducers.ingestCollection({
    key: skillKey,
    source: "relay",
    epoch: "test-epoch-1",
    payload: JSON.stringify({
      player: { entity_id: playerId },
      skills: [{ skill_id: 3, xp: null }],
    }),
    observedAt: BigInt(Date.now()) * 1000n,
  });
  assert.deepEqual(
    JSON.parse(await first.conn.procedures.collectionHistory({ playerId }))
      .rates,
    [],
    "Unknown current XP suppresses rates",
  );
  await second.conn.reducers.collectionFailure({ key: skillKey });
  assert.deepEqual(
    JSON.parse(await first.conn.procedures.collectionHistory({ playerId }))
      .rates,
    [],
    "Delayed feeds suppress current rates",
  );
  await assert.rejects(
    second.conn.reducers.ingestCollection({
      key: watch.key,
      source: "bitjita",
      epoch: "test-epoch-1",
      payload: "{}",
      observedAt: 1n,
    }),
    /out of order/,
  );
  execFileSync(
    cli,
    [
      "call",
      database,
      "authorize_collector",
      JSON.stringify(second.conn.identity!.toHexString()),
      "false",
      "--server",
      server,
      "--no-config",
    ],
    { stdio: "pipe", windowsHide: true },
  );
  await assert.rejects(
    second.conn.reducers.collectionFailure({ key: watch.key }),
    /authorization/,
  );
  console.log(
    "BitCraft server checks passed: public catalogs, draft privacy, administrator grant/revoke, publishing/unpublishing, import preservation, provider configuration protection, widget ownership, reconnect and sharing.",
  );
} finally {
  first.conn.disconnect();
  second.conn.disconnect();
  reconnect?.conn.disconnect();
}
