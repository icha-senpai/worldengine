import { orderDelta } from "../bitcraft/src/trade-delta";
import { readdir } from "node:fs/promises";
import {
  registerTemporaryDatabase,
  disposeStorageTest,
} from "./bitcraft-storage";
import { execFileSync } from "node:child_process";
import assert from "node:assert/strict";
import { DbConnection } from "../src/bindings/bitcraft";
const cli =
    process.env.LOCALAPPDATA + "/SpacetimeDB/bin/current/spacetimedb-cli.exe",
  server = "http://127.0.0.1:3100",
  database = `space-relay-checks-${Date.now()}`;
const replicaBefore = new Set(await readdir(".runtime/data/replicas"));
execFileSync(
  cli,
  [
    "publish",
    database,
    "--module-path",
    "bitcraft",
    "--server",
    server,
    "--yes",
    "--no-config",
  ],
  { stdio: "inherit", windowsHide: true },
);
const storageBinding = await registerTemporaryDatabase(database, replicaBefore);
execFileSync(
  cli,
  ["call", database, "storage_lease", "300", "--server", server, "--no-config"],
  { stdio: "pipe", windowsHide: true },
);

const connect = () =>
  new Promise<DbConnection>((resolve, reject) =>
    DbConnection.builder()
      .withUri("ws://127.0.0.1:3100")
      .withDatabaseName(database)
      .onConnect((c) => resolve(c))
      .onConnectError((_c, e) => reject(e))
      .build(),
  );
const collector = await connect(),
  reader = await connect();
try {
  const at = BigInt(Date.now() - 10000) * 1000n,
    epoch = "relay-tests-generation-1",
    item = {
      id: "7",
      kind: "item",
      name: "Plank",
      sellOrders: 1,
      buyOrders: 0,
    };
  const heartbeat = {
    key: "trading:8",
    provider: "bitconnect",
    regionId: 8,
    epoch,
    ready: true,
    rows: 1,
    error: "",
  };
  await assert.rejects(
    reader.reducers.relayHeartbeat(heartbeat),
    /authorization/,
  );
  execFileSync(
    cli,
    [
      "call",
      database,
      "authorize_collector",
      JSON.stringify(collector.identity!.toHexString()),
      "true",
      "--server",
      server,
      "--no-config",
    ],
    { stdio: "pipe", windowsHide: true },
  );
  await collector.reducers.ingestMarketDirectory({
    resource: "market",
    payload: JSON.stringify({ items: [item] }),
    observedAt: at,
  });
  await collector.reducers.ingestMarketDirectory({
    resource: "regions",
    payload: JSON.stringify({
      regions: [
        { id: 8, name: "Solmere" },
        { id: 9, name: "Marowik" },
      ],
    }),
    observedAt: at,
  });
  await collector.reducers.relayHeartbeat(heartbeat);
  const book = {
    item,
    sellOrders: [
      {
        entityId: "1297036692719788627",
        regionId: 8,
        quantity: 25,
        priceThreshold: 3,
        claimEntityId: "10",
        claimName: "Native claim",
      },
    ],
    buyOrders: [],
  };
  const ingest = {
    regionId: 8,
    epoch,
    payload: JSON.stringify({ books: { "item:7": book }, stalls: [] }),
    observedAt: at + 1000000n,
  };
  await assert.rejects(
    reader.reducers.ingestRelayTrading(ingest),
    /authorization/,
  );
  await collector.reducers.ingestRelayTrading(ingest);
  const read = () =>
    reader.procedures
      .readMarketBook({ kind: "item", id: "7" })
      .then(JSON.parse);
  assert.equal((await read()).book.sellOrders[0].claimName, "Native claim");
  await collector.reducers.ingestMarketBook({
    kind: "item",
    id: "7",
    payload: JSON.stringify({
      ...book,
      sellOrders: [
        { entityId: "old-api", regionId: 8, quantity: 999, priceThreshold: 1 },
      ],
    }),
    observedAt: at + 2000000n,
  });
  assert.equal(
    (await read()).book.sellOrders[0].claimName,
    "Native claim",
    "Late API must not overwrite native state",
  );
  await assert.rejects(
    collector.reducers.ingestRelayTrading({
      ...ingest,
      epoch: "wrong-generation",
      observedAt: at + 3000000n,
    }),
    /generation/,
  );
  await assert.rejects(
    collector.reducers.ingestRelayTrading({
      ...ingest,
      payload: JSON.stringify({
        books: {
          "item:7": {
            ...book,
            sellOrders: [{ ...book.sellOrders[0], regionId: 9 }],
          },
        },
        stalls: [],
      }),
      observedAt: at + 3000000n,
    }),
    /region boundary/,
  );
  assert.equal(
    (await read()).book.sellOrders.length,
    1,
    "Bad scope must roll back atomically",
  );
  await collector.reducers.ingestRelayTrading({
    ...ingest,
    observedAt: at + 3000000n,
    payload: JSON.stringify({
      delta: true,
      reset: false,
      books: {
        "item:7": orderDelta(book, {
          ...book,
          sellOrders: [{ ...book.sellOrders[0], priceThreshold: 4 }],
        }),
      },
      stalls: [{ entityId: "555", regionId: 8, orders: [] }],
      removedBooks: [],
      removedStalls: [],
    }),
  });
  assert.equal((await read()).book.sellOrders[0].priceThreshold, 4);
  assert.equal(
    JSON.parse(await reader.procedures.readRelayBarter({})).stalls.length,
    1,
  );
  const beforeUnchanged = await read();
  await collector.reducers.ingestRelayTrading({
    ...ingest,
    observedAt: at + 4000000n,
    payload: JSON.stringify({
      books: {
        "item:7": {
          ...book,
          sellOrders: [{ ...book.sellOrders[0], priceThreshold: 4 }],
        },
      },
      stalls: [{ entityId: "555", regionId: 8, orders: [] }],
    }),
  });
  assert.equal(
    (await read()).observedAt,
    beforeUnchanged.observedAt,
    "Unchanged reconstructed full books must not rewrite their projections.",
  );
  await assert.rejects(
    collector.reducers.ingestRelayTrading({
      ...ingest,
      observedAt: at + 5000000n,
      payload: JSON.stringify({
        delta: true,
        reset: false,
        books: {},
        stalls: [],
        removedBooks: ["bad:key"],
        removedStalls: [],
      }),
    }),
    /removals/,
  );
  assert.equal((await read()).book.sellOrders[0].priceThreshold, 4);
  await collector.reducers.ingestRelayTrading({
    ...ingest,
    observedAt: at + 5000000n,
    payload: JSON.stringify({
      delta: true,
      reset: false,
      books: {},
      stalls: [],
      removedBooks: ["item:7"],
      removedStalls: ["555"],
    }),
  });
  assert.equal((await read()).book.sellOrders.length, 0);
  assert.equal(
    JSON.parse(await reader.procedures.readRelayBarter({})).stalls.length,
    0,
  );
  await collector.reducers.ingestRelayTrading({
    ...ingest,
    payload: '{"books":{},"stalls":[]}',
    observedAt: at + 5000000n,
  });
  assert.equal(
    (await read()).book.sellOrders.length,
    0,
    "Removed order must leave the canonical book",
  );
  const barter = JSON.parse(await reader.procedures.readRelayBarter({}));
  assert.equal(barter.available, true);
  assert.equal(barter.complete, false);
  await collector.reducers.relayHeartbeat({
    ...heartbeat,
    epoch: "relay-tests-generation-2",
  });
  assert.equal(
    JSON.parse(await reader.procedures.readRelayBarter({})).coverage[0].ready,
    false,
    "A reconnected transport must reconcile its new generation before declaring stored coverage ready",
  );
  await collector.reducers.relayHeartbeat({
    ...heartbeat,
    ready: false,
    error: "Test disconnect",
  });
  assert.equal(
    JSON.parse(await reader.procedures.readRelayBarter({})).coverage[0].ready,
    false,
  );
  console.log(
    "Relay server checks passed: authorization, generation fencing, API authority, atomic rollback, cancellation, and retained gap status.",
  );
} finally {
  collector.disconnect();
  reader.disconnect();
  await disposeStorageTest(storageBinding);
}
