// Disposable, isolated browser fixture; remove with dispose-storage-test.ts.
import { execFileSync } from "node:child_process";
import { readdir } from "node:fs/promises";
import { resolve } from "node:path";
import {
  registerTemporaryDatabase,
  ownerCall,
  disposeStorageTest,
} from "./bitcraft-storage";
import { claimChanges, type ClaimState } from "../bitcraft/src/claim-history";
const database = `space-relay-checks-${Date.now()}`,
  before = new Set(await readdir(".runtime/data/replicas"));
execFileSync(
  resolve(
    process.env.LOCALAPPDATA!,
    "SpacetimeDB/bin/current/spacetimedb-cli.exe",
  ),
  [
    "publish",
    database,
    "--js-path",
    "bitcraft/dist/bundle.js",
    "--server",
    "http://127.0.0.1:3100",
    "--delete-data=never",
    "--yes",
    "--no-config",
  ],
  { stdio: "pipe", windowsHide: true },
);
const binding = await registerTemporaryDatabase(database, before);
try {
  const at = BigInt(Date.now()) * 1000n;
  const old: ClaimState = {
    id: "10",
    name: "Browser fixture claim",
    ownerId: "20",
    ownerName: "Previous fixture owner",
    totemId: "30",
    supplies: 0,
    tier: 6,
    waystones: 1,
    banks: 1,
    x: 100,
    z: 200,
    members: [
      { id: "20", name: "Departed fixture member", permissions: "1111" },
    ],
  };
  const next = {
    ...old,
    ownerId: "21",
    ownerName: "New fixture owner",
    members: [{ id: "21", name: "New fixture owner", permissions: "1111" }],
  };
  const actual = claimChanges(old, next).map((e, i) => ({
    key: `fixture:${i}`,
    regionId: 8,
    claimId: e.claimId,
    kind: e.kind,
    observedAt: String(at + BigInt(i)),
    payload: JSON.stringify(e.detail),
  }));
  const fillers = Array.from({ length: 101 }, (_, i) => ({
    key: `fixture:refill:${i}`,
    regionId: 8,
    claimId: "10",
    kind: "supplies_refilled",
    observedAt: String(at - BigInt(i + 1)),
    payload: JSON.stringify({
      claimName: old.name,
      tier: 6,
      totemId: "30",
      x: 100,
      z: 200,
      gap: false,
      before: 0,
      after: 100,
    }),
  }));
  await ownerCall(database, "restore_market_checkpoint", [
    "claim_history_event",
    JSON.stringify([...actual, ...fillers]),
  ]);
  await ownerCall(database, "restore_market_checkpoint", [
    "claim_history_state",
    JSON.stringify(
      Array.from({ length: 51 }, (_, i) => ({
        key: `8:${i === 0 ? "10" : String(100 + i)}`,
        regionId: 8,
        payload: JSON.stringify({
          ...next,
          id: i === 0 ? "10" : String(100 + i),
          name: i === 0 ? next.name : `Pagination fixture ${i}`,
        }),
        epoch: "fixture",
        observedAt: String(at),
      })),
    ),
  ]);
  await ownerCall(database, "restore_market_checkpoint", [
    "claim_supply_status",
    JSON.stringify([
      {
        key: "8:10",
        regionId: 8,
        epoch: "fixture",
        observedAt: String(at),
        payload: JSON.stringify({
          claimId: "10",
          supplies: 2400,
          upkeepPerHour: 100,
          tileCount: 1000,
          buildingMaintenance: 90,
          treasury: "10000",
          tickMillis: 3600000,
        }),
      },
    ]),
  ]);
  await ownerCall(database, "restore_market_checkpoint", [
    "claim_history_region",
    JSON.stringify([
      {
        key: "8",
        regionId: 8,
        epoch: "fixture",
        sequence: 1,
        observedAt: String(at),
        startedAt: String(at),
        complete: true,
        prunedAt: String(at),
        discarded: "0",
        reconciling: false,
      },
    ]),
  ]);
  console.log(
    JSON.stringify({
      database,
      events: actual.length + fillers.length,
      cleanup: `npx tsx scripts/dispose-storage-test.ts ${database}`,
    }),
  );
} catch (e) {
  await disposeStorageTest(binding);
  throw e;
}
