import { existsSync } from "node:fs";
import { homedir } from "node:os";
import { resolve } from "node:path";

const windowsCli = resolve(
  process.env.LOCALAPPDATA ?? ".",
  "SpacetimeDB/bin/current/spacetimedb-cli.exe",
);
export const spacetimeCli =
  process.env.SPACETIME_CLI ||
  (process.platform === "win32" && existsSync(windowsCli)
    ? windowsCli
    : "spacetime");
export const spacetimeServer =
  process.env.SPACE_SERVER_URL || "http://127.0.0.1:3100";
export const spacetimeData = resolve(
  process.env.SPACE_DATA_DIR || ".runtime/data",
);
export const spacetimeConfig = resolve(
  process.env.SPACETIME_CONFIG_FILE ||
    (process.platform === "win32"
      ? resolve(
          process.env.LOCALAPPDATA ?? ".",
          "SpacetimeDB/config/cli.toml",
        )
      : resolve(
          process.env.XDG_CONFIG_HOME || resolve(homedir(), ".config"),
          "spacetime/cli.toml",
        )),
);
