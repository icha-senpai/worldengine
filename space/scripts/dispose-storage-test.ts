import {
  registerTemporaryDatabase,
  disposeStorageTest,
} from "./bitcraft-storage";
const database = process.argv[2] ?? "";
const binding = await registerTemporaryDatabase(database, new Set());
await disposeStorageTest(binding);
console.log(
  "Temporary test database and replica removed after identity verification.",
);
