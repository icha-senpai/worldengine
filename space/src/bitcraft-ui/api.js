import { connectBitcraft, requestData, observeCollection } from "../bitcraft";
import { upstreamRequest } from "../../bitcraft/src/requests";
import { relayRequest } from "../../bitcraft/src/providers";
import {
  isRetiredRegion,
  isVisibleRegionRecord,
} from "../../bitcraft/src/regions";
import { pageState } from "./navigation";
import { route } from "./navigation";
import {
  marketItem,
  normalizeOrderBook,
  barterRows,
  stallClaims,
  unwrap,
} from "./market";
import {
  activityTracker,
  inventoryTracker,
  passiveTracker,
  levelProgress,
} from "./trackers";
import { openCraftRows, filterCrafts } from "./openCrafts";
import { collectedActivitySamples } from "./activitySamples";
import { xpSourceAction, resolveXpSource } from "./xpSource";
import {
  relayPlayer,
  relayInventory,
  relayCrafts,
  withRelayCraftTimers,
  relaySkills,
  storageInventories,
  mergeInventories,
  inventoryQuantities,
} from "./relay";

const catalogs = new Map(),
  pending = new Map();
let policy;
async function providerPolicy() {
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
    .then((response) => {
      const updatedAt = response.updatedAt
        ? new Date(Number(response.updatedAt / 1000n)).toISOString()
        : null;
      const retryAfter = Math.max(
        0,
        Math.ceil((Number(response.retryAt / 1000n) - Date.now()) / 1000),
      );
      const refresh = {
        updatedAt,
        delayed: Boolean(response.error) || retryAfter > 0,
        retryAfter,
      };
      if (!response.payload) {
        const error = new Error(
          response.error ||
            "Data is temporarily unavailable. Try again shortly.",
        );
        error.retryAfter = retryAfter;
        throw error;
      }
      return {
        sourceKey: response.key,
        data: unwrap(JSON.parse(response.payload)),
        refresh,
        error: response.error || null,
      };
    })
    .finally(() => pending.delete(key));
  pending.set(key, work);
  return work;
}
async function lookupPlayerResponse(character) {
  if (!character) return null;
  try {
    const playerResponse = await lookupRelayPlayer(character);
    if (playerResponse?.data.player) {
      const skills = await live(
        "relaySkills",
        playerResponse.data.player.entityId,
      );
      if (!skills.refresh.delayed)
        return {
          ...skills,
          data: {
            player: relaySkills(skills.data, playerResponse.data.player),
          },
        };
    }
  } catch {
    /* Use the searchable player API during relay recovery. */
  }
  if (/^\d+$/.test(character)) return await live("player", character);
  const data = (await live("players", "", character)).data;
  const selected =
    (data.players ?? []).find(
      (player) =>
        String(player.username).toLowerCase() === character.toLowerCase(),
    ) ??
    data.players?.[0] ??
    null;
  return selected ? await live("player", selected.entityId) : null;
}
export async function lookupPlayer(character) {
  return (await lookupPlayerResponse(character))?.data.player ?? null;
}
async function lookupRelayPlayer(character) {
  if (!character) return null;
  if (/^\d+$/.test(character)) {
    const response = await live("relayPlayer", character);
    return { ...response, data: { player: relayPlayer(response.data) } };
  }
  const response = await live("relayPlayers", "", character);
  const selected =
    response.data.find(
      (row) => row.username?.toLowerCase() === character.toLowerCase(),
    ) ?? response.data[0];
  return selected
    ? lookupRelayPlayer(String(selected.entity_id ?? selected.entityId))
    : null;
}
async function trackerPlayer(tool, character) {
  if (tool === "inventory")
    try {
      const response = await lookupRelayPlayer(character);
      if (response?.data.player && !response.refresh.delayed) return response;
    } catch {
      /* Use the player APIs when the relay is unavailable. */
    }
  try {
    const response = await lookupPlayerResponse(character);
    return response?.data.player || tool === "activity"
      ? response
      : await lookupRelayPlayer(character);
  } catch (error) {
    if (tool === "activity") throw error;
    return await lookupRelayPlayer(character);
  }
}
async function enrichTrackerItems(payload) {
  const raw = (payload.inventories ?? [])
    .flatMap((row) => row.items ?? row.pockets ?? [])
    .map((row) => row.contents ?? row.item ?? row)
    .concat(
      (payload.craftResults ?? []).flatMap(
        (row) =>
          row.craftedItem ??
          row.crafted_item ??
          row.craftedItems ??
          row.outputs ??
          [],
      ),
    );
  const keys = [
    ...new Set(
      raw
        .map(
          (row) =>
            `${["cargo", 1, "1"].includes(row.item_type ?? row.itemType ?? row.kind) ? "cargo" : "item"}:${row.item_id ?? row.itemId ?? row.id}`,
        )
        .filter((key) => /^(item|cargo):\d+$/.test(key)),
    ),
  ];
  const items = await mapLimited(keys, (key) => catalog("item-info", key));
  return {
    ...payload,
    items: {
      ...(payload.items ?? {}),
      ...Object.fromEntries(
        items.filter((row) => row?.kind === "item").map((row) => [row.id, row]),
      ),
    },
    cargos: {
      ...(payload.cargos ?? {}),
      ...Object.fromEntries(
        items
          .filter((row) => row?.kind === "cargo")
          .map((row) => [row.id, row]),
      ),
    },
  };
}
async function trackerInventory(player, filters = {}) {
  let base;
  try {
    const response = await live("relayInventories", player.entityId);
    if (!response.refresh.delayed)
      base = {
        ...response,
        data: await enrichTrackerItems(relayInventory(response.data)),
      };
  } catch {
    /* Continue with the player APIs. */
  }
  if (!base) {
    const response = await live("inventories", player.entityId);
    base = { ...response, data: await enrichTrackerItems(response.data) };
  }
  const stores = [];
  if ([true, "true", "1"].includes(filters.includeHousing))
    stores.push(["relayHousing", player.entityId, "housing"]);
  if (/^\d{1,24}$/.test(String(filters.storageClaimId ?? "")))
    stores.push([
      "relayClaimInventory",
      String(filters.storageClaimId),
      "claim",
    ]);
  if (!stores.length) return base;
  const responses = await Promise.all(
    stores.map(async ([resource, id, kind]) => {
      const result = await live(resource, id);
      return {
        ...result,
        data: { inventories: storageInventories(result.data, kind) },
      };
    }),
  );
  return {
    ...base,
    data: await enrichTrackerItems(
      mergeInventories(base.data, ...responses.map((row) => row.data)),
    ),
    refresh: {
      ...base.refresh,
      delayed:
        base.refresh.delayed || responses.some((row) => row.refresh.delayed),
    },
    error: base.error || responses.find((row) => row.error)?.error,
  };
}
async function passiveSnapshot(player) {
  const response = await trackerPassive(player);
  const ids = [
    ...new Set(
      (response.data.craftResults ?? [])
        .map((row) => String(row.recipeId ?? row.recipe_id))
        .filter((id) => /^\d+$/.test(id)),
    ),
  ];
  const times = {};
  for (let index = 0; index < ids.length; index += 6)
    Object.assign(
      times,
      await catalog("recipe-times", ids.slice(index, index + 6).join(",")),
    );
  return {
    tracker: passiveTracker(player, response.data, times),
    error: response.error,
    sampledAt: response.refresh.updatedAt ?? new Date().toISOString(),
    refresh: response.refresh,
  };
}
async function trackerPassive(player) {
  let failure;
  try {
    const [response, timing] = await Promise.all([
      live("relayCrafts", player.entityId),
      live("relaySkills", player.entityId).catch(() => null),
    ]);
    const ids = [
      ...new Set(
        (response.data.crafts ?? [])
          .filter((row) => row.is_passive && !row.completed)
          .map((row) => String(row.claim_entity_id ?? ""))
          .filter((id) => /^\d+$/.test(id)),
      ),
    ];
    const claims = Object.fromEntries(
      await mapLimited(ids, async (id) => [
        id,
        await live("relayClaim", id)
          .then((result) => result.data)
          .catch(() => ({})),
      ]),
    );
    if (response.refresh.delayed) throw new Error("Craft refresh delayed.");
    return {
      ...response,
      data: await enrichTrackerItems(
        withRelayCraftTimers(
          relayCrafts(response.data, claims),
          timing?.data?.passiveCrafts,
        ),
      ),
    };
  } catch (error) {
    failure = error;
  }
  try {
    const response = await live("passive", player.entityId);
    return { ...response, data: await enrichTrackerItems(response.data) };
  } catch (error) {
    throw failure ?? error;
  }
}
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
async function mapLimited(rows, callback, concurrency) {
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
    if (refreshMarket && (String(filters.q ?? "").trim() || filters.itemId)) {
      // Queuing is quick, shared and coalesced. Never wait for upstream HTTP.
      await conn.reducers
        .requestMarketRefresh({
          query: String(filters.q ?? ""),
          itemId: String(filters.itemId ?? ""),
          kind: filters.itemKind || "item",
        })
        .catch(() => {
          data.storage.error =
            "Item refresh could not be queued. Stored matches remain available.";
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
async function orderBook(filters, { history: includeHistory = true } = {}) {
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
function filtersFor(tool, query, setup) {
  const defaults = {
    source: "default",
    character:
      pageState.props.bitcraft.player?.entityId ||
      localStorage.getItem("space.bitcraft.player") ||
      "icha",
    skill: "all",
    title: {
      activity: "XP Goals",
      inventory: "Fishing Day!",
      "passive-crafts": "Passive Crafts",
      tasks: "Stream Tasks",
    }[tool],
    icons: {
      activity: "✨ 🏆",
      inventory: "🐟 🎣",
      "passive-crafts": "🧵 🔨",
      tasks: "✅ ✨",
    }[tool],
    itemSearch: "",
    skillSearch: "",
    itemKeys: "",
    skillKeys: "",
    skillGoalLevels: "",
    skillGoalXp: "",
    itemNeeds: "",
    includeHousing: false,
    storageClaimId: "",
    need: null,
    taskText: "",
    tasks: [],
    q: "",
    category: "",
    claimQ: "",
    claimEntityId: "",
    empire: "",
    empireEntityId: "",
    region: "",
    side: "",
    hasOrders: null,
    hasSellOrders: false,
    hasBuyOrders: false,
    itemId: null,
    itemKind: "item",
    quantity: 1,
    levelUps: false,
    meetsLevel: false,
    mine: false,
    sort: "xp",
    page: 1,
    setup,
  };
  const filters = {
    ...defaults,
    ...(tool === "open-crafts" ? { skill: "" } : {}),
    ...(["market", "barter-stalls"].includes(tool) ? { itemKind: "" } : {}),
    ...query,
    setup,
  };
  for (const key of [
    "hasOrders",
    "hasSellOrders",
    "hasBuyOrders",
    "levelUps",
    "meetsLevel",
    "mine",
  ])
    filters[key] =
      filters[key] === null
        ? null
        : filters[key] === true ||
          filters[key] === "1" ||
          filters[key] === "true";
  if (typeof filters.tasks === "string") {
    try {
      filters.tasks = JSON.parse(filters.tasks);
    } catch {
      filters.tasks = [];
    }
  }
  filters.tasks = Array.isArray(filters.tasks)
    ? filters.tasks
        .filter(
          (task) =>
            task &&
            typeof task.text === "string" &&
            typeof task.id === "string",
        )
        .slice(0, 200)
    : [];
  return filters;
}
export async function trackerSnapshot(tool, filters) {
  const sampledAt = new Date().toISOString();
  try {
    const playerResponse = await trackerPlayer(tool, filters.character),
      player = playerResponse?.data.player;
    if (!player)
      throw new Error(`No BitCraft player matched '${filters.character}'.`);
    if (tool === "activity") {
      const [definitions, response, history] = await Promise.all([
        catalog("skills"),
        live("levels"),
        connectBitcraft()
          .then((conn) =>
            conn.procedures.collectionHistory({
              playerId: String(player.entityId),
            }),
          )
          .then(JSON.parse)
          .catch(() => null),
      ]);
      const activity =
        filters.background === "1"
          ? null
          : await live("relaySession", player.entityId)
              .then((row) => ({ ...row.data, delayed: row.refresh.delayed }))
              .catch(() => null);
      const crafts =
        filters.background === "1" ||
        [false, "false", "0"].includes(filters.showCrafts)
          ? { tracker: null, refresh: { delayed: false } }
          : await passiveSnapshot(player).catch(() => ({
              tracker: null,
              refresh: { delayed: true },
            }));
      const action = xpSourceAction(activity);
      const sourceCard =
        action &&
        ["Craft", "Extract"].includes(action.action_type) &&
        /^\d+$/.test(String(action.recipe_id))
          ? await catalog(
              "card",
              String(action.recipe_id),
              action.action_type === "Extract" ? "gathering" : "crafting",
            ).catch(() => null)
          : null;
      return {
        tracker: {
          ...activityTracker(
            player,
            definitions,
            Array.isArray(response.data)
              ? response.data
              : (response.data.levels ?? []),
            filters,
          ),
          activity,
          xpSource: resolveXpSource(action, sourceCard, player.entityId),
          passiveCrafts: crafts.tracker?.crafts ?? [],
          passiveCraftGroups: crafts.tracker?.groups ?? [],
          craftsDelayed: crafts.refresh.delayed,
        },
        error: playerResponse.error || response.error,
        sampledAt: playerResponse.refresh.updatedAt ?? sampledAt,
        sampleSourceKey: playerResponse.sourceKey,
        xpHistory: collectedActivitySamples(
          history,
          String(player.entityId),
          playerResponse.sourceKey,
        ),
        refresh: {
          ...playerResponse.refresh,
          delayed: playerResponse.refresh.delayed || response.refresh.delayed,
        },
      };
    }
    if (tool === "inventory") {
      const [response, items] = await Promise.all([
        trackerInventory(player, filters),
        catalog("items", "", filters.itemSearch ?? ""),
      ]);
      return {
        ...inventoryTracker(player, response.data, items, filters),
        error: response.error,
        sampledAt: response.refresh.updatedAt ?? sampledAt,
        refresh: response.refresh,
      };
    }
    return await passiveSnapshot(player);
  } catch (error) {
    return {
      tracker: null,
      options:
        tool === "inventory"
          ? await catalog("items", "", filters.itemSearch ?? "")
              .then((items) =>
                items.map((item) => ({
                  ...item,
                  key: `${item.kind}:${item.id}`,
                })),
              )
              .catch(() => [])
          : [],
      error: error.message,
      sampledAt,
      refresh: {
        delayed: Boolean(error.retryAfter),
        retryAfter: error.retryAfter ?? 0,
      },
    };
  }
}
export async function saveGuide(id, form) {
  const conn = await connectBitcraft();
  const guideId = id
    ? BigInt(id)
    : crypto.getRandomValues(new BigUint64Array(1))[0] || 1n;
  await conn.reducers.saveGuide({
    id: guideId,
    title: form.title,
    summary: form.summary,
    category: form.category,
    content: JSON.stringify(form.content),
    published: Boolean(form.is_published),
  });
  return String(guideId);
}
export async function pageProps(
  tool,
  query = {},
  setup = true,
  id = "",
  editing = false,
  options = {},
) {
  if (
    query.profile &&
    ["activity", "inventory", "passive-crafts", "tasks"].includes(tool)
  ) {
    const { readWidget } = await import("./widgets");
    const presentation = query.presentation;
    const character = query.character;
    query = {
      ...(await readWidget(tool, String(query.profile))),
      profile: query.profile,
      ...(presentation ? { presentation } : {}),
      ...(character ? { character } : {}),
    };
  }
  const filters = filtersFor(tool, query, setup);
  if (["activity", "inventory", "passive-crafts"].includes(tool))
    return {
      filters,
      snapshot: await trackerSnapshot(tool, filters),
      [tool === "activity" ? "pollUrl" : "snapshotUrl"]: route(
        `bitcraft.${tool === "inventory" ? "inventory-tracker" : tool}.snapshot`,
        filters,
      ),
    };
  if (tool === "tasks") return { filters };
  if (tool === "tool-rates") {
    const entries = await catalog("entries");
    let observations = null,
      nearby = null,
      nearbyError = null;
    if (pageState.props.bitcraft.player) {
      const player = await lookupPlayer(
        pageState.props.bitcraft.player.entityId,
      ).catch(() => null);
      if (player) {
        if ([true, "true", "1"].includes(query.nearby)) {
          try {
            const result = await live("relayNearby", player.entityId);
            nearby = { ...result.data, refresh: result.refresh };
          } catch (error) {
            nearbyError = error.message;
          }
        }
        const conn = await connectBitcraft();
        const history = await conn.procedures
          .collectionHistory({ playerId: player.entityId })
          .then(JSON.parse)
          .catch(() => null);
        const skills = await catalog("skills");
        observations = {
          username: player.username,
          rates: (history?.rates ?? []).map((row) => ({
            ...row,
            name:
              skills.find((skill) => Number(skill.id) === row.skillId)?.name ??
              `Skill ${row.skillId}`,
          })),
        };
      }
    }
    return { ...entries, observations, nearby, nearbyError };
  }
  if (tool === "hunting-calculator") {
    try {
      const response = await live("levels");
      const levels = Array.isArray(response.data)
        ? response.data
        : (response.data.levels ?? []);
      let playerSkill = null;
      if (pageState.props.bitcraft.player) {
        const player = await lookupPlayer(
          pageState.props.bitcraft.player.entityId,
        ).catch(() => null);
        const skill = player?.experience?.find(
          (row) => Number(row.skill_id ?? row.skillId) === 9,
        );
        if (skill?.quantity != null)
          playerSkill = {
            username: player.username,
            ...levelProgress(Number(skill.quantity), levels),
          };
      }
      return {
        levels,
        playerSkill,
        error: response.error,
      };
    } catch (error) {
      return { levels: [], error: error.message };
    }
  }
  if (["market", "barter-stalls"].includes(tool))
    return {
      filters,
      ...(await marketPage(tool, filters, options)),
      ...(tool === "barter-stalls"
        ? {
            tool: {
              key: tool,
              routeName: "bitcraft.barter-stalls",
              title: "Barter Stalls",
              subtitle:
                "Find player barter trades by item, claim, empire, and region.",
              claimIdLabel: "Claim / stall ID",
              claimSearchLabel: "Claim search",
              claimSectionTitle: "Stalls",
              claimSectionSubtitle: "Claims with barter stations",
              claimEmptyLabel:
                "Search a name or region to find claims with barter stations.",
              tradeBuildingSingular: "barter stall",
              tradeBuildingPlural: "barter stalls",
              buildingSectionTitle: "Barter Stalls",
              buildingSectionSubtitle: "Barter stations inside the claim",
              buildingEmptyLabel: "Pick a claim to find barter stalls.",
              listingSectionTitle: "Barter Listings",
              clearLabel: "Clear Stall",
              claimLinkLabel: "Use this stall ->",
            },
          }
        : {}),
    };
  if (tool === "crafting") {
    const [items, detail, snapshot] = await Promise.all([
      filters.q ? catalog("targets", "", filters.q) : [],
      filters.itemId
        ? catalog("detail", `${filters.itemKind}:${filters.itemId}`)
        : null,
      catalog("metadata"),
    ]);
    let inventory = null;
    if (detail && pageState.props.bitcraft.player) {
      try {
        const player = await lookupPlayer(
          pageState.props.bitcraft.player.entityId,
        );
        const response = await trackerInventory(player, filters);
        inventory = {
          username: player.username,
          quantities: inventoryQuantities(response.data),
          refresh: response.refresh,
          error: response.error,
        };
      } catch (error) {
        inventory = { quantities: {}, error: error.message };
      }
    }
    return {
      filters,
      items,
      detail,
      snapshot,
      inventory,
      error:
        filters.itemId && !detail
          ? "That item is not available in the current catalog."
          : null,
    };
  }
  if (tool === "open-crafts") {
    try {
      let [response, skills] = await Promise.all([
        live("crafts"),
        catalog("skills"),
      ]);
      let player = null,
        levels = [],
        playerError = null;
      if (pageState.props.bitcraft.player)
        try {
          player = await lookupPlayer(pageState.props.bitcraft.player.entityId);
          const result = await live("levels");
          levels = Array.isArray(result.data)
            ? result.data
            : (result.data.levels ?? []);
        } catch (error) {
          playerError = error.message;
        }
      if (!player)
        for (const flag of ["levelUps", "meetsLevel", "mine"])
          filters[flag] = false;
      if (player) {
        try {
          const relay = await live("relayCrafts", player.entityId);
          if (!relay.refresh.delayed) {
            const claimIds = [
              ...new Set(
                (relay.data.crafts ?? [])
                  .map((job) => job.claim_entity_id)
                  .filter(Boolean),
              ),
            ];
            const claims = Object.fromEntries(
              await Promise.all(
                claimIds.map(async (id) => [
                  id,
                  await live("relayClaim", String(id))
                    .then((result) => result.data)
                    .catch(() => ({})),
                ]),
              ),
            );
            const raw = relayCrafts(relay.data, claims, false);
            const existing = new Map(
              (response.data.craftResults ?? []).map((job) => [
                String(job.entityId),
                job,
              ]),
            );
            const jobs = await Promise.all(
              raw.craftResults.map(async (job) => {
                const previous = existing.get(job.entityId) ?? {};
                let recipe = previous;
                if (!recipe.experiencePerProgress) {
                  const output = job.craftedItem[0];
                  if (output) {
                    const detail = await live(
                      [1, "1", "Cargo", "cargo"].includes(output.item_type)
                        ? "cargoItem"
                        : "item",
                      String(output.item_id),
                    )
                      .then((result) => result.data)
                      .catch(() => null);
                    recipe =
                      detail?.craftingRecipes?.find(
                        (row) => String(row.id) === String(job.recipeId),
                      ) ?? {};
                  }
                }
                const defined = Object.fromEntries(
                  Object.entries(job).filter(
                    ([, value]) => value !== undefined,
                  ),
                );
                return {
                  ...previous,
                  ...defined,
                  experiencePerProgress: recipe?.experiencePerProgress,
                  levelRequirements: recipe?.levelRequirements ?? [],
                  toolRequirements: recipe?.toolRequirements ?? [],
                };
              }),
            );
            const known = new Set(jobs.map((job) => job.entityId));
            const enriched = await enrichTrackerItems({ craftResults: jobs });
            response = {
              ...response,
              data: {
                ...response.data,
                craftResults: [
                  ...(response.data.craftResults ?? []).filter(
                    (job) =>
                      !known.has(String(job.entityId)) &&
                      String(job.ownerEntityId) !== String(player.entityId),
                  ),
                  ...jobs,
                ],
                items: [
                  ...(response.data.items ?? []),
                  ...Object.values(enriched.items ?? {}),
                ],
                cargos: [
                  ...(response.data.cargos ?? []),
                  ...Object.values(enriched.cargos ?? {}),
                ],
              },
            };
          }
        } catch {
          /* Preserve the public craft directory during recovery. */
        }
      }
      return {
        filters,
        ...filterCrafts(
          openCraftRows(response.data, player, levels, skills),
          filters,
          player,
        ),
        refresh: response.refresh,
        error: response.error,
        playerError,
        playerRefresh: {},
      };
    } catch (error) {
      return {
        filters,
        crafts: [],
        pagination: { page: 1, lastPage: 1, total: 0, all: 0 },
        skillOptions: [],
        regionOptions: [],
        refresh: { delayed: Boolean(error.retryAfter) },
        error: error.message,
        playerError: null,
        playerRefresh: {},
      };
    }
  }
  if (tool === "guides") {
    const conn = await connectBitcraft();
    const canManage = await conn.procedures.guideAccess({});
    if (editing && !canManage)
      throw new Error(
        `Guide editing needs a local administrator grant. Run: npm run bitcraft:admin -- ${conn.identity.toHexString()} Admin`,
      );
    if (editing && !id) return { guide: null };
    const rows = await new Promise((resolve, reject) => {
      let subscription;
      subscription = conn
        .subscriptionBuilder()
        .onApplied(() => {
          const rows = [
            ...(canManage
              ? conn.db.editableGuides
              : conn.db.publishedGuides
            ).iter(),
          ].map((row) => ({
            ...row,
            metadata: [...conn.db.visibleGuideMetadata.iter()].find(
              (meta) => meta.id === row.id,
            ),
          }));
          queueMicrotask(() => subscription.unsubscribe());
          resolve(rows);
        })
        .onError((_ctx, error) => reject(error))
        .subscribe([
          canManage
            ? "SELECT * FROM editable_guides"
            : "SELECT * FROM published_guides",
          "SELECT * FROM visible_guide_metadata",
        ]);
    });
    const guides = rows
      .map((row) => ({
        ...row,
        author: { name: row.metadata?.authorName ?? "Admin" },
        published_at: row.published
          ? row.metadata?.publishedAt
            ? new Date(Number(row.metadata.publishedAt / 1000n)).toISOString()
            : null
          : null,
        updated_at: row.metadata?.updatedAt
          ? new Date(Number(row.metadata.updatedAt / 1000n)).toISOString()
          : null,
        id: String(row.id),
        content: JSON.parse(row.content),
      }))
      .sort((a, b) =>
        String(b.updated_at ?? "").localeCompare(String(a.updated_at ?? "")),
      );
    if (id) {
      const guide = guides.find((guide) => guide.id === String(id));
      if (!guide) throw new Error("This guide is unavailable.");
      return { guide, canManage };
    }
    const filtered = guides.filter((guide) =>
      [guide.title, guide.summary, guide.category]
        .join(" ")
        .toLowerCase()
        .includes(filters.q.toLowerCase()),
    );
    const page = Math.max(
      1,
      Math.min(
        Number(filters.page) || 1,
        Math.max(1, Math.ceil(filtered.length / 12)),
      ),
    );
    return {
      filters,
      guides: {
        data: filtered.slice((page - 1) * 12, page * 12),
        total: filtered.length,
        current_page: page,
        last_page: Math.max(1, Math.ceil(filtered.length / 12)),
        prev_page_url:
          page > 1
            ? route("bitcraft.guides.index", { q: filters.q, page: page - 1 })
            : null,
        next_page_url:
          page * 12 < filtered.length
            ? route("bitcraft.guides.index", { q: filters.q, page: page + 1 })
            : null,
      },
      canManage,
    };
  }
  throw new Error("This BitCraft tool is unavailable.");
}
export async function bitcraftFetch(input, options = {}) {
  const url = new URL(input, location.origin),
    filters = Object.fromEntries(url.searchParams);
  if (options.signal?.aborted) throw new DOMException("Aborted", "AbortError");
  try {
    let data;
    if (url.pathname === "/bitcraft/players/search")
      data = {
        players:
          (await live("players", "", filters.q ?? "")).data.players ?? [],
      };
    else if (url.pathname === "/bitcraft/market/order-book")
      data = await orderBook(filters, { history: filters.history !== "0" });
    else if (url.pathname === "/bitcraft/barter-stalls/listings") {
      const result = await marketPage("barter-stalls", {
        ...filters,
        hasOrders: false,
      });
      data = {
        item: result.market.items.find(
          (item) => String(item.id) === String(filters.itemId),
        ),
        listings: result.market.listings,
        cache: result.cache,
        refresh: result.refresh,
        error: result.error,
      };
    } else if (url.pathname === "/bitcraft/crafting/branch") {
      const detail = await catalog(
        "branch",
        `${filters.itemKind ?? "item"}:${filters.itemId}`,
      );
      data = { recipes: detail?.recipeTree ?? [], item: detail?.item };
    } else if (url.pathname.endsWith("/snapshot")) {
      const tool = url.pathname.split("/")[2];
      data = await trackerSnapshot(tool, filters);
    } else if (url.pathname === "/bitcraft/guides/items") {
      data = {
        items: await catalog("items", "", filters.q ?? ""),
        available: true,
      };
    } else if (url.pathname === "/bitcraft/guides/card-options") {
      const items =
        filters.activity === "gathering"
          ? (await catalog("entries")).entries
              .filter((row) =>
                row.name
                  .toLowerCase()
                  .includes((filters.q ?? "").toLowerCase()),
              )
              .slice(0, 60)
          : await catalog("targets", "", filters.q ?? "");
      data = { items, available: true };
    } else if (url.pathname === "/bitcraft/guides/card-data") {
      data =
        filters.activity === "gathering"
          ? await catalog("card", filters.recipeId ?? "", "gathering")
          : await catalog(
              "card-item",
              `${filters.kind ?? "item"}:${filters.itemId}`,
              filters.recipeId ?? "",
            );
      if (!data)
        return Response.json(
          { error: "This recipe is unavailable." },
          { status: 404 },
        );
    } else throw new Error("This data request is unavailable.");
    if (options.signal?.aborted)
      throw new DOMException("Aborted", "AbortError");
    return Response.json(data);
  } catch (error) {
    if (options.signal?.aborted)
      throw new DOMException("Aborted", "AbortError");
    return Response.json(
      {
        error: error.message,
        refresh: {
          delayed: Boolean(error.retryAfter),
          retryAfter: error.retryAfter ?? 0,
        },
      },
      {
        status: error.retryAfter ? 429 : 502,
        headers: error.retryAfter
          ? { "Retry-After": String(error.retryAfter) }
          : {},
      },
    );
  }
}
