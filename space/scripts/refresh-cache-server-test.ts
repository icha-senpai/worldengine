import { collectionKey } from "../bitcraft/src/collection";
import { upstreamRequest } from "../bitcraft/src/requests";
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readdir } from "node:fs/promises";
import { join } from "node:path";
import { DbConnection } from "../src/bindings/bitcraft";
import { providerDefaults } from "../bitcraft/src/providers";
import {
  ownerToken,
  ownerCall,
  registerTemporaryDatabase,
  disposeStorageTest,
  directoryBytes,
  safeReplicaPath,
} from "./bitcraft-storage";
const cli =
  process.env.LOCALAPPDATA + "/SpacetimeDB/bin/current/spacetimedb-cli.exe";
const database = `space-market-checks-${Date.now()}`;
const server = "http://127.0.0.1:3100";
const before = new Set(await readdir(".runtime/data/replicas"));
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
const binding = await registerTemporaryDatabase(database, before);
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
const owner = await connect(await ownerToken()),
  reader = await connect();
const request = (collector = false) =>
  (collector ? owner : reader).procedures.requestData({
    resource: "regions",
    id: "",
    query: "",
    page: 1,
    options: collector ? '{"collector":true}' : "{}",
  });
const sql = async (query: string) => {
  const response = await fetch(`${server}/v1/database/${database}/sql`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${await ownerToken()}`,
      "Content-Type": "text/plain",
    },
    body: query,
  });
  assert.ok(response.ok);
  return (await response.json())[0].rows;
};
try {
  await owner.reducers.storageLease({ seconds: 300 });
  await owner.reducers.authorizeCollector({
    identity: owner.identity!,
    enabled: true,
  });
  await owner.reducers.configureProvider({
    name: "bitjita",
    config: JSON.stringify({
      ...providerDefaults.bitjita,
      ttl: { ...providerDefaults.bitjita.ttl, regions: 1 },
    }),
  });
  const first = await request();
  assert.ok(
    first.payload && !first.error,
    "Live regions API must be available for this integration check.",
  );
  const body = JSON.stringify({
    ...JSON.parse(first.payload),
    padding: "x".repeat(1000000),
  });
  const key = collectionKey(
      "regions",
      upstreamRequest("regions", "", "", 1).key,
    ),
    epoch = "cache-refresh-test-generation";
  await owner.reducers.collectorHeartbeat({ epoch, error: "" });
  await owner.reducers.ingestCollection({
    key,
    source: "bitjita",
    epoch,
    payload: body,
    observedAt: first.updatedAt,
  });
  const cacheBefore = await sql("SELECT updated_at FROM snapshot");
  const feedBefore = await sql("SELECT observed_at FROM collection_feed");
  const clog = join(safeReplicaPath(binding.replica), "clog");
  const bytesBefore = await directoryBytes(clog);
  for (let iteration = 0; iteration < 3; iteration++) {
    await new Promise((done) => setTimeout(done, 1100));
    const next = await request(true);
    assert.ok(
      next.payload === first.payload,
      "Region directory must remain unchanged during the check.",
    );
    assert.ok(next.updatedAt > first.updatedAt);
    await owner.reducers.touchCollection({ key, observedAt: next.updatedAt });
    const collected = await request();
    assert.equal(collected.payload, body);
    assert.equal(collected.updatedAt, next.updatedAt);
  }
  assert.deepEqual(
    await sql("SELECT updated_at FROM snapshot"),
    cacheBefore,
    "Unchanged upstream responses must not rewrite the payload row.",
  );
  assert.deepEqual(
    await sql("SELECT observed_at FROM collection_feed"),
    feedBefore,
    "Freshness updates must not rewrite the collected payload.",
  );
  await new Promise((done) => setTimeout(done, 100));
  const growth = (await directoryBytes(clog)) - bytesBefore;
  assert.ok(
    growth < 100000,
    `Unchanged refreshes unexpectedly wrote ${growth} bytes.`,
  );
  await owner.reducers.collectionFailure({ key });
  assert.deepEqual(
    await sql("SELECT observed_at FROM collection_feed"),
    feedBefore,
    "Failure status must not rewrite the payload row.",
  );
  console.log(
    JSON.stringify({
      passed: true,
      payloadBytes: Buffer.byteLength(body),
      unchangedRefreshes: 3,
      apiPayloadBytes: Buffer.byteLength(first.payload),
      commitlogGrowthBytes: growth,
      unchangedCacheAndFeedRows: true,
    }),
  );
} finally {
  owner.disconnect();
  reader.disconnect();
  await disposeStorageTest(binding);
}
