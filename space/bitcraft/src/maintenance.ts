import { Identity } from "spacetimedb";

// Only the owner can export/restore these records. Relay frames, response caches,
// market data is checkpointed separately in bounded chunks; status leases are rebuilt.
export const retainedTables = [
  "administrator",
  "guide",
  "guide_metadata",
  "guide_administrator",
  "widget_profile",
  "provider_settings",
  "collection_sample",
  "collection_watch",
  "collection_feed",
] as const;

export function retainedState(ctx: any) {
  const rows = (table: any) => [...table.iter()];
  return {
    version: 3,
    administrator: rows(ctx.db.administrator).map((r) => ({
      ...r,
      owner: r.owner.toHexString(),
    })),
    guide: rows(ctx.db.guide),
    guide_metadata: rows(ctx.db.guideMetadata),
    guide_administrator: rows(ctx.db.guideAdministrator).map((r) => ({
      ...r,
      identity: r.identity.toHexString(),
    })),
    widget_profile: rows(ctx.db.widgetProfile).map((r) => ({
      ...r,
      owner: r.owner.toHexString(),
    })),
    provider_settings: rows(ctx.db.providerSettings),
    collection_sample: rows(ctx.db.collectionSample),
    collection_watch: rows(ctx.db.collectionWatch).filter(
      (r) => r.resource === "relaySkills",
    ),
    collection_feed: rows(ctx.db.collectionFeed).filter(
      (r) => r.resource === "relaySkills",
    ),
  };
}

export function encodeRetainedState(ctx: any) {
  const payload = JSON.stringify(retainedState(ctx), (_key, value) =>
    typeof value === "bigint" ? String(value) : value,
  );
  if (payload.length > 20000000)
    throw Error(
      "Retained state exceeds the maintenance budget. Collection must remain stopped.",
    );
  return payload;
}

export function restoreRetainedState(ctx: any, payload: string) {
  if (payload.length > 20000000) throw Error("Retained state is too large.");
  const state = JSON.parse(payload);
  if (state.version === 2) {
    state.collection_watch ??= [];
    state.collection_feed ??= [];
  }
  if (
    ![2, 3].includes(state.version) ||
    retainedTables.some((name) => !Array.isArray(state[name]))
  )
    throw Error("Invalid retained state.");
  if (
    state.administrator.length !== 1 ||
    state.administrator[0].id !== 1 ||
    !Identity.fromString(state.administrator[0].owner).equals(ctx.sender)
  )
    throw Error("The restored owner must match the database owner.");
  const tableNames = {
    administrator: "administrator",
    guide: "guide",
    guide_metadata: "guideMetadata",
    guide_administrator: "guideAdministrator",
    widget_profile: "widgetProfile",
    provider_settings: "providerSettings",
    collection_sample: "collectionSample",
    collection_watch: "collectionWatch",
    collection_feed: "collectionFeed",
  } as const;
  const primaryKeys = {
    administrator: "id",
    guide: "id",
    guide_metadata: "id",
    guide_administrator: "identity",
    widget_profile: "token",
    provider_settings: "name",
    collection_sample: "key",
    collection_watch: "key",
    collection_feed: "key",
  } as const;
  // Host type checking and uniqueness constraints reject malformed rows; a
  // reducer transaction rolls back the entire restoration on any failure.
  for (const name of retainedTables) {
    if (state[name].length > (name === "collection_sample" ? 6000 : 10000))
      throw Error("Retained row limit exceeded.");
    const table = ctx.db[tableNames[name]],
      pk = primaryKeys[name];
    for (const row of table.iter()) table[pk].delete(row[pk]);
    for (const raw of state[name]) {
      const row = { ...raw };
      for (const field of [
        "id",
        "publishedAt",
        "updatedAt",
        "observedAt",
        "receivedAt",
        "expiresAt",
      ])
        if (field in row && !(name === "administrator" && field === "id"))
          row[field] = BigInt(row[field]);
      for (const field of ["owner", "identity"])
        if (field in row) row[field] = Identity.fromString(row[field]);
      table.insert(row);
    }
  }
}
