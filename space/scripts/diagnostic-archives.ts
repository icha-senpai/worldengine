import { createReadStream, createWriteStream } from "node:fs";
import { lstat, readdir, rename, rm, stat, writeFile } from "node:fs/promises";
import { basename, join } from "node:path";
import { createHash, randomUUID } from "node:crypto";
import { Transform } from "node:stream";
import { pipeline } from "node:stream/promises";
import { createGzip, createGunzip, constants } from "node:zlib";

export const ARCHIVE_BUDGET = 250_000_000;
export const ARCHIVE_COUNT = 100;
const archiveLocks = new Map<string, Promise<unknown>>();
export function archiveDiagnosticFile(
  source: string,
  directory: string,
  removeSource = true,
) {
  const previous = archiveLocks.get(directory) ?? Promise.resolve();
  const operation = previous
    .catch(() => {})
    .then(() => archiveFile(source, directory, removeSource));
  archiveLocks.set(directory, operation);
  return operation;
}
async function archiveFile(
  source: string,
  directory: string,
  removeSource = true,
) {
  const info = await lstat(source);
  if (!info.isFile() || info.isSymbolicLink())
    throw Error("Diagnostic archive source must be a regular file.");
  if (!info.size) {
    if (removeSource) await rm(source);
    return;
  }
  const name = `archive-${Date.now()}-${randomUUID()}-${basename(source).replace(/[^a-zA-Z0-9_.-]/g, "_")}.gz`;
  const target = join(directory, name),
    temporary = target + ".tmp";
  const inputHash = createHash("sha256");
  let bytes = 0;
  try {
    // Stream a fixed prefix even for multi-GB files; never load the source into RAM.
    await pipeline(
      createReadStream(source, { start: 0, end: info.size - 1 }),
      new Transform({
        transform(chunk, _encoding, done) {
          inputHash.update(chunk);
          bytes += chunk.length;
          done(null, chunk);
        },
      }),
      createGzip({ level: constants.Z_BEST_COMPRESSION }),
      createWriteStream(temporary, { flags: "wx", mode: 0o600 }),
    );
    const expected = inputHash.digest("hex"),
      actual = createHash("sha256");
    let restoredBytes = 0;
    await pipeline(
      createReadStream(temporary),
      createGunzip(),
      new Transform({
        transform(chunk, _encoding, done) {
          actual.update(chunk);
          restoredBytes += chunk.length;
          done();
        },
      }),
    );
    if (
      bytes !== info.size ||
      restoredBytes !== bytes ||
      actual.digest("hex") !== expected
    )
      throw Error(
        "Diagnostic archive verification failed; original preserved.",
      );
    if ((await stat(temporary)).size + 4096 > ARCHIVE_BUDGET)
      throw Error(
        "A diagnostic archive exceeds retention budget; original preserved.",
      );
    await rename(temporary, target);
    await writeFile(
      target + ".json",
      JSON.stringify({
        source: basename(source),
        archivedAt: new Date().toISOString(),
        originalBytes: bytes,
        compressedBytes: (await stat(target)).size,
        sha256: expected,
        compression: "gzip-level-9",
      }),
      { flag: "wx", mode: 0o600 },
    );
    if (removeSource) await rm(source);
    await pruneDiagnosticArchives(directory);
    return target;
  } catch (error) {
    await rm(temporary, { force: true });
    throw error;
  }
}
export async function pruneDiagnosticArchives(
  directory: string,
  budget = ARCHIVE_BUDGET,
  count = ARCHIVE_COUNT,
) {
  const names = (await readdir(directory))
    .filter((name) => /^archive-\d+-.*\.gz$/.test(name))
    .sort()
    .reverse();
  let bytes = 0;
  for (const [index, name] of names.entries()) {
    const path = join(directory, name);
    const info = await lstat(path);
    if (!info.isFile() || info.isSymbolicLink())
      throw Error("Unexpected linked diagnostic archive.");
    const size =
      info.size + (await stat(path + ".json").catch(() => ({ size: 0 }))).size;
    if (index >= count || bytes + size > budget) {
      await rm(path);
      await rm(path + ".json", { force: true });
    } else bytes += size;
  }
}
let archiveQueue = Promise.resolve();
export function queueDiagnosticArchive(source: string, directory: string) {
  archiveQueue = archiveQueue
    .then(() => archiveDiagnosticFile(source, directory))
    .then(() => {})
    .catch(() => {
      // Preserve the pending source on failure; measured storage guards still apply.
      console.error("Diagnostic compression failed; pending source preserved.");
    });
  return archiveQueue;
}
export const waitForDiagnosticArchives = () => archiveQueue;
