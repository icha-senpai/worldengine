import {
  readBinding,
  storageUsage,
  rebuildStorage,
  ownerCall,
} from "./bitcraft-storage";
import { execFileSync } from "node:child_process";
import { createHash, randomUUID } from "node:crypto";
import { installCollectorConsole } from "./collector-log";
import { pruneSchedule } from "./collector-scheduler";
import { CollectorCycle } from "./bitcraft-collector-cycle";
import { CollectorDiagnostics } from "./collector-diagnostics";
import {
  mkdir,
  readFile,
  writeFile,
  rename,
  readdir,
  unlink,
} from "node:fs/promises";
import { resolve, join } from "node:path";
import { DbConnection } from "../src/bindings/bitcraft";
import { collectionInterval, collectionKey } from "../bitcraft/src/collection";
import { relayRequest } from "../bitcraft/src/providers";
import { upstreamRequest } from "../bitcraft/src/requests";
import { NativeRelayCollector } from "./bitcraft-relay-collector";
import {
  summarizeResources,
  type ResourceDictionary,
} from "./bitcraft-resources";

const server = process.env.BITCRAFT_COLLECTOR_HOST || "ws://127.0.0.1:3100";
const databaseArg = process.argv.indexOf("--database");
const database =
  (databaseArg >= 0 ? process.argv[databaseArg + 1] : undefined) ||
  process.env.BITCRAFT_COLLECTOR_DATABASE ||
  "space-bitcraft-tools";
if (
  databaseArg >= 0 &&
  (!process.argv[databaseArg + 1] ||
    process.argv[databaseArg + 1]!.startsWith("--"))
)
  throw new Error("--database requires a database name.");
if (!/^[a-zA-Z0-9-]{1,80}$/.test(database))
  throw new Error("Invalid collector database.");
const storageOnly = process.argv.includes("--storage-only");
const serviceName =
  database === "space-bitcraft-tools"
    ? "collector"
    : database === "space-bitcraft-checks"
      ? "collector-test"
      : `collector-${database}`;
const diagnostics = new CollectorDiagnostics(database);
installCollectorConsole(diagnostics.directory);
async function markReady() {
  diagnostics.phase("ready");
  diagnostics.event("ready");
  diagnostics.sample();
  await mkdir(resolve(".runtime/services"), { recursive: true });
  await writeFile(
    resolve(`.runtime/services/${serviceName}.ready.json`),
    JSON.stringify({
      pid: process.pid,
      database,
      epoch,
      mode: storageOnly ? "storage" : "collection",
      at: Date.now(),
    }),
  );
}
const directory = resolve(".runtime/collector", database);
await mkdir(directory, { recursive: true });
const tokenPath = join(directory, "identity.json");
let token: string | undefined;
try {
  token = JSON.parse(await readFile(tokenPath, "utf8")).token;
} catch {
  /* First start. */
}
let stopping = false;
let activeCycle: CollectorCycle | undefined;
process.on("SIGINT", () => {
  diagnostics.phase("stopping");
  diagnostics.event("signal");
  console.log("Collector received SIGINT; stopping.");
  stopping = true;
  activeCycle?.stop("Collector stopped.");
});
process.on("SIGTERM", () => {
  diagnostics.phase("stopping");
  diagnostics.event("signal");
  console.log("Collector received SIGTERM; stopping.");
  stopping = true;
  activeCycle?.stop("Collector stopped.");
});
const epoch = randomUUID();
process.on("exit", (code) => {
  console.log(`Collector process exiting with code ${code}.`);
});
const due = new Map<string, number>();
const dictionaries = new Map<string, ResourceDictionary>();
type Request = {
  resource: string;
  entityId: string;
  query: string;
  page: number;
  options: string;
  key?: string;
};
const seeds: Request[] = ["regions", "empires", "levels"].map((resource) => ({
  resource,
  entityId: "",
  query: "",
  page: 1,
  options: "{}",
}));
for (const entityId of (process.env.BITCRAFT_COLLECTOR_PLAYERS || "")
  .split(",")
  .filter(Boolean)) {
  if (!/^\d{1,24}$/.test(entityId))
    throw new Error("Configured player IDs must be decimal strings.");
  for (const resource of [
    "relayPlayer",
    "relaySkills",
    "relayInventories",
    "relayHousing",
    "relayCrafts",
  ])
    seeds.push({ resource, entityId, query: "", page: 1, options: "{}" });
}
function keyFor(request: Request) {
  const path = request.resource.startsWith("relay")
    ? relayRequest(request.resource, request.entityId, request.query).path
    : upstreamRequest(
        request.resource,
        request.entityId,
        request.query,
        request.page,
        JSON.parse(request.options),
      ).key;
  return collectionKey(request.resource, path);
}
// These old compressed copies duplicated rebuildable relay/API data. Keep the
// collector identity, but never accumulate another disk cache beside SpacetimeDB.
for (const name of await readdir(directory)) {
  if (/^[a-f0-9]{64}\.json\.gz(?:\.tmp)?$/.test(name))
    await unlink(join(directory, name));
}
async function connect(cycle: CollectorCycle) {
  return new Promise<DbConnection>((resolveConnection, reject) => {
    let connection: DbConnection | undefined;
    let timedOut = false;
    const timer = setTimeout(() => {
      timedOut = true;
      connection?.disconnect();
      reject(new Error("Database connection timed out."));
    }, 15000);
    const builder = DbConnection.builder()
      .withUri(server)
      .withDatabaseName(database);
    if (token) builder.withToken(token);
    connection = builder
      .onConnect(async (conn, identity, nextToken) => {
        clearTimeout(timer);
        if (timedOut || cycle.stopped) {
          conn.disconnect();
          return;
        }
        token = nextToken;
        try {
          await writeFile(
            tokenPath + ".tmp",
            JSON.stringify({ token: nextToken }),
            { mode: 0o600 },
          );
          await rename(tokenPath + ".tmp", tokenPath);
        } catch {
          conn.disconnect();
          reject(new Error("Collector identity could not be saved."));
          return;
        }
        if (process.argv.includes("--authorize")) {
          if (!/^wss?:\/\/(127\.0\.0\.1|localhost):\d+$/.test(server)) {
            conn.disconnect();
            reject(new Error("Automatic authorization is local-only."));
            return;
          }
          const cli =
            process.env.SPACETIME_CLI ||
            resolve(
              process.env.LOCALAPPDATA ?? ".",
              "SpacetimeDB/bin/current/spacetimedb-cli.exe",
            );
          try {
            execFileSync(
              cli,
              [
                "call",
                database,
                "authorize_collector",
                JSON.stringify(identity.toHexString()),
                "true",
                "--server",
                server.replace(/^ws/, "http"),
                "--no-config",
              ],
              { stdio: "pipe", windowsHide: true, timeout: 15000 },
            );
          } catch {
            conn.disconnect();
            reject(
              new Error("The local owner could not authorize the collector."),
            );
            return;
          }
        }
        resolveConnection(conn);
      })
      .onConnectError((_ctx, error) => {
        clearTimeout(timer);
        reject(error);
      })
      .onDisconnect(() => cycle.stop("Database disconnected; reconnecting."))
      .build();
  });
}
const publishedFeeds = new Map<string, { hash: string; observedAt: bigint }>();
async function collect(
  conn: DbConnection,
  cycle: CollectorCycle,
  request: Request,
  seed = false,
) {
  const key = request.key ?? keyFor(request);
  const options = {
    ...JSON.parse(request.options),
    collector: true,
    ...(seed ? { collectorWatch: true } : {}),
  };
  try {
    const response =
      request.resource === "relayNearby"
        ? await cycle.wait(() => collectResources(conn, cycle, request))
        : await cycle.wait(() =>
            conn.procedures.requestData({
              resource: request.resource,
              id: request.entityId,
              query: request.query,
              page: request.page,
              options: JSON.stringify(options),
            }),
          );
    if (
      !response.payload ||
      response.error ||
      response.retryAt > BigInt(Date.now()) * 1000n
    )
      throw new Error("Feed refresh delayed.");
    // A seed can read an already collected feed. Its source remains the origin,
    // never the collector cache itself.
    const source = response.key.startsWith("collection|")
      ? response.key.split("|")[1]
      : response.key.split("|")[0];
    if (!["relay", "bitjita"].includes(source))
      throw new Error("Unexpected feed source.");
    if (!response.key.startsWith("collection|")) {
      const digest = createHash("sha256")
        .update(response.payload)
        .digest("hex");
      const previous = publishedFeeds.get(key);
      if (request.resource !== "relaySkills" && previous?.hash === digest) {
        if (response.updatedAt > previous.observedAt) {
          try {
            await cycle.wait(() =>
              conn.reducers.touchCollection({
                key,
                observedAt: response.updatedAt,
              }),
            );
          } catch (error) {
            if (cycle.stopped) throw error;
            // A scope can be evicted while the worker retains its hash.
            await cycle.wait(() =>
              conn.reducers.ingestCollection({
                key,
                source,
                epoch,
                payload: response.payload,
                observedAt: response.updatedAt,
              }),
            );
          }
        }
      } else {
        await cycle.wait(() =>
          conn.reducers.ingestCollection({
            key,
            source,
            epoch,
            payload: response.payload,
            observedAt: response.updatedAt,
          }),
        );
      }
      if (!publishedFeeds.has(key) && publishedFeeds.size >= 500)
        publishedFeeds.delete(publishedFeeds.keys().next().value!);
      publishedFeeds.set(key, { hash: digest, observedAt: response.updatedAt });
    }
    due.set(key, Date.now() + collectionInterval(request.resource) * 1000);
    // World directories are collected page by page; never declare the first
    // page a complete world snapshot.
    const raw = JSON.parse(response.payload);
    const body = raw.data ?? raw;
    const pagination = body.pagination ?? raw.pagination ?? body;
    const fullDirectoryPage =
      ["claims", "stalls"].includes(request.resource) &&
      (body[request.resource]?.length ?? 0) === 100;
    if (
      seed &&
      ["claims", "stalls"].includes(request.resource) &&
      !request.query &&
      request.options === "{}" &&
      request.page < 100 &&
      pagination &&
      (fullDirectoryPage ||
        pagination.hasNextPage === true ||
        Number(pagination.totalPages ?? pagination.lastPage) > request.page)
    ) {
      const next = { ...request, key: undefined, page: request.page + 1 };
      if (!seeds.some((row) => keyFor(row) === keyFor(next))) {
        seeds.push(next);
        console.log(
          `Collecting ${request.resource} directory page ${next.page}.`,
        );
      }
    }
  } catch (error) {
    if (cycle.stopped) throw error;
    due.set(key, Date.now() + 60000);
    if (conn.isActive)
      await cycle
        .wait(() => conn.reducers.collectionFailure({ key }))
        .catch((failure) => {
          if (cycle.stopped) throw failure;
        });
    console.error(
      `Collection delayed for ${request.resource}: ${(error as Error).message}`,
    );
  }
}
async function collectResources(
  conn: DbConnection,
  cycle: CollectorCycle,
  request: Request,
) {
  const { baseUrl } = JSON.parse(
    await cycle.wait(() =>
      conn.procedures.reserveCollectionMap({ playerId: request.entityId }),
    ),
  );
  const response = await fetch(
    `${baseUrl}/bitme/session/${request.entityId}/resources`,
    { signal: AbortSignal.any([cycle.signal, AbortSignal.timeout(12000)]) },
  );
  if (!response.ok) throw new Error("Resource window is unavailable.");
  const bytes = new Uint8Array(await response.arrayBuffer());
  if (bytes.length !== 320024)
    throw new Error("Unexpected resource window size.");
  const header = new DataView(bytes.buffer),
    region = header.getUint32(16, true),
    version = header.getUint32(20, true);
  const key = `${region}:${version}`;
  let dictionary = dictionaries.get(key);
  if (!dictionary) {
    const result = await fetch(
      `${baseUrl}/bitme/region/${region}/resource-dictionary`,
      { signal: AbortSignal.any([cycle.signal, AbortSignal.timeout(12000)]) },
    );
    if (!result.ok) throw new Error("Resource dictionary is unavailable.");
    dictionary = (await result.json()) as ResourceDictionary;
    if (!Array.isArray(dictionary.entries) || dictionary.entries.length > 2048)
      throw new Error("Invalid resource dictionary.");
    if (dictionaries.size >= 25) dictionaries.clear();
    dictionaries.set(key, dictionary);
  }
  const payload = summarizeResources(bytes, dictionary);
  return {
    key: "relay|bitme/session/" + request.entityId + "/resources",
    payload: JSON.stringify(payload),
    updatedAt: BigInt(Date.now()) * 1000n,
    retryAt: 0n,
    error: "",
  };
}
async function collectMarket(
  conn: DbConnection,
  cycle: CollectorCycle,
  task: { resource: string; id: string; kind?: string },
) {
  let retryAt = BigInt(Date.now() + 60000) * 1000n;
  try {
    const response = await cycle.wait(() =>
      conn.procedures.requestData({
        resource: task.resource,
        id: task.id,
        query: "",
        page: 1,
        options: '{"collector":true}',
      }),
    );
    if (response.retryAt > retryAt) retryAt = response.retryAt;
    if (
      !response.payload ||
      response.error ||
      response.retryAt > BigInt(Date.now()) * 1000n
    )
      throw new Error("Source refresh delayed");
    if (task.kind)
      await cycle.wait(() =>
        conn.reducers.ingestMarketBook({
          kind: task.kind!,
          id: task.id,
          payload: response.payload,
          observedAt: response.updatedAt,
        }),
      );
    else
      await cycle.wait(() =>
        conn.reducers.ingestMarketDirectory({
          resource: task.resource,
          payload: response.payload,
          observedAt: response.updatedAt,
        }),
      );
  } catch (error) {
    if (cycle.stopped) throw error;
    await cycle
      .wait(() =>
        conn.reducers.marketRefreshFailure({
          resource: task.resource,
          id: task.id,
          retryAt,
        }),
      )
      .catch((failure) => {
        if (cycle.stopped) throw failure;
      });
    console.error(
      `Stored market refresh delayed for ${task.resource}/${task.id}: ${(error as Error).message}`,
    );
  }
}
const sleep = (ms: number) =>
  new Promise((resolveSleep) => setTimeout(resolveSleep, ms));
let once = process.argv.includes("--once");
while (!stopping) {
  const cycle = new CollectorCycle();
  activeCycle = cycle;
  let conn: DbConnection | undefined;
  let native: NativeRelayCollector | undefined;
  let monitor: ReturnType<typeof setInterval> | undefined;
  let monitorWork: Promise<void> | undefined;
  let storageReady = false;
  try {
    diagnostics.phase("storage-check");
    let binding = await readBinding(database);
    let usage = await storageUsage(binding);
    if (usage.bytes >= usage.targetBytes) {
      diagnostics.phase("maintenance");
      diagnostics.event("maintenance-start");
      diagnostics.sample();
      binding = await rebuildStorage(binding);
      diagnostics.event("maintenance-end");
      usage = await storageUsage(binding);
    }
    if (usage.freeBytes < 512_000_000)
      throw Error("Insufficient disk headroom; updates remain paused.");
    await ownerCall(database, "storage_lease", [20]);
    let leasedAt = Date.now();
    monitor = setInterval(() => {
      if (monitorWork || stopping || cycle.stopped) return;
      monitorWork = (async () => {
        try {
          diagnostics.storage("storage-monitor");
          const current = await storageUsage(binding);
          if (
            current.bytes >= current.targetBytes ||
            current.freeBytes < 512_000_000
          ) {
            console.log(
              `Storage maintenance required at ${Math.round(current.bytes / 1_000_000)} MB. Pausing collection.`,
            );
            cycle.stop("Storage maintenance required.");
            await ownerCall(database, "storage_lease", [0]);
            native?.stop();
            conn?.disconnect();
            if (
              current.freeBytes < 512_000_000 &&
              current.bytes < current.targetBytes
            ) {
              stopping = true;
              process.exitCode = 1;
              console.error(
                "Low disk space: updates paused. Free disk space before restarting.",
              );
            }
          } else if (Date.now() - leasedAt >= 5000) {
            diagnostics.storage("storage-lease");
            await ownerCall(database, "storage_lease", [20]);
            leasedAt = Date.now();
          }
        } catch (error) {
          diagnostics.event("storage-error", error);
          diagnostics.sample();
          stopping = true;
          process.exitCode = 1;
          cycle.stop("Storage monitor failed; updates remain paused.");
          native?.stop();
          conn?.disconnect();
          console.error(
            `Storage monitor failed: ${(error as Error).message}. Updates will stay paused.`,
          );
        } finally {
          diagnostics.storage("idle");
          monitorWork = undefined;
        }
      })();
    }, 1000);
    storageReady = true;
    if (storageOnly) {
      console.log(`BitCraft storage guard ready for ${database}.`);
      await markReady();
      while (!stopping && !cycle.stopped) await cycle.wait(() => sleep(1000));
      continue;
    }
    publishedFeeds.clear();
    diagnostics.phase("database-connect");
    conn = await cycle.wait(() => connect(cycle));
    await cycle.wait(() =>
      conn!.reducers.collectorHeartbeat({ epoch, error: "" }),
    );
    await cycle.wait(
      () =>
        new Promise<void>((ready, reject) =>
          conn!
            .subscriptionBuilder()
            .onApplied(() => ready())
            .onError((ctx) => reject(ctx.event))
            .subscribe(["SELECT * FROM collector_watches"]),
        ),
    );
    console.log(`BitCraft collector ready for ${database}.`);
    if (!once && process.env.BITCRAFT_NATIVE_RELAYS !== "0") {
      native = new NativeRelayCollector(conn, epoch, due, diagnostics, cycle);
      void native
        .start()
        .catch((error) =>
          console.error(`Native relay startup: ${error.message}`),
        );
    }
    await markReady();
    while (!stopping && !cycle.stopped && conn.isActive) {
      await cycle.wait(() =>
        conn!.reducers.collectorHeartbeat({ epoch, error: "" }),
      );
      const activeWatches = [...conn.db.collectorWatches.iter()].filter(
        (row) => row.expiresAt / 1000n > BigInt(Date.now()),
      );
      pruneSchedule(
        due,
        new Set([...seeds.map(keyFor), ...activeWatches.map((row) => row.key)]),
      );
      diagnostics.phase("relay-tick");
      if (native)
        await cycle.wait(() =>
          native!.tick([
            ...conn!.db.collectorWatches.iter(),
            ...seeds.map((row) => ({
              ...row,
              expiresAt: BigInt(Date.now() + 600000) * 1000n,
            })),
          ]),
        );
      diagnostics.phase("market-refresh");
      const marketWork = JSON.parse(
        await cycle.wait(() => conn!.procedures.marketCollectionWork({})),
      );
      await Promise.all(
        marketWork.map(
          (task: { resource: string; id: string; kind?: string }) =>
            collectMarket(conn!, cycle, task),
        ),
      );
      await cycle.wait(() =>
        conn!.reducers.collectorHeartbeat({ epoch, error: "" }),
      );
      diagnostics.phase("api-refresh");
      {
        for (const request of seeds
          .filter((row) => Date.now() >= (due.get(keyFor(row)) ?? 0))
          .filter((row) => !native?.owns(row.resource, row.entityId))
          .slice(0, 2)) {
          if (!conn.isActive || stopping) break;
          await cycle.wait(() =>
            conn!.reducers.collectorHeartbeat({ epoch, error: "" }),
          );
          await collect(conn, cycle, request, true);
        }
      }
      const watches = [...conn.db.collectorWatches.iter()].filter(
        (row) =>
          ![
            "market",
            "itemOrders",
            "cargoOrders",
            "claimListings",
            "itemPriceHistory",
            "cargoPriceHistory",
          ].includes(row.resource) &&
          Number(row.expiresAt / 1000n) > Date.now() &&
          Date.now() >= (due.get(row.key) ?? 0),
      );
      // Small bounded batches keep HTTP calls within the shared database budget.
      for (let i = 0; i < Math.min(watches.length, 2); i += 2) {
        if (!conn.isActive || stopping) break;
        await Promise.all(
          watches
            .slice(i, i + 2)
            .filter((row) => !native?.owns(row.resource, row.entityId))
            .map((request) => collect(conn!, cycle, request)),
        );
        await cycle.wait(() =>
          conn!.reducers.collectorHeartbeat({ epoch, error: "" }),
        );
      }
      if (once && seeds.every((row) => due.has(keyFor(row)))) {
        stopping = true;
        break;
      }
      diagnostics.phase("idle");
      await cycle.wait(() => sleep(1000));
    }
  } catch (error) {
    diagnostics.event("cycle-error", error);
    diagnostics.sample();
    console.error(`Collector reconnecting: ${(error as Error).message}`);
    if (once || !storageReady) {
      stopping = true;
      process.exitCode = 1;
    }
  } finally {
    diagnostics.phase("cycle-ended");
    if (monitor) clearInterval(monitor);
    cycle.stop("Collection cycle ended.");
    native?.stop();
    conn?.disconnect();
    // A size check already in progress must finish before resetting/reopening
    // storage, so an old cycle cannot renew the lease during the rebuild.
    await monitorWork;
    activeCycle = undefined;
  }
  if (!stopping) await sleep(5000);
}
