import { claimDecayForecast, type ClaimDecayTiming } from "./claim-decay";

export type ClaimSupply = {
  claimId: string;
  supplies: number;
  upkeepPerHour: number | null;
  tileCount: number;
  buildingMaintenance: number;
  treasury: string;
  tickMillis: number | null;
};

export function calculateClaimUpkeep(
  local: any,
  tileCosts: any[],
  parameters: any,
): number | null {
  const tiles = Number(local?.num_tiles),
    neighbors = Number(local?.num_tile_neighbors),
    maintenance = Number(local?.building_maintenance);
  const tick = Number(parameters?.building_decay_tick_millis);
  if (
    !local ||
    !parameters ||
    !tileCosts.length ||
    ![tiles, neighbors, maintenance, tick].every(Number.isFinite) ||
    tiles <= 0 ||
    neighbors < 0 ||
    maintenance < 0 ||
    tick <= 0
  )
    return null;
  if (
    tileCosts.some(
      (row) =>
        !Number.isInteger(row.tile_count) ||
        !Number.isFinite(row.cost_per_tile) ||
        row.cost_per_tile < 0,
    )
  )
    return null;
  let cost = 0.1,
    start = 1,
    tileMaintenance = 0;
  for (const row of [...tileCosts].sort(
    (a, b) => a.tile_count - b.tile_count,
  )) {
    const next = Math.max(1, row.tile_count);
    if (next > tiles) break;
    tileMaintenance += (next - start) * cost;
    start = next;
    cost = row.cost_per_tile;
  }
  tileMaintenance += (tiles - start + 1) * cost;
  // November 10 server logic hardcodes these values; the old m/b parameters
  // remain in static data but no longer control stability.
  const multiplier = Math.max(1, Math.exp(5.5 * (5.65 - neighbors / tiles)));
  const upkeep =
    ((tileMaintenance * multiplier + maintenance) * 3_600_000) / tick;
  return Number.isFinite(upkeep) && upkeep >= 0 ? upkeep : null;
}

export function claimSupplyEstimate(
  supply: ClaimSupply | null,
  observedAt: bigint,
  now: bigint,
  ready: boolean,
  timing: ClaimDecayTiming | null = null,
  epoch = "",
) {
  const rate = supply?.upkeepPerHour ?? null;
  const decay = claimDecayForecast(
    timing,
    supply,
    Number(observedAt / 1000n),
    Number(now / 1000n),
    ready,
    epoch,
  );
  const expiresAt =
    decay.expiresAt ??
    (supply && supply.supplies > 0 && rate !== null && rate > 0
      ? Number(observedAt / 1000n) + (supply.supplies / rate) * 3_600_000
      : null);
  return {
    ...decay,
    estimateBasis: decay.expiresAt !== null ? "regional_tick" : "hourly_rate",
    supplies: supply?.supplies ?? null,
    upkeepPerHour: rate,
    tileCount: supply?.tileCount ?? null,
    buildingMaintenance: supply?.buildingMaintenance ?? null,
    treasury: supply?.treasury ?? null,
    tickMillis: supply?.tickMillis ?? null,
    suppliesObservedAt: supply ? String(observedAt) : null,
    estimatedExpiresAt: expiresAt,
    remainingHours:
      expiresAt !== null
        ? Math.max(0, (expiresAt - Number(now / 1000n)) / 3_600_000)
        : null,
    supplyStatus: !supply ? "unknown" : ready ? "ready" : "stale",
  };
}
