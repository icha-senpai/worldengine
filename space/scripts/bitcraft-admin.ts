import { execFileSync } from "node:child_process";
import { resolve } from "node:path";
const identity = process.argv[2];
if (!identity || !/^[a-f0-9]{64}$/i.test(identity))
  throw new Error(
    "Usage: npm run bitcraft:admin -- BROWSER_IDENTITY [DISPLAY_NAME] [--revoke]",
  );
const cli =
  process.env.SPACETIME_CLI ||
  resolve(
    process.env.LOCALAPPDATA ?? ".",
    "SpacetimeDB/bin/current/spacetimedb-cli.exe",
  );
execFileSync(
  cli,
  [
    "call",
    "space-bitcraft-tools",
    "grant_guide_administrator",
    JSON.stringify(identity),
    JSON.stringify(
      process.argv[3] && !process.argv[3].startsWith("--")
        ? process.argv[3]
        : "Admin",
    ),
    String(!process.argv.includes("--revoke")),
    "--server",
    "http://127.0.0.1:3100",
    "--no-config",
  ],
  { stdio: "inherit", windowsHide: true },
);
console.log(
  process.argv.includes("--revoke")
    ? "Guide editing access revoked."
    : "Guide editing access granted. Refresh Guides in that browser.",
);
