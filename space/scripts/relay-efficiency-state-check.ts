// Private backup and exact comparison of application records during publication.
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { createHash } from "node:crypto";
import { ownerCall, canonicalState } from "./bitcraft-storage";
const phase = process.argv[2];
if (!["before", "after"].includes(phase ?? ""))
  throw Error("Choose before or after.");
await mkdir(".runtime/backups", { recursive: true });
const stable = [
  "administrator",
  "guide",
  "guide_metadata",
  "guide_administrator",
  "widget_profile",
  "provider_settings",
];
for (const database of ["space-bitcraft-tools", "space-bitcraft-checks"]) {
  const path = `.runtime/backups/${database}-relay-efficiency-before.json`;
  const payload = (await ownerCall(database, "export_app_state", [])) as string;
  const state = JSON.parse(payload);
  if (phase === "before") {
    await writeFile(path, payload, { flag: "wx", mode: 0o600 });
    await writeFile(
      path + ".sha256",
      createHash("sha256").update(payload).digest("hex"),
      { flag: "wx", mode: 0o600 },
    );
  } else {
    const before = await readFile(path, "utf8");
    if (
      createHash("sha256").update(before).digest("hex") !==
      (await readFile(path + ".sha256", "utf8")).trim()
    )
      throw Error("Application backup hash mismatch.");
    const expected = JSON.parse(before);
    const select = (value: any) =>
      Object.fromEntries(stable.map((table) => [table, value[table]]));
    if (canonicalState(select(expected)) !== canonicalState(select(state)))
      throw Error(
        "Application records changed unexpectedly during publication.",
      );
  }
  console.log(
    JSON.stringify({
      database,
      phase,
      verified: true,
      guides: state.guide.length,
      widgets: state.widget_profile.length,
      xpSamples: state.collection_sample.length,
    }),
  );
}
