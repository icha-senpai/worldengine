import catalog from '../../../../../content/species.json';
import world from '../../../../../content/world.json';
import sizeRules from '../../../../../content/size-rules.json';

export type CatalogFish = typeof catalog.species[number];
const million = 1_000_000n;

/** Baseline at-least-one-per-cast chance, including the Twig's bonus pull. */
export function castChance(fish: CatalogFish, rarity?: string) {
  const tickets = rarity ? fish.ranks.find(rank => rank.rarity === rarity)!.encounterWeight : fish.encounterWeight;
  const biome = world.biomes.find(row => row.biomeId === fish.biomeId)!;
  const poolTickets = catalog.species.filter(row => row.biomeId === fish.biomeId).reduce((sum, row) => sum + row.encounterWeight, 0);
  const p = tickets / poolTickets * biome.fishWeight / (biome.fishWeight + biome.junkWeight + biome.treasureWeight);
  const q = world.rods[0].power / 200;
  return (1 + q) * p - q * p * p;
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
export function rankSpreads(fish: CatalogFish) {
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
      sharePercent: rank.encounterWeight / fish.encounterWeight * 100,
      castChance: castChance(fish, rank.rarity),
    };
  });
}
