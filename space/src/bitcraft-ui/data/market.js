import { connectBitcraft } from "../../bitcraft";
import { live, providerPolicy, mapLimited } from "./client";
import {
  isRetiredRegion,
  isVisibleRegionRecord,
} from "../../../bitcraft/src/regions";
import {
  marketItem,
  normalizeOrderBook,
  barterRows,
  stallClaims,
} from "../market";
async function regionsAndFilters(filters) {
  const response = await live("regions");
  const regions = (
    Array.isArray(response.data) ? response.data : (response.data.regions ?? [])
  )
    .filter(
      (row) =>
        !isRetiredRegion(row.id ?? row.regionId, row.name ?? row.regionName),
    )
    .map((row) => ({
      ...row,
      id: String(row.id ?? row.regionId),
      name: row.name ?? row.regionName,
    }));
  const match = regions.find(
    (row) =>
      row.id === String(filters.region) ||
      row.name.toLowerCase() === String(filters.region).toLowerCase(),
  );
  if (filters.region && !match)
    throw new Error(`No region matched '${filters.region}'.`);
  if (match) {
    filters.regionId = match.id;
    filters.regionName = match.name;
  }
  return regions;
}
async function retryDelayedMarketRequest(callback) {
  for (let attempt = 0; ; attempt++) {
    try {
      return await callback();
    } catch (error) {
      // Cold complete searches may cross the shared request window. Follow
      // the server's backoff instead of immediately spending more requests.
      if (!error.retryAfter || attempt >= 2) throw error;
      await new Promise((resolve) =>
        setTimeout(resolve, error.retryAfter * 1000 + 100),
      );
    }
  }
}
async function paged(resource, id = "", q = "", options = {}) {
  const first = await retryDelayedMarketRequest(() =>
    live(resource, id, q, 1, options),
  );
  const field =
    resource === "stalls"
      ? "stalls"
      : resource === "claimListings"
        ? "listings"
        : "claims";
  const pages = Math.max(
    1,
    Number(
      first.data.totalPages ??
        Math.ceil(
          Number(first.data.count ?? first.data[field]?.length ?? 0) /
            Number(first.data.limit ?? 100),
        ),
    ),
  );
  if (pages > 100)
    throw new Error(
      "This search is too broad. Narrow it by item, claim, or region.",
    );
  const rest = await mapLimited(
    Array.from({ length: pages - 1 }, (_, index) => index + 2),
    (page) =>
      retryDelayedMarketRequest(() => live(resource, id, q, page, options)),
    // Claim loading already has an outer worker pool; do not multiply it by
    // another pool for each claim's pages.
    resource === "claimListings" ? 1 : undefined,
  );
  return {
    ...first,
    data: {
      ...first.data,
      [field]: [
        ...(first.data[field] ?? []),
        ...rest.flatMap((response) => response.data[field] ?? []),
      ],
    },
    refresh: {
      ...first.refresh,
      delayed:
        first.refresh.delayed ||
        rest.some((response) => response.refresh.delayed),
    },
  };
}
let stallsCache;
async function allStalls() {
  const { stallsCacheSeconds } = await providerPolicy();
  if (!stallsCache || Date.now() > stallsCache.until)
    stallsCache = {
      until: Date.now() + stallsCacheSeconds * 1000,
      promise: paged("stalls").catch((error) => {
        stallsCache = null;
        throw error;
      }),
    };
  return stallsCache.promise;
}
export const emptyMarket = () => ({
  items: [],
  categories: [],
  claims: [],
  tradeBuildings: [],
  listings: [],
  exchanges: [],
  orderBooks: {},
  empires: [],
  orderBook: null,
  metrics: {},
  claim: null,
});
const storedDate = (micros) =>
  Number(micros) > 0 ? new Date(Number(micros) / 1000).toISOString() : null;
async function resolveMarketEmpire(filters) {
  if (!(filters.empire || filters.empireEntityId)) return [];
  if (Array.isArray(filters.claimIds) && filters.empireEntityId) return [];
  // Explicit empire filters need membership metadata; orders still come from storage.
  const empires =
    filters.empire && !/^\d+$/.test(filters.empire)
      ? ((await live("empires", "", filters.empire)).data.empires ?? [])
      : [];
  const empire =
    empires.find(
      (row) => row.name?.toLowerCase() === filters.empire.toLowerCase(),
    ) ?? empires[0];
  const id =
    filters.empireEntityId ||
    (/^\d+$/.test(filters.empire) ? filters.empire : empire?.entityId);
  if (!id) throw new Error(`No empire matched '${filters.empire}'.`);
  const claims = (await live("empireClaims", id)).data.claims;
  if (!Array.isArray(claims))
    throw new Error(
      "Empire membership is unavailable. Stored orders have been retained.",
    );
  filters.empireEntityId = String(id);
  filters.empireName = empire?.name ?? null;
  filters.claimIds = claims.map((claim) => String(claim.entityId));
  return empires;
}
export async function storedMarketPage(filters, { refreshMarket = true } = {}) {
  const market = emptyMarket();
  try {
    market.empires = await resolveMarketEmpire(filters);
    const conn = await connectBitcraft();
    // Both broad browsing and initial item matches are database reads only.
    const data = JSON.parse(
      await conn.procedures.readMarket({ filters: JSON.stringify(filters) }),
    );
    Object.assign(filters, data.filters);
    Object.assign(market, {
      items: data.items.map(marketItem),
      categories: data.categories,
      claims: data.claims,
      orderScope: data.orderScope,
      storage: data.storage,
    });
    if (filters.claimEntityId)
      market.claim =
        data.claims.find(
          (claim) => String(claim.entityId) === String(filters.claimEntityId),
        ) ?? null;
    const relay = data.storage.relay;
    if (
      refreshMarket &&
      (!relay || relay.ready < relay.total) &&
      (String(filters.q ?? "").trim() || filters.itemId)
    ) {
      // Queuing is quick, shared and coalesced. Never wait for upstream HTTP.
      await conn.reducers
        .requestMarketRefresh({
          query: String(filters.q ?? ""),
          itemId: String(filters.itemId ?? ""),
          kind: filters.itemKind || "item",
        })
        .catch((error) => {
          data.storage.error = String(error).includes("Narrow your item search")
            ? "Narrow your item search to refresh at most 50 matching items. Stored matches remain available."
            : "Item refresh could not be queued. Stored matches remain available.";
        });
    }
    const refresh = {
      updatedAt: storedDate(data.storage.oldestAt),
      stored: true,
      delayed: Boolean(data.storage.error),
      pending: data.storage.pending,
    };
    return {
      market,
      regions: data.regions,
      refresh,
      cache: { ...refresh, sources: [] },
      error: null,
    };
  } catch (error) {
    return {
      market,
      regions: [],
      refresh: {},
      cache: {},
      error: error.message,
    };
  }
}
export async function marketPage(tool, filters, options = {}) {
  if (tool !== "barter-stalls") return storedMarketPage(filters, options);
  const conn = await connectBitcraft();
  const native = await conn.procedures.readRelayBarter({}).then(JSON.parse);
  if (native.available) {
    const market = emptyMarket();
    market.empires = await resolveMarketEmpire(filters);
    const region = native.regions.find(
      (row) =>
        String(row.id) === String(filters.regionId || filters.region) ||
        row.name.toLowerCase() === String(filters.region || "").toLowerCase(),
    );
    if ((filters.region || filters.regionId) && !region)
      throw new Error("Choose a current region.");
    if (region)
      Object.assign(filters, { regionId: region.id, regionName: region.name });
    const stalls = native.stalls.filter(
      (stall) =>
        (!filters.regionId ||
          String(stall.regionId) === String(filters.regionId)) &&
        (!filters.claimQ ||
          String(stall.claimName ?? "")
            .toLowerCase()
            .includes(filters.claimQ.toLowerCase())) &&
        (!filters.claimIds ||
          filters.claimIds.includes(String(stall.claimEntityId))) &&
        (!filters.claimEntityId ||
          String(stall.claimEntityId) === String(filters.claimEntityId) ||
          String(stall.entityId) === String(filters.claimEntityId)),
    );
    const data = barterRows({ stalls }, { ...filters, claimEntityId: "" });
    Object.assign(market, data);
    market.claims = stallClaims(data.stalls);
    market.claim =
      market.claims.find(
        (row) => String(row.entityId) === String(filters.claimEntityId),
      ) ?? null;
    market.tradeBuildings = data.stalls.map((stall) => ({
      ...stall,
      buildingName: "Barter Stall",
      buildingNickname: stall.nickname,
      tradeOrders: stall.orders.length,
      inventoryItems: [],
    }));
    market.categories = [
      ...new Set(data.items.map((row) => row.category).filter(Boolean)),
    ].sort();
    const scopes = native.coverage.filter(
      (row) =>
        !filters.regionId || String(row.regionId) === String(filters.regionId),
    );
    const ready = scopes.filter((row) => row.ready).length,
      total = filters.regionId ? 1 : 9;
    market.storage = {
      relay: { ready, total },
      error:
        ready < total
          ? "Some regions are reconnecting or still loading. Last collected exchanges are shown."
          : "",
      pending: 0,
    };
    return {
      market,
      regions: native.regions,
      cache: {
        stored: true,
        updatedAt: storedDate(native.updatedAt),
        delayed: ready < total,
      },
      error: null,
    };
  }
  const market = emptyMarket();
  let regions = [],
    refresh = {};
  try {
    regions = await retryDelayedMarketRequest(() => regionsAndFilters(filters));
    if (filters.empire || filters.empireEntityId) {
      const empires =
        filters.empire && !/^\d+$/.test(filters.empire)
          ? ((await live("empires", "", filters.empire)).data.empires ?? [])
          : [];
      const empire =
        empires.find(
          (row) => row.name?.toLowerCase() === filters.empire.toLowerCase(),
        ) ?? empires[0];
      const id =
        filters.empireEntityId ||
        (/^\d+$/.test(filters.empire) ? filters.empire : empire?.entityId);
      if (!id) throw new Error(`No empire matched '${filters.empire}'.`);
      filters.empireEntityId = String(id);
      filters.empireName = empire?.name ?? null;
      market.empires = empires;
      market.claims = (await live("empireClaims", id)).data.claims ?? [];
    } else if (filters.claimQ || (tool === "barter-stalls" && filters.region))
      market.claims =
        (
          await paged("claims", "", filters.claimQ || "", {
            regionId: filters.regionId,
          })
        ).data.claims ?? [];
    if (filters.regionId)
      market.claims = market.claims.filter(
        (claim) => String(claim.regionId) === filters.regionId,
      );
    if (filters.claimEntityId) {
      market.claim =
        market.claims.find(
          (row) => String(row.entityId) === String(filters.claimEntityId),
        ) ?? (await live("claim", filters.claimEntityId)).data.claim;
      if (market.claim) market.claims = [market.claim];
    }
    if (tool === "barter-stalls") {
      if (
        filters.q ||
        filters.claimQ ||
        filters.claimEntityId ||
        filters.category ||
        filters.region ||
        filters.empireEntityId ||
        filters.itemId
      ) {
        const response = await allStalls();
        refresh = response.refresh;
        let stalls = response.data.stalls.filter(isVisibleRegionRecord);
        if (filters.claimQ)
          stalls = stalls.filter((stall) =>
            String(stall.claimName ?? "")
              .toLowerCase()
              .includes(filters.claimQ.toLowerCase()),
          );
        if (filters.empireEntityId) {
          const names = new Set(market.claims.map((claim) => claim.name));
          stalls = stalls.filter((stall) => names.has(stall.claimName));
        }
        if (filters.claimEntityId && market.claim)
          stalls = stalls.filter(
            (stall) =>
              stall.claimName === market.claim.name ||
              String(stall.entityId) === String(filters.claimEntityId),
          );
        const data = barterRows({ stalls }, { ...filters, claimEntityId: "" });
        Object.assign(market, data);
        market.tradeBuildings = data.stalls.map((stall) => ({
          ...stall,
          buildingName: "Barter Stall",
          buildingNickname: stall.nickname,
          tradeOrders: stall.orders.length,
          inventoryItems: [],
        }));
        const matchingStalls = [
          ...new Map(
            data.listings.map((row) => [row.stall.entityId, row.stall]),
          ).values(),
        ];
        market.claims = stallClaims(matchingStalls, market.claims);
      }
    }
    if (!market.categories.length)
      market.categories = [
        ...new Set(market.items.map((row) => row.category).filter(Boolean)),
      ].sort();
    return {
      market,
      regions,
      cache: {
        ...refresh,
        sources: [
          {
            maxAgeSeconds:
              tool === "barter-stalls"
                ? (await providerPolicy()).stallsCacheSeconds
                : (await providerPolicy()).marketCacheSeconds,
          },
        ],
      },
      refresh,
      error: null,
    };
  } catch (error) {
    return {
      market,
      regions,
      cache: { ...refresh, sources: [] },
      refresh,
      error: error.message,
    };
  }
}
export async function orderBook(
  filters,
  { history: includeHistory = true } = {},
) {
  await resolveMarketEmpire(filters);
  const conn = await connectBitcraft();
  // Resolve region/claim scope through the stored directory, never an API call.
  const scope = JSON.parse(
    await conn.procedures.readMarket({
      filters: JSON.stringify({
        ...filters,
        hasOrders: false,
        hasSellOrders: false,
        hasBuyOrders: false,
      }),
    }),
  );
  Object.assign(filters, scope.filters);
  if (filters.claimQ && !filters.claimEntityId)
    filters.claimIds = scope.claims.map((claim) => String(claim.entityId));
  const stored = JSON.parse(
    await conn.procedures.readMarketBook({
      kind: filters.itemKind || "item",
      id: String(filters.itemId),
    }),
  );
  if (!stored.book)
    return {
      orderBook: null,
      pending: stored.pending,
      error:
        "This item's orders are still being collected. Stored results will update automatically.",
    };
  const history = includeHistory
    ? await live(
        filters.itemKind === "cargo" ? "cargoPriceHistory" : "itemPriceHistory",
        filters.itemId,
        "",
        1,
        filters,
      ).catch(() => null)
    : null;
  const refresh = {
    updatedAt: storedDate(stored.observedAt),
    stored: true,
    delayed: Boolean(stored.error),
    pending: stored.pending,
  };
  return {
    orderBook: {
      ...normalizeOrderBook(stored.book, filters),
      observedAt: stored.observedAt,
      history: history?.data.priceStats ?? null,
      historyLoaded: includeHistory,
      historyDelayed: history?.refresh.delayed ?? true,
    },
    cache: refresh,
    refresh,
    error: null,
  };
}
