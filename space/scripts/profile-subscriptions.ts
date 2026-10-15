import { DbConnection as BitcraftConnection } from "../src/bindings/bitcraft";
import { DbConnection as EvergatherConnection } from "../src/bindings/evergather";
import { readServerMetrics, summarizeServerMetrics } from "./load-metrics";

const server = "http://127.0.0.1:3100";
const seconds = Number(process.argv[2] ?? 15);
if (!Number.isInteger(seconds) || seconds < 5 || seconds > 60)
  throw Error("Choose a 5–60 second observation window.");
const metadata = async (database: string) => {
  const response = await fetch(`${server}/v1/database/${database}`, {
    signal: AbortSignal.timeout(10000),
  });
  if (!response.ok)
    throw Error(`Database metadata returned ${response.status}.`);
  const value = await response.json();
  return {
    identity: String(value.database_identity.__identity__).replace(/^0x/, ""),
    program: value.initial_program,
  };
};
const bytes = (rows: unknown[]) =>
  Buffer.byteLength(
    JSON.stringify(rows, (_key, value) =>
      typeof value === "bigint" ? String(value) : value,
    ),
  );

// Only public subscriptions are opened. No player requests, reducer calls,
// background watches, owner credentials, exports or report files are created.
async function profile(
  database: string,
  builder: any,
  groups: Array<{ name: string; tables: string[]; queries: string[] }>,
) {
  const before = await metadata(database);
  const counters = await readServerMetrics(server, before.identity);
  let conn: any;
  const snapshots = [];
  const started = performance.now();
  try {
    await new Promise<void>((resolve, reject) => {
      const timeout = setTimeout(() => {
        conn?.disconnect();
        reject(Error("Profile connection timed out."));
      }, 10000);
      conn = builder
        .withUri(server.replace("http:", "ws:"))
        .withDatabaseName(database)
        .onConnect(() => {
          clearTimeout(timeout);
          resolve();
        })
        .onConnectError((_ctx: unknown, error: Error) => {
          clearTimeout(timeout);
          reject(error);
        })
        .build();
    });
    for (const group of groups) {
      const samples = [];
      for (let i = 0; i < 3; i++) {
        const start = performance.now();
        let subscription: any;
        try {
          await new Promise<void>((resolve, reject) => {
            const timeout = setTimeout(
              () => reject(Error("Profile subscription timed out.")),
              10000,
            );
            subscription = conn
              .subscriptionBuilder()
              .onApplied(() => {
                clearTimeout(timeout);
                resolve();
              })
              .onError(() => {
                clearTimeout(timeout);
                reject(Error("Profile subscription failed."));
              })
              .subscribe(group.queries);
          });
          const elapsedMs = performance.now() - start;
          const rows = group.tables.flatMap((table) => [
            ...conn.db[table].iter(),
          ]);
          samples.push({
            elapsedMs: Math.round(elapsedMs * 100) / 100,
            rows: rows.length,
            decodedJsonBytes: bytes(rows),
          });
        } finally {
          subscription?.unsubscribe();
        }
      }
      snapshots.push({ name: group.name, samples });
    }
    await new Promise((resolve) => setTimeout(resolve, seconds * 1000));
    const after = await metadata(database);
    if (JSON.stringify(before) !== JSON.stringify(after))
      throw Error("Database changed during profiling; discard this sample.");
    const summary = summarizeServerMetrics(
      counters,
      await readServerMetrics(server, before.identity),
    );
    return {
      database,
      observationSeconds: Math.round((performance.now() - started) / 1000),
      snapshots,
      viewActivity: Object.entries(summary.views).sort(
        (a, b) => b[1].computationMs - a[1].computationMs,
      ),
      limitation:
        "Decoded JSON sizes are not compressed wire sizes. First snapshots can warm shared views. Activity includes all current clients of this database.",
    };
  } finally {
    conn?.disconnect();
  }
}

// Sequential windows avoid warming both modules at the same time.
const bitcraft = await profile(
  "space-bitcraft-tools",
  BitcraftConnection.builder(),
  [
    {
      name: "market status",
      tables: ["marketStatus", "relayStatus"],
      queries: [
        "SELECT * FROM market_status WHERE key = 'market'",
        "SELECT * FROM relay_status",
      ],
    },
    {
      name: "claim revisions",
      tables: ["claimChanges"],
      queries: ["SELECT * FROM claim_changes"],
    },
  ],
);
console.log(JSON.stringify(bitcraft, null, 2));
console.log(
  JSON.stringify(
    await profile("space-evergather", EvergatherConnection.builder(), [
      {
        name: "core definitions",
        tables: ["coreDefinitions"],
        queries: ["SELECT * FROM core_definitions"],
      },
      {
        name: "inventory directory",
        tables: ["inventoryDirectory"],
        queries: ["SELECT * FROM inventory_directory"],
      },
      {
        name: "market invalidation and totals",
        tables: ["marketRevision", "marketSummary"],
        queries: [
          "SELECT * FROM market_revision",
          "SELECT * FROM market_summary",
        ],
      },
    ]),
    null,
    2,
  ),
);
