import { writeFile } from "node:fs/promises";
import { join } from "node:path";
import {
  readBinding,
  storageUsage,
  directoryBytes,
  safeReplicaPath,
} from "./bitcraft-storage";
const database = "space-bitcraft-tools";
const duration = Number(process.argv[2] ?? 360);
if (!Number.isFinite(duration) || duration < 30 || duration > 900)
  throw Error("Choose a sample duration between 30 and 900 seconds.");
const label = process.argv[3] ?? "transition";
if (!["steady", "transition"].includes(label)) throw Error("Choose steady or transition.");
const samples = [];
const deadline = Date.now() + duration * 1000;
do {
  const binding = await readBinding(database);
  samples.push({
    at: Date.now(),
    replica: binding.replica,
    commitlogBytes: await directoryBytes(
      join(safeReplicaPath(binding.replica), "clog"),
    ),
    managedBytes: (await storageUsage(binding)).bytes,
  });
  if (Date.now() >= deadline) break;
  await new Promise((done) =>
    setTimeout(done, Math.min(15000, deadline - Date.now())),
  );
} while (true);
const first = samples[0]!,
  last = samples[samples.length - 1]!;
const sameReplica = samples.every((sample) => sample.replica === first.replica);
const result = {
  database,
  sampledAt: new Date().toISOString(),
  seconds: (last.at - first.at) / 1000,
  sameReplica,
  commitlogGrowthBytes: sameReplica
    ? last.commitlogBytes - first.commitlogBytes
    : null,
  note: "Live interval including normal game activity; this is not a matched-activity before/after benchmark.",
  samples,
};
await writeFile(
  `output/relay-growth-${label}-after-efficiency-20261008.json`,
  JSON.stringify(result, null, 2) + "\n",
);
console.log(JSON.stringify(result, null, 2));
