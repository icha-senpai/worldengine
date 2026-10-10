import { readdir, rm, mkdir } from "node:fs/promises";
import { resolve, join } from "node:path";
import {
  archiveDiagnosticFile,
  pruneDiagnosticArchives,
} from "./diagnostic-archives";
import { trimCollectorLogs } from "./bitcraft-storage";
const database = process.argv[2];
if (!["space-bitcraft-tools", "space-bitcraft-checks"].includes(database ?? ""))
  throw Error("Invalid collector archive target.");
const directory = resolve(".runtime/diagnostics", database!);
await mkdir(directory, { recursive: true });
// Runs only before a replacement worker starts; abandoned partial outputs are safe to remove.
for (const name of await readdir(directory)) {
  if (/^archive-\d+-.*\.gz\.tmp$/.test(name)) await rm(join(directory, name));
  else if (/^pending-\d+-/.test(name))
    await archiveDiagnosticFile(join(directory, name), directory);
}
await pruneDiagnosticArchives(directory);
await trimCollectorLogs(
  database === "space-bitcraft-tools" ? "collector" : "collector-test",
);
