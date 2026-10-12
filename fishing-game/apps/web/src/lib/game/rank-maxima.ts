import type { RankMaximum, SpeciesDefinition } from '@fishing-game/generated/types';

/** Counters count distinct species/rank pairs, including the one-rank bonus species. */
export function rankMaximaSummary(rows: readonly RankMaximum[], species: readonly SpeciesDefinition[], playerId: bigint | null | undefined) {
  const marks = new Set(rows.filter(row => row.playerId === playerId).map(row => `${row.speciesId}:${row.rarity}`));
  const valid = species.flatMap(fish => fish.allowedRarities.map(rank => `${fish.speciesId}:${rank}`));
  return {
    marks,
    count: valid.filter(key => marks.has(key)).length,
    fullyMaxedSpecies: species.filter(fish => fish.allowedRarities.length > 0 && fish.allowedRarities.every(rank => marks.has(`${fish.speciesId}:${rank}`))).length,
  };
}
