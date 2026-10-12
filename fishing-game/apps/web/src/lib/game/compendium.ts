import catalog from '../../../../../content/species.json';
import world from '../../../../../content/world.json';
import sizeRules from '../../../../../content/size-rules.json';
import gameRules from '../../../../../content/game-rules.json';

export type CatalogFish = typeof catalog.species[number];
export type CollectionFocus = { biomeIds: readonly number[]; rankMasks: Readonly<Record<number, number>> };
export type RateLoadout = { name: string; power: number; luckBp: number; sizeRolls: number; rulesVersion: number; focus?: CollectionFocus };
export const baselineLoadout: RateLoadout = {
  name: 'Common Twig Rod · no bait', power: world.rods[0].power, luckBp: 0, sizeRolls: 1, rulesVersion: gameRules.version,
};
const million = 1_000_000n;
const ranks = sizeRules.tiers.map(row => row.rarity);
const pools = new Map<string, Map<number, Map<string, number>>>();

/** Match rods::luck_weights, including the Sock's exceptional luck score. */
function adjustedPool(biomeId: number, luckBp: number) {
  const key = `${biomeId}:${luckBp}`;
  const cached = pools.get(key);
  if (cached) return cached;
  const entries = catalog.species.filter(row => row.biomeId === biomeId).flatMap(fish => fish.ranks.map(rank => ({
    speciesId: fish.speciesId, rarity: rank.rarity, weight: BigInt(rank.encounterWeight),
    score: BigInt(fish.key === 'nidalees-lost-sock' ? 9 : ranks.indexOf(rank.rarity)),
  })));
  const total = entries.reduce((sum, row) => sum + row.weight, 0n);
  const moment = entries.reduce((sum, row) => sum + row.weight * row.score, 0n);
  const distance = 9n * total - moment;
  const denominator = Number(total * 10000n * distance);
  const pool = new Map<number, Map<string, number>>();
  for (const entry of entries) {
    const factor = 10000n * distance + BigInt(luckBp) * (entry.score * total - moment);
    const bands = pool.get(entry.speciesId) ?? new Map<string, number>();
    bands.set(entry.rarity, Number(entry.weight * factor) / denominator);
    pool.set(entry.speciesId, bands);
  }
  pools.set(key, pool);
  return pool;
}

function rankMask(fish: CatalogFish, loadout: RateLoadout) {
  return loadout.focus?.rankMasks[fish.speciesId] ?? 0;
}

function focusActive(fish: CatalogFish, loadout: RateLoadout) {
  return loadout.rulesVersion >= 13 && fish.countsForOrdinaryCollectionCompletion && !!loadout.focus?.biomeIds.includes(fish.biomeId);
}

/** Keep species/category mass fixed; redistribute only duplicates of this fish. */
function pullBands(fish: CatalogFish, loadout: RateLoadout, mask = rankMask(fish, loadout)) {
  const base = adjustedPool(fish.biomeId, loadout.luckBp).get(fish.speciesId)!;
  const biome = world.biomes.find(row => row.biomeId === fish.biomeId)!;
  const fishChance = biome.fishWeight / (biome.fishWeight + biome.junkWeight + biome.treasureWeight);
  let duplicates = 0, missing = 0;
  for (const [rank, chance] of base) {
    if (mask & (1 << ranks.indexOf(rank))) duplicates += chance;
    else missing += chance;
  }
  const active = focusActive(fish, loadout) && missing > 0;
  return new Map([...base].map(([rank, chance]) => [rank, chance * fishChance * (!active ? 1 : mask & (1 << ranks.indexOf(rank)) ? 0.8 : 1 + 0.2 * duplicates / missing)]));
}

function pullChance(fish: CatalogFish, rarity: string | undefined, loadout: RateLoadout) {
  const bands = pullBands(fish, loadout);
  return rarity ? bands.get(rarity) ?? 0 : [...bands.values()].reduce((sum, value) => sum + value, 0);
}

/** The first pull updates collection before the second. Exclude first hits. */
function outcomeChance(fish: CatalogFish, rarity: string, sizeChance: number, loadout: RateLoadout) {
  if (!focusActive(fish, loadout)) return chancePerCast(pullChance(fish, rarity, loadout) * sizeChance, loadout);
  const mask = rankMask(fish, loadout);
  const first = pullBands(fish, loadout, mask);
  const hit = (first.get(rarity) ?? 0) * sizeChance;
  const speciesChance = [...first.values()].reduce((sum, value) => sum + value, 0);
  let secondAfterMiss = (1 - speciesChance) * hit;
  for (const [rank, chance] of first) {
    const next = pullBands(fish, loadout, mask | (1 << ranks.indexOf(rank)));
    secondAfterMiss += chance * (rank === rarity ? 1 - sizeChance : 1) * (next.get(rarity) ?? 0) * sizeChance;
  }
  return hit + loadout.power / 200 * secondAfterMiss;
}

function chancePerCast(p: number, loadout: RateLoadout) {
  const q = loadout.power / 200;
  return (1 + q) * p - q * p * p;
}

/** At least one matching catch per cast, including the rod's bonus pull. */
export function castChance(fish: CatalogFish, rarity?: string, loadout = baselineLoadout) {
  return rarity ? outcomeChance(fish, rarity, 1, loadout) : chancePerCast(pullChance(fish, undefined, loadout), loadout);
}

/** Both exact maxima of the selected rank on one retained specimen. */
export function rankMaximumOdds(fish: CatalogFish, rarity: string, loadout = baselineLoadout) {
  const band = rankSpreads(fish).find(row => row.rarity === rarity);
  if (!band) return null;
  const weights = weightRangeAtLength(fish, rarity, band.maxLengthMm);
  // Length is uniform in integer millimetres, then weight is uniform in grams
  // at that length. Include the natural outcome when the special roll misses.
  const naturalSizeChance = 1 / ((band.maxLengthMm - band.minLengthMm + 1) * (weights.max - weights.min + 1));
  const endpointEnabled = loadout.rulesVersion >= 11 && (rarity === 'UUR' || loadout.rulesVersion >= 12 && loadout.sizeRolls > 1);
  const endpointChance = endpointEnabled ? 1 / gameRules.perfectRollOneIn : 0;
  const attemptChance = endpointChance + (1 - endpointChance) * naturalSizeChance;
  // A perfect has the greatest weight and length, so it always survives the
  // heaviest-then-longest selection across Trophy Chum's size attempts.
  const sizeChance = -Math.expm1(loadout.sizeRolls * Math.log1p(-attemptChance));
  return { maxLengthMm: band.maxLengthMm, maxWeightG: weights.max, naturalSizeChance, attemptChance, sizeChance,
    chancePerCast: outcomeChance(fish, rarity, sizeChance, loadout) };
}

/** Official perfect-catch credit still requires the species' UUR maxima. */
export function perfectCatchOdds(fish: CatalogFish, loadout = baselineLoadout) {
  if (fish.key === 'nidalees-lost-sock') return null;
  return rankMaximumOdds(fish, 'UUR', loadout);
}

export const formatOdds = (chance: number) => `1 in ${(1 / chance).toLocaleString(undefined, { maximumFractionDigits: 1 })}`;
export const formatPercent = (percent: number) => `${percent.toLocaleString(undefined, { maximumFractionDigits: percent < 0.01 ? 6 : percent < 1 ? 4 : 2 })}%`;

function ceilRatio(typical: number, factor: number) {
  return Number((BigInt(typical) * BigInt(factor) + million - 1n) / million);
}

function weightAtLength(fish: CatalogFish, length: number, condition: number) {
  // Match the authoritative cubic model and nearest-gram rounding exactly.
  const denominator = BigInt(fish.typicalLengthMm) ** 3n * million;
  const numerator = BigInt(fish.typicalWeightG) * BigInt(length) ** 3n * BigInt(condition);
  const weight = Number((numerator + denominator / 2n) / denominator);
  return Math.min(fish.typicalWeightG * 8, Math.max(Math.max(1, Math.floor(fish.typicalWeightG / 8)), weight));
}

export function weightRangeAtLength(fish: CatalogFish, rarity: string, length: number) {
  const rule = sizeRules.tiers.find(row => row.rarity === rarity)!;
  const min = Math.max(weightAtLength(fish, length, 880_000), ceilRatio(fish.typicalWeightG, rule.minimumWeightMillionths), Math.max(1, Math.floor(fish.typicalWeightG / 8)));
  const max = Math.min(fish.typicalWeightG * 8, Math.max(min, weightAtLength(fish, length, 1_120_000)));
  return { min, max };
}

/** Catchable ranges, rather than the wider configured safety caps. */
export function rankSpreads(fish: CatalogFish, loadout = baselineLoadout) {
  const speciesChance = pullChance(fish, undefined, loadout);
  return fish.ranks.map(rank => {
    const index = sizeRules.tiers.findIndex(row => row.rarity === rank.rarity);
    const rule = sizeRules.tiers[index];
    const next = sizeRules.tiers[index + 1];
    const minLengthMm = Math.max(Math.floor(fish.typicalLengthMm * 55 / 100), ceilRatio(fish.typicalLengthMm, rule.minimumLengthMillionths));
    const maxLengthMm = Math.min(Math.floor(fish.typicalLengthMm * 19 / 10), next ? ceilRatio(fish.typicalLengthMm, next.minimumLengthMillionths) - 1 : Infinity);
    return {
      rarity: rank.rarity, minLengthMm, maxLengthMm,
      minWeightG: weightRangeAtLength(fish, rank.rarity, minLengthMm).min,
      maxWeightG: weightRangeAtLength(fish, rank.rarity, maxLengthMm).max,
      minimumLengthFactor: rule.minimumLengthMillionths / 1_000_000,
      minimumWeightFactor: rule.minimumWeightMillionths / 1_000_000,
      sharePercent: pullChance(fish, rank.rarity, loadout) / speciesChance * 100,
      castChance: castChance(fish, rank.rarity, loadout),
    };
  });
}
