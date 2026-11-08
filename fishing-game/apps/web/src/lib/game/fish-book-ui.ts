import catalog from '../../../../../content/species.json';

export const fishRanks = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'] as const;
const speciesKeys = new Map(catalog.species.map(fish => [fish.speciesId, fish.key]));

export function matchesBookFilters(
  fish: { speciesId: number; name: string; biomeId: number; allowedRarities: readonly string[] },
  query: string, biome: number, rarity: string
): boolean {
  const search = query.trim().toLowerCase();
  const key = speciesKeys.get(fish.speciesId) ?? '';
  return (!biome || fish.biomeId === biome) && (!rarity || fish.allowedRarities.includes(rarity))
    && (fish.name.toLowerCase().includes(search) || key.includes(search.replaceAll(' ', '-')));
}

export function ownedDiscoveries<T extends { playerId: bigint; count: bigint }>(rows: readonly T[], playerId: bigint | null | undefined): T[] {
  return playerId == null ? [] : rows.filter(row => row.playerId === playerId && row.count > 0n);
}

export function caughtAtRank(row: { rankCounts?: readonly bigint[] } | undefined, rank: string): bigint {
  const index = fishRanks.findIndex(value => value === rank);
  return index < 0 ? 0n : row?.rankCounts?.[index] ?? 0n;
}
