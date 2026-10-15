import {
  registerTemporaryDatabase,
  disposeStorageTest,
  readBinding,
} from "./bitcraft-storage";
import { existsSync } from "node:fs";
import { resolve } from "node:path";
const database = process.argv[2] ?? "";
const binding = existsSync(
  resolve(".runtime/bitcraft-storage", database + ".json"),
)
  ? await readBinding(database)
  : await registerTemporaryDatabase(database, new Set());
await disposeStorageTest(binding);
console.log(
  "Temporary test database and replica removed after identity verification.",
);
