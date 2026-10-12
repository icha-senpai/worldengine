import { createHash } from "node:crypto";
import { readFile, writeFile } from "node:fs/promises";
import { join } from "node:path";

export const backupHash = (bytes: string | Buffer) =>
  createHash("sha256").update(bytes).digest("hex");

export async function saveSqlBackup(
  directory: string,
  table: string,
  raw: string,
) {
  if (!/^[a-z][a-z0-9_]*$/.test(table))
    throw Error("Unsafe backup table name.");
  const parsed = JSON.parse(raw);
  if (
    !Array.isArray(parsed) ||
    parsed.length !== 1 ||
    !Array.isArray(parsed[0]?.rows)
  )
    throw Error("Expected a single SQL table result.");
  // Preserve the CLI bytes, including large integer values and original formatting.
  const path = join(directory, `${table}.json`);
  await writeFile(path, raw, { flag: "wx", mode: 0o600 });
  const sha256 = backupHash(raw);
  if (backupHash(await readFile(path)) !== sha256)
    throw Error("Backup verification failed.");
  return { rows: parsed[0].rows.length, sha256 };
}
