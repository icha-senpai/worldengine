import { sealPublishedModule } from "./bitcraft-storage";
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
async function publishModules(selected: typeof modules) {
  // A busy local collector can hold HTTP procedures across publication. Stop
  // only our managed worker, preserve its state, and restore it afterwards.
  const resume =
    process.platform !== "win32"
      ? []
      : selected
          .filter((module) => module.path === "bitcraft")
          .map((module) =>
            module.database === "space-bitcraft-checks" ? "Test" : "Main",
          )
          .filter((target) =>
            existsSync(
              resolve(
                `.runtime/services/${target === "Test" ? "collector-test" : "collector"}.pid`,
              ),
            ),
          );
  const collector = (action: string, target: string) =>
    execFileSync(
      "powershell.exe",
      [
        "-NoProfile",
        "-ExecutionPolicy",
        "Bypass",
        "-File",
        resolve("scripts/collector-services.ps1"),
        "-Action",
        action,
        "-Target",
        target,
      ],
      { stdio: "inherit", windowsHide: true },
    );
  for (const target of resume) collector("Stop", target);
  try {
    for (const module of selected) {
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
      if (module.path === "bitcraft")
        await sealPublishedModule(module.database);
    }
  } finally {
    for (const target of resume) collector("Start", target);
  }
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
    await publishModules(modules);
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
  case "publish-bitcraft":
    await publishModules(
      modules.filter((module) => module.path === "bitcraft"),
    );
    break;
  case "publish-bitcraft-test":
    await publishModules([
      {
        path: "bitcraft",
        database: "space-bitcraft-checks",
        bindings: "src/bindings/bitcraft",
      },
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
    throw new Error(
      "Choose start, generate, publish, publish-bitcraft, publish-bitcraft-test, auth, or local.",
    );
}
