// Run after provisioning and stopping the managed main collector. The Linux
// updater performs publication and startup; this only selects its verified target.
import { readFile, writeFile, rename, lstat } from "node:fs/promises";
import { resolve } from "node:path";
try {
  const collector = JSON.parse(
    await readFile(".runtime/services/collector.ready.json", "utf8"),
  );
  if (collector.database !== "space-bitcraft-release") {
    try {
      process.kill(collector.pid, 0);
      throw Error("Stop the managed main collector before switching worlds.");
    } catch (error) {
      if (error.code !== "ESRCH") throw error;
    }
  }
} catch (error) {
  if (error.code !== "ENOENT") throw error;
}
const ready = JSON.parse(
  await readFile(".runtime/bitcraft-release-ready.json", "utf8"),
);
if (
  ready.database !== "space-bitcraft-release" ||
  ready.source !== "space-bitcraft-tools" ||
  ready.world !== "release" ||
  !/^[a-f0-9]{64}$/.test(ready.identity)
)
  throw Error("Invalid release provisioning result.");
const server = process.env.SPACE_SERVER_URL || "http://127.0.0.1:3100";
const info = await fetch(`${server}/v1/database/${ready.database}`).then(
  (r) => {
    if (!r.ok) throw Error("Release database unavailable.");
    return r.json();
  },
);
if (info.database_identity.__identity__.replace(/^0x/, "") !== ready.identity)
  throw Error("Release identity mismatch.");
const files = new Map();
const edit = async (name, key, value) => {
  let text = "";
  try {
    if ((await lstat(name)).isSymbolicLink())
      throw Error("Linked configuration refused.");
    text = await readFile(name, "utf8");
  } catch (error) {
    if (error.code !== "ENOENT") throw error;
  }
  const expression = new RegExp(`^${key}=.*$`, "m");
  files.set(
    name,
    expression.test(text)
      ? text.replace(expression, `${key}=${value}`)
      : `${text.trimEnd()}\n${key}=${value}\n`,
  );
};
if (process.platform === "win32")
  await edit(".env.local", "VITE_BITCRAFT_DATABASE", ready.database);
else {
  await edit(
    ".runtime/production-web.env",
    "VITE_BITCRAFT_DATABASE",
    ready.database,
  );
  await edit(
    ".runtime/production-collector.env",
    "BITCRAFT_COLLECTOR_DATABASE",
    ready.database,
  );
}
const main = resolve(".runtime/bitcraft-main.json");
try {
  if ((await lstat(main)).isSymbolicLink())
    throw Error("Linked main binding refused.");
} catch (error) {
  if (error.code !== "ENOENT") throw error;
}
files.set(main, JSON.stringify(ready) + "\n");
for (const [name, content] of files) {
  await writeFile(name + ".tmp", content, { mode: 0o600 });
  await rename(name + ".tmp", name);
}
console.log(
  "Verified release-world target selected. Run the managed publisher/updater to activate services.",
);
