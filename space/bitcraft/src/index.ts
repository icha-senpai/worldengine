import {
  schema,
  table,
  t,
  SenderError,
  type InferSchema,
  type ReducerCtx,
} from "spacetimedb/server";
import { TimeDuration } from "spacetimedb";
import { upstreamRequest } from "./requests";
import publishedContent from "./content/guides.json";
import { catalogResponse } from "./catalog";
import { validateWidget } from "./widgets";
import { validateGuide } from "./guides";
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
const db = schema({
  snapshot,
  budget,
  administrator,
  guide,
  widgetProfile,
  guideAdministrator,
  guideMetadata,
  providerSettings,
  providerBudget,
});
export default db;
type Ctx = ReducerCtx<InferSchema<typeof db>>;
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

export const configureProvider = db.reducer(
  { name: t.string(), config: t.string() },
  (ctx, args) => {
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
    const relay = args.resource.startsWith("relay")
      ? relayRequest(args.resource, args.id, args.query)
      : null;
    const request = relay
      ? { path: relay.path, key: relay.path }
      : upstreamRequest(
          args.resource,
          args.id,
          args.query,
          args.page,
          JSON.parse(args.options || "{}"),
        );
    const kind = relay?.kind ?? args.resource,
      now = ctx.timestamp.microsSinceUnixEpoch;
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
        const existing = tx.db.snapshot.key.find(key);
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
            .filter((row) => row.refreshingUntil <= now)
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
        if (existing) tx.db.snapshot.key.update(next);
        else tx.db.snapshot.insert(next);
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
        } else payload = body;
      } catch {
        retrySeconds = retrySeconds || config.failureCooldown || 5;
        error = "Refresh delayed. The last available data is shown.";
      }
      const result = ctx.withTx((tx) => {
        const row = tx.db.snapshot.key.find(key);
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
        tx.db.snapshot.key.update(next);
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
    return chooseProviderResult(results, now);
  },
);
