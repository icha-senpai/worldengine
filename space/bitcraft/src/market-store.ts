import { isVisibleRegionRecord } from "./regions";
type Row = Record<string, any>;
export const MARKET_DISCOVERY_SECONDS = 300;
export const MARKET_ROLLING_SECONDS = 1800;
export const MARKET_SEARCH_SECONDS = 30;

export function marketIdentity(kind: string, value: unknown) {
  if (!["item", "cargo"].includes(kind))
    throw new Error("Invalid market item kind.");
  if (typeof value === "number" && !Number.isSafeInteger(value))
    throw new Error("A market ID lost precision.");
  const id = String(value ?? "");
  if (!/^\d{1,24}$/.test(id)) throw new Error("Invalid market item ID.");
  return `${kind}:${id}`;
}
const body = (raw: Row) => raw?.data ?? raw;
export function marketDirectory(raw: Row) {
  const data = body(raw);
  if (
    !Array.isArray(data?.items) ||
    !data.items.length ||
    data.items.length > 20000 ||
    Number(data.totalPages ?? 1) > 1
  )
    throw new Error("A complete market directory is required.");
  const seen = new Set<string>();
  return data.items.map((item: Row) => {
    const kind = [1, "1", "cargo"].includes(
      item.itemType ?? item.kind ?? item.type,
    )
      ? "cargo"
      : "item";
    const key = marketIdentity(kind, item.id ?? item.itemId);
    if (seen.has(key) || typeof item.name !== "string" || !item.name.trim())
      throw new Error("Invalid market directory item.");
    seen.add(key);
    if (
      !Number.isFinite(Number(item.sellOrders)) ||
      !Number.isFinite(Number(item.buyOrders))
    )
      throw new Error("Missing market availability.");
    return {
      ...marketDisplayItem(item),
      sellOrders: Number(item.sellOrders),
      buyOrders: Number(item.buyOrders),
      key,
      id: key.split(":")[1],
      kind,
      category: item.category ?? item.tag ?? "",
      sellOrderCount: Number(item.sellOrders),
      buyOrderCount: Number(item.buyOrders),
    };
  });
}
export function marketBook(
  kind: string,
  id: string,
  raw: Row,
  metadata: Row = {},
) {
  const key = marketIdentity(kind, id),
    data = body(raw);
  if (
    !Array.isArray(data?.sellOrders) ||
    !Array.isArray(data?.buyOrders) ||
    Number(data.totalPages ?? 1) > 1
  )
    throw new Error("A complete unfiltered market book is required.");
  const item = data.item ?? data.cargo;
  if (!item || marketIdentity(kind, item.id ?? item.itemId) !== key)
    throw new Error("Market book item does not match.");
  const result: Row = { ...data, item: { ...metadata, ...item, id, kind } };
  for (const field of [
    "sellOrders",
    "buyOrders",
    "packageSellOrders",
    "packageBuyOrders",
  ]) {
    const orders = data[field] ?? [];
    if (!Array.isArray(orders) || orders.length > 100000)
      throw new Error("Invalid market orders.");
    const seen = new Set<string>();
    result[field] = orders.map((order: Row) => {
      const entityId = String(order.entityId ?? order.id ?? "");
      if (
        !entityId ||
        seen.has(entityId) ||
        (typeof (order.entityId ?? order.id) === "number" &&
          !Number.isSafeInteger(order.entityId ?? order.id))
      )
        throw new Error("Invalid or duplicate market order ID.");
      seen.add(entityId);
      const quantity = Number(order.quantity),
        regionId = Number(order.regionId ?? 0);
      const price = order.priceThreshold ?? order.price;
      if (
        !Number.isFinite(quantity) ||
        quantity < 0 ||
        !Number.isInteger(regionId) ||
        regionId < 0 ||
        regionId > 1000 ||
        (price != null &&
          (!Number.isFinite(Number(price)) || Number(price) < 0))
      )
        throw new Error("Invalid market order values.");
      if (
        order.claimEntityId != null &&
        typeof order.claimEntityId === "number" &&
        !Number.isSafeInteger(order.claimEntityId)
      )
        throw new Error("A claim ID lost precision.");
      return { ...order, entityId, quantity, regionId };
    });
  }
  return result;
}
export function emptyMarketBook(item: Row) {
  return {
    item,
    sellOrders: [],
    buyOrders: [],
    packageSellOrders: [],
    packageBuyOrders: [],
  };
}
export function marketDisplayItem(item: Row) {
  return Object.fromEntries(
    [
      "id",
      "kind",
      "name",
      "category",
      "tag",
      "tier",
      "rarity",
      "rarityStr",
      "iconAssetName",
      "modelAssetName",
      "itemType",
    ]
      .filter((key) => item[key] !== undefined)
      .map((key) => [key, item[key]]),
  );
}
export function marketSummary(book: Row, filters: Row = {}) {
  const match = (order: Row) =>
    isVisibleRegionRecord(order) &&
    (!filters.regionId ||
      String(order.regionId) === String(filters.regionId)) &&
    (!filters.claimEntityId ||
      String(order.claimEntityId) === String(filters.claimEntityId)) &&
    (!filters.claimQ ||
      String(order.claimName ?? "")
        .toLowerCase()
        .includes(String(filters.claimQ).toLowerCase())) &&
    (!filters.claimIds ||
      filters.claimIds.includes(String(order.claimEntityId)));
  const side = (ordinary: string, packages: string) => {
    const orders = (book[ordinary] ?? []).filter(match);
    return orders.length ? orders : (book[packages] ?? []).filter(match);
  };
  const sells = side("sellOrders", "packageSellOrders"),
    buys = side("buyOrders", "packageBuyOrders");
  const prices = (orders: Row[]) =>
    orders
      .map((order) => order.priceThreshold ?? order.price)
      .filter(
        (value) =>
          value !== null &&
          value !== undefined &&
          value !== "" &&
          Number.isFinite(Number(value)),
      )
      .map(Number);
  const sellPrices = prices(sells),
    buyPrices = prices(buys);
  const bestClaim = (orders: Row[], desc: boolean) =>
    orders
      .filter((order) => prices([order]).length)
      .slice()
      .sort(
        (a, b) =>
          (Number(a.priceThreshold ?? a.price) -
            Number(b.priceThreshold ?? b.price)) *
          (desc ? -1 : 1),
      )[0]?.claimName ?? null;
  return {
    ...marketDisplayItem(book.item),
    lowestSellPrice: sellPrices.length ? Math.min(...sellPrices) : null,
    highestBuyPrice: buyPrices.length ? Math.max(...buyPrices) : null,
    lowestBuyPrice: buyPrices.length ? Math.min(...buyPrices) : null,
    bestSellClaimName: bestClaim(sells, false),
    bestBuyClaimName: bestClaim(buys, true),
    sellOrderCount: sells.length,
    buyOrderCount: buys.length,
    sellOrderQuantity: sells.reduce(
      (sum: number, order: Row) => sum + order.quantity,
      0,
    ),
    buyOrderQuantity: buys.reduce(
      (sum: number, order: Row) => sum + order.quantity,
      0,
    ),
  };
}
export function marketRegions(book: Row) {
  return [
    0,
    ...new Set<number>(
      ["sellOrders", "buyOrders", "packageSellOrders", "packageBuyOrders"]
        .flatMap((field) =>
          (book[field] ?? [])
            .filter(isVisibleRegionRecord)
            .map((order: Row) => Number(order.regionId)),
        )
        .filter((region) => region > 0),
    ),
  ];
}
export function marketMatches(
  item: Row,
  query: string,
  itemId = "",
  kind = "",
) {
  if (itemId)
    return String(item.id) === itemId && (!kind || item.kind === kind);
  const q = query.trim().toLowerCase();
  return (
    !q || String(item.name).toLowerCase().includes(q) || String(item.id) === q
  );
}
export function marketWorkPriority(row: {
  requestedAt: bigint;
  observedAt: bigint;
}) {
  return row.requestedAt > row.observedAt;
}
