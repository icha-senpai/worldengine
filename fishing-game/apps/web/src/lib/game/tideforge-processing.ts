import catalog from '../../../../../content/species.json';
import { rankSpreads } from './compendium';
import type { OwnedSpecimen, SpeciesDefinition } from '@fishing-game/generated/types';

export type ProcessingProtection = 'ordinary' | 'trophy' | 'favorite' | 'bonus' | 'unknown';
type Fish = Pick<OwnedSpecimen, 'speciesId' | 'rarity' | 'lengthMm' | 'weightG' | 'favorite'>;
type Species = Pick<SpeciesDefinition, 'speciesId' | 'countsForOrdinaryCollectionCompletion'
  | 'typicalLengthMm' | 'typicalWeightG' | 'minLengthMm' | 'maxLengthMm'
  | 'minWeightG' | 'maxWeightG' | 'allowedRarities'>;
const bands = new Map<number, ReturnType<typeof rankSpreads>>();

/** Conservative selection help only; the server revalidates every specimen. */
export function specimenProcessingProtection(fish: Fish, species?: Species): ProcessingProtection {
  if (fish.favorite) return 'favorite';
  if (!species || species.speciesId !== fish.speciesId) return 'unknown';
  if (!species.countsForOrdinaryCollectionCompletion) return 'bonus';
  const model = catalog.species.find(row => row.speciesId === species.speciesId);
  if (!model || model.typicalLengthMm !== species.typicalLengthMm
    || BigInt(model.typicalWeightG) !== species.typicalWeightG
    || species.minLengthMm !== Math.floor(model.typicalLengthMm * 55 / 100)
    || species.maxLengthMm !== Math.floor(model.typicalLengthMm * 19 / 10)
    || species.minWeightG !== (BigInt(model.typicalWeightG) / 8n || 1n)
    || species.maxWeightG !== BigInt(model.typicalWeightG) * 8n
    || species.allowedRarities.join(',') !== model.allowedRarities.join(',')) return 'unknown';
  let ranges = bands.get(model.speciesId);
  if (!ranges) {
    ranges = rankSpreads(model);
    bands.set(model.speciesId, ranges);
  }
  const rank = ranges.find(row => row.rarity === fish.rarity);
  const perfect = ranges.find(row => row.rarity === 'UUR');
  if (!rank || !perfect) return 'unknown';
  const matches = (range: typeof rank) =>
    fish.lengthMm === range.maxLengthMm && fish.weightG === BigInt(range.maxWeightG);
  return matches(rank) || matches(perfect) ? 'trophy' : 'ordinary';
}
