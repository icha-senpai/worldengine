import { collectionResult } from "./result";
import {
  connectBitcraft,
  requestData,
  observeCollection,
} from "../../bitcraft";
import { upstreamRequest } from "../../../bitcraft/src/requests";
import { relayRequest } from "../../../bitcraft/src/providers";
import { unwrap } from "../market";
const catalogs = new Map(),
  pending = new Map();
let policy;
export async function providerPolicy() {
  if (!policy || policy.until < Date.now())
    policy = {
      until: Date.now() + 60000,
      promise: connectBitcraft()
        .then((conn) => conn.procedures.providerPolicy({}))
        .then(JSON.parse)
        .catch((error) => {
          policy = null;
          throw error;
        }),
    };
  return policy.promise;
}
export async function catalog(kind, id = "", q = "") {
  const key = JSON.stringify([kind, id, q]);
  if (!catalogs.has(key))
    catalogs.set(
      key,
      connectBitcraft()
        .then((conn) =>
          conn.procedures.publicCatalog({ kind, id: String(id), q }),
        )
        .then(JSON.parse)
        .catch((error) => {
          catalogs.delete(key);
          throw error;
        }),
    );
  return catalogs.get(key);
}
export async function live(resource, id = "", q = "", page = 1, options = {}) {
  // UI pagination changes which rows we show, not the upstream response.
  options = Object.fromEntries(
    Object.entries(options).filter(
      ([key]) => !["page", "scopePage", "setup"].includes(key),
    ),
  );
  const key = JSON.stringify([resource, id, q, page, options]);
  const path = resource.startsWith("relay")
    ? relayRequest(resource, String(id), q).path
    : upstreamRequest(resource, String(id), q, Number(page) || 1, options).key;
  void observeCollection(`${resource}|${path}`).catch(() => {});
  if (pending.has(key)) return pending.get(key);
  const work = requestData(resource, String(id), q, Number(page) || 1, options)
    .then((response) =>
      collectionResult(response, (payload) => unwrap(JSON.parse(payload))),
    )
    .finally(() => pending.delete(key));
  pending.set(key, work);
  return work;
}

export async function mapLimited(rows, callback, concurrency) {
  const result = new Array(rows.length);
  const { poolConcurrency } = await providerPolicy();
  let next = 0;
  await Promise.all(
    Array.from(
      {
        length: Math.min(
          concurrency ?? poolConcurrency,
          poolConcurrency,
          rows.length,
        ),
      },
      async () => {
        while (next < rows.length) {
          const index = next++;
          result[index] = await callback(rows[index]);
        }
      },
    ),
  );
  return result;
}
