import { execFileSync } from "node:child_process";
import { mkdtempSync } from "node:fs";
import { lstat, readdir, rm } from "node:fs/promises";
import { join, resolve, sep } from "node:path";
import { replicaIdentity, safeReplicaPath } from "./bitcraft-storage";

const server = "http://127.0.0.1:3100";
const runtime = resolve(".runtime");
const cli = resolve(
  process.env.LOCALAPPDATA!,
  "SpacetimeDB/bin/current/spacetimedb-cli.exe",
);

export function assertTemporaryDatabase(database: string) {
  if (
    !/^space-evergather-(checks|loadtest|catalog-checks|economy-checks|migration-checks)-\d+$/.test(
      database,
    )
  )
    throw Error(
      "Only timestamped temporary Evergather databases can be disposed.",
    );
}

export async function removeOwnedDirectory(path: string, expected: string) {
  const target = resolve(path);
  if (target !== resolve(expected) || !target.startsWith(runtime + sep))
    throw Error("Cleanup target is outside its expected runtime directory.");
  async function check(directory: string): Promise<void> {
    const info = await lstat(directory);
    if (info.isSymbolicLink())
      throw Error("Linked cleanup paths are not permitted.");
    if (!info.isDirectory()) throw Error("Expected a cleanup directory.");
    for (const entry of await readdir(directory, { withFileTypes: true })) {
      if (entry.isSymbolicLink())
        throw Error("Linked cleanup paths are not permitted.");
      if (entry.isDirectory()) await check(join(directory, entry.name));
    }
  }
  await check(target);
  await rm(target, {
    recursive: true,
    force: false,
    maxRetries: 20,
    retryDelay: 500,
  });
}

export async function disposeTemporaryDatabase(
  database: string,
  expectedIdentity?: string,
) {
  assertTemporaryDatabase(database);
  const response = await fetch(`${server}/v1/database/${database}`, {
    signal: AbortSignal.timeout(10000),
  });
  if (response.status === 404) return;
  if (!response.ok)
    throw Error(`Cannot verify temporary database (${response.status}).`);
  const metadata = (await response.json()) as any;
  const identity = String(metadata.database_identity.__identity__).replace(
    /^0x/,
    "",
  );
  if (!/^[a-f0-9]{64}$/.test(identity))
    throw Error("Unexpected database identity.");
  if (expectedIdentity && identity !== expectedIdentity)
    throw Error("Temporary database identity changed; leaving it intact.");
  const replicas: string[] = [];
  for (const replica of await readdir(resolve(".runtime/data/replicas"))) {
    if (!/^\d+$/.test(replica)) throw Error("Unexpected replica directory.");
    try {
      if ((await replicaIdentity(replica)) === identity) replicas.push(replica);
    } catch (error: any) {
      if (error.code !== "ENOENT") throw error;
    }
  }
  if (!replicas.length)
    throw Error(
      "Cannot identify the temporary database replica; leaving it intact.",
    );
  execFileSync(
    cli,
    ["delete", database, "--server", server, "--yes", "--no-config"],
    { stdio: "pipe", windowsHide: true, timeout: 60000 },
  );
  const deleted = await fetch(`${server}/v1/database/${identity}`, {
    signal: AbortSignal.timeout(10000),
  });
  if (deleted.status !== 404)
    throw Error("Database deletion was not verified; replica files preserved.");
  for (const replica of replicas) {
    if ((await replicaIdentity(replica)) !== identity)
      throw Error("Replica ownership changed; files preserved.");
    const path = safeReplicaPath(replica);
    await removeOwnedDirectory(path, path);
  }
}

export async function runTemporaryDatabaseTest(
  database: string,
  run: (scope: { fixture(prefix: string): string }) => Promise<void>,
) {
  assertTemporaryDatabase(database);
  const existing = await fetch(`${server}/v1/database/${database}`, {
    signal: AbortSignal.timeout(10000),
  });
  if (existing.status !== 404)
    throw Error(
      "Test database name must be unused; refusing to adopt an existing database.",
    );
  const fixtures: string[] = [];
  try {
    await run({
      fixture(prefix) {
        if (!/^(economy-checks-module-|legacy-economy-module-)$/.test(prefix))
          throw Error("Unexpected temporary module prefix.");
        const path = mkdtempSync(join(runtime, prefix));
        fixtures.push(path);
        return path;
      },
    });
  } finally {
    await disposeTemporaryDatabase(database);
    for (const path of fixtures) await removeOwnedDirectory(path, path);
  }
}
