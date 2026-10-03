export const unwrap = (value) => value?.data ?? value ?? {};
export const number = (value) =>
  Number.isFinite(Number(value)) ? Number(value) : null;
export const itemKind = (value) =>
  String(value) === "1" || value === "cargo" ? "cargo" : "item";
export function marketItem(raw) {
  const stats = raw.stats ?? {};
  const item = {
    ...raw,
    id: raw.id ?? raw.itemId,
    kind: itemKind(raw.itemType ?? raw.kind ?? raw.type),
    type: raw.itemType ?? raw.type ?? "item",
    name: raw.name ?? raw.itemName ?? "Unknown item",
    category: raw.category ?? raw.tag,
    rarity: raw.rarityStr ?? raw.itemRarityStr ?? raw.rarity,
  };
  for (const key of [
    "lowestSellPrice",
    "highestBuyPrice",
    "sellOrderCount",
    "buyOrderCount",
    "sellOrderQuantity",
    "buyOrderQuantity",
    "lowestBuyPrice",
    "highestBuyQuantity",
    "highestBuyLineTotal",
    "lowestBuyQuantity",
    "lowestBuyLineTotal",
    "largestBuyOrderPrice",
    "largestBuyOrderQuantity",
    "largestBuyOrderLineTotal",
    "smallestBuyOrderPrice",
    "smallestBuyOrderQuantity",
    "smallestBuyOrderLineTotal",
  ])
    item[key] = raw[key] ?? stats[key] ?? null;
  item.lowestSellPrice ??= stats.lowestSell;
  item.highestBuyPrice ??= stats.highestBuy;
  item.sellOrderCount ??= raw.sellOrders;
  item.buyOrderCount ??= raw.buyOrders;
  return item;
}
export function matchesOrders(item, filters) {
  return (
    (!filters.category || String(item.category) === filters.category) &&
    (!filters.hasOrders ||
      Number(item.sellOrderCount) + Number(item.buyOrderCount) > 0) &&
    (!filters.hasSellOrders || Number(item.sellOrderCount) > 0) &&
    (!filters.hasBuyOrders || Number(item.buyOrderCount) > 0)
  );
}
export function normalizeOrderBook(raw, filters = {}) {
  const payload = unwrap(raw);
  const normalize = (rows, side) =>
    rows
      .map((row) => ({
        ...row,
        entityId: String(row.entityId ?? row.id ?? ""),
        side,
        price: row.priceThreshold ?? row.price,
        quantity: Number(row.quantity ?? 0),
      }))
      .filter(
        (row) =>
          (!filters.regionId ||
            String(row.regionId) === String(filters.regionId)) &&
          (!filters.claimEntityId ||
            String(row.claimEntityId) === String(filters.claimEntityId)) &&
          (!filters.claimIds ||
            filters.claimIds.includes(String(row.claimEntityId))),
      );
  const sellOrders = normalize(payload.sellOrders ?? [], "sell"),
    buyOrders = normalize(payload.buyOrders ?? [], "buy");
  const packageSellOrders = normalize(payload.packageSellOrders ?? [], "sell"),
    packageBuyOrders = normalize(payload.packageBuyOrders ?? [], "buy");
  const buys = buyOrders.length ? buyOrders : packageBuyOrders,
    sells = sellOrders.length ? sellOrders : packageSellOrders;
  const pick = (rows, key, desc) =>
    [...rows].sort(
      (a, b) => (Number(a[key]) - Number(b[key])) * (desc ? -1 : 1),
    )[0];
  const highest = pick(buys, "price", true),
    lowest = pick(buys, "price", false),
    largest = pick(buys, "quantity", true),
    smallest = pick(buys, "quantity", false);
  const stats = {
    ...payload.stats,
    lowestSell: sells.length
      ? Math.min(...sells.map((row) => Number(row.price)))
      : null,
    highestBuy: highest?.price ?? null,
    lowestBuy: lowest?.price ?? null,
    sellOrderCount: sells.length,
    buyOrderCount: buys.length,
  };
  for (const [prefix, row] of [
    ["highestBuy", highest],
    ["lowestBuy", lowest],
    ["largestBuyOrder", largest],
    ["smallestBuyOrder", smallest],
  ]) {
    stats[prefix + "Price"] = row?.price ?? null;
    stats[prefix + "Quantity"] = row?.quantity ?? null;
    stats[prefix + "LineTotal"] = row ? Number(row.price) * row.quantity : null;
  }
  return {
    item: marketItem(payload.item ?? payload.cargo ?? {}),
    sellOrders,
    buyOrders,
    packageSellOrders,
    packageBuyOrders,
    packageInfo: payload.packageInfo ?? null,
    stats,
  };
}
const stackKey = (item) => `${item.kind}:${item.id}`;
export function stallClaims(stalls, fallback = []) {
  const grouped = new Map();
  for (const stall of stalls) {
    if (!stall.claimName) continue;
    const key = String(stall.claimName).toLowerCase(),
      known = fallback.find(
        (claim) => String(claim.name).toLowerCase() === key,
      );
    const group = grouped.get(key) ?? {
      ...known,
      entityId:
        known?.entityId ||
        stall.claimEntityId ||
        `stall-claim:${encodeURIComponent(stall.claimName)}`,
      name: stall.claimName,
      regionId: stall.regionId,
      regionName: stall.regionName,
      locationX: stall.locationX,
      locationZ: stall.locationZ,
      tradeBuildingCount: 0,
      tradeOrderCount: 0,
    };
    group.tradeBuildingCount++;
    group.tradeOrderCount += Number(
      stall.orderCount ?? stall.orders?.length ?? 0,
    );
    grouped.set(key, group);
  }
  return [...grouped.values()].sort((a, b) => a.name.localeCompare(b.name));
}
export function barterRows(raw, filters = {}) {
  const payload = unwrap(raw);
  const stalls = (payload.stalls ?? []).map((stall) => ({
    ...stall,
    entityId: String(stall.entityId ?? ""),
    claimEntityId: String(stall.claimEntityId ?? stall.claim?.entityId ?? ""),
    claimName: stall.claimName ?? stall.claim?.name,
    regionName: stall.regionName ?? stall.claim?.regionName,
    orders: stall.orders ?? [],
  }));
  const rows = [];
  for (const stall of stalls) {
    if (
      filters.claimEntityId &&
      stall.claimEntityId !== String(filters.claimEntityId)
    )
      continue;
    if (filters.regionId && String(stall.regionId) !== String(filters.regionId))
      continue;
    for (const order of stall.orders) {
      const normalize = (items, kind) =>
        items.map((stack) => ({
          ...marketItem({ ...stack, kind }),
          quantity: Number(stack.quantity ?? 1),
        }));
      const offered = [
        ...normalize(order.offerItems ?? [], "item"),
        ...normalize(order.offerCargo ?? [], "cargo"),
      ];
      const required = [
        ...normalize(order.requiredItems ?? [], "item"),
        ...normalize(order.requiredCargo ?? [], "cargo"),
      ];
      for (const [side, items, costs] of [
        ["sell", offered, required],
        ["buy", required, offered],
      ])
        for (const item of items) {
          if (Number(item.id) === 1) continue;
          if (filters.itemId && String(item.id) !== String(filters.itemId))
            continue;
          if (filters.itemKind && item.kind !== filters.itemKind) continue;
          if (
            filters.q &&
            !item.name.toLowerCase().includes(filters.q.toLowerCase())
          )
            continue;
          if (filters.side && side !== filters.side) continue;
          const coins = costs.find(
            (stack) => Number(stack.id) === 1 && stack.kind === "item",
          );
          if (!coins) continue;
          const summary = (stacks) =>
            stacks
              .map(
                (stack) => `${stack.quantity.toLocaleString()}x ${stack.name}`,
              )
              .join(" + ");
          rows.push({
            ...item,
            entityId: `${order.entityId}:${side}:${item.kind}:${item.id}`,
            source: "stall-order",
            itemId: item.id,
            itemType: item.kind,
            itemKind: item.kind,
            itemName: item.name,
            itemIconAssetName: item.iconAssetName,
            itemCategory: item.kind === "cargo" ? "Cargo" : "Item",
            itemTier: item.tier,
            itemRarity: item.rarity,
            side,
            quantity: Number(order.remainingStock ?? 0),
            price: coins.quantity / Math.max(1, item.quantity),
            remainingStock: order.remainingStock ?? null,
            bundlePrice: coins.quantity,
            priceCurrency: "Hex Coin",
            offerStacks: offered,
            requiredStacks: required,
            offerSummary: summary(offered),
            requiredSummary: summary(required),
            stallMatchStatus: "matched",
            stall: {
              ...stall,
              name: stall.nickname ?? stall.ownerName ?? "Barter Stall",
              buildingName: "Barter Stall",
              buildingNickname: stall.nickname,
            },
            claimEntityId: stall.claimEntityId || null,
            claimName: stall.claimName,
            regionId: stall.regionId,
            regionName: stall.regionName,
            ownerUsername: stall.ownerName,
          });
        }
    }
  }
  return {
    stalls,
    listings: rows,
    items: itemsFromListings(rows).filter((row) => matchesOrders(row, filters)),
  };
}
export function itemsFromListings(rows) {
  const grouped = new Map();
  for (const row of rows) {
    const kind = itemKind(row.itemType ?? row.itemKind),
      groupKey = stackKey({ kind, id: row.itemId });
    const group = grouped.get(groupKey) ?? {
      id: row.itemId,
      name: row.itemName,
      kind,
      type: kind,
      category:
        row.itemCategory ??
        row.itemTag ??
        (kind === "cargo" ? "Cargo" : "Item"),
      tier: row.itemTier,
      rarity: row.itemRarityStr ?? row.itemRarity,
      iconAssetName: row.itemIconAssetName ?? row.iconAssetName,
      sellOrderCount: 0,
      buyOrderCount: 0,
      sellOrderQuantity: 0,
      buyOrderQuantity: 0,
      lowestSellPrice: null,
      highestBuyPrice: null,
    };
    group[row.side + "OrderCount"]++;
    group[row.side + "OrderQuantity"] += Number(row.quantity ?? 0);
    if (row.price !== null) {
      const field = row.side === "sell" ? "lowestSellPrice" : "highestBuyPrice";
      group[field] =
        group[field] === null
          ? row.price
          : row.side === "sell"
            ? Math.min(group[field], row.price)
            : Math.max(group[field], row.price);
    }
    grouped.set(groupKey, group);
  }
  for (const group of grouped.values()) {
    const buys = rows.filter(
      (row) =>
        itemKind(row.itemType ?? row.itemKind) === group.kind &&
        String(row.itemId) === String(group.id) &&
        row.side === "buy",
    );
    for (const [prefix, key, desc] of [
      ["highestBuy", "price", true],
      ["lowestBuy", "price", false],
      ["largestBuyOrder", "quantity", true],
      ["smallestBuyOrder", "quantity", false],
    ]) {
      const best = [...buys].sort(
        (a, b) => (Number(a[key]) - Number(b[key])) * (desc ? -1 : 1),
      )[0];
      group[prefix + "Price"] = best?.price ?? null;
      group[prefix + "Quantity"] = best?.quantity ?? null;
      group[prefix + "LineTotal"] = best
        ? Number(best.price) * Number(best.quantity)
        : null;
    }
  }
  return [...grouped.values()];
}
