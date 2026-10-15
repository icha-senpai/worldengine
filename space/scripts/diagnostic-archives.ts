import { lstat, readdir, rm, stat } from "node:fs/promises";
import { join } from "node:path";

export const ARCHIVE_BUDGET = 0;
export const ARCHIVE_COUNT = 0;
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
  // Boss opts out of diagnostic history. Callers pass closed, retired segments;
  // active output stays in the current bounded files and must never be removed.
  if (removeSource) await rm(source);
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
      console.error(
        "Retired diagnostic cleanup failed; pending source preserved.",
      );
    });
  return archiveQueue;
}
export const waitForDiagnosticArchives = () => archiveQueue;
