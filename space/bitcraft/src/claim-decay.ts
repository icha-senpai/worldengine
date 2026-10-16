import type { ClaimSupply } from "./claim-supplies";

// One bounded row per region, never a history of individual supply ticks.
export const DECAY_WINDOW_MS = 90_000;
const MIN_CLAIMS = 3;
const MAX_CLAIMS = 32;
export type ClaimDecayTiming = {
  epoch: string;
  tickMillis: number;
  lastTickAt: number | null;
  confirmedClaims: number;
  cycles: number;
  candidate: { at: number; claims: string[] };
};

export function supplyTickCost(supply: ClaimSupply): number | null {
  if (!supply.tickMillis || !supply.upkeepPerHour) return null;
  const cost = (supply.upkeepPerHour * supply.tickMillis) / 3_600_000;
  // The game rounds the remaining positive supply balance after subtraction.
  return Number.isFinite(cost) && cost >= 0
    ? Math.max(0, Math.ceil(cost - 0.5))
    : null;
}

export function observeClaimDecay(
  timing: ClaimDecayTiming | null,
  previous: ClaimSupply,
  supply: ClaimSupply,
  epoch: string,
  observedAt: number,
  previousAt: number,
  receivedAt: number,
): ClaimDecayTiming | null {
  const cost = supplyTickCost(supply);
  const drop = previous.supplies - supply.supplies;
  if (
    cost === null ||
    cost <= 0 ||
    drop <= 0 ||
    previous.tickMillis !== supply.tickMillis ||
    previous.upkeepPerHour !== supply.upkeepPerHour ||
    previous.tileCount !== supply.tileCount ||
    previous.buildingMaintenance !== supply.buildingMaintenance ||
    Math.abs(drop - Math.min(previous.supplies, cost)) > 1 ||
    observedAt <= previousAt ||
    receivedAt < observedAt ||
    receivedAt - observedAt > DECAY_WINDOW_MS ||
    observedAt - previousAt > supply.tickMillis! + DECAY_WINDOW_MS
  )
    return timing;

  let next: ClaimDecayTiming;
  if (
    !timing ||
    timing.epoch !== epoch ||
    timing.tickMillis !== supply.tickMillis
  ) {
    next = {
      epoch,
      tickMillis: supply.tickMillis!,
      lastTickAt: null,
      confirmedClaims: 0,
      cycles: 0,
      candidate: { at: observedAt, claims: [] },
    };
  } else {
    next = {
      ...timing,
      candidate: { ...timing.candidate, claims: [...timing.candidate.claims] },
    };
    if (observedAt < next.candidate.at) return timing;
    if (observedAt - next.candidate.at > DECAY_WINDOW_MS) {
      // A claim refilling/spending again shortly after a tick is not a new cycle.
      if (
        next.lastTickAt !== null &&
        observedAt - next.lastTickAt < next.tickMillis / 2
      )
        return timing;
      next.candidate = { at: observedAt, claims: [] };
    }
  }
  if (
    next.candidate.claims.includes(supply.claimId) ||
    next.candidate.claims.length >= MAX_CLAIMS
  )
    return timing;
  next.candidate.claims.push(supply.claimId);
  if (next.candidate.claims.length >= MIN_CLAIMS) {
    if (next.lastTickAt !== next.candidate.at) next.cycles++;
    next.lastTickAt = next.candidate.at;
    next.confirmedClaims = next.candidate.claims.length;
  }
  return next;
}

export function claimDecayForecast(
  timing: ClaimDecayTiming | null,
  supply: ClaimSupply | null,
  observedAt: number,
  now: number,
  ready: boolean,
  epoch: string,
) {
  const current =
    !!timing &&
    timing.epoch === epoch &&
    timing.tickMillis === supply?.tickMillis;
  const usable =
    current &&
    ready &&
    timing!.lastTickAt !== null &&
    now >= timing!.lastTickAt &&
    now - timing!.lastTickAt <= timing!.tickMillis * 2 + DECAY_WINDOW_MS;
  const cost = supply ? supplyTickCost(supply) : null;
  const last = timing?.lastTickAt ?? null;
  const nextDecayAt = usable
    ? last! +
      (Math.floor((now - last!) / timing!.tickMillis) + 1) * timing!.tickMillis
    : null;
  // Snap the observed balance to its decay interval instead of a rolling duration.
  const expiresAt =
    usable && supply && supply.supplies > 0 && cost && cost > 0
      ? last! +
        (Math.floor((observedAt - last!) / timing!.tickMillis) +
          Math.ceil(supply.supplies / cost)) *
          timing!.tickMillis
      : null;
  return {
    expiresAt,
    nextDecayAt,
    lastDecayAt: last,
    decayConfirmations: timing?.confirmedClaims ?? 0,
    decayTimingStatus: usable
      ? "observed"
      : last !== null
        ? "stale"
        : current
          ? "learning"
          : "unknown",
  };
}
