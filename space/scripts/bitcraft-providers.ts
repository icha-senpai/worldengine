import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { providerConfig } from "../bitcraft/src/providers";
const file = process.argv[2];
if (!file)
  throw new Error("Usage: npm run bitcraft:providers -- PRIVATE_CONFIG_JSON");
const configs = JSON.parse(readFileSync(resolve(file), "utf8"));
const cli =
  process.env.SPACETIME_CLI ||
  resolve(
    process.env.LOCALAPPDATA ?? ".",
    "SpacetimeDB/bin/current/spacetimedb-cli.exe",
  );
for (const [name, value] of Object.entries(configs)) {
  const config = providerConfig(name, JSON.stringify(value));
  try {
    execFileSync(
      cli,
      [
        "call",
        "space-bitcraft-tools",
        "configure_provider",
        JSON.stringify(name),
        JSON.stringify(JSON.stringify(config)),
        "--server",
        "http://127.0.0.1:3100",
        "--no-config",
      ],
      { stdio: "pipe", windowsHide: true },
    );
  } catch {
    throw new Error(
      "Provider settings could not be applied. Check the local database and publisher identity.",
    );
  }
  console.log(name + ": server settings applied.");
}
