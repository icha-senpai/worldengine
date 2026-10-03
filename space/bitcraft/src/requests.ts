export function upstreamRequest(
  resource: string,
  id: string,
  query: string,
  page: number,
  options: Record<string, unknown> = {},
) {
  if (query.length > 120 || !Number.isInteger(page) || page < 1 || page > 100)
    throw new Error("Invalid search or page.");
  if (!options || Array.isArray(options) || typeof options !== "object")
    throw new Error("Invalid filters.");
  const numeric = () => {
    if (!/^\d{1,24}$/.test(id))
      throw new Error("Choose a valid item or player.");
    return id;
  };
  const paths: Record<string, () => string> = {
    market: () => "api/market",
    items: () => "api/items",
    cargo: () => "api/cargo",
    players: () => "api/players",
    player: () => `api/players/${numeric()}`,
    inventories: () => `api/players/${numeric()}/inventories`,
    passive: () => `api/players/${numeric()}/passive-crafts`,
    crafts: () => "api/crafts",
    item: () => `api/items/${numeric()}`,
    cargoItem: () => `api/cargo/${numeric()}`,
    itemOrders: () => `api/market/item/${numeric()}`,
    cargoOrders: () => `api/market/cargo/${numeric()}`,
    stalls: () => "api/stalls",
    claims: () => "api/claims",
    regions: () => "api/regions",
    claim: () => `api/claims/${numeric()}`,
    claimBuildings: () => `api/claims/${numeric()}/buildings`,
    claimListings: () => `api/claims/${numeric()}/market/listings`,
    empires: () => "api/empires",
    empireClaims: () => `api/empires/${numeric()}/claims`,
    levels: () => "static/experience/levels.json",
  };
  if (!paths[resource]) throw new Error("Unknown data request.");
  const path = paths[resource]();
  const params: Array<[string, string]> = [];
  if (query.trim()) params.push(["q", query.trim()]);
  const allowed: Record<string, string[]> = {
    market: [
      "category",
      "claimEntityId",
      "regionId",
      "hasOrders",
      "hasSellOrders",
      "hasBuyOrders",
    ],
    itemOrders: ["claimEntityId", "regionId"],
    cargoOrders: ["claimEntityId", "regionId"],
    claims: ["regionId"],
    claimListings: ["itemId", "itemType", "side"],
    stalls: ["itemId", "itemType", "claimEntityId", "regionId"],
  };
  for (const name of allowed[resource] ?? []) {
    const value = options[name];
    if (
      value === undefined ||
      value === null ||
      value === "" ||
      value === false
    )
      continue;
    if (
      !["string", "number", "boolean"].includes(typeof value) ||
      String(value).length > 120
    )
      throw new Error("Invalid filter.");
    if (name.endsWith("Id") && !/^\d{1,24}$/.test(String(value)))
      throw new Error("Choose a valid filter.");
    params.push([name, String(value)]);
  }
  if (["stalls", "claims", "claimListings"].includes(resource))
    params.push(
      ["page", String(page)],
      ["limit", resource === "claimListings" ? "200" : "100"],
    );
  if (resource === "passive") params.push(["status", "all"]);
  if (resource === "claims") params.push(["sort", "name"], ["order", "asc"]);
  const key = `${path}?${params.map(([key, value]) => `${key}=${encodeURIComponent(value)}`).join("&")}`;
  return { key, path: key };
}
