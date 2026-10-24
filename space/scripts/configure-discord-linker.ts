import { execFileSync } from "node:child_process";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { DbConnection } from "../src/bindings/evergather";
import { spacetimeCli, spacetimeConfig } from "./spacetime-config";

// Run only after publishing the additive account module, as the database owner.
// Relative service paths follow the linker's fishing-game working directory.
const server = process.env.EVERGATHER_SPACETIMEDB_URI;
const database = process.env.EVERGATHER_DATABASE;
if (
  server !== "http://127.0.0.1:3100" ||
  !database ||
  !/^[a-z0-9-]+$/.test(database)
)
  throw Error("Set the Evergather loopback 3100 URI and database explicitly.");
const file = process.env.EVERGATHER_LINKER_TOKEN_FILE;
const path = file ? resolve("../fishing-game", file) : undefined;
let token = process.env.EVERGATHER_LINKER_TOKEN?.trim();
if (!token && path && existsSync(path))
  token = readFileSync(path, "utf8").trim();
if (!token && !path)
  throw Error(
    "Set EVERGATHER_LINKER_TOKEN_FILE to a private ignored service-token file.",
  );
await new Promise<void>((accept, reject) => {
  const timer = setTimeout(() => {
    connection.disconnect();
    reject(Error("Evergather linker setup timed out."));
  }, 15000);
  const connection = DbConnection.builder()
    .withUri(server)
    .withDatabaseName(database)
    .withToken(token)
    .onConnect((connection, identity, issuedToken) => {
      try {
        if (!token && path) {
          mkdirSync(dirname(path), { recursive: true });
          writeFileSync(path, issuedToken + "\n", { flag: "wx", mode: 0o600 });
        }
        execFileSync(
          spacetimeCli,
          [
            "--config-path",
            spacetimeConfig,
            "call",
            database,
            "configure_discord_linker",
            JSON.stringify(identity.toHexString()),
            "true",
            "--server",
            server,
            "--no-config",
            "--yes",
          ],
          { stdio: "pipe", windowsHide: true, timeout: 15000 },
        );
        console.log(
          "Evergather account-link service authorized; token value was not printed.",
        );
        accept();
      } catch {
        reject(
          Error(
            "Could not authorize the Evergather linker. Check the database-owner CLI configuration.",
          ),
        );
      } finally {
        clearTimeout(timer);
        connection.disconnect();
      }
    })
    .onConnectError((_connection, error) => {
      clearTimeout(timer);
      connection.disconnect();
      reject(error);
    })
    .build();
});
