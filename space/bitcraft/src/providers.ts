export type ProviderName = "bitjuice" | "bitjita" | "relay";
export interface ProviderConfig {
  enabled: boolean;
  baseUrl: string;
  timeout: number;
  poolConcurrency?: number;
  requestsPerMinute: number;
  callerRequestsPerMinute: number;
  staleSeconds: number;
  failureCooldown: number;
  appIdentifier: string;
  identity: string;
  token: string;
  ttl: Record<string, number>;
}
export const providerDefaults: Record<ProviderName, ProviderConfig> = {
  bitjita: {
    enabled: true,
    baseUrl: "https://bitjita.com",
    timeout: 12,
    requestsPerMinute: 200,
    callerRequestsPerMinute: 150,
    staleSeconds: 300,
    failureCooldown: 30,
    appIdentifier: "Dataverse Bitcraft Tools",
    identity: "",
    token: "",
    ttl: {
      regions: 86400,
      claims: 300,
      empires: 600,
      empireClaims: 600,
      items: 3600,
      cargo: 3600,
      item: 3600,
      cargoItem: 3600,
      levels: 86400,
      market: 60,
      itemOrders: 30,
      cargoOrders: 30,
      claimListings: 30,
      claim: 300,
      claimBuildings: 300,
      stalls: 300,
      players: 60,
      player: 15,
      inventories: 15,
      passive: 15,
      crafts: 60,
    },
  },
  bitjuice: {
    enabled: true,
    baseUrl: "https://bitjuiceapi.deeznuts.chat",
    timeout: 5,
    requestsPerMinute: 200,
    callerRequestsPerMinute: 30,
    staleSeconds: 300,
    failureCooldown: 30,
    appIdentifier: "",
    identity: "",
    token: "",
    ttl: { players: 60, player: 30, inventories: 60, passive: 30, crafts: 60 },
  },
  relay: {
    enabled: true,
    baseUrl: "https://relay.bitcraftsync.app",
    timeout: 8,
    requestsPerMinute: 200,
    callerRequestsPerMinute: 150,
    staleSeconds: 0,
    failureCooldown: 5,
    appIdentifier: "",
    identity: "",
    token: "",
    ttl: { players: 5, player: 5, inventories: 5, crafts: 5, claim: 5 },
  },
};
export function providerConfig(name: string, input: string): ProviderConfig {
  if (
    !Object.prototype.hasOwnProperty.call(providerDefaults, name) ||
    input.length > 12000
  )
    throw new Error("Invalid provider settings.");
  const defaults = providerDefaults[name as ProviderName],
    value = JSON.parse(input);
  if (!value || typeof value !== "object" || Array.isArray(value))
    throw new Error("Invalid provider settings.");
  const config = {
    ...defaults,
    ...value,
    ttl: { ...defaults.ttl, ...value.ttl },
  };
  if (
    typeof config.baseUrl !== "string" ||
    !/^https:\/\/[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::\d{1,5})?(?:\/[a-z0-9_./~-]*)?$/i.test(
      config.baseUrl,
    )
  )
    throw new Error("Use an HTTPS provider base URL without credentials.");
  config.baseUrl = config.baseUrl.replace(/\/+$/, "");
  if (typeof config.enabled !== "boolean")
    throw new Error("Invalid provider setting.");
  if (
    config.poolConcurrency !== undefined &&
    (!Number.isInteger(config.poolConcurrency) ||
      config.poolConcurrency < 1 ||
      config.poolConcurrency > 16)
  )
    throw new Error("Invalid provider concurrency.");
  for (const [key, max] of [
    ["timeout", 30],
    ["requestsPerMinute", 250],
    ["callerRequestsPerMinute", 250],
    ["staleSeconds", 3600],
    ["failureCooldown", 3600],
  ] as const) {
    if (!Number.isInteger(config[key]) || config[key] < 0 || config[key] > max)
      throw new Error("Invalid provider limit.");
  }
  if (
    config.timeout < 1 ||
    config.requestsPerMinute < 1 ||
    config.callerRequestsPerMinute < 1
  )
    throw new Error("Provider limits must be positive.");
  for (const seconds of Object.values(config.ttl))
    if (
      !Number.isInteger(seconds) ||
      Number(seconds) < 0 ||
      Number(seconds) > 86400
    )
      throw new Error("Invalid cache duration.");
  for (const key of ["appIdentifier", "identity", "token"] as const)
    if (
      typeof config[key] !== "string" ||
      config[key].length > 2000 ||
      /[\r\n]/.test(config[key])
    )
      throw new Error("Invalid provider header.");
  return config;
}
export function providerOrder(resource: string): ProviderName[] {
  if (resource.startsWith("relay")) return ["relay"];
  return ["players", "player", "inventories", "passive", "crafts"].includes(
    resource,
  )
    ? ["bitjuice", "bitjita"]
    : ["bitjita"];
}
export function relayRequest(resource: string, id: string, query: string) {
  const names: Record<string, string> = {
    relayPlayers: "players",
    relayPlayer: "player",
    relayInventories: "inventories",
    relayCrafts: "crafts",
    relayClaim: "claim",
  };
  const kind = names[resource];
  if (
    !kind ||
    query.length > 120 ||
    (kind !== "players" && !/^\d{1,24}$/.test(id))
  )
    throw new Error("Invalid relay request.");
  const paths: Record<string, string> = {
    players: `player?name=${encodeURIComponent(query.trim())}`,
    player: `player/${id}`,
    inventories: `player/${id}/inventory`,
    crafts: `player/${id}/crafts?completed=false`,
    claim: `claim/${id}`,
  };
  return { path: paths[kind], kind };
}
export function validProviderPayload(
  provider: ProviderName,
  kind: string,
  payload: unknown,
  id = "",
) {
  if (provider === "relay") {
    if (kind === "players") return Array.isArray(payload);
    if (!payload || typeof payload !== "object" || Array.isArray(payload))
      return false;
    if (kind === "inventories" || kind === "crafts")
      return Array.isArray((payload as any)[kind]);
    return Boolean((payload as any).entity_id ?? (payload as any).entityId);
  }
  const body = (payload as any)?.data ?? payload;
  if (!body || typeof body !== "object") return false;
  const field: Record<string, string> = {
    players: "players",
    player: "player",
    inventories: "inventories",
    passive: "craftResults",
    crafts: "craftResults",
  };
  if (!field[kind]) return true;
  if (kind === "player")
    return Boolean(
      body.player &&
      typeof body.player === "object" &&
      !Array.isArray(body.player) &&
      typeof body.player.username === "string" &&
      Array.isArray(body.player.experience) &&
      (!id || String(body.player.entityId) === id),
    );
  if (!Array.isArray(body[field[kind]])) return false;
  if (provider !== "bitjuice") return true;
  if (
    kind === "inventories" &&
    (!body.items ||
      typeof body.items !== "object" ||
      !body.cargos ||
      typeof body.cargos !== "object")
  )
    return false;
  return body[field[kind]].every((row: any) => {
    if (!row || !/^\d+$/.test(String(row.entityId))) return false;
    if (kind === "players")
      return typeof row.username === "string" && Boolean(row.username.trim());
    if (kind === "inventories") return Array.isArray(row.pockets);
    if (
      !Number.isFinite(Number(row.recipeId)) ||
      Number(row.recipeId) < 1 ||
      !Array.isArray(row.craftedItem)
    )
      return false;
    if (kind === "passive") return Boolean(row.timestamp);
    return (
      Number.isFinite(Number(row.progress)) &&
      Number.isFinite(Number(row.totalActionsRequired)) &&
      typeof row.completed === "boolean" &&
      typeof row.isPublic === "boolean"
    );
  });
}
export function chooseProviderResult<
  T extends {
    payload: string;
    error: string;
    retryAt: bigint;
    updatedAt: bigint;
  },
>(results: T[], now: bigint): T {
  const fresh = results.find(
    (row) => row.payload && !row.error && row.retryAt <= now,
  );
  if (fresh) return fresh;
  return [...results].sort(
    (a, b) =>
      Number(Boolean(b.payload)) - Number(Boolean(a.payload)) ||
      (a.updatedAt > b.updatedAt ? -1 : a.updatedAt < b.updatedAt ? 1 : 0),
  )[0];
}
export function retryAfterSeconds(header: string, nowSeconds: number) {
  const value = header.trim();
  if (/^\d+$/.test(value) && Number.isFinite(Number(value)))
    return Math.max(1, Math.min(3600, Number(value)));
  const timestamp = Date.parse(value);
  return Number.isFinite(timestamp)
    ? Math.max(1, Math.min(3600, Math.ceil(timestamp / 1000 - nowSeconds)))
    : 60;
}
