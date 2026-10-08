import { marketBook } from "./market-store";
export const CURRENT_REGIONS: Record<number, string> = {
  7: "Virexal",
  8: "Solmere",
  9: "Marowik",
  12: "Elyndor",
  13: "Hexalis",
  14: "Lumethis",
  17: "Draxen",
  18: "Oryxen",
  19: "Zephra",
};
type Row = Record<string, any>;
type Tables = Map<string, Map<string, Row>>;
export function assembleRelayTrading(
  tables: Tables,
  regionId: number,
  lookup: (kind: string, id: string) => Row | null,
) {
  if (!CURRENT_REGIONS[regionId]) throw Error("Unsupported current region.");
  const rows = (name: string) => {
    const table = tables.get(name);
    if (!table) throw Error("Incomplete trading subscription: " + name);
    return table;
  };
  const claims = rows("claim_state"),
    locations = rows("claim_local_state"),
    names = rows("player_username_state");
  const reference = (claimId: string, ownerId?: string) => {
    const claim = claims.get(claimId),
      local = locations.get(claimId),
      location = local?.location?.some;
    return {
      claimEntityId: claimId,
      claimName: claim?.name ?? null,
      ownerEntityId: ownerId ?? claim?.owner_player_entity_id ?? null,
      ownerUsername:
        names.get(ownerId ?? claim?.owner_player_entity_id)?.username ?? null,
      regionId,
      regionName: CURRENT_REGIONS[regionId],
      claimLocationX: location?.x ?? null,
      claimLocationZ: location?.z ?? null,
    };
  };
  const metadata = (kind: string, id: string) => ({
    ...lookup(kind, id),
    id,
    kind,
    name:
      lookup(kind, id)?.name ?? `${kind === "cargo" ? "Cargo" : "Item"} ${id}`,
  });
  const books: Record<string, Row> = {};
  for (const [table, field] of [
    ["sell_order_state", "sellOrders"],
    ["buy_order_state", "buyOrders"],
  ])
    for (const row of rows(table).values()) {
      if (
        ![0, 1].includes(row.item_type) ||
        row.quantity < 0 ||
        row.price_threshold < 0
      )
        throw Error("Invalid relay order.");
      const kind = row.item_type === 1 ? "cargo" : "item",
        id = String(row.item_id),
        key = `${kind}:${id}`;
      const book = (books[key] ??= {
        item: metadata(kind, id),
        sellOrders: [],
        buyOrders: [],
        packageSellOrders: [],
        packageBuyOrders: [],
      });
      book[field].push({
        entityId: row.entity_id,
        ...reference(row.claim_entity_id, row.owner_entity_id),
        quantity: row.quantity,
        priceThreshold: row.price_threshold,
        timestamp: row.timestamp,
      });
    }
  const shops = rows("barter_stall_state"),
    buildings = rows("building_state"),
    nicknames = rows("building_nickname_state"),
    stalls = new Map<string, Row>();
  const stack = (item: Row) => ({
    ...metadata("item", String(item.item_id)),
    quantity: item.quantity,
  });
  const cargo = (id: any) => ({
    ...metadata("cargo", String(id)),
    quantity: 1,
  });
  for (const row of rows("trade_order_state").values()) {
    const shop = shops.get(row.shop_entity_id),
      place = buildings.get(row.shop_entity_id);
    // Only exchanges attached to a player barter stall belong in Barter.
    // Traveler/NPC recipes may have effectively infinite stock and no bundles.
    if (!shop || !place || row.traveler_trade_order_id?.some !== undefined)
      continue;
    const offerItems = (row.offer_items ?? []).map(stack),
      requiredItems = (row.required_items ?? []).map(stack),
      offerCargo = (row.offer_cargo_id ?? []).map(cargo),
      requiredCargo = (row.required_cargo_id ?? []).map(cargo);
    if (
      !(
        offerItems.length +
        offerCargo.length +
        requiredItems.length +
        requiredCargo.length
      )
    )
      continue;
    const ref = reference(place.claim_entity_id);
    const stall = stalls.get(row.shop_entity_id) ?? {
      entityId: row.shop_entity_id,
      ...ref,
      ownerName: null,
      ownerEntityId: null,
      ownerUsername: null,
      claimOwnerUsername: ref.ownerUsername,
      constructedByUsername:
        names.get(place.constructed_by_player_entity_id)?.username ?? null,
      marketModeEnabled: shop.market_mode_enabled,
      nickname: nicknames.get(row.shop_entity_id)?.nickname ?? null,
      orders: [],
    };
    stall.orders.push({
      entityId: row.entity_id,
      remainingStock: row.remaining_stock,
      offerItems,
      requiredItems,
      offerCargo,
      requiredCargo,
    });
    stalls.set(row.shop_entity_id, stall);
  }
  return {
    books,
    stalls: [...stalls.values()].map((s) => ({
      ...s,
      orderCount: s.orders.length,
    })),
  };
}
export function validateRelayTrading(regionId: number, raw: Row) {
  if (
    !CURRENT_REGIONS[regionId] ||
    !raw ||
    typeof raw.books !== "object" ||
    Array.isArray(raw.books) ||
    !Array.isArray(raw.stalls) ||
    raw.stalls.length > 20000
  )
    throw Error("Invalid regional trading snapshot.");
  const books: Record<string, Row> = {};
  for (const [key, book] of Object.entries(raw.books) as [string, Row][]) {
    if (!/^(item|cargo):\d{1,24}$/.test(key))
      throw Error("Invalid regional item key.");
    const [kind, id] = key.split(":");
    books[key] = marketBook(kind, id, book);
    if (
      [
        ...books[key].sellOrders,
        ...books[key].buyOrders,
        ...books[key].packageSellOrders,
        ...books[key].packageBuyOrders,
      ].some((r) => r.regionId !== regionId)
    )
      throw Error("An order crossed its region boundary.");
  }
  if (Object.keys(books).length > 20000)
    throw Error("Regional market exceeds capacity.");
  const ids = new Set<string>();
  for (const stall of raw.stalls) {
    if (
      !/^\d{1,24}$/.test(stall.entityId) ||
      ids.has(stall.entityId) ||
      stall.regionId !== regionId ||
      !Array.isArray(stall.orders) ||
      stall.orders.length > 10000
    )
      throw Error("Invalid regional barter stall.");
    ids.add(stall.entityId);
    const orders = new Set<string>();
    for (const order of stall.orders) {
      if (
        !/^\d{1,24}$/.test(order.entityId) ||
        orders.has(order.entityId) ||
        !Number.isSafeInteger(order.remainingStock) ||
        order.remainingStock < 0
      )
        throw Error("Invalid barter stock.");
      orders.add(order.entityId);
      for (const field of [
        "offerItems",
        "offerCargo",
        "requiredItems",
        "requiredCargo",
      ]) {
        if (
          !Array.isArray(order[field]) ||
          order[field].length > 100 ||
          order[field].some(
            (s: Row) =>
              !/^\d{1,24}$/.test(String(s.id)) ||
              !Number.isSafeInteger(s.quantity) ||
              s.quantity <= 0 ||
              typeof s.name !== "string",
          )
        )
          throw Error("Invalid barter bundle.");
      }
    }
  }
  return { books, stalls: raw.stalls };
}
export function mergeRegionalBook(
  key: string,
  base: Row,
  regions: { regionId: number; books: Record<string, Row> }[],
) {
  const authoritative = new Set(regions.map((r) => r.regionId));
  const book = { ...base };
  for (const field of [
    "sellOrders",
    "buyOrders",
    "packageSellOrders",
    "packageBuyOrders",
  ])
    book[field] = [
      ...(base[field] ?? []).filter(
        (r: Row) => !authoritative.has(Number(r.regionId)),
      ),
      ...regions.flatMap((r) => r.books[key]?.[field] ?? []),
    ];
  return book;
}
