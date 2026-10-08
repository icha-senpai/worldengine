// Read-only audit. Output contains counts and byte totals, never cached payloads,
// credentials, widget tokens, player names or private application records.
import { readdir } from "node:fs/promises";
import { join } from "node:path";
import {
  ownerToken,
  readBinding,
  storageUsage,
  directoryBytes,
  safeReplicaPath,
} from "./bitcraft-storage";
import { parseRelayJson, TRADING_TABLES } from "./bitcraft-relay";

const database = "space-bitcraft-tools";
const token = await ownerToken();
const bytes = (value: unknown) =>
  Buffer.byteLength(
    typeof value === "string" ? value : JSON.stringify(value ?? null),
  );
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
  if (!response.ok)
    throw Error(`Read-only market audit query failed (${response.status}).`);
  const result = parseRelayJson(await response.text())[0];
  const fields = result.schema.elements.map(
    (field: any) => field.name?.some ?? field.name,
  );
  if (fields.some((field: unknown) => typeof field !== "string"))
    throw Error("Unexpected SQL result field names.");
  return result.rows.map((row: any[]) =>
    Object.fromEntries(
      fields.map((field: string, i: number) => [field, row[i]]),
    ),
  );
}
const metrics: Record<string, any> = {};
const orderIds = new Map<string, Set<string>>();
const queries: Record<string, string> = {
  relay_book: "SELECT key, payload FROM relay_book",
  market_item: "SELECT key, metadata, book FROM market_item",
  relay_stall: "SELECT key, payload FROM relay_stall",
  market_summary: "SELECT key, payload FROM market_summary",
  market_meta: "SELECT key, payload FROM market_meta",
  snapshot: "SELECT key, payload FROM snapshot",
  collection_feed: "SELECT resource, payload FROM collection_feed",
  collected_entity: "SELECT scope, payload FROM collected_entity",
};
for (const [table, query] of Object.entries(queries)) {
  const rows = await sql(query);
  metrics[table] = {
    rows: rows.length,
    textBytes: rows.reduce(
      (sum, row) =>
        sum +
        ["payload", "metadata", "book"].reduce(
          (n, field) => n + bytes(row[field] ?? ""),
          0,
        ),
      0,
    ),
  };
  if (table === "relay_book" || table === "market_item") {
    const books = rows.map((row) => ({
      key: row.key,
      value: row.payload || row.book,
      book: JSON.parse(row.payload || row.book || "{}"),
    }));
    const counts: Record<string, number> = {};
    const ids = new Set<string>();
    let orderTextBytes = 0,
      repeatedReferenceBytes = 0,
      itemTextBytes = 0;
    for (const row of books) {
      itemTextBytes += bytes(row.book.item ?? {});
      for (const field of [
        "sellOrders",
        "buyOrders",
        "packageSellOrders",
        "packageBuyOrders",
      ]) {
        const orders = row.book[field] ?? [];
        counts[field] = (counts[field] ?? 0) + orders.length;
        for (const order of orders) {
          ids.add(`${order.regionId}:${field}:${order.entityId}`);
          orderTextBytes += bytes(order);
          repeatedReferenceBytes += bytes(
            Object.fromEntries(
              [
                "claimName",
                "ownerUsername",
                "regionName",
                "claimLocationX",
                "claimLocationZ",
              ]
                .filter((key) => key in order)
                .map((key) => [key, order[key]]),
            ),
          );
        }
      }
    }
    metrics[table] = {
      ...metrics[table],
      counts,
      orderTextBytes,
      repeatedReferenceBytes,
      itemTextBytes,
      uniqueOrderRecords: ids.size,
      largestBooks: books
        .map((row) => ({ key: row.key, bytes: bytes(row.value) }))
        .sort((a, b) => b.bytes - a.bytes)
        .slice(0, 5),
    };
    orderIds.set(table, ids);
  }
  if (table === "relay_stall")
    metrics[table].barterOrders = rows.reduce(
      (n, row) => n + JSON.parse(row.payload).orders.length,
      0,
    );
  if (table === "snapshot") {
    const market = rows.filter(
      (row) => row.key.includes("/market") || row.key.includes("|market|"),
    );
    metrics[table].marketRows = market.length;
    metrics[table].marketTextBytes = market.reduce(
      (n, row) => n + bytes(row.payload),
      0,
    );
  }
  if (table === "collection_feed")
    metrics[table].resources = Object.fromEntries(
      [...new Set(rows.map((row) => row.resource))].map((resource) => [
        resource,
        {
          rows: rows.filter((row) => row.resource === resource).length,
          textBytes: rows
            .filter((row) => row.resource === resource)
            .reduce((n, row) => n + bytes(row.payload), 0),
        },
      ]),
    );
}
const binding = await readBinding(database);
const maintenanceTimes = (await readdir(".runtime/bitcraft-storage"))
  .filter((name) => /^space-bitcraft-tools-\d+\.json$/.test(name))
  .map((name) => Number(name.match(/-(\d+)\.json$/)![1]))
  .sort((a, b) => b - a);
const replica = safeReplicaPath(binding.replica);
const disk: Record<string, number> = {};
for (const name of await readdir(replica)) {
  try {
    disk[name] = await directoryBytes(join(replica, name));
  } catch {
    /* Root files are included by storageUsage. */
  }
}
console.log(
  JSON.stringify(
    {
      sampledAt: new Date().toISOString(),
      database,
      replica: binding.replica,
      recentMaintenanceAt: maintenanceTimes
        .slice(0, 2)
        .map((time) => new Date(time).toISOString()),
      lastMaintenanceIntervalMinutes:
        maintenanceTimes.length > 1
          ? (maintenanceTimes[0]! - maintenanceTimes[1]!) / 60000
          : null,
      note: "Table reads happen sequentially while collection stays live. Text bytes measure current stored payloads, not native disk allocation.",
      subscribedTables: TRADING_TABLES,
      tables: metrics,
      orderRecordsStoredInBothCopies: [
        ...(orderIds.get("relay_book") ?? []),
      ].filter((id) => orderIds.get("market_item")?.has(id)).length,
      disk,
      usage: await storageUsage(binding),
    },
    null,
    2,
  ),
);
