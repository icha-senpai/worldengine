import catalog from '../../../../../content/species.json';
import world from '../../../../../content/world.json';
import sizeRules from '../../../../../content/size-rules.json';
import gameRules from '../../../../../content/game-rules.json';

export type CatalogFish = typeof catalog.species[number];
export type RateLoadout = { name: string; power: number; luckBp: number; sizeRolls: number; rulesVersion: number };
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

function pullChance(fish: CatalogFish, rarity: string | undefined, loadout: RateLoadout) {
  const bands = adjustedPool(fish.biomeId, loadout.luckBp).get(fish.speciesId)!;
  const chance = rarity ? bands.get(rarity) ?? 0 : [...bands.values()].reduce((sum, value) => sum + value, 0);
  const biome = world.biomes.find(row => row.biomeId === fish.biomeId)!;
  return chance * biome.fishWeight / (biome.fishWeight + biome.junkWeight + biome.treasureWeight);
}

function chancePerCast(p: number, loadout: RateLoadout) {
  const q = loadout.power / 200;
  return (1 + q) * p - q * p * p;
}

/** At least one matching catch per cast, including one independent bonus pull. */
export function castChance(fish: CatalogFish, rarity?: string, loadout = baselineLoadout) {
  return chancePerCast(pullChance(fish, rarity, loadout), loadout);
}

/** Perfection requires both exact UUR maxima on one retained specimen. */
export function perfectCatchOdds(fish: CatalogFish, loadout = baselineLoadout) {
  if (fish.key === 'nidalees-lost-sock' || !fish.allowedRarities.includes('UUR')) return null;
  const band = rankSpreads(fish).find(row => row.rarity === 'UUR')!;
  const weights = weightRangeAtLength(fish, 'UUR', band.maxLengthMm);
  // Length is uniform in integer millimetres, then weight is uniform in grams
  // at that length. Include the natural outcome when the special roll misses.
  const naturalSizeChance = 1 / ((band.maxLengthMm - band.minLengthMm + 1) * (weights.max - weights.min + 1));
  const endpointChance = loadout.rulesVersion >= 11 ? 1 / gameRules.perfectRollOneIn : 0;
  const attemptChance = endpointChance + (1 - endpointChance) * naturalSizeChance;
  // A perfect has the greatest weight and length, so it always survives the
  // heaviest-then-longest selection across Trophy Chum's size attempts.
  const sizeChance = -Math.expm1(loadout.sizeRolls * Math.log1p(-attemptChance));
  return { maxLengthMm: band.maxLengthMm, maxWeightG: weights.max, naturalSizeChance, attemptChance, sizeChance,
    chancePerCast: chancePerCast(pullChance(fish, 'UUR', loadout) * sizeChance, loadout) };
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
