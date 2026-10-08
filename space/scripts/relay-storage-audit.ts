// Read-only: report aggregate sizes and resource names, never payloads or IDs.
import { createHash } from "node:crypto";
import { writeFile, mkdir, readdir } from "node:fs/promises";
import { join } from "node:path";
import {
  ownerToken,
  readBinding,
  storageUsage,
  directoryBytes,
  safeReplicaPath,
} from "./bitcraft-storage";
import { parseRelayJson } from "./bitcraft-relay";

const database = "space-bitcraft-tools";
const token = await ownerToken();
async function sql(query: string): Promise<Record<string, any>[]> {
  const response = await fetch(
    `http://127.0.0.1:3100/v1/database/${database}/sql`,
    {
      method: "POST",
      headers: {
        Authorization: `Bearer ${token}`,
        "Content-Type": "text/plain",
      },
      body: query,
      signal: AbortSignal.timeout(30000),
    },
  );
  if (!response.ok) throw Error(`Audit query failed (${response.status}).`);
  const result = parseRelayJson(await response.text())[0];
  const fields = result.schema.elements.map((f: any) => f.name?.some ?? f.name);
  if (fields.some((f: unknown) => typeof f !== "string"))
    throw Error("Unexpected SQL schema.");
  return result.rows.map((row: any[]) =>
    Object.fromEntries(fields.map((f: string, i: number) => [f, row[i]])),
  );
}
const bytes = (payload: string) => Buffer.byteLength(payload || "");
const hash = (payload: string) =>
  createHash("sha256")
    .update(payload || "")
    .digest("hex");
function grouped(
  rows: Record<string, any>[],
  group: (row: Record<string, any>) => string,
) {
  const result: Record<
    string,
    { rows: number; payloadBytes: number; largestPayloadBytes: number }
  > = {};
  for (const row of rows) {
    const key = group(row);
    const metric = (result[key] ??= {
      rows: 0,
      payloadBytes: 0,
      largestPayloadBytes: 0,
    });
    metric.rows++;
    metric.payloadBytes += bytes(row.payload);
    metric.largestPayloadBytes = Math.max(
      metric.largestPayloadBytes,
      bytes(row.payload),
    );
  }
  return result;
}
const cache = await sql("SELECT key, payload FROM snapshot");
const feeds = await sql("SELECT resource, payload FROM collection_feed");
const entities = await sql("SELECT kind, payload FROM collected_entity");
const trades = await sql("SELECT scope, payload FROM trade_record");
const references = await sql("SELECT payload FROM trade_reference");
const marketRows = await sql("SELECT metadata, book FROM market_item");
const marketItems = marketRows.map(row => ({ payload: row.book }));
const marketMetadata = marketRows.map(row => ({ payload: row.metadata }));
const regionalBooks = await sql("SELECT payload FROM relay_book");
const stalls = await sql("SELECT payload FROM relay_stall");
const directory = await sql("SELECT payload FROM market_meta");
const summaries = await sql("SELECT payload FROM market_summary");
const samples = await sql("SELECT payload FROM collection_sample");
const watches = await sql("SELECT resource, expires_at FROM collection_watch");
const cachedHashes = new Set(cache.map((row) => hash(row.payload)));
const duplicateFeeds = feeds.filter((row) =>
  cachedHashes.has(hash(row.payload)),
);
const now = BigInt(Date.now()) * 1000n;
function cacheResource(row: Record<string, any>) {
  // Cache keys include provider and URL; only expose a known endpoint category.
  const endpoint = String(row.key)
    .split("|")[1]
    ?.split("?")[0]
    ?.split("/")
    .filter(Boolean);
  return endpoint?.[0] === "api" ? (endpoint[1] ?? "other") : "other";
}
const binding = await readBinding(database);
const disk: Record<string, number> = {};
const replica = safeReplicaPath(binding.replica);
for (const name of await readdir(replica)) {
  try {
    disk[name] = await directoryBytes(join(replica, name));
  } catch {
    /* Root files are included by storageUsage. */
  }
}
const result = {
  sampledAt: new Date().toISOString(),
  database,
  replica: binding.replica,
  note: "Sequential live reads. Payload bytes are logical UTF-8 text, not native allocation or attributed commitlog bytes. Exact duplicates are compared in memory; hashes and IDs are not exported.",
  cache: grouped(cache, cacheResource),
  feeds: grouped(feeds, (row) => row.resource),
  entities: grouped(entities, (row) => row.kind),
  canonicalTrading: grouped(trades, (row) => String(row.scope).split(":")[0]),
  sharedReferences: grouped(references, () => "labels"),
  tradingHeaders: {
    marketItems: grouped(marketItems, () => "books"),
    marketMetadata: grouped(marketMetadata, () => "metadata"),
    regionalBooks: grouped(regionalBooks, () => "books"),
    barterStalls: grouped(stalls, () => "stalls"),
    directory: grouped(directory, () => "directory"),
    summaries: grouped(summaries, () => "summaries"),
  },
  xpSamples: grouped(samples, () => "samples").samples ?? {
    rows: 0,
    payloadBytes: 0,
    largestPayloadBytes: 0,
  },
  watches: Object.fromEntries(
    [...new Set(watches.map((row) => row.resource))].map((resource) => [
      resource,
      {
        active: watches.filter(
          (row) => row.resource === resource && BigInt(row.expires_at) > now,
        ).length,
        expired: watches.filter(
          (row) => row.resource === resource && BigInt(row.expires_at) <= now,
        ).length,
      },
    ]),
  ),
  feedsExactlyDuplicatedInApiCache: grouped(
    duplicateFeeds,
    (row) => row.resource,
  ),
  disk,
  usage: await storageUsage(binding),
};
await mkdir("output", { recursive: true });
const path = `output/relay-storage-audit-${new Date().toISOString().slice(0, 10).replaceAll("-", "")}.json`;
await writeFile(path, JSON.stringify(result, null, 2) + "\n");
console.log(JSON.stringify(result, null, 2));
