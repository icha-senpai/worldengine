import { execFileSync } from "node:child_process";
import { existsSync } from "node:fs";
import { resolve } from "node:path";

const installed = resolve(
  process.env.LOCALAPPDATA ?? ".",
  "SpacetimeDB/bin/current/spacetimedb-cli.exe",
);
const cli =
  process.env.SPACETIME_CLI ||
  (existsSync(installed) ? installed : "spacetime");
const server = "http://127.0.0.1:3100";
const modules = [
  {
    path: "spacetimedb",
    database: "space-evergather",
    bindings: "src/bindings/evergather",
  },
  {
    path: "bitcraft",
    database: "space-bitcraft-tools",
    bindings: "src/bindings/bitcraft",
  },
];
function run(args: string[]) {
  execFileSync(cli, args, { stdio: "inherit", windowsHide: true });
}
const task = process.argv[2];
switch (task) {
  case "start":
    run([
      "start",
      "--listen-addr",
      "127.0.0.1:3100",
      "--data-dir",
      resolve(".runtime/data"),
      "--non-interactive",
    ]);
    break;
  case "generate":
    for (const module of modules)
      run([
        "generate",
        "--lang",
        "typescript",
        "--out-dir",
        module.bindings,
        "--module-path",
        module.path,
        "--yes",
        "--no-config",
      ]);
    break;
  case "publish":
    for (const module of modules)
      run([
        "publish",
        module.database,
        "--module-path",
        module.path,
        "--server",
        server,
        "--yes",
        "--no-config",
      ]);
    run([
      "call",
      "space-evergather",
      "refresh_content",
      "--server",
      server,
      "--no-config",
    ]);
    run([
      "call",
      "space-bitcraft-tools",
      "migrate_guides",
      "--server",
      server,
      "--no-config",
    ]);
    break;
  case "auth": {
    const clientId = process.argv[3];
    if (!clientId)
      throw new Error(
        "Usage: npm run spacetime:auth -- PUBLIC_SPACETIMEAUTH_CLIENT_ID",
      );
    run([
      "call",
      "space-evergather",
      "configure_auth",
      JSON.stringify("https://auth.spacetimedb.com/oidc"),
      JSON.stringify(clientId),
      "--server",
      server,
      "--no-config",
    ]);
    break;
  }
  case "local": {
    const setting = process.argv[3];
    if (setting !== "on" && setting !== "off")
      throw new Error("Usage: npm run spacetime:local -- on|off");
    run([
      "call",
      "space-evergather",
      "configure_local_play",
      setting === "on" ? "true" : "false",
      "--server",
      server,
      "--no-config",
    ]);
    break;
  }
  default:
    throw new Error("Choose start, generate, publish, auth, or local.");
}
