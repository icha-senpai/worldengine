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
  const m = Number(parameters?.claim_stability_param_m),
    b = Number(parameters?.claim_stability_param_b),
    tick = Number(parameters?.building_decay_tick_millis);
  if (
    !local ||
    !parameters ||
    !tileCosts.length ||
    ![tiles, neighbors, maintenance, m, b, tick].every(Number.isFinite) ||
    tiles <= 0 ||
    neighbors < 0 ||
    maintenance < 0 ||
    tick <= 0
  )
    return null;
  let cost = 0.1,
    threshold = 0;
  for (const row of tileCosts) {
    if (row.tile_count <= tiles && row.tile_count > threshold) {
      threshold = row.tile_count;
      cost = Number(row.cost_per_tile);
    }
  }
  const upkeep =
    ((tiles * cost * Math.max(1, (m * neighbors) / tiles + b) + maintenance) *
      3_600_000) /
    tick;
  return Number.isFinite(upkeep) && upkeep >= 0 ? upkeep : null;
}

export function claimSupplyEstimate(
  supply: ClaimSupply | null,
  observedAt: bigint,
  now: bigint,
  ready: boolean,
) {
  const rate = supply?.upkeepPerHour ?? null;
  const expiresAt =
    supply && supply.supplies > 0 && rate !== null && rate > 0
      ? Number(observedAt / 1000n) + (supply.supplies / rate) * 3_600_000
      : null;
  return {
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
