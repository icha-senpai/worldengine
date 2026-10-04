import { pageWindow } from "../../spacetimedb/src/performance";
import { connectBitcraft, requestData } from "../bitcraft";
import { pageState } from "./navigation";
import { route } from "./navigation";
import {
  marketItem,
  matchesOrders,
  normalizeOrderBook,
  barterRows,
  itemsFromListings,
  stallClaims,
  unwrap,
} from "./market";
import { activityTracker, inventoryTracker, passiveTracker } from "./trackers";
import { openCraftRows, filterCrafts } from "./openCrafts";
import { relayPlayer, relayInventory, relayCrafts } from "./relay";

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
async function trackerInventory(player) {
  try {
    const response = await live("relayInventories", player.entityId);
    if (!response.refresh.delayed)
      return {
        ...response,
        data: await enrichTrackerItems(relayInventory(response.data)),
      };
  } catch {
    /* Continue with the player APIs. */
  }
  const response = await live("inventories", player.entityId);
  return { ...response, data: await enrichTrackerItems(response.data) };
}
async function trackerPassive(player) {
  let primary, failure;
  try {
    primary = await live("passive", player.entityId);
    if (!primary.refresh.delayed)
      return { ...primary, data: await enrichTrackerItems(primary.data) };
  } catch (error) {
    failure = error;
  }
  try {
    const response = await live("relayCrafts", player.entityId);
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
    return {
      ...response,
      data: await enrichTrackerItems(relayCrafts(response.data, claims)),
    };
  } catch (error) {
    if (primary)
      return { ...primary, data: await enrichTrackerItems(primary.data) };
    throw failure ?? error;
  }
}
async function regionsAndFilters(filters) {
  const response = await live("regions");
  const regions = (
    Array.isArray(response.data) ? response.data : (response.data.regions ?? [])
  ).map((row) => ({
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
async function mapLimited(rows, callback) {
  const result = new Array(rows.length);
  const { poolConcurrency } = await providerPolicy();
  let next = 0;
  await Promise.all(
    Array.from({ length: Math.min(poolConcurrency, rows.length) }, async () => {
      while (next < rows.length) {
        const index = next++;
        result[index] = await callback(rows[index]);
      }
    }),
  );
  return result;
}
async function paged(resource, id = "", q = "", options = {}) {
  const first = await live(resource, id, q, 1, options);
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
    (page) => live(resource, id, q, page, options),
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
async function scopedListings(claims, filters) {
  const side =
    filters.hasBuyOrders && !filters.hasSellOrders
      ? "buy"
      : filters.hasSellOrders && !filters.hasBuyOrders
        ? "sell"
        : undefined;
  const responses = await mapLimited(claims, async (claim) => ({
    claim,
    response: await paged("claimListings", claim.entityId, "", {
      itemId: filters.itemId,
      itemType: filters.itemKind,
      side,
    }),
  }));
  const listings = responses
    .flatMap(({ claim, response }) =>
      response.data.listings.map((row) => ({
        ...row,
        source: "market-order",
        price: row.priceThreshold ?? row.price,
        claimEntityId: row.claimEntityId ?? claim.entityId,
        claimName: row.claimName ?? claim.name,
        regionId: row.regionId ?? claim.regionId,
        regionName: row.regionName ?? claim.regionName,
      })),
    )
    .filter(
      (row) =>
        (!filters.q ||
          String(row.itemName ?? "")
            .toLowerCase()
            .includes(filters.q.toLowerCase()) ||
          String(row.itemId) === filters.q) &&
        (!filters.category ||
          String(row.itemCategory ?? row.itemTag ?? "")
            .toLowerCase()
            .includes(filters.category.toLowerCase())) &&
        (!filters.itemId || String(row.itemId) === String(filters.itemId)),
    );
  return {
    listings,
    refresh: {
      updatedAt:
        responses
          .map(({ response }) => response.refresh.updatedAt)
          .filter(Boolean)
          .sort()
          .at(-1) ?? null,
      delayed: responses.some(({ response }) => response.refresh.delayed),
      retryAfter: Math.max(
        0,
        ...responses.map(({ response }) => response.refresh.retryAfter),
      ),
    },
  };
}
export const emptyMarket = () => ({
  items: [],
  categories: [],
  claims: [],
  tradeBuildings: [],
  listings: [],
  empires: [],
  orderBook: null,
  metrics: {},
  claim: null,
});
export async function marketPage(tool, filters) {
  const market = emptyMarket();
  let regions = [],
    refresh = {};
  try {
    regions = await regionsAndFilters(filters);
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
    } else if (filters.claimQ || filters.region)
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
        let stalls = response.data.stalls;
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
    } else if (
      filters.q ||
      filters.category ||
      filters.regionId ||
      filters.empireEntityId ||
      filters.claimQ ||
      filters.claimEntityId ||
      filters.itemId ||
      filters.hasOrders ||
      filters.hasSellOrders ||
      filters.hasBuyOrders
    ) {
      if (
        filters.regionId ||
        filters.claimEntityId ||
        filters.empireEntityId ||
        filters.claimQ
      ) {
        const scope = pageWindow(
          market.claims.length,
          Number(filters.scopePage) || 1,
          10,
        );
        market.scopePagination = scope;
        const response = await scopedListings(
          market.claims.slice(scope.offset, scope.offset + 10),
          filters,
        );
        market.listings = response.listings;
        refresh = response.refresh;
        market.items = itemsFromListings(market.listings).filter((row) =>
          matchesOrders(row, filters),
        );
      } else {
        const response = await live("market", "", filters.q || "", 1, filters);
        refresh = response.refresh;
        market.items = (response.data.items ?? [])
          .map(marketItem)
          .filter((row) => matchesOrders(row, filters));
        market.metrics = response.data.metrics ?? {};
        // Market search returns availability flags; prices and real counts live
        // in each item's order book. The shared server cache coalesces lookups.
        market.categories = [
          ...new Set(market.items.map((row) => row.category).filter(Boolean)),
        ].sort();
        const pagination = pageWindow(
          market.items.length,
          Number(filters.page) || 1,
          20,
        );
        market.pagination = pagination;
        market.items = await mapLimited(
          market.items.slice(pagination.offset, pagination.offset + 20),
          async (item) => {
            const { orderBook: book } = await orderBook({
              ...filters,
              itemId: item.id,
              itemKind: item.kind,
            });
            const stats = book.stats;
            return {
              ...item,
              lowestSellPrice: stats.lowestSell,
              highestBuyPrice: stats.highestBuy,
              lowestBuyPrice: stats.lowestBuy,
              sellOrderCount: stats.sellOrderCount,
              buyOrderCount: stats.buyOrderCount,
              sellOrderQuantity: book.sellOrders.reduce(
                (sum, row) => sum + row.quantity,
                0,
              ),
              buyOrderQuantity: book.buyOrders.reduce(
                (sum, row) => sum + row.quantity,
                0,
              ),
              ...Object.fromEntries(
                Object.entries(stats).filter(([key]) =>
                  /Quantity|LineTotal|OrderPrice/.test(key),
                ),
              ),
            };
          },
        );
        if (response.error)
          throw Object.assign(new Error(response.error), { partial: true });
      }
    }
    if (!market.categories.length)
      market.categories = [
        ...new Set(market.items.map((row) => row.category).filter(Boolean)),
      ].sort();
    if (filters.claimEntityId) {
      market.claim =
        market.claims.find(
          (row) => String(row.entityId) === String(filters.claimEntityId),
        ) ?? (await live("claim", filters.claimEntityId)).data.claim;
      if (tool !== "barter-stalls") {
        const buildings =
          (await live("claimBuildings", filters.claimEntityId)).data
            .buildings ?? [];
        market.tradeBuildings = buildings
          .filter((row) =>
            /market|barter/i.test(row.buildingName ?? row.name ?? ""),
          )
          .map((row) => ({
            ...row,
            buildingName: row.buildingName ?? row.name,
            inventoryItems: row.inventoryItems ?? [],
          }));
      }
    }
    if (filters.itemId && tool !== "barter-stalls") {
      const response = await orderBook(filters);
      market.orderBook = response.orderBook;
      refresh = response.refresh;
    }
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
async function orderBook(filters) {
  if (filters.region && !filters.regionId) await regionsAndFilters(filters);
  if (filters.empireEntityId && !filters.claimIds)
    filters.claimIds =
      (await live("empireClaims", filters.empireEntityId)).data.claims?.map(
        (claim) => String(claim.entityId),
      ) ?? [];
  const response = await live(
    filters.itemKind === "cargo" ? "cargoOrders" : "itemOrders",
    filters.itemId,
    "",
    1,
    filters,
  );
  return {
    orderBook: normalizeOrderBook(response.data, filters),
    cache: response.refresh,
    refresh: response.refresh,
    error: response.error,
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
      const [definitions, response] = await Promise.all([
        catalog("skills"),
        live("levels"),
      ]);
      return {
        tracker: activityTracker(
          player,
          definitions,
          Array.isArray(response.data)
            ? response.data
            : (response.data.levels ?? []),
          filters,
        ),
        error: playerResponse.error || response.error,
        sampledAt: playerResponse.refresh.updatedAt ?? sampledAt,
        refresh: {
          ...playerResponse.refresh,
          delayed: playerResponse.refresh.delayed || response.refresh.delayed,
        },
      };
    }
    if (tool === "inventory") {
      const [response, items] = await Promise.all([
        trackerInventory(player),
        catalog("items", "", filters.itemSearch ?? ""),
      ]);
      return {
        ...inventoryTracker(player, response.data, items, filters),
        error: response.error,
        sampledAt: response.refresh.updatedAt ?? sampledAt,
        refresh: response.refresh,
      };
    }
    const response = await trackerPassive(player);
    const ids = [
        ...new Set(
          (response.data.craftResults ?? [])
            .map((row) => String(row.recipeId ?? row.recipe_id))
            .filter((id) => /^\d+$/.test(id)),
        ),
      ],
      times = {};
    for (let index = 0; index < ids.length; index += 6)
      Object.assign(
        times,
        await catalog("recipe-times", ids.slice(index, index + 6).join(",")),
      );
    return {
      tracker: passiveTracker(player, response.data, times),
      error: response.error,
      sampledAt: response.refresh.updatedAt ?? sampledAt,
      refresh: response.refresh,
    };
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
) {
  if (
    query.profile &&
    ["activity", "inventory", "passive-crafts", "tasks"].includes(tool)
  ) {
    const { readWidget } = await import("./widgets");
    query = {
      ...(await readWidget(tool, String(query.profile))),
      profile: query.profile,
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
  if (tool === "tool-rates") return await catalog("entries");
  if (tool === "hunting-calculator") {
    try {
      const response = await live("levels");
      return {
        levels: Array.isArray(response.data)
          ? response.data
          : (response.data.levels ?? []),
        error: response.error,
      };
    } catch (error) {
      return { levels: [], error: error.message };
    }
  }
  if (["market", "barter-stalls"].includes(tool))
    return {
      filters,
      ...(await marketPage(tool, filters)),
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
    return {
      filters,
      items,
      detail,
      snapshot,
      error:
        filters.itemId && !detail
          ? "That item is not available in the current catalog."
          : null,
    };
  }
  if (tool === "open-crafts") {
    try {
      const [response, skills] = await Promise.all([
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
      data = await orderBook(filters);
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
