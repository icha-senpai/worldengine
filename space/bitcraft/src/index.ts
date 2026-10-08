import { tradeRecord, tradeReference } from "./trade-schema";
import { checkpointPage, restoreCheckpointPage } from "./market-checkpoint";
import { applyOrderDelta } from "./trade-delta";
import {
  schema,
  table,
  t,
  SenderError,
  type InferSchema,
  type ReducerCtx,
} from "spacetimedb/server";
import { TimeDuration } from "spacetimedb";
import { encodeRetainedState, restoreRetainedState } from "./maintenance";
import {
  storeBook,
  loadBook,
  tradingJson,
  storeStall,
  loadStall,
  clearTradeScope,
} from "./trade-storage";
import { upstreamRequest } from "./requests";
import {
  RETIRED_REGION_IDS,
  isRetiredRegion,
  visibleMarketBook,
} from "./regions";
import publishedContent from "./content/guides.json";
import { catalogResponse } from "./catalog";
import { validateWidget } from "./widgets";
import { validateGuide } from "./guides";
import {
  relayBook,
  relayStall,
  relayRegion,
  relayStatus,
} from "./relay-schema";
import {
  CURRENT_REGIONS,
  validateRelayTrading,
  mergeRegionalBook,
} from "./relay-trading";
import {
  marketItem,
  marketMeta,
  marketSummaryRow,
  marketStatus,
} from "./market-schema";
import {
  marketDirectory,
  marketDisplayItem,
  marketBook,
  emptyMarketBook,
  marketSummary,
  marketRegions,
  marketMatches,
  marketIdentity as parseMarketIdentity,
  marketWorkPriority,
  MARKET_DISCOVERY_SECONDS,
  MARKET_ROLLING_SECONDS,
  MARKET_SEARCH_SECONDS,
} from "./market-store";
import {
  collectorIdentity,
  collectionWatch,
  collectionFeed,
  collectionStatus,
  collectionSample,
  collectedEntity,
} from "./collection-schema";
import {
  collectionKey,
  collectionInterval,
  collectionWatchSeconds,
  projectCollection,
  observedRates,
} from "./collection";
import {
  providerDefaults,
  providerConfig,
  providerOrder,
  relayRequest,
  validProviderPayload,
  chooseProviderResult,
  retryAfterSeconds,
  type ProviderName,
} from "./providers";
const snapshot = table(
  { name: "snapshot", public: true },
  {
    key: t.string().primaryKey(),
    payload: t.string(),
    updatedAt: t.u64(),
    expiresAt: t.u64(),
    retryAt: t.u64(),
    refreshingUntil: t.u64(),
    error: t.string(),
  },
);
// Payload-independent refresh state. Existing payload tables remain compatible
// with older clients; freshness comes from this small row on procedure reads.
const refreshState = table(
  { name: "refresh_state" },
  {
    key: t.string().primaryKey(),
    updatedAt: t.u64(),
    expiresAt: t.u64(),
    retryAt: t.u64(),
    refreshingUntil: t.u64(),
    error: t.string(),
  },
);
const budget = table(
  { name: "budget" },
  {
    id: t.u32().primaryKey(),
    window: t.u64(),
    requests: t.u32(),
    blockedUntil: t.u64(),
  },
);
const administrator = table(
  { name: "administrator" },
  { id: t.u32().primaryKey(), owner: t.identity() },
);
const guide = table(
  { name: "guide" },
  {
    id: t.u64().primaryKey(),
    title: t.string(),
    summary: t.string(),
    category: t.string(),
    content: t.string(),
    published: t.bool().index("btree"),
  },
);
const widgetProfile = table(
  { name: "widget_profile" },
  {
    token: t.string().primaryKey(),
    owner: t.identity().index("btree"),
    kind: t.string(),
    settings: t.string(),
    updatedAt: t.u64(),
  },
);
const guideAdministrator = table(
  { name: "guide_administrator" },
  { identity: t.identity().primaryKey(), name: t.string() },
);
const guideMetadata = table(
  { name: "guide_metadata" },
  {
    id: t.u64().primaryKey(),
    authorName: t.string(),
    publishedAt: t.u64(),
    updatedAt: t.u64(),
  },
);
const providerSettings = table(
  { name: "provider_settings" },
  { name: t.string().primaryKey(), config: t.string() },
);
const providerBudget = table(
  { name: "provider_budget" },
  {
    key: t.string().primaryKey(),
    window: t.u64(),
    requests: t.u32(),
    blockedUntil: t.u64(),
  },
);
const storageState = table(
  { name: "storage_state" },
  { id: t.u32().primaryKey(), writableUntil: t.u64() },
);
const db = schema({
  storageState,
  snapshot,
  budget,
  administrator,
  guide,
  widgetProfile,
  guideAdministrator,
  guideMetadata,
  providerSettings,
  providerBudget,
  collectorIdentity,
  collectionWatch,
  collectionFeed,
  collectionStatus,
  collectionSample,
  collectedEntity,
  marketItem,
  marketMeta,
  marketSummaryRow,
  marketStatus,
  relayRegion,
  relayStatus,
  relayBook,
  relayStall,
  refreshState,
  tradeRecord,
  tradeReference,
});
export default db;
type Ctx = ReducerCtx<InferSchema<typeof db>>;
function refresh(ctx: Ctx, key: string, fallback: any = {}) {
  fallback ??= {};
  return (
    ctx.db.refreshState.key.find(key) ?? {
      key,
      updatedAt: fallback.updatedAt ?? fallback.observedAt ?? 0n,
      expiresAt: fallback.expiresAt ?? 0n,
      retryAt: fallback.retryAt ?? 0n,
      refreshingUntil: fallback.refreshingUntil ?? 0n,
      error: fallback.error ?? "",
    }
  );
}
function saveRefresh(
  ctx: Ctx,
  key: string,
  changes: Partial<ReturnType<typeof refresh>>,
  fallback?: any,
) {
  const row = { ...refresh(ctx, key, fallback), ...changes, key };
  if (ctx.db.refreshState.key.find(key)) ctx.db.refreshState.key.update(row);
  else ctx.db.refreshState.insert(row);
}
function cachedSnapshot(ctx: Ctx, key: string) {
  const row = ctx.db.snapshot.key.find(key);
  if (!row) return null;
  return { ...row, ...refresh(ctx, `cache|${key}`, row), key };
}
function collectedFeed(ctx: Ctx, key: string) {
  const row = ctx.db.collectionFeed.key.find(key);
  if (!row) return null;
  const status = refresh(ctx, `feed|${key}`, row);
  return {
    ...row,
    observedAt: status.updatedAt,
    expiresAt: status.expiresAt,
    error: status.error,
  };
}
function requireWrites(ctx: Ctx) {
  if (
    (ctx.db.storageState.id.find(1)?.writableUntil ?? 0n) <=
    ctx.timestamp.microsSinceUnixEpoch
  )
    throw new SenderError(
      "Updates are paused for storage maintenance. Stored data remains available.",
    );
}
function requireOwner(ctx: any) {
  if (!ctx.db.administrator.id.find(1)?.owner.equals(ctx.sender))
    throw new SenderError("Database owner authorization required.");
}
export const storageLease = db.reducer({ seconds: t.u32() }, (ctx, args) => {
  requireOwner(ctx);
  if (args.seconds > 300)
    throw new SenderError("Storage leases must be short.");
  const row = {
    id: 1,
    writableUntil: args.seconds
      ? ctx.timestamp.microsSinceUnixEpoch + BigInt(args.seconds) * 1000000n
      : 0n,
  };
  if (ctx.db.storageState.id.find(1)) ctx.db.storageState.id.update(row);
  else ctx.db.storageState.insert(row);
});
export const exportAppState = db.procedure({}, t.string(), (ctx) =>
  ctx.withTx((tx) => {
    requireOwner(tx);
    return encodeRetainedState(tx);
  }),
);
export const restoreAppState = db.reducer(
  { payload: t.string() },
  (ctx, args) => {
    requireOwner(ctx);
    if (
      (ctx.db.storageState.id.find(1)?.writableUntil ?? 0n) >
      ctx.timestamp.microsSinceUnixEpoch
    )
      throw new SenderError("Close the storage lease before restoring data.");
    try {
      restoreRetainedState(ctx, args.payload);
    } catch (error) {
      throw new SenderError((error as Error).message);
    }
  },
);
export const exportMarketCheckpoint = db.procedure(
  { table: t.string(), after: t.string() },
  t.string(),
  (ctx, args) =>
    ctx.withTx((tx) => {
      requireOwner(tx);
      return checkpointPage(tx, args.table, args.after);
    }),
);
export const restoreMarketCheckpoint = db.reducer(
  { table: t.string(), payload: t.string() },
  (ctx, args) => {
    requireOwner(ctx);
    if (
      (ctx.db.storageState.id.find(1)?.writableUntil ?? 0n) >
      ctx.timestamp.microsSinceUnixEpoch
    )
      throw new SenderError(
        "Close the storage lease before restoring market data.",
      );
    restoreCheckpointPage(ctx, args.table, args.payload);
  },
);
function importGuides(ctx: Ctx) {
  for (const value of publishedContent) {
    const row = {
      id: BigInt(value.id),
      title: value.title,
      summary: value.summary ?? "",
      category: value.category ?? "General",
      content: JSON.stringify(value.content),
      published: true,
    };
    if (!ctx.db.guide.id.find(row.id)) ctx.db.guide.insert(row);
    if (!ctx.db.guideMetadata.id.find(row.id))
      ctx.db.guideMetadata.insert({
        id: row.id,
        authorName: value.author?.name ?? "Admin",
        publishedAt: BigInt(Date.parse(value.published_at) || 0) * 1000n,
        updatedAt: BigInt(Date.parse(value.updated_at) || 0) * 1000n,
      });
  }
}
export const init = db.init((ctx) => {
  ctx.db.budget.insert({ id: 1, window: 0n, requests: 0, blockedUntil: 0n });
  ctx.db.administrator.insert({ id: 1, owner: ctx.sender });
  importGuides(ctx);
});
export const grantGuideAdministrator = db.reducer(
  { identity: t.identity(), name: t.string(), enabled: t.bool() },
  (ctx, args) => {
    requireWrites(ctx);
    if (!ctx.db.administrator.id.find(1)?.owner.equals(ctx.sender))
      throw new SenderError("Only the database owner can grant guide access.");
    if (!args.enabled) {
      ctx.db.guideAdministrator.identity.delete(args.identity);
      return;
    }
    if (!args.name.trim() || args.name.length > 80)
      throw new SenderError("Choose an administrator display name.");
    const row = { identity: args.identity, name: args.name.trim() };
    if (ctx.db.guideAdministrator.identity.find(args.identity))
      ctx.db.guideAdministrator.identity.update(row);
    else ctx.db.guideAdministrator.insert(row);
  },
);
const canEditGuides = (ctx: any) =>
  Boolean(
    ctx.db.administrator.id.find(1)?.owner.equals(ctx.sender) ||
    ctx.db.guideAdministrator.identity.find(ctx.sender),
  );
export const guideAccess = db.procedure({}, t.bool(), (ctx) =>
  ctx.withTx((tx) => canEditGuides(tx)),
);
export const migrateGuides = db.reducer((ctx) => {
  requireWrites(ctx);
  const admin = ctx.db.administrator.id.find(1);
  if (!admin?.owner.equals(ctx.sender))
    throw new SenderError("Only the database owner can import guides.");
  importGuides(ctx);
});
export const saveGuide = db.reducer(
  {
    id: t.u64(),
    title: t.string(),
    summary: t.string(),
    category: t.string(),
    content: t.string(),
    published: t.bool(),
  },
  (ctx, args) => {
    requireWrites(ctx);
    if (!canEditGuides(ctx))
      throw new SenderError(
        "Only the database owner or a guide administrator can edit guides.",
      );
    let validated;
    try {
      validated = validateGuide(args);
    } catch (error) {
      throw new SenderError((error as Error).message);
    }
    const id =
      args.id ||
      [...ctx.db.guide.iter()].reduce(
        (max, row) => (row.id > max ? row.id : max),
        0n,
      ) + 1n;
    const row = { ...args, ...validated, id };
    const existing = ctx.db.guide.id.find(id),
      metadata = ctx.db.guideMetadata.id.find(id);
    if (existing) ctx.db.guide.id.update(row);
    else ctx.db.guide.insert(row);
    const meta = {
      id,
      authorName:
        metadata?.authorName ??
        ctx.db.guideAdministrator.identity.find(ctx.sender)?.name ??
        "Admin",
      publishedAt: args.published
        ? metadata?.publishedAt || ctx.timestamp.microsSinceUnixEpoch
        : 0n,
      updatedAt: ctx.timestamp.microsSinceUnixEpoch,
    };
    if (metadata) ctx.db.guideMetadata.id.update(meta);
    else ctx.db.guideMetadata.insert(meta);
  },
);
export const editableGuides = db.view(
  { name: "editable_guides", public: true },
  t.array(guide.rowType),
  (ctx) => (canEditGuides(ctx) ? [...ctx.db.guide.iter()] : []),
);
export const visibleGuideMetadata = db.view(
  { name: "visible_guide_metadata", public: true },
  t.array(guideMetadata.rowType),
  (ctx) =>
    [...ctx.db.guideMetadata.iter()].filter(
      (row) => canEditGuides(ctx) || ctx.db.guide.id.find(row.id)?.published,
    ),
);
export const publishedGuides = db.view(
  { name: "published_guides", public: true },
  t.array(guide.rowType),
  (ctx) => [...ctx.db.guide.published.filter(true)],
);
export const publicCatalog = db.procedure(
  { kind: t.string(), id: t.string(), q: t.string() },
  t.string(),
  (_ctx, args) => JSON.stringify(catalogResponse(args.kind, args.id, args.q)),
);
export const saveWidget = db.reducer(
  { token: t.string(), kind: t.string(), settings: t.string() },
  (ctx, args) => {
    requireWrites(ctx);
    const settings = validateWidget(args.token, args.kind, args.settings);
    const existing = ctx.db.widgetProfile.token.find(args.token);
    if (
      existing &&
      (!existing.owner.equals(ctx.sender) || existing.kind !== args.kind)
    )
      throw new SenderError("This widget belongs to another browser.");
    if (
      !existing &&
      ([...ctx.db.widgetProfile.owner.filter(ctx.sender)].length >= 20 ||
        ctx.db.widgetProfile.count() >= 10000n)
    )
      throw new SenderError("The widget profile limit has been reached.");
    const row = {
      ...args,
      settings,
      owner: ctx.sender,
      updatedAt: ctx.timestamp.microsSinceUnixEpoch,
    };
    if (existing) ctx.db.widgetProfile.token.update(row);
    else ctx.db.widgetProfile.insert(row);
  },
);
export const readWidget = db.procedure(
  { token: t.string(), kind: t.string() },
  t.string(),
  (ctx, args) => {
    if (!/^[a-zA-Z0-9_-]{24,80}$/.test(args.token))
      throw new SenderError("Invalid widget link.");
    return ctx.withTx((tx) => {
      const row = tx.db.widgetProfile.token.find(args.token);
      if (!row || row.kind !== args.kind)
        throw new SenderError("This widget profile is unavailable.");
      return JSON.stringify({
        settings: JSON.parse(row.settings),
        editable: row.owner.equals(ctx.sender),
      });
    });
  },
);
const DataResponse = t.object("DataResponse", {
  key: t.string(),
  payload: t.string(),
  updatedAt: t.u64(),
  retryAt: t.u64(),
  error: t.string(),
});

export const authorizeCollector = db.reducer(
  { identity: t.identity(), enabled: t.bool() },
  (ctx, args) => {
    requireWrites(ctx);
    if (!ctx.db.administrator.id.find(1)?.owner.equals(ctx.sender))
      throw new SenderError(
        "Only the database owner can authorize collection.",
      );
    if (args.enabled && !ctx.db.collectorIdentity.identity.find(args.identity))
      ctx.db.collectorIdentity.insert({ identity: args.identity });
    if (!args.enabled) ctx.db.collectorIdentity.identity.delete(args.identity);
  },
);
export const collectorWatches = db.view(
  { name: "collector_watches", public: true },
  t.array(collectionWatch.rowType),
  (ctx) =>
    ctx.db.collectorIdentity.identity.find(ctx.sender)
      ? [...ctx.db.collectionWatch.iter()]
      : [],
);
export const collectorHeartbeat = db.reducer(
  { epoch: t.string(), error: t.string() },
  (ctx, args) => {
    requireWrites(ctx);
    if (!ctx.db.collectorIdentity.identity.find(ctx.sender))
      throw new SenderError("Collector authorization required.");
    if (!/^[a-zA-Z0-9-]{8,80}$/.test(args.epoch) || args.error.length > 240)
      throw new SenderError("Invalid collector status.");
    const row = {
      key: "collector",
      epoch: args.epoch,
      heartbeatAt: ctx.timestamp.microsSinceUnixEpoch,
      feeds: Number(ctx.db.collectionFeed.count()),
      error: args.error,
    };
    const previous = ctx.db.collectionStatus.key.find(row.key);
    if (
      !previous ||
      previous.heartbeatAt / 60000000n !== row.heartbeatAt / 60000000n
    )
      for (const sample of ctx.db.collectionSample.iter())
        if (sample.observedAt + 21600000000n < row.heartbeatAt)
          ctx.db.collectionSample.key.delete(sample.key);
    if (previous) ctx.db.collectionStatus.key.update(row);
    else ctx.db.collectionStatus.insert(row);
    for (const watch of ctx.db.collectionWatch.iter())
      if (watch.expiresAt < ctx.timestamp.microsSinceUnixEpoch)
        ctx.db.collectionWatch.key.delete(watch.key);
    for (const feed of ctx.db.collectionFeed.iter()) {
      if (
        feed.receivedAt + 86400000000n < ctx.timestamp.microsSinceUnixEpoch &&
        !ctx.db.collectionWatch.key.find(feed.key)
      ) {
        for (const row of ctx.db.collectedEntity.scope.filter(feed.key))
          ctx.db.collectedEntity.key.delete(row.key);
        ctx.db.collectionFeed.key.delete(feed.key);
        ctx.db.refreshState.key.delete(`feed|${feed.key}`);
      }
    }
  },
);
export const ingestCollection = db.reducer(
  {
    key: t.string(),
    source: t.string(),
    epoch: t.string(),
    payload: t.string(),
    observedAt: t.u64(),
  },
  (ctx, args) => {
    requireWrites(ctx);
    if (!ctx.db.collectorIdentity.identity.find(ctx.sender))
      throw new SenderError("Collector authorization required.");
    const watch = ctx.db.collectionWatch.key.find(args.key);
    if (
      !watch ||
      !["relay", "bitjita"].includes(args.source) ||
      !/^[a-zA-Z0-9-]{8,80}$/.test(args.epoch) ||
      args.payload.length > 4000000 ||
      !args.observedAt ||
      args.observedAt > ctx.timestamp.microsSinceUnixEpoch + 5000000n
    )
      throw new SenderError("Invalid collection snapshot.");
    const previous = collectedFeed(ctx, args.key);
    if (
      previous?.epoch.includes("-native-") &&
      !args.epoch.includes("-native-") &&
      !previous.error &&
      previous.expiresAt > ctx.timestamp.microsSinceUnixEpoch
    )
      throw new SenderError(
        "The live relay owns this scope until its lease expires.",
      );
    if (
      previous &&
      args.source === previous.source &&
      args.observedAt < previous.observedAt
    )
      throw new SenderError("Collection snapshot is out of order.");
    let projected;
    try {
      const payload = JSON.parse(args.payload);
      const relay = watch.resource.startsWith("relay");
      if (args.source !== (relay ? "relay" : "bitjita"))
        throw new Error("Collection source does not match its scope.");
      const kind = relay
        ? relayRequest(watch.resource, watch.entityId, watch.query).kind
        : watch.resource;
      if (kind === "nearby") {
        if (
          !Array.isArray(payload.resources) ||
          !Number.isInteger(payload.region) ||
          payload.resources.length > 2048 ||
          payload.resources.some(
            (row: any) =>
              !Number.isInteger(row.resourceId) ||
              typeof row.name !== "string" ||
              !Number.isInteger(row.count) ||
              row.count < 0 ||
              !Number.isFinite(row.north) ||
              !Number.isFinite(row.east),
          )
        )
          throw new Error("Invalid resource summary.");
      } else if (
        !validProviderPayload(
          args.source as ProviderName,
          kind,
          payload,
          watch.entityId,
        )
      ) {
        throw new Error("Invalid collection response.");
      }
      projected = projectCollection(watch.resource, payload, watch.entityId);
    } catch (error) {
      throw new SenderError((error as Error).message);
    }
    if (!previous && ctx.db.collectionFeed.count() >= 500n) {
      const inactive = [...ctx.db.collectionFeed.iter()]
        .filter((feed) => {
          const scope = ctx.db.collectionWatch.key.find(feed.key);
          return (
            !scope || scope.expiresAt <= ctx.timestamp.microsSinceUnixEpoch
          );
        })
        .sort((a, b) => (a.receivedAt < b.receivedAt ? -1 : 1))[0];
      if (!inactive) throw new SenderError("Collection feed limit reached.");
      for (const entity of ctx.db.collectedEntity.scope.filter(inactive.key))
        ctx.db.collectedEntity.key.delete(entity.key);
      ctx.db.collectionFeed.key.delete(inactive.key);
      ctx.db.refreshState.key.delete(`feed|${inactive.key}`);
    }
    if (
      [...ctx.db.collectionFeed.iter()].reduce(
        (sum, row) => sum + (row.key === args.key ? 0 : row.payload.length),
        args.payload.length,
      ) > 32000000
    )
      throw new SenderError(
        "Collection payload budget reached. Existing data remains available.",
      );
    const old = [...ctx.db.collectedEntity.scope.filter(args.key)];
    if (
      ctx.db.collectedEntity.count() -
        BigInt(old.length) +
        BigInt(projected.length) >
      100000n
    )
      throw new SenderError("Collected entity limit reached.");
    if (
      watch.resource !== "relaySkills" &&
      [...ctx.db.collectedEntity.iter()].reduce(
        (sum, row) => sum + (row.scope === args.key ? 0 : row.payload.length),
        projected.reduce(
          (sum: number, row: any) => sum + row.payload.length,
          0,
        ),
      ) > 64000000
    )
      throw new SenderError("Collection projection budget reached.");
    const remaining = new Map(old.map((row) => [row.key, row]));
    for (const projectedRow of projected) {
      const row = {
        ...projectedRow,
        key: `${args.key}|${projectedRow.kind}|${projectedRow.entityId}|${projectedRow.itemKey}`,
        scope: args.key,
        source: args.source,
        observedAt: args.observedAt,
      };
      const previousRow = remaining.get(row.key);
      if (!previousRow) ctx.db.collectedEntity.insert(row);
      else if (
        JSON.stringify({ ...previousRow, observedAt: undefined }) !==
        JSON.stringify({ ...row, observedAt: undefined })
      )
        ctx.db.collectedEntity.key.update(row);
      remaining.delete(row.key);
    }
    for (const key of remaining.keys()) ctx.db.collectedEntity.key.delete(key);
    const row = {
      ...args,
      resource: watch.resource,
      entityId: watch.entityId,
      receivedAt: ctx.timestamp.microsSinceUnixEpoch,
      expiresAt:
        args.observedAt +
        BigInt(collectionInterval(watch.resource) * 3) * 1000000n,
      error: "",
    };
    if (!previous) ctx.db.collectionFeed.insert(row);
    else if (
      previous.payload !== row.payload ||
      previous.epoch !== row.epoch ||
      previous.source !== row.source ||
      watch.resource === "relaySkills"
    )
      ctx.db.collectionFeed.key.update(row);
    saveRefresh(
      ctx,
      `feed|${args.key}`,
      { updatedAt: args.observedAt, expiresAt: row.expiresAt, error: "" },
      previous,
    );

    const skills = projected.filter((row) => row.kind === "skill");
    if (skills.length || watch.resource === "relaySkills") {
      const key = `${args.key}|${args.source}|${args.epoch}|${args.observedAt / 60000000n}`;
      const sample = {
        key,
        scope: args.key,
        source: args.source,
        epoch: args.epoch,
        observedAt: args.observedAt,
        payload: JSON.stringify(
          Object.fromEntries(skills.map((row) => [row.entityId, row.quantity])),
        ),
      };
      if (ctx.db.collectionSample.key.find(key))
        ctx.db.collectionSample.key.update(sample);
      else {
        if (ctx.db.collectionSample.count() >= 6000n) {
          const oldest = [...ctx.db.collectionSample.iter()].reduce((a, b) =>
            a.observedAt < b.observedAt ? a : b,
          );
          ctx.db.collectionSample.key.delete(oldest.key);
        }
        ctx.db.collectionSample.insert(sample);
      }
    }
  },
);
export const touchCollection = db.reducer(
  { key: t.string(), observedAt: t.u64() },
  (ctx, args) => {
    requireMarketCollector(ctx);
    const feed = collectedFeed(ctx, args.key);
    if (
      !feed ||
      !args.observedAt ||
      args.observedAt < feed.observedAt ||
      args.observedAt > ctx.timestamp.microsSinceUnixEpoch + 5000000n
    )
      throw new SenderError("Invalid collection freshness.");
    saveRefresh(
      ctx,
      `feed|${args.key}`,
      {
        updatedAt: args.observedAt,
        expiresAt:
          args.observedAt +
          BigInt(collectionInterval(feed.resource) * 3) * 1000000n,
        error: "",
      },
      feed,
    );
  },
);
export const collectionHistory = db.procedure(
  { playerId: t.string() },
  t.string(),
  (ctx, args) => {
    if (!/^\d{1,24}$/.test(args.playerId))
      throw new SenderError("Choose a valid player.");
    return ctx.withTx((tx) => {
      const scope = collectionKey(
        "relaySkills",
        `player/${args.playerId}/skills`,
      );
      const feed = collectedFeed(tx, scope);
      const samples = [...tx.db.collectionSample.scope.filter(scope)]
        .filter(
          (row) =>
            row.observedAt + 21600000000n > ctx.timestamp.microsSinceUnixEpoch,
        )
        .sort((a, b) => (a.observedAt < b.observedAt ? -1 : 1))
        .slice(-360)
        .map((row) => ({
          at: Number(row.observedAt / 1000n),
          source: row.source,
          epoch: row.epoch,
          xp: JSON.parse(row.payload),
        }));
      const status = tx.db.collectionStatus.key.find("collector");
      const fresh =
        feed &&
        !feed.error &&
        feed.expiresAt > ctx.timestamp.microsSinceUnixEpoch &&
        status &&
        (status.epoch === feed.epoch ||
          feed.epoch.startsWith(status.epoch + "-native-")) &&
        status.heartbeatAt + 30000000n > ctx.timestamp.microsSinceUnixEpoch;
      return JSON.stringify({
        samples,
        rates: fresh ? observedRates(samples) : [],
        fresh: Boolean(fresh),
      });
    });
  },
);
export const collectionFailure = db.reducer(
  { key: t.string() },
  (ctx, args) => {
    requireWrites(ctx);
    if (!ctx.db.collectorIdentity.identity.find(ctx.sender))
      throw new SenderError("Collector authorization required.");
    const row = ctx.db.collectionFeed.key.find(args.key);
    if (row)
      saveRefresh(
        ctx,
        `feed|${args.key}`,
        { error: "Refresh delayed. The last available data is shown." },
        row,
      );
  },
);

export const configureProvider = db.reducer(
  { name: t.string(), config: t.string() },
  (ctx, args) => {
    requireWrites(ctx);
    if (!ctx.db.administrator.id.find(1)?.owner.equals(ctx.sender))
      throw new SenderError("Only the database owner can configure providers.");
    let settings;
    try {
      settings = providerConfig(args.name, args.config);
    } catch (error) {
      throw new SenderError((error as Error).message);
    }
    const row = { name: args.name, config: JSON.stringify(settings) };
    if (ctx.db.providerSettings.name.find(args.name))
      ctx.db.providerSettings.name.update(row);
    else ctx.db.providerSettings.insert(row);
    for (const cached of ctx.db.snapshot.iter())
      if (cached.key.startsWith(args.name + "|"))
        ctx.db.snapshot.key.delete(cached.key);
    for (const feed of ctx.db.collectionFeed.iter())
      if (feed.source === args.name)
        ctx.db.collectionFeed.key.update({
          ...feed,
          expiresAt: 0n,
          error: "Refreshing after configuration change.",
        });
  },
);
function settings(ctx: any, name: ProviderName) {
  const value = ctx.db.providerSettings.name.find(name);
  return value ? providerConfig(name, value.config) : providerDefaults[name];
}
export const providerPolicy = db.procedure({}, t.string(), (ctx) =>
  ctx.withTx((tx) => {
    const config = settings(tx, "bitjita");
    return JSON.stringify({
      stallsCacheSeconds: config.ttl.stalls,
      marketCacheSeconds: config.ttl.market,
      poolConcurrency: config.poolConcurrency ?? 8,
    });
  }),
);
export const reserveCollectionMap = db.procedure(
  { playerId: t.string() },
  t.string(),
  (ctx, args) => {
    if (!/^\d{1,24}$/.test(args.playerId))
      throw new SenderError("Choose a valid player.");
    return ctx.withTx((tx) => {
      requireWrites(tx);
      if (!tx.db.collectorIdentity.identity.find(ctx.sender))
        throw new SenderError("Collector authorization required.");
      const watch = tx.db.collectionWatch.key.find(
        collectionKey(
          "relayNearby",
          `bitme/session/${args.playerId}/resources`,
        ),
      );
      if (!watch || watch.expiresAt <= ctx.timestamp.microsSinceUnixEpoch)
        throw new SenderError("Map is not being watched.");
      const config = settings(tx, "relay"),
        now = ctx.timestamp.microsSinceUnixEpoch,
        window = now / 60000000n;
      const counter = tx.db.providerBudget.key.find("relay") ?? {
        key: "relay",
        window,
        requests: 0,
        blockedUntil: 0n,
      };
      if (
        !config.enabled ||
        counter.blockedUntil > now ||
        (counter.window === window &&
          counter.requests + 2 > config.requestsPerMinute)
      )
        throw new SenderError("Refresh delayed. Please try again shortly.");
      const next = {
        ...counter,
        window,
        requests: (counter.window === window ? counter.requests : 0) + 2,
      };
      if (tx.db.providerBudget.key.find("relay"))
        tx.db.providerBudget.key.update(next);
      else tx.db.providerBudget.insert(next);
      return JSON.stringify({ baseUrl: config.baseUrl });
    });
  },
);
function requireMarketCollector(ctx: Ctx) {
  requireWrites(ctx);
  if (!ctx.db.collectorIdentity.identity.find(ctx.sender))
    throw new SenderError("Collector authorization required.");
}
function marketInput<T>(parse: () => T): T {
  try {
    return parse();
  } catch (error) {
    throw new SenderError((error as Error).message);
  }
}
function marketIdentity(kind: string, id: unknown) {
  return marketInput(() => parseMarketIdentity(kind, id));
}
function currentMarketStatus(ctx: Ctx) {
  return (
    ctx.db.marketStatus.key.find("market") ?? {
      key: "market",
      revision: 0n,
      indexAt: 0n,
      updatedAt: 0n,
      totalItems: 0,
      loadedItems: 0,
      activeItems: 0,
      loadedActiveItems: 0,
      error: "",
    }
  );
}
function saveMarketStatus(
  ctx: Ctx,
  changes: Partial<ReturnType<typeof currentMarketStatus>> = {},
) {
  const previous = currentMarketStatus(ctx);
  const row = { ...previous, ...changes, revision: previous.revision + 1n };
  if (ctx.db.marketStatus.key.find("market"))
    ctx.db.marketStatus.key.update(row);
  else ctx.db.marketStatus.insert(row);
}
function saveMarketSummaries(
  ctx: Ctx,
  key: string,
  book: Record<string, any>,
  observedAt: bigint,
) {
  book = visibleMarketBook(book);
  const previous = new Map(
    [...ctx.db.marketSummaryRow.itemKey.filter(key)].map((row) => [
      row.key,
      row,
    ]),
  );
  for (const regionId of marketRegions(book)) {
    const row = {
      key: `${key}:${regionId}`,
      itemKey: key,
      regionId,
      observedAt,
      payload: JSON.stringify(marketSummary(book, { regionId })),
    };
    const old = previous.get(row.key);
    if (!old) ctx.db.marketSummaryRow.insert(row);
    else if (
      old.payload !== row.payload ||
      old.observedAt / 60000000n !== observedAt / 60000000n
    )
      ctx.db.marketSummaryRow.key.update(row);
    previous.delete(row.key);
  }
  for (const key of previous.keys()) ctx.db.marketSummaryRow.key.delete(key);
}
function enforceTradingBudget(ctx: Ctx) {
  if (
    [...ctx.db.tradeRecord.iter()].reduce(
      (sum, row) => sum + row.payload.length,
      0,
    ) +
      [...ctx.db.tradeReference.iter()].reduce(
        (sum, row) => sum + row.payload.length,
        0,
      ) >
    64000000
  )
    throw new SenderError("Canonical trading payload budget reached.");
}
function nativeRegions(ctx: Ctx, keys?: Set<string>) {
  const regions = [...ctx.db.relayRegion.iter()].map((row) => ({
    regionId: row.regionId,
    books: {} as Record<string, any>,
  }));
  const rows = keys
    ? [...keys].flatMap((key) => [...ctx.db.relayBook.itemKey.filter(key)])
    : [...ctx.db.relayBook.iter()];
  const lookup = new Map(regions.map((region) => [region.regionId, region]));
  for (const row of rows) {
    const region = lookup.get(row.regionId);
    if (region) region.books[row.itemKey] = loadBook(ctx, row.payload);
  }
  return regions;
}
function nativeBook(ctx: Ctx, key: string, book: Record<string, any>) {
  return mergeRegionalBook(key, book, nativeRegions(ctx, new Set([key])));
}
function nativeTradingReady(ctx: Ctx, status: any) {
  const region = ctx.db.relayRegion.regionId.find(status.regionId);
  return (
    status.ready &&
    !status.error &&
    region?.epoch === status.epoch &&
    status.heartbeatAt + 70000000n > ctx.timestamp.microsSinceUnixEpoch
  );
}
export const relayHeartbeat = db.reducer(
  {
    key: t.string(),
    provider: t.string(),
    regionId: t.u32(),
    epoch: t.string(),
    ready: t.bool(),
    rows: t.u32(),
    error: t.string(),
  },
  (ctx, args) => {
    requireWrites(ctx);
    requireMarketCollector(ctx);
    if (
      !/^(trading:\d+|player:\d{1,24})$/.test(args.key) ||
      !["bitconnect", "bitsync"].includes(args.provider) ||
      !CURRENT_REGIONS[args.regionId] ||
      args.epoch.length > 80 ||
      args.error.length > 240
    )
      throw new SenderError("Invalid relay status.");
    const previous = ctx.db.relayStatus.key.find(args.key);
    const row = {
      ...args,
      heartbeatAt: ctx.timestamp.microsSinceUnixEpoch,
      observedAt: previous?.observedAt ?? 0n,
    };
    if (previous) ctx.db.relayStatus.key.update(row);
    else ctx.db.relayStatus.insert(row);
  },
);
export const ingestRelayTrading = db.reducer(
  {
    regionId: t.u32(),
    epoch: t.string(),
    payload: t.string(),
    observedAt: t.u64(),
  },
  (ctx, args) => {
    requireWrites(ctx);
    requireMarketCollector(ctx);
    const status = ctx.db.relayStatus.key.find(`trading:${args.regionId}`);
    if (
      !status?.ready ||
      status.epoch !== args.epoch ||
      status.heartbeatAt + 70000000n < ctx.timestamp.microsSinceUnixEpoch ||
      args.payload.length > 16000000 ||
      !args.observedAt ||
      args.observedAt > ctx.timestamp.microsSinceUnixEpoch + 5000000n
    )
      throw new SenderError("Invalid relay trading generation.");
    const previous = ctx.db.relayRegion.regionId.find(args.regionId);
    if (previous && args.observedAt < previous.observedAt)
      throw new SenderError("Regional snapshot is out of order.");
    const raw = marketInput(() => JSON.parse(args.payload));
    if (raw.delta === true && raw.reset === false) {
      marketInput(() => {
        for (const [key, patch] of Object.entries(raw.books ?? {}) as [
          string,
          any,
        ][]) {
          if (patch._ordersDelta) {
            const prior = ctx.db.relayBook.key.find(`${args.regionId}:${key}`);
            raw.books[key] = applyOrderDelta(
              prior ? loadBook(ctx, prior.payload) : null,
              patch,
            );
          }
        }
        raw.stalls = (raw.stalls ?? []).map((patch: any) => {
          if (!patch._ordersDelta) return patch;
          const prior = ctx.db.relayStall.key.find(
            `${args.regionId}:${patch.entityId}`,
          );
          return applyOrderDelta(
            prior
              ? loadStall(ctx, prior.payload, (kind, id) =>
                  catalogResponse("item-info", `${kind}:${id}`, ""),
                )
              : null,
            patch,
            true,
          );
        });
      });
    } else if (
      Object.values(raw.books ?? {}).some((book: any) => book._ordersDelta) ||
      (raw.stalls ?? []).some((stall: any) => stall._ordersDelta)
    ) {
      throw new SenderError("Initial generations require complete orders.");
    }
    const data = marketInput(() => validateRelayTrading(args.regionId, raw));
    const patch = raw.delta === true;
    if (patch && typeof raw.reset !== "boolean")
      throw new SenderError("Invalid relay patch.");
    if (patch && !raw.reset && (!previous || previous.epoch !== args.epoch))
      throw new SenderError("A new generation requires a complete snapshot.");
    const oldBooks = new Map(
      [...ctx.db.relayBook.regionId.filter(args.regionId)].map((row) => [
        row.itemKey,
        row,
      ]),
    );
    const oldStalls = new Map(
      [...ctx.db.relayStall.regionId.filter(args.regionId)].map((row) => [
        row.key.split(":")[1]!,
        row,
      ]),
    );
    const reset = !patch || raw.reset;
    const removedBooks: string[] = reset
      ? [...oldBooks.keys()].filter((key) => !data.books[key])
      : raw.removedBooks;
    const newStalls = new Set(data.stalls.map((stall: any) => stall.entityId));
    const removedStalls: string[] = reset
      ? [...oldStalls.keys()].filter((key) => !newStalls.has(key))
      : raw.removedStalls;
    if (
      !Array.isArray(removedBooks) ||
      !Array.isArray(removedStalls) ||
      removedBooks.length > 20000 ||
      removedStalls.length > 20000 ||
      removedBooks.some(
        (key) => !/^(item|cargo):\d{1,24}$/.test(key) || data.books[key],
      ) ||
      removedStalls.some((key) => !/^\d{1,24}$/.test(key) || newStalls.has(key))
    )
      throw new SenderError("Invalid regional removals.");
    const beforeBooks = new Map(
      Object.keys(data.books).map((key) => [
        key,
        oldBooks.get(key)
          ? tradingJson(loadBook(ctx, oldBooks.get(key)!.payload))
          : "",
      ]),
    );
    const affected = new Set<string>(removedBooks);
    for (const [itemKey, book] of Object.entries(data.books)) {
      const compact = storeBook(
        ctx,
        `native:${args.regionId}:${itemKey}`,
        book,
      );
      const row = {
        key: `${args.regionId}:${itemKey}`,
        regionId: args.regionId,
        itemKey,
        payload: compact.payload,
      };
      const old = oldBooks.get(itemKey);
      if (
        old?.payload === row.payload &&
        !compact.changed &&
        beforeBooks.get(itemKey) === tradingJson(book)
      )
        continue;
      if (old) ctx.db.relayBook.key.update(row);
      else ctx.db.relayBook.insert(row);
      affected.add(itemKey);
    }
    for (const key of removedBooks) {
      ctx.db.relayBook.key.delete(`${args.regionId}:${key}`);
      clearTradeScope(ctx, `native:${args.regionId}:${key}`);
    }
    for (const stall of data.stalls) {
      const compact = storeStall(
        ctx,
        `barter:${args.regionId}:${stall.entityId}`,
        stall,
      );
      const row = {
        key: `${args.regionId}:${stall.entityId}`,
        regionId: args.regionId,
        payload: compact.payload,
      };
      const old = oldStalls.get(stall.entityId);
      if (old?.payload === row.payload && !compact.changed) continue;
      if (old) ctx.db.relayStall.key.update(row);
      else ctx.db.relayStall.insert(row);
    }
    for (const key of removedStalls) {
      ctx.db.relayStall.key.delete(`${args.regionId}:${key}`);
      clearTradeScope(ctx, `barter:${args.regionId}:${key}`);
    }
    enforceTradingBudget(ctx);
    const row = {
      regionId: args.regionId,
      epoch: args.epoch,
      observedAt: args.observedAt,
      payload: "{}",
    };
    if (previous) ctx.db.relayRegion.regionId.update(row);
    else ctx.db.relayRegion.insert(row);
    const market = currentMarketStatus(ctx);
    const allReady =
      !previous &&
      ctx.db.relayRegion.count() ===
        BigInt(Object.keys(CURRENT_REGIONS).length);
    const regions = nativeRegions(ctx, allReady ? undefined : affected);
    for (const key of affected) {
      const stored = ctx.db.marketItem.key.find(key);
      const metadata = stored
        ? JSON.parse(stored.metadata)
        : data.books[key]?.item;
      if (!metadata) continue;
      const book = mergeRegionalBook(
        key,
        stored?.book ? loadBook(ctx, stored.book) : emptyMarketBook(metadata),
        regions,
      );
      const next = {
        key,
        metadata: JSON.stringify(metadata),
        indexAt: stored?.indexAt ?? market.indexAt,
        indexHasOrders: stored?.indexHasOrders ?? true,
        bookHasOrders: Boolean(
          marketSummary(book).sellOrderCount +
          marketSummary(book).buyOrderCount,
        ),
        book:
          ctx.db.relayRegion.count() ===
          BigInt(Object.keys(CURRENT_REGIONS).length)
            ? JSON.stringify(emptyMarketBook(marketDisplayItem(book.item)))
            : storeBook(ctx, `api:${key}`, book).payload,
        observedAt: args.observedAt,
        requestedAt: stored?.requestedAt ?? 0n,
        retryAt: stored?.retryAt ?? 0n,
        error: stored?.error ?? "",
      };
      if (
        ctx.db.relayRegion.count() ===
        BigInt(Object.keys(CURRENT_REGIONS).length)
      )
        clearTradeScope(ctx, `api:${key}`);
      if (stored) ctx.db.marketItem.key.update(next);
      else ctx.db.marketItem.insert(next);
      saveMarketSummaries(ctx, key, book, args.observedAt);
    }
    // After all regions are applied, their complete order union also proves zero
    // offers for directory items absent from every regional snapshot.
    if (!previous && regions.length === Object.keys(CURRENT_REGIONS).length) {
      for (const item of ctx.db.marketItem.iter()) {
        if (item.indexAt !== market.indexAt || affected.has(item.key)) continue;
        const base = item.book
          ? loadBook(ctx, item.book)
          : emptyMarketBook(JSON.parse(item.metadata));
        const book = mergeRegionalBook(item.key, base, regions),
          encoded = JSON.stringify(
            emptyMarketBook(marketDisplayItem(book.item)),
          );
        clearTradeScope(ctx, `api:${item.key}`);
        if (!item.observedAt || encoded !== item.book) {
          ctx.db.marketItem.key.update({
            ...item,
            book: encoded,
            bookHasOrders: Boolean(
              marketSummary(book).sellOrderCount +
              marketSummary(book).buyOrderCount,
            ),
            observedAt: args.observedAt,
          });
          saveMarketSummaries(ctx, item.key, book, args.observedAt);
        }
      }
    }
    const items = [...ctx.db.marketItem.iter()].filter(
      (r) => r.indexAt === market.indexAt,
    );
    saveMarketStatus(ctx, {
      updatedAt: ctx.timestamp.microsSinceUnixEpoch,
      totalItems: items.length,
      loadedItems: items.filter((r) => r.observedAt > 0n).length,
      activeItems: items.filter((r) => r.indexHasOrders || r.bookHasOrders)
        .length,
      loadedActiveItems: items.filter(
        (r) => r.observedAt > 0n && (r.indexHasOrders || r.bookHasOrders),
      ).length,
      error: "",
    });
    ctx.db.relayStatus.key.update({ ...status, observedAt: args.observedAt });
  },
);
export const readRelayBarter = db.procedure({}, t.string(), (ctx) =>
  ctx.withTx((tx) => {
    const regions = [...tx.db.relayRegion.iter()];
    const health = [...tx.db.relayStatus.iter()].filter((r) =>
      r.key.startsWith("trading:"),
    );
    return JSON.stringify({
      available: regions.length > 0,
      complete: regions.length === Object.keys(CURRENT_REGIONS).length,
      stalls: [...tx.db.relayStall.iter()].map((r) =>
        loadStall(tx, r.payload, (kind, id) =>
          catalogResponse("item-info", `${kind}:${id}`, ""),
        ),
      ),
      regions: Object.entries(CURRENT_REGIONS).map(([id, name]) => ({
        id,
        name,
      })),
      coverage: health.map((r) => ({
        regionId: r.regionId,
        ready: nativeTradingReady(tx, r),
        observedAt: String(r.observedAt),
        error: r.error,
      })),
      updatedAt: regions.length
        ? String(
            regions.reduce((a, b) => (a.observedAt < b.observedAt ? a : b))
              .observedAt,
          )
        : "0",
    });
  }),
);
export const ingestMarketDirectory = db.reducer(
  { resource: t.string(), payload: t.string(), observedAt: t.u64() },
  (ctx, args) => {
    requireWrites(ctx);
    requireMarketCollector(ctx);
    if (
      !["market", "regions"].includes(args.resource) ||
      args.payload.length > 16000000 ||
      !args.observedAt ||
      args.observedAt > ctx.timestamp.microsSinceUnixEpoch + 5000000n
    )
      throw new SenderError("Invalid market directory.");
    const previous = ctx.db.marketMeta.key.find(args.resource);
    if (previous && args.observedAt < previous.observedAt)
      throw new SenderError("Market directory is out of order.");
    const raw = marketInput(() => JSON.parse(args.payload)),
      data = raw.data ?? raw;
    if (args.resource === "regions") {
      if (
        !Array.isArray(data.regions ?? data) ||
        (data.regions ?? data).length > 1000
      )
        throw new SenderError("Invalid market regions.");
    } else {
      const items = marketInput(() => marketDirectory(raw));
      let loadedItems = 0,
        activeItems = 0,
        loadedActiveItems = 0;
      const keys = new Set<string>(items.map((item: any) => item.key));
      const status = currentMarketStatus(ctx);
      const existingKeys = new Set(
        [...ctx.db.marketItem.iter()]
          .filter((row) => row.indexAt === status.indexAt)
          .map((row) => row.key),
      );
      const indexAt =
        !status.indexAt ||
        keys.size !== existingKeys.size ||
        [...keys].some((key) => !existingKeys.has(key))
          ? args.observedAt
          : status.indexAt;
      // Membership gets a generation marker. A timestamp-only directory refresh
      // must not rewrite every retained book and summary in the transaction log.
      for (const summary of ctx.db.marketSummaryRow.iter())
        if (!keys.has(summary.itemKey))
          ctx.db.marketSummaryRow.key.delete(summary.key);
      for (const item of items) {
        const old = ctx.db.marketItem.key.find(item.key);
        const active = item.sellOrderCount + item.buyOrderCount > 0;
        let book = old?.book ?? "",
          observedAt = old?.observedAt ?? 0n;
        // A full index confirming no offers is enough for a previously empty
        // item. Previously populated books require their own complete refresh.
        if (!active && (!book || !old?.bookHasOrders)) {
          const empty = JSON.stringify(
            emptyMarketBook(marketDisplayItem(item)),
          );
          const existing = book ? JSON.stringify(loadBook(ctx, book)) : "";
          if (empty !== existing || !observedAt) observedAt = args.observedAt;
          book = empty;
        }
        if (!book) {
          const resource = item.kind === "cargo" ? "cargoOrders" : "itemOrders";
          const path = upstreamRequest(resource, item.id, "", 1, {}).key;
          const cached = ctx.db.snapshot.key.find(`bitjita|${path}`);
          const collected = ctx.db.collectionFeed.key.find(
            collectionKey(resource, path),
          );
          const source =
            collected?.payload && !collected.error
              ? { payload: collected.payload, at: collected.observedAt }
              : cached?.payload && !cached.error
                ? { payload: cached.payload, at: cached.updatedAt }
                : null;
          if (source) {
            try {
              book = JSON.stringify(
                marketBook(
                  item.kind,
                  item.id,
                  JSON.parse(source.payload),
                  item,
                ),
              );
              observedAt = source.at;
            } catch {
              /* Leave invalid or scoped cache data out of the durable store. */
            }
          }
        }
        const row = {
          key: item.key,
          metadata: JSON.stringify(marketDisplayItem(item)),
          indexAt,
          indexHasOrders: active,
          bookHasOrders:
            old && ctx.db.relayRegion.count() > 0n
              ? old.bookHasOrders
              : book
                ? Boolean(
                    marketSummary(loadBook(ctx, book)).sellOrderCount +
                    marketSummary(loadBook(ctx, book)).buyOrderCount,
                  )
                : false,
          book: book
            ? ctx.db.relayRegion.count() ===
              BigInt(Object.keys(CURRENT_REGIONS).length)
              ? JSON.stringify(emptyMarketBook(marketDisplayItem(item)))
              : storeBook(ctx, `api:${item.key}`, loadBook(ctx, book)).payload
            : "",
          observedAt,
          requestedAt: old?.requestedAt ?? 0n,
          retryAt: old?.retryAt ?? 0n,
          error: old?.error ?? "",
        };
        const dataChanged =
          !old || old.metadata !== row.metadata || old.book !== row.book;
        if (!old) ctx.db.marketItem.insert(row);
        else if (
          dataChanged ||
          old.indexAt !== row.indexAt ||
          old.indexHasOrders !== row.indexHasOrders ||
          old.bookHasOrders !== row.bookHasOrders ||
          old.observedAt !== row.observedAt
        )
          ctx.db.marketItem.key.update(row);
        if (book && dataChanged)
          saveMarketSummaries(
            ctx,
            item.key,
            {
              ...nativeBook(ctx, item.key, loadBook(ctx, row.book)),
              item: { ...loadBook(ctx, book).item, ...marketDisplayItem(item) },
            },
            observedAt,
          );
        else if (!book && dataChanged) {
          const pending = {
            ...marketDisplayItem(item),
            sellOrderCount: item.sellOrderCount,
            buyOrderCount: item.buyOrderCount,
            lowestSellPrice: null,
            highestBuyPrice: null,
            lowestBuyPrice: null,
            sellOrderQuantity: null,
            buyOrderQuantity: null,
            pendingCollection: true,
          };
          const summary = {
            key: `${item.key}:0`,
            itemKey: item.key,
            regionId: 0,
            payload: JSON.stringify(pending),
            observedAt: 0n,
          };
          if (ctx.db.marketSummaryRow.key.find(summary.key))
            ctx.db.marketSummaryRow.key.update(summary);
          else ctx.db.marketSummaryRow.insert(summary);
        }
        if (book) loadedItems++;
        if (active) {
          activeItems++;
          if (book) loadedActiveItems++;
        }
      }
      saveMarketStatus(ctx, {
        indexAt,
        updatedAt: ctx.timestamp.microsSinceUnixEpoch,
        totalItems: items.length,
        loadedItems,
        activeItems,
        loadedActiveItems,
        error: "",
      });
    }
    enforceTradingBudget(ctx);
    const row = {
      key: args.resource,
      payload: args.resource === "market" ? "{}" : args.payload,
      observedAt: args.observedAt,
      retryAt: 0n,
      error: "",
    };
    if (previous) ctx.db.marketMeta.key.update(row);
    else ctx.db.marketMeta.insert(row);
    saveRefresh(
      ctx,
      `directory|${args.resource}`,
      { updatedAt: args.observedAt, retryAt: 0n, error: "" },
      row,
    );
  },
);
export const ingestMarketBook = db.reducer(
  {
    kind: t.string(),
    id: t.string(),
    payload: t.string(),
    observedAt: t.u64(),
  },
  (ctx, args) => {
    requireWrites(ctx);
    requireMarketCollector(ctx);
    const key = marketIdentity(args.kind, args.id),
      previous = ctx.db.marketItem.key.find(key);
    if (
      !previous ||
      args.payload.length > 8000000 ||
      !args.observedAt ||
      args.observedAt > ctx.timestamp.microsSinceUnixEpoch + 5000000n
    )
      throw new SenderError("Invalid market snapshot.");
    if (args.observedAt < previous.observedAt)
      throw new SenderError("Market snapshot is out of order.");
    const book = nativeBook(
      ctx,
      key,
      marketInput(() =>
        marketBook(
          args.kind,
          args.id,
          JSON.parse(args.payload),
          JSON.parse(previous.metadata),
        ),
      ),
    );
    saveMarketSummaries(ctx, key, book, args.observedAt);
    ctx.db.marketItem.key.update({
      ...previous,
      book:
        ctx.db.relayRegion.count() ===
        BigInt(Object.keys(CURRENT_REGIONS).length)
          ? JSON.stringify(emptyMarketBook(marketDisplayItem(book.item)))
          : storeBook(ctx, `api:${key}`, book).payload,
      bookHasOrders: Boolean(
        marketSummary(book).sellOrderCount + marketSummary(book).buyOrderCount,
      ),
      observedAt: args.observedAt,
      requestedAt:
        args.observedAt >= previous.requestedAt
          ? previous.requestedAt
          : args.observedAt,
      retryAt: 0n,
      error: "",
    });
    enforceTradingBudget(ctx);
    const status = currentMarketStatus(ctx);
    saveMarketStatus(ctx, {
      loadedItems: status.loadedItems + (previous.observedAt ? 0 : 1),
      loadedActiveItems:
        status.loadedActiveItems +
        (!previous.observedAt && previous.indexHasOrders ? 1 : 0),
      updatedAt: ctx.timestamp.microsSinceUnixEpoch,
      error: "",
    });
  },
);
export const requestMarketRefresh = db.reducer(
  { query: t.string(), itemId: t.string(), kind: t.string() },
  (ctx, args) => {
    requireWrites(ctx);
    if ((!args.query.trim() && !args.itemId) || args.query.length > 120)
      throw new SenderError("Choose an item to refresh.");
    if (args.itemId) marketIdentity(args.kind, args.itemId);
    const now = ctx.timestamp.microsSinceUnixEpoch,
      status = currentMarketStatus(ctx);
    let queued = false;
    for (const row of ctx.db.marketItem.iter()) {
      if (
        row.indexAt !== status.indexAt ||
        !marketMatches(
          JSON.parse(row.metadata),
          args.query,
          args.itemId,
          args.kind,
        )
      )
        continue;
      if (
        row.requestedAt > row.observedAt ||
        row.observedAt + BigInt(MARKET_SEARCH_SECONDS) * 1000000n > now
      )
        continue;
      ctx.db.marketItem.key.update({ ...row, requestedAt: now });
      queued = true;
    }
    if (queued) saveMarketStatus(ctx);
  },
);
export const marketCollectionWork = db.procedure({}, t.string(), (ctx) =>
  ctx.withTx((tx) => {
    requireMarketCollector(tx);
    // Rebuild retained summaries from old snapshots without waiting for upstream refresh.
    const retiredItems = new Set<string>();
    for (const regionId of RETIRED_REGION_IDS)
      for (const row of tx.db.marketSummaryRow.regionId.filter(regionId))
        retiredItems.add(row.itemKey);
    for (const key of retiredItems) {
      const item = tx.db.marketItem.key.find(key);
      if (item?.book)
        saveMarketSummaries(
          tx,
          key,
          nativeBook(tx, item.key, loadBook(tx, item.book)),
          item.observedAt,
        );
    }
    if (retiredItems.size) saveMarketStatus(tx);
    const now = tx.timestamp.microsSinceUnixEpoch,
      work: Record<string, any>[] = [];
    for (const resource of ["regions", "market"]) {
      const stored = tx.db.marketMeta.key.find(resource);
      const row = stored
        ? {
            ...stored,
            retryAt: refresh(tx, `directory|${resource}`, stored).retryAt,
          }
        : null;
      if (
        (!row ||
          row.observedAt +
            BigInt(resource === "regions" ? 86400 : MARKET_DISCOVERY_SECONDS) *
              1000000n <=
            now) &&
        (!row || row.retryAt <= now)
      ) {
        const lease = {
          key: resource,
          payload: row?.payload ?? "",
          observedAt: row?.observedAt ?? 0n,
          retryAt: now + 20000000n,
          error: row?.error ?? "",
        };
        saveRefresh(
          tx,
          `directory|${resource}`,
          { retryAt: lease.retryAt },
          row,
        );
        if (!row) tx.db.marketMeta.insert({ ...lease, payload: "" });
        work.push({ resource, id: "" });
      }
    }
    const status = currentMarketStatus(tx);
    const books = [...tx.db.marketItem.iter()].filter(
      (row) =>
        row.indexAt === status.indexAt &&
        row.retryAt <= now &&
        (marketWorkPriority(row)
          ? row.observedAt + BigInt(MARKET_SEARCH_SECONDS) * 1000000n <= now
          : (row.indexHasOrders || row.bookHasOrders) &&
            tx.db.relayRegion.count() <
              BigInt(Object.keys(CURRENT_REGIONS).length) &&
            row.observedAt + BigInt(MARKET_ROLLING_SECONDS) * 1000000n <= now),
    );
    books.sort(
      (a, b) =>
        Number(marketWorkPriority(b)) - Number(marketWorkPriority(a)) ||
        (a.observedAt < b.observedAt
          ? -1
          : a.observedAt > b.observedAt
            ? 1
            : a.key.localeCompare(b.key)),
    );
    for (const row of books.slice(0, Math.max(0, 2 - work.length))) {
      const [kind, id] = row.key.split(":");
      tx.db.marketItem.key.update({ ...row, retryAt: now + 20000000n });
      work.push({
        resource: kind === "cargo" ? "cargoOrders" : "itemOrders",
        kind,
        id,
      });
    }
    return JSON.stringify(work);
  }),
);
export const marketRefreshFailure = db.reducer(
  { resource: t.string(), id: t.string(), retryAt: t.u64() },
  (ctx, args) => {
    requireWrites(ctx);
    requireMarketCollector(ctx);
    const retryAt =
      args.retryAt > ctx.timestamp.microsSinceUnixEpoch
        ? args.retryAt
        : ctx.timestamp.microsSinceUnixEpoch + 60000000n;
    if (["market", "regions"].includes(args.resource)) {
      const row = ctx.db.marketMeta.key.find(args.resource);
      if (row)
        ctx.db.marketMeta.key.update({
          ...row,
          retryAt,
          error: "Refresh delayed",
        });
    } else {
      const key = marketIdentity(
          args.resource === "cargoOrders" ? "cargo" : "item",
          args.id,
        ),
        row = ctx.db.marketItem.key.find(key);
      if (row)
        ctx.db.marketItem.key.update({
          ...row,
          retryAt,
          error: "Refresh delayed",
        });
    }
    if (!currentMarketStatus(ctx).error)
      saveMarketStatus(ctx, {
        error: "Market refresh delayed. Stored orders remain available.",
      });
  },
);
export const readMarket = db.procedure(
  { filters: t.string() },
  t.string(),
  (ctx, args) =>
    ctx.withTx((tx) => {
      if (args.filters.length > 4000)
        throw new SenderError("Too many market filters.");
      const filters = marketInput(() => JSON.parse(args.filters || "{}"));
      if (!filters || typeof filters !== "object" || Array.isArray(filters))
        throw new SenderError("Invalid market filters.");
      const q = String(filters.q ?? "");
      if (q.length > 120) throw new SenderError("Search is too long.");
      const rawRegions = JSON.parse(
        tx.db.marketMeta.key.find("regions")?.payload || '{"regions":[]}',
      );
      const regionData = rawRegions.data ?? rawRegions;
      const regions = (
        Array.isArray(regionData) ? regionData : (regionData.regions ?? [])
      )
        .filter(
          (r: any) =>
            !isRetiredRegion(r.id ?? r.regionId, r.name ?? r.regionName),
        )
        .map((r: any) => ({
          ...r,
          id: String(r.id ?? r.regionId),
          name: r.name ?? r.regionName,
        }));
      const region = regions.find(
        (r: any) =>
          r.id === String(filters.regionId || filters.region) ||
          String(r.name).toLowerCase() ===
            String(filters.region || "").toLowerCase(),
      );
      if ((filters.regionId || filters.region) && !region)
        throw new SenderError("That region has not been collected yet.");
      if (region) {
        filters.regionId = region.id;
        filters.regionName = region.name;
      }
      const status = currentMarketStatus(tx),
        selectedRegion = Number(filters.regionId) || 0;
      if (
        (filters.empire || filters.empireEntityId) &&
        !Array.isArray(filters.claimIds)
      ) {
        const feed = [...tx.db.collectionFeed.iter()].find(
          (row) =>
            row.resource === "empireClaims" &&
            row.entityId === String(filters.empireEntityId),
        );
        if (!feed)
          throw new SenderError(
            "Empire membership has not been stored yet. Choose a region or claim.",
          );
        filters.claimIds = (
          JSON.parse(feed.payload).data ?? JSON.parse(feed.payload)
        ).claims.map((claim: any) => String(claim.entityId));
      }
      const scoped =
        filters.claimQ || filters.claimEntityId || filters.claimIds;
      let pending = 0,
        oldest = 0n,
        newest = 0n;
      const categories = new Set<string>(),
        claims = new Map<string, any>();
      const rows =
        filters.itemId && filters.itemKind
          ? [
              ...tx.db.marketSummaryRow.itemKey.filter(
                marketIdentity(filters.itemKind, filters.itemId),
              ),
            ].filter((row) => row.regionId === 0)
          : [...tx.db.marketSummaryRow.regionId.filter(0)];
      const items = rows.flatMap((row) => {
        const stored = tx.db.marketItem.key.find(row.itemKey);
        if (!stored || stored.indexAt !== status.indexAt) return [];
        let item = JSON.parse(row.payload);
        if (
          !marketMatches(
            item,
            q,
            String(filters.itemId ?? ""),
            String(filters.itemKind ?? ""),
          )
        )
          return [];
        if (stored.requestedAt > stored.observedAt) pending++;
        if (selectedRegion) {
          const regional = tx.db.marketSummaryRow.key.find(
            `${row.itemKey}:${selectedRegion}`,
          );
          if (regional) item = JSON.parse(regional.payload);
          else if (stored.observedAt)
            item = marketSummary(emptyMarketBook(item));
          else
            item = {
              ...item,
              sellOrderCount: null,
              buyOrderCount: null,
              pendingCollection: true,
            };
        }
        if (scoped) {
          if (!stored.book) return [];
          const book = visibleMarketBook(
            nativeBook(tx, stored.key, loadBook(tx, stored.book)),
          );
          item = marketSummary(book, filters);
          for (const field of [
            "sellOrders",
            "buyOrders",
            "packageSellOrders",
            "packageBuyOrders",
          ])
            for (const order of book[field] ?? [])
              if (
                (!selectedRegion ||
                  Number(order.regionId) === selectedRegion) &&
                (!filters.claimEntityId ||
                  String(order.claimEntityId) ===
                    String(filters.claimEntityId)) &&
                (!filters.claimQ ||
                  String(order.claimName ?? "")
                    .toLowerCase()
                    .includes(String(filters.claimQ).toLowerCase())) &&
                (!filters.claimIds ||
                  filters.claimIds.includes(String(order.claimEntityId)))
              )
                claims.set(String(order.claimEntityId), {
                  entityId: String(order.claimEntityId),
                  name: order.claimName,
                  regionId: order.regionId,
                });
          if (!item.sellOrderCount && !item.buyOrderCount) return [];
        }
        if (item.category) categories.add(item.category);
        if (filters.category && item.category !== filters.category) return [];
        if (filters.hasOrders && !(item.sellOrderCount + item.buyOrderCount))
          return [];
        if (filters.hasSellOrders && !item.sellOrderCount) return [];
        if (filters.hasBuyOrders && !item.buyOrderCount) return [];
        if (row.observedAt && (!oldest || row.observedAt < oldest))
          oldest = row.observedAt;
        if (row.observedAt > newest) newest = row.observedAt;
        return [
          {
            ...item,
            observedAt: String(row.observedAt),
            refreshPending: stored.requestedAt > stored.observedAt,
          },
        ];
      });
      return JSON.stringify(
        {
          items,
          regions,
          filters,
          categories: [...categories].sort(),
          claims: [...claims.values()],
          storage: {
            ...status,
            error:
              [...tx.db.relayRegion.iter()].some(
                (r) => !selectedRegion || r.regionId === selectedRegion,
              ) &&
              [...tx.db.relayStatus.iter()].filter(
                (r) =>
                  r.key.startsWith("trading:") &&
                  (!selectedRegion || r.regionId === selectedRegion) &&
                  nativeTradingReady(tx, r),
              ).length <
                (selectedRegion ? 1 : Object.keys(CURRENT_REGIONS).length)
                ? "Some live regions are reconnecting. Showing the last stored orders."
                : status.error,
            pending,
            oldestAt: oldest,
            newestAt: newest,
            relay: {
              total: selectedRegion ? 1 : Object.keys(CURRENT_REGIONS).length,
              ready: [...tx.db.relayStatus.iter()].filter(
                (r) =>
                  r.key.startsWith("trading:") &&
                  (!selectedRegion || r.regionId === selectedRegion) &&
                  nativeTradingReady(tx, r),
              ).length,
            },
          },
          orderScope: scoped ? "claims" : selectedRegion ? "region" : "all",
        },
        (_key, value) => (typeof value === "bigint" ? String(value) : value),
      );
    }),
);
export const readMarketBook = db.procedure(
  { kind: t.string(), id: t.string() },
  t.string(),
  (ctx, args) =>
    ctx.withTx((tx) => {
      const row = tx.db.marketItem.key.find(marketIdentity(args.kind, args.id));
      return JSON.stringify({
        book: row?.book
          ? visibleMarketBook(nativeBook(tx, row.key, loadBook(tx, row.book)))
          : null,
        observedAt: String(row?.observedAt ?? 0n),
        pending: row
          ? row.requestedAt > row.observedAt || !row.observedAt
          : false,
        error: row?.error ?? "",
      });
    }),
);
export const requestData = db.procedure(
  {
    resource: t.string(),
    id: t.string(),
    query: t.string(),
    page: t.u32(),
    options: t.string(),
  },
  DataResponse,
  (ctx, args) => {
    if (args.options.length > 2000) throw new SenderError("Too many filters.");
    const options = JSON.parse(args.options || "{}");
    if (!options || typeof options !== "object" || Array.isArray(options))
      throw new SenderError("Invalid filters.");
    const internal = options.collector === true;
    delete options.collector;
    const registerWatch = options.collectorWatch === true;
    delete options.collectorWatch;
    if (registerWatch && !internal)
      throw new SenderError("Collector authorization required.");
    if (
      internal &&
      !ctx.withTx((tx) =>
        Boolean(tx.db.collectorIdentity.identity.find(ctx.sender)),
      )
    )
      throw new SenderError("Collector authorization required.");
    const relay = args.resource.startsWith("relay")
      ? relayRequest(args.resource, args.id, args.query)
      : null;
    const request = relay
      ? { path: relay.path, key: relay.path }
      : upstreamRequest(args.resource, args.id, args.query, args.page, options);
    const kind = relay?.kind ?? args.resource,
      now = ctx.timestamp.microsSinceUnixEpoch;
    const scope = collectionKey(args.resource, request.key);
    const collected = ctx.withTx((tx) => {
      requireWrites(tx);
      if (!internal || registerWatch) {
        const existing = tx.db.collectionWatch.key.find(scope);
        if (existing || tx.db.collectionWatch.count() < 500n) {
          const watch = {
            key: scope,
            resource: args.resource,
            entityId: args.id,
            query: args.query,
            page: args.page,
            options: JSON.stringify(options),
            expiresAt:
              now + BigInt(collectionWatchSeconds(args.resource)) * 1000000n,
          };
          if (existing) tx.db.collectionWatch.key.update(watch);
          else tx.db.collectionWatch.insert(watch);
        }
      }
      const row = collectedFeed(tx, scope);
      const status = tx.db.collectionStatus.key.find("collector");
      if (internal || !row || row.expiresAt + 300000000n <= now) return null;
      const healthy =
        status &&
        (status.epoch === row.epoch ||
          row.epoch.startsWith(status.epoch + "-native-")) &&
        status.heartbeatAt + 30000000n > now &&
        row.expiresAt > now &&
        !row.error;
      return {
        key: `collection|${row.source}|${row.epoch}|${scope}`,
        payload: row.payload,
        updatedAt: row.observedAt,
        retryAt: healthy ? 0n : now + 5000000n,
        error: healthy
          ? ""
          : "Refresh delayed. The last available data is shown.",
      };
    });
    if (collected && !collected.error) return collected;
    if (kind === "nearby")
      return (
        collected ?? {
          key: scope,
          payload: "",
          updatedAt: 0n,
          retryAt: now + 5000000n,
          error: "Nearby resources are being collected. Try again shortly.",
        }
      );
    const results: Array<{
      key: string;
      payload: string;
      updatedAt: bigint;
      retryAt: bigint;
      error: string;
    }> = [];
    for (const name of providerOrder(args.resource)) {
      const config = ctx.withTx((tx) => settings(tx, name));
      if (!config.enabled) continue;
      const path =
        name === "bitjuice" && kind === "passive"
          ? request.path.replace("?status=all", "")
          : request.path;
      const key = name + "|" + path;
      const reservation = ctx.withTx((tx) => {
        requireWrites(tx);
        const existing = cachedSnapshot(tx, key);
        const row = existing ?? {
          key,
          payload: "",
          updatedAt: 0n,
          expiresAt: 0n,
          retryAt: 0n,
          refreshingUntil: 0n,
          error: "",
        };
        const visible = {
          key,
          payload:
            row.expiresAt + BigInt(config.staleSeconds) * 1000000n > now
              ? row.payload
              : "",
          updatedAt: row.updatedAt,
          retryAt: row.retryAt,
          error: row.error,
        };
        if (row.expiresAt > now)
          return {
            fetch: false,
            response: { ...visible, retryAt: 0n, error: "" },
          };
        if (row.refreshingUntil > now || row.retryAt > now)
          return {
            fetch: false,
            response: {
              ...visible,
              retryAt:
                row.retryAt > row.refreshingUntil
                  ? row.retryAt
                  : row.refreshingUntil,
            },
          };
        const window = now / 60000000n;
        const keys = [name, name + ":" + ctx.sender.toHexString()];
        const counters = keys.map(
          (key) =>
            tx.db.providerBudget.key.find(key) ?? {
              key,
              window,
              requests: 0,
              blockedUntil: 0n,
            },
        );
        const limits = [
          config.requestsPerMinute,
          config.callerRequestsPerMinute,
        ];
        let retryAt = 0n;
        for (let index = 0; index < counters.length; index++) {
          const counter = counters[index];
          if (counter.blockedUntil > now) retryAt = counter.blockedUntil;
          if (counter.window === window && counter.requests >= limits[index])
            retryAt = (window + 1n) * 60000000n;
        }
        if (retryAt)
          return {
            fetch: false,
            response: {
              ...visible,
              retryAt,
              error: "Refresh delayed. The last available data is shown.",
            },
          };
        if (tx.db.providerBudget.count() >= 2000n) {
          for (const counter of tx.db.providerBudget.iter())
            if (counter.window < window && counter.blockedUntil <= now)
              tx.db.providerBudget.key.delete(counter.key);
          if (
            tx.db.providerBudget.count() >= 2000n &&
            !tx.db.providerBudget.key.find(keys[1])
          )
            return {
              fetch: false,
              response: {
                ...visible,
                retryAt: (window + 1n) * 60000000n,
                error: "Refresh delayed. Please try again shortly.",
              },
            };
        }
        if (!existing && tx.db.snapshot.count() >= 500n) {
          const oldest = [...tx.db.snapshot.iter()]
            .filter(
              (row) =>
                refresh(tx, `cache|${row.key}`, row).refreshingUntil <= now,
            )
            .sort((a, b) =>
              a.updatedAt < b.updatedAt
                ? -1
                : a.updatedAt > b.updatedAt
                  ? 1
                  : 0,
            )[0];
          if (!oldest)
            return {
              fetch: false,
              response: {
                ...visible,
                retryAt: now + 1000000n,
                error: "Refresh delayed. Please try again shortly.",
              },
            };
          tx.db.snapshot.key.delete(oldest.key);
          tx.db.refreshState.key.delete(`cache|${oldest.key}`);
        }
        for (const counter of counters) {
          const next = {
            ...counter,
            window,
            requests: (counter.window === window ? counter.requests : 0) + 1,
          };
          if (tx.db.providerBudget.key.find(counter.key))
            tx.db.providerBudget.key.update(next);
          else tx.db.providerBudget.insert(next);
        }
        const next = {
          ...row,
          refreshingUntil: now + BigInt(config.timeout + 5) * 1000000n,
        };
        saveRefresh(tx, `cache|${key}`, { ...next, key: `cache|${key}` }, row);
        if (!existing) tx.db.snapshot.insert(row);
        return { fetch: true, response: visible };
      });
      if (!reservation.fetch) {
        results.push(reservation.response);
        if (
          reservation.response.payload &&
          !reservation.response.error &&
          reservation.response.retryAt <= now
        )
          break;
        continue;
      }
      let payload = "",
        error = "",
        retrySeconds = 0,
        providerBlocked = false;
      try {
        providerBlocked = name === "bitjuice";
        const headers: Record<string, string> = {
          Accept: "application/json",
          "User-Agent": "Dataverse Bitcraft Tools/1.0",
        };
        if (config.appIdentifier)
          headers["x-app-identifier"] = config.appIdentifier;
        if (config.identity) headers["x-bitjita-identity"] = config.identity;
        if (config.token) headers.Authorization = "Bearer " + config.token;
        const response = ctx.http.fetch(config.baseUrl + "/" + path, {
          timeout: TimeDuration.fromMillis(config.timeout * 1000),
          headers,
        });
        if (response.status >= 400 && response.status < 500)
          providerBlocked = false;
        if (name === "bitjuice" && response.status >= 500)
          providerBlocked = true;
        if (response.status === 429) {
          retrySeconds = retryAfterSeconds(
            response.headers.get("retry-after") ?? "",
            Number(now / 1000000n),
          );
          providerBlocked = true;
          throw new Error("rate");
        }
        if (response.status < 200 || response.status >= 300) {
          providerBlocked =
            providerBlocked || (name === "bitjuice" && response.status < 400);
          throw new Error("http");
        }
        const body = response.text();
        if (
          body.length > 30000000 ||
          !validProviderPayload(name, kind, JSON.parse(body), args.id)
        ) {
          providerBlocked = name === "bitjuice";
          throw new Error("payload");
        }
        if (name === "bitjuice" && kind === "player") {
          const value = JSON.parse(body);
          (value.data ?? value).player.skillMap = Object.fromEntries(
            (catalogResponse("skills", "", "") as any[]).map((skill) => [
              skill.id,
              skill,
            ]),
          );
          payload = JSON.stringify(value);
        } else if (kind === "market") {
          payload = JSON.stringify({
            items: marketDirectory(JSON.parse(body)).map((item: any) => ({
              ...marketDisplayItem(item),
              sellOrders: item.sellOrderCount,
              buyOrders: item.buyOrderCount,
            })),
          });
        } else payload = body;
      } catch {
        retrySeconds = retrySeconds || config.failureCooldown || 5;
        error = "Refresh delayed. The last available data is shown.";
      }
      const result = ctx.withTx((tx) => {
        requireWrites(tx);
        const row = cachedSnapshot(tx, key);
        if (!row) return reservation.response;
        const next = {
          ...row,
          payload: payload || row.payload,
          updatedAt: payload ? now : row.updatedAt,
          expiresAt: payload
            ? now + BigInt(config.ttl[kind] ?? 60) * 1000000n
            : row.expiresAt,
          refreshingUntil: 0n,
          retryAt: payload ? 0n : now + BigInt(retrySeconds) * 1000000n,
          error,
        };
        saveRefresh(tx, `cache|${key}`, { ...next, key: `cache|${key}` }, row);
        if (payload && payload !== row.payload) {
          if (payload.length > 8000000)
            throw new SenderError("Response payload budget exceeded.");
          let size = [...tx.db.snapshot.iter()].reduce(
            (sum, entry) =>
              sum + (entry.key === key ? 0 : entry.payload.length),
            payload.length,
          );
          for (const old of [...tx.db.snapshot.iter()]
            .filter(
              (entry) =>
                entry.key !== key &&
                refresh(tx, `cache|${entry.key}`, entry).refreshingUntil <= now,
            )
            .sort((a, b) => (a.updatedAt < b.updatedAt ? -1 : 1))) {
            if (size <= 32000000) break;
            size -= old.payload.length;
            tx.db.snapshot.key.delete(old.key);
            tx.db.refreshState.key.delete(`cache|${old.key}`);
          }
          if (size > 32000000)
            throw new SenderError("Response cache budget reached.");
          tx.db.snapshot.key.update(next);
        }
        if (providerBlocked && !payload) {
          const counter = tx.db.providerBudget.key.find(name)!;
          tx.db.providerBudget.key.update({
            ...counter,
            blockedUntil: next.retryAt,
          });
        }
        return {
          key,
          payload:
            payload ||
            (next.expiresAt + BigInt(config.staleSeconds) * 1000000n > now
              ? next.payload
              : ""),
          updatedAt: next.updatedAt,
          retryAt: next.retryAt,
          error,
        };
      });
      results.push(result);
      if (payload) break;
    }
    if (!results.length)
      return {
        key: request.key,
        payload: "",
        updatedAt: 0n,
        retryAt: 0n,
        error: "This data service is disabled.",
      };
    const chosen = chooseProviderResult(results, now);
    return chosen.payload && !chosen.error ? chosen : (collected ?? chosen);
  },
);
