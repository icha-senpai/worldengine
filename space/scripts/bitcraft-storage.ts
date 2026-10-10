import { marketCheckpointTables } from "../bitcraft/src/market-checkpoint";
import { archiveDiagnosticFile } from "./diagnostic-archives";
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import {
  mkdir,
  readFile,
  writeFile,
  rename,
  readdir,
  stat,
  statfs,
  rm,
  open,
} from "node:fs/promises";
import { resolve, join, relative, isAbsolute } from "node:path";
import { parseRelayJson } from "./bitcraft-relay";

export const STORAGE_TARGET = 2_000_000_000;
export const STORAGE_BUDGET = 3_000_000_000;
const managedTables = new Set([
  "administrator",
  "budget",
  "collected_entity",
  "collection_feed",
  "collection_sample",
  "collection_status",
  "collection_watch",
  "collector_identity",
  "guide",
  "guide_administrator",
  "guide_metadata",
  "market_item",
  "market_meta",
  "market_status",
  "market_summary",
  "provider_budget",
  "provider_settings",
  "relay_book",
  "relay_region",
  "relay_stall",
  "relay_status",
  "snapshot",
  "refresh_state",
  "trade_record",
  "trade_reference",
  "storage_state",
  "widget_profile",
  "collector_watches",
  "editable_guides",
  "published_guides",
  "visible_guide_metadata",
]);
const server = "http://127.0.0.1:3100";
const cli =
  process.env.SPACETIME_CLI ||
  resolve(
    process.env.LOCALAPPDATA ?? ".",
    "SpacetimeDB/bin/current/spacetimedb-cli.exe",
  );
const replicas = resolve(".runtime/data/replicas");
const maintenance = resolve(".runtime/bitcraft-storage");
type Binding = {
  database: string;
  identity: string;
  replica: string;
  bundleHash: string;
  program: string;
  pending?: {
    backup: string;
    previous: string;
    next?: string;
    before?: string[];
  };
};
type State = Record<string, any>;

export function safeReplicaPath(replica: string) {
  if (!/^\d+$/.test(replica)) throw Error("Invalid replica directory.");
  const path = resolve(replicas, replica),
    rel = relative(replicas, path);
  if (!rel || rel.startsWith("..") || isAbsolute(rel))
    throw Error("Replica path escapes the storage directory.");
  return path;
}
export async function replicaIdentity(replica: string) {
  const snapshots = join(safeReplicaPath(replica), "snapshots"),
    deadline = Date.now() + 10000;
  // Snapshot completion is asynchronous after publication. Use any completed
  // generation from this replica; never read a partially written newest file.
  while (Date.now() < deadline) {
    const generations = (await readdir(snapshots))
      .filter((name) => /^\d+\.snapshot_dir$/.test(name))
      .sort()
      .reverse();
    for (const generation of generations) {
      const directory = join(snapshots, generation);
      const name = (await readdir(directory)).find((name) =>
        /^\d+\.snapshot_bsatn$/.test(name),
      );
      if (!name) continue;
      const file = await open(join(directory, name), "r"),
        header = Buffer.alloc(97);
      try {
        const result = await file.read(header, 0, header.length, 0);
        // v0 Snapshot BSATN in 2.10.2: hash, byte-array magic, version,
        // Identity, replica ID, ABI, transaction offset.
        if (result.bytesRead !== header.length) continue;
        if (
          header.readUInt32LE(32) !== 4 ||
          header.subarray(36, 40).toString() !== "txyz" ||
          header[40] !== 0 ||
          header.readBigUInt64LE(73) !== BigInt(replica)
        )
          throw Error(
            "Unrecognized replica snapshot format; writes remain paused.",
          );
        return Buffer.from(header.subarray(41, 73)).reverse().toString("hex");
      } finally {
        await file.close();
      }
    }
    await new Promise((done) => setTimeout(done, 200));
  }
  throw Error("Replica snapshot metadata is incomplete; writes remain paused.");
}
export async function directoryBytes(
  path: string,
  linkedFiles = new Set<string>(),
): Promise<number> {
  let total = 0;
  for (const entry of await readdir(path, { withFileTypes: true })) {
    if (entry.isSymbolicLink()) throw Error("Unexpected linked storage path.");
    const child = join(path, entry.name);
    try {
      if (entry.isDirectory())
        total += await directoryBytes(child, linkedFiles);
      else {
        const info = await stat(child, { bigint: true });
        // Snapshot pages are hardlinked across generations. Count a shared file
        // once while retaining a conservative file-size estimate for sparse files.
        if (info.nlink > 1n && info.ino !== 0n) {
          const identity = `${info.dev}:${info.ino}`;
          if (linkedFiles.has(identity)) continue;
          linkedFiles.add(identity);
        }
        total += Number(info.size);
      }
    } catch (error: any) {
      if (error.code !== "ENOENT") throw error;
    }
  }
  return total;
}
export async function trimCollectorLogs(serviceName = "collector") {
  if (!/^[a-zA-Z0-9-]+$/.test(serviceName))
    throw Error("Invalid service name.");
  const directory = resolve(".runtime/services");
  const diagnosticDirectory = resolve(
    ".runtime/diagnostics",
    serviceName === "collector"
      ? "space-bitcraft-tools"
      : serviceName === "collector-test"
        ? "space-bitcraft-checks"
        : serviceName.replace(/^collector-/, ""),
  );
  await mkdir(diagnosticDirectory, { recursive: true });
  const names = (await readdir(directory))
    .filter((name) =>
      new RegExp(`^${serviceName}-\\d{8}-\\d{6}-\\d{3}(?:\\.err)?\\.log$`).test(
        name,
      ),
    )
    .sort()
    .reverse();
  let bytes = 0;
  for (const [index, name] of names.entries()) {
    const path = join(directory, name);
    if (index >= 16) {
      await archiveDiagnosticFile(path, diagnosticDirectory);
      continue;
    }
    const size = (await stat(path)).size;
    // Called only before launching a worker; these redirected logs are closed.
    if (size > 1_000_000)
      await archiveDiagnosticFile(path, diagnosticDirectory);
    else bytes += size;
  }
  return bytes;
}
async function atomicJson(path: string, value: unknown) {
  await writeFile(path + ".tmp", JSON.stringify(value), { mode: 0o600 });
  await rename(path + ".tmp", path);
}
export async function ownerToken() {
  const config = await readFile(
    resolve(process.env.LOCALAPPDATA ?? ".", "SpacetimeDB/config/cli.toml"),
    "utf8",
  );
  const raw = config.match(
    /^spacetimedb_token\s*=\s*("(?:[^"\\]|\\.)*")/m,
  )?.[1];
  if (!raw)
    throw Error(
      "Local database owner credentials are unavailable; writes remain paused.",
    );
  return JSON.parse(raw) as string;
}
export async function ownerCall(
  database: string,
  name: string,
  args: unknown[],
  token?: string,
) {
  const response = await fetch(
    `${server}/v1/database/${database}/call/${name}`,
    {
      method: "POST",
      headers: {
        Authorization: `Bearer ${token ?? (await ownerToken())}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify(args),
      signal: AbortSignal.timeout(60000),
    },
  );
  if (!response.ok)
    throw Error(
      `Owner operation ${name} failed (${response.status}); writes remain paused.`,
    );
  const body = await response.text();
  return body ? parseRelayJson(body) : undefined;
}
async function databaseInfo(database: string) {
  const response = await fetch(`${server}/v1/database/${database}`, {
    signal: AbortSignal.timeout(10000),
  });
  if (!response.ok) throw Error("Cannot verify the database identity.");
  const data = (await response.json()) as any;
  return {
    identity: String(data.database_identity.__identity__).replace(/^0x/, ""),
    program: String(data.initial_program),
  };
}
export function canonicalState(state: State) {
  // Table iteration order can change after restoration. Compare every retained
  // field, including private ownership and provider configuration.
  return JSON.stringify(
    Object.fromEntries(
      Object.keys(state)
        .sort()
        .map((key) => [
          key,
          Array.isArray(state[key])
            ? state[key]
                .map((row: State) =>
                  Object.fromEntries(
                    Object.keys(row)
                      .sort()
                      .map((field) => [field, row[field]]),
                  ),
                )
                .sort((a: State, b: State) =>
                  JSON.stringify(a).localeCompare(JSON.stringify(b)),
                )
            : state[key],
        ]),
    ),
  );
}
export async function exportState(database: string) {
  const payload = await ownerCall(database, "export_app_state", []);
  if (typeof payload !== "string")
    throw Error("Invalid state export response.");
  const state = parseRelayJson(payload) as State;
  let bytes = Buffer.byteLength(payload);
  for (const table of Object.keys(marketCheckpointTables)) {
    state[table] = [];
    let after = "";
    while (true) {
      const page = parseRelayJson(
        (await ownerCall(database, "export_market_checkpoint", [
          table,
          after,
        ])) as string,
      );
      bytes += Buffer.byteLength(JSON.stringify(page.rows));
      if (bytes > 250000000 || state[table].length + page.rows.length > 250000)
        throw Error(
          "Latest market checkpoint exceeds its budget; database has not been reset.",
        );
      state[table].push(...page.rows);
      if (page.done) break;
      if (!page.next || page.next <= after)
        throw Error("Checkpoint pagination did not advance.");
      after = page.next;
    }
  }
  const apiScopes = new Set(
    state.market_item
      .map((row: any) => (row.book ? JSON.parse(row.book)._orderScope : null))
      .filter(Boolean),
  );
  state.trade_record = state.trade_record.filter(
    (row: any) => !row.scope.startsWith("api:") || apiScopes.has(row.scope),
  );
  const references = new Set(
    state.trade_record.flatMap((row: any) => {
      const order = JSON.parse(row.payload).order;
      return order ? [order._claimRef, order._ownerRef].filter(Boolean) : [];
    }),
  );
  state.trade_reference = state.trade_reference.filter((row: any) =>
    references.has(row.key),
  );
  return state;
}
async function restoreStorageState(database: string, state: State) {
  const app = { ...state };
  for (const table of Object.keys(marketCheckpointTables)) delete app[table];
  await ownerCall(database, "restore_app_state", [JSON.stringify(app)]);
  for (const table of Object.keys(marketCheckpointTables)) {
    let chunk: any[] = [],
      size = 0;
    const flush = async () => {
      if (chunk.length)
        await ownerCall(database, "restore_market_checkpoint", [
          table,
          JSON.stringify(chunk),
        ]);
      chunk = [];
      size = 0;
    };
    for (const row of state[table] ?? []) {
      const length = JSON.stringify(row).length;
      if (chunk.length && (size + length > 1000000 || chunk.length >= 2000))
        await flush();
      chunk.push(row);
      size += length;
    }
    await flush();
  }
}
export async function readBinding(
  database: string,
  allowPending = false,
): Promise<Binding> {
  if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(database))
    throw Error("Invalid database name.");
  const binding = JSON.parse(
    await readFile(join(maintenance, database + ".json"), "utf8"),
  ) as Binding;
  if (
    binding.database !== database ||
    binding.identity !== (await databaseInfo(database)).identity
  )
    throw Error(
      "The storage binding does not match the database. Writes remain paused.",
    );
  safeReplicaPath(binding.replica);
  if (
    !binding.pending &&
    (await replicaIdentity(binding.replica)) !== binding.identity
  )
    throw Error(
      "The bound replica belongs to a different database; writes remain paused.",
    );
  if (binding.pending && !allowPending)
    throw Error(
      "An unfinished storage rebuild requires recovery; writes remain paused.",
    );
  return binding;
}
export async function registerBinding(database: string, replica: string) {
  safeReplicaPath(replica);
  await mkdir(maintenance, { recursive: true });
  const info = await databaseInfo(database),
    bundle = await readFile(resolve("bitcraft/dist/bundle.js"));
  if ((await replicaIdentity(replica)) !== info.identity)
    throw Error("The supplied replica belongs to a different database.");
  const binding = {
    database,
    identity: info.identity,
    program: info.program,
    replica,
    bundleHash: createHash("sha256").update(bundle).digest("hex"),
  };
  await writeFile(join(maintenance, database + ".bundle.js"), bundle, {
    flag: "wx",
    mode: 0o600,
  });
  await writeFile(
    join(maintenance, database + ".json"),
    JSON.stringify(binding),
    { flag: "wx", mode: 0o600 },
  );
  return binding;
}
export async function disposeStorageTest(binding: Binding) {
  if (
    !/^space-(storage|market|relay)-checks-\d+$/.test(binding.database) ||
    binding.pending
  )
    throw Error("Only completed temporary storage checks can be disposed.");
  if (
    binding.identity !== (await databaseInfo(binding.database)).identity ||
    (await replicaIdentity(binding.replica)) !== binding.identity
  )
    throw Error("Temporary database and replica identities do not match.");
  execFileSync(
    cli,
    ["delete", binding.database, "--server", server, "--yes", "--no-config"],
    { stdio: "pipe", windowsHide: true },
  );
  await rm(safeReplicaPath(binding.replica), {
    recursive: true,
    force: false,
    maxRetries: 20,
    retryDelay: 500,
  });
  for (const name of await readdir(maintenance)) {
    if (
      name === binding.database + ".json" ||
      name === binding.database + ".bundle.js" ||
      new RegExp(`^${binding.database}-\\d+\\.json(?:\\.sha256)?$`).test(name)
    )
      await rm(join(maintenance, name));
  }
}
export async function registerTemporaryDatabase(
  database: string,
  before: Set<string>,
) {
  if (!/^space-(storage|market|relay)-checks-\d+$/.test(database))
    throw Error("Only temporary check databases can be registered here.");
  const identity = (await databaseInfo(database)).identity;
  const matches: string[] = [];
  for (const name of (await readdir(replicas)).filter(
    (name) => !before.has(name),
  )) {
    try {
      if ((await replicaIdentity(name)) === identity) matches.push(name);
    } catch {
      /* Another database may be rebuilding concurrently. */
    }
  }
  if (matches.length !== 1)
    throw Error("Cannot identify exactly one temporary replica.");
  return registerBinding(database, matches[0]!);
}
export async function sealPublishedModule(database: string) {
  const path = join(maintenance, database + ".json");
  let binding: Binding;
  try {
    binding = JSON.parse(await readFile(path, "utf8"));
  } catch (error: any) {
    if (error.code === "ENOENT") return;
    throw error;
  }
  if (
    binding.pending ||
    binding.identity !== (await databaseInfo(database)).identity
  )
    throw Error("Cannot seal a mismatched or unfinished storage rebuild.");
  const bundle = await readFile(resolve("bitcraft/dist/bundle.js"));
  await writeFile(join(maintenance, database + ".bundle.js.tmp"), bundle, {
    mode: 0o600,
  });
  await rename(
    join(maintenance, database + ".bundle.js.tmp"),
    join(maintenance, database + ".bundle.js"),
  );
  binding.bundleHash = createHash("sha256").update(bundle).digest("hex");
  binding.program = (await databaseInfo(database)).program;
  await atomicJson(path, binding);
}
export async function retainedStorageBytes(
  database: string,
  root = maintenance,
) {
  if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(database))
    throw Error("Invalid database name.");
  const names = new RegExp(
    `^${database}(?:\\.bundle\\.js(?:\\.tmp)?|\\.json(?:\\.tmp)?|-\\d+\\.json(?:\\.sha256)?(?:\\.tmp)?)$`,
  );
  let bytes = 0;
  for (const entry of await readdir(root, { withFileTypes: true })) {
    if (!names.test(entry.name)) continue;
    if (!entry.isFile()) throw Error("Unexpected linked maintenance file.");
    bytes += (await stat(join(root, entry.name))).size;
  }
  return bytes;
}
export async function storageUsage(binding: Binding) {
  const activeBytes = await directoryBytes(safeReplicaPath(binding.replica));
  const retainedBytes = await retainedStorageBytes(binding.database);
  const checkpointBytes = await directoryBytes(
    resolve(".runtime/collector", binding.database),
  ).catch((error) => {
    if (error.code === "ENOENT") return 0;
    throw error;
  });
  const disk = await statfs(replicas);
  const diagnosticDirectory = resolve(".runtime/diagnostics", binding.database);
  // Diagnostics rotate concurrently; a file disappearing between listing and
  // stat is normal. Avoid turning a log rotation into a storage-guard shutdown.
  const diagnosticBytes = await (async () => {
    let bytes = 0;
    for (const entry of await readdir(diagnosticDirectory, {
      withFileTypes: true,
    })) {
      if (!entry.isFile() || entry.isSymbolicLink())
        throw Error("Unexpected linked diagnostic path.");
      try {
        bytes += (await stat(join(diagnosticDirectory, entry.name))).size;
      } catch (error: any) {
        if (error.code !== "ENOENT") throw error;
      }
    }
    return bytes;
  })().catch((error) => {
    if (error.code === "ENOENT") return 0;
    throw error;
  });
  return {
    activeBytes,
    retainedBytes,
    checkpointBytes,
    diagnosticBytes,
    bytes: activeBytes + retainedBytes + checkpointBytes + diagnosticBytes,
    freeBytes: disk.bavail * disk.bsize,
    targetBytes: STORAGE_TARGET,
    budgetBytes: STORAGE_BUDGET,
  };
}
export async function rebuildStorage(
  binding: Binding,
  beforeRetirement?: () => void,
) {
  await ownerCall(binding.database, "storage_lease", [0]);
  const response = await fetch(
    `${server}/v1/database/${binding.database}/sql`,
    {
      method: "POST",
      headers: {
        Authorization: `Bearer ${await ownerToken()}`,
        "Content-Type": "text/plain",
      },
      body: "SELECT table_name FROM st_table",
      signal: AbortSignal.timeout(10000),
    },
  );
  if (!response.ok)
    throw Error(
      "Cannot verify the retention policy; database has not been reset.",
    );
  const schema = parseRelayJson(await response.text());
  if (
    schema[0].rows.some(
      ([name]: [string]) => !name.startsWith("st_") && !managedTables.has(name),
    )
  )
    throw Error(
      "A new table needs a retention policy; database has not been reset.",
    );
  const state = await exportState(binding.database);
  const backup = join(maintenance, `${binding.database}-${Date.now()}.json`);
  const payload = JSON.stringify(state),
    hash = createHash("sha256").update(payload).digest("hex");
  await writeFile(backup, payload, { flag: "wx", mode: 0o600 });
  await writeFile(backup + ".sha256", hash, { flag: "wx", mode: 0o600 });
  if (
    createHash("sha256")
      .update(await readFile(backup))
      .digest("hex") !== hash
  )
    throw Error(
      "State backup verification failed; database has not been reset.",
    );
  // Seal the exact deployed artifact before any destructive operation.
  const bundle = join(maintenance, binding.database + ".bundle.js");
  if (
    createHash("sha256")
      .update(await readFile(bundle))
      .digest("hex") !== binding.bundleHash ||
    (await databaseInfo(binding.database)).program !== binding.program
  )
    throw Error(
      "The sealed module does not match the deployed module; database has not been reset.",
    );
  const before = new Set(await readdir(replicas));
  const path = join(maintenance, binding.database + ".json");
  binding.pending = { backup, previous: binding.replica, before: [...before] };
  await atomicJson(path, binding);
  execFileSync(
    cli,
    [
      "publish",
      binding.database,
      "--js-path",
      bundle,
      "--delete-data=always",
      "--server",
      server,
      "--yes",
      "--no-config",
    ],
    { stdio: "pipe", windowsHide: true, timeout: 60000 },
  );
  if (binding.identity !== (await databaseInfo(binding.database)).identity)
    throw Error(
      "Database identity changed unexpectedly; writes remain paused.",
    );
  const created: string[] = [];
  for (const name of (await readdir(replicas)).filter(
    (name) => !before.has(name),
  ))
    if ((await replicaIdentity(name)) === binding.identity) created.push(name);
  if (created.length !== 1)
    throw Error(
      "Cannot safely identify the replacement replica; writes remain paused.",
    );
  binding.pending.next = created[0]!;
  safeReplicaPath(binding.pending.next);
  if ((await replicaIdentity(binding.pending.next)) !== binding.identity)
    throw Error(
      "The replacement replica identity does not match; old storage has been preserved.",
    );
  await atomicJson(path, binding);
  await restoreStorageState(binding.database, state);
  if (
    canonicalState(state) !==
    canonicalState(await exportState(binding.database))
  )
    throw Error(
      "Restored records do not match the verified backup; old storage has been preserved.",
    );
  beforeRetirement?.();
  // The supported reset detached this exact old replica. Reclaim its files only
  // after every retained record has been checked. Never delete individual WALs.
  await rm(safeReplicaPath(binding.pending.previous), {
    recursive: true,
    force: false,
    maxRetries: 20,
    retryDelay: 500,
  });
  binding.replica = binding.pending.next;
  delete binding.pending;
  binding.program = (await databaseInfo(binding.database)).program;
  await atomicJson(path, binding);
  const archives = (await readdir(maintenance))
    .filter(
      (name) =>
        name.startsWith(binding.database + "-") && name.endsWith(".json"),
    )
    .sort()
    .reverse();
  for (const name of archives.slice(2)) {
    await rm(join(maintenance, name));
    await rm(join(maintenance, name + ".sha256"));
  }
  const usage = await storageUsage(binding);
  if (usage.bytes >= STORAGE_TARGET)
    throw Error("Rebuilt storage exceeds its budget; writes remain paused.");
  console.log(
    `Storage rebuilt and verified: ${Math.round(usage.bytes / 1_000_000)} MB. Latest market data retained; relays will reconcile changes.`,
  );
  return binding;
}

export async function recoverStorage(binding: Binding) {
  const pending = binding.pending;
  if (!pending) throw Error("This database has no pending storage rebuild.");
  const backup = resolve(pending.backup),
    rel = relative(maintenance, backup);
  if (rel !== `${binding.database}-${rel.match(/-(\d+)\.json$/)?.[1]}.json`)
    throw Error("The recorded backup path is invalid.");
  const payload = await readFile(backup, "utf8");
  if (
    createHash("sha256").update(payload).digest("hex") !==
    (await readFile(backup + ".sha256", "utf8")).trim()
  )
    throw Error("Recovery backup verification failed.");
  await ownerCall(binding.database, "storage_lease", [0]);
  if (!pending.next) {
    if (!pending.before)
      throw Error("Replacement replica must be identified before recovery.");
    const created: string[] = [];
    for (const name of (await readdir(replicas)).filter(
      (name) => !pending.before!.includes(name),
    ))
      if ((await replicaIdentity(name)) === binding.identity)
        created.push(name);
    if (created.length !== 1)
      throw Error(
        "Cannot identify one replacement replica; old storage has been preserved.",
      );
    pending.next = created[0]!;
    await atomicJson(join(maintenance, binding.database + ".json"), binding);
  }
  if ((await replicaIdentity(pending.next)) !== binding.identity)
    throw Error("Replacement replica identity does not match.");
  const expected = parseRelayJson(payload);
  await restoreStorageState(binding.database, expected);
  for (const table of Object.keys(marketCheckpointTables))
    expected[table] ??= [];
  if (expected.version === 2) {
    expected.version = 3;
    expected.collection_watch ??= [];
    expected.collection_feed ??= [];
  }
  if (
    canonicalState(expected) !==
    canonicalState(await exportState(binding.database))
  )
    throw Error(
      "Recovery did not reproduce the retained records; old storage has been preserved.",
    );
  const old = safeReplicaPath(pending.previous);
  if (
    await stat(old).then(
      () => true,
      (error) => {
        if (error.code === "ENOENT") return false;
        throw error;
      },
    )
  )
    await rm(old, {
      recursive: true,
      force: false,
      maxRetries: 20,
      retryDelay: 500,
    });
  binding.replica = pending.next;
  delete binding.pending;
  binding.program = (await databaseInfo(binding.database)).program;
  await atomicJson(join(maintenance, binding.database + ".json"), binding);
  if ((await storageUsage(binding)).bytes >= STORAGE_TARGET)
    throw Error(
      "Recovered storage still exceeds its budget; updates remain paused.",
    );
  console.log(
    "Retained records verified and pending storage rebuild recovered. Restart collection to resume updates.",
  );
  return binding;
}

if (
  process.argv[1] &&
  resolve(process.argv[1]) === resolve("scripts/bitcraft-storage.ts")
) {
  const command = process.argv[2],
    database = process.argv[3] || "space-bitcraft-tools";
  let binding =
    command === "register"
      ? await registerBinding(database, process.argv[4] ?? "")
      : await readBinding(database, command === "recover");
  if (command === "rebuild") binding = await rebuildStorage(binding);
  if (command === "recover") binding = await recoverStorage(binding);
  if (!["register", "status", "rebuild", "recover"].includes(command ?? ""))
    throw Error("Choose register, status, rebuild, or recover.");
  console.log(await storageUsage(binding));
}
