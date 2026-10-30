import { equippedBaits, stackedBaitBonuses } from './bait-loadout';
import catalog from '../../../../../content/species.json';
import world from '../../../../../content/world.json';
import crafting from '../../../../../content/crafting.json';
import raidRules from '../../../../../content/raids.json';
import { baselineLoadout, type CollectionFocus, type RateLoadout } from './compendium';
import type { BaitDefinition, BaitLoadout, BaitStack, GameConfig, Player, QualityDefinition, RodDefinition } from '@fishing-game/generated/types';

type EquippedRateState = {
  collection?: { speciesId: number; count: bigint; rankCounts: bigint[] }[];
  focusPreferences?: { biomeId: number; enabled: boolean }[];
  rodReinforcements?: { rodId: number; level: number }[];
  ready: boolean;
  player: Pick<Player, 'equippedRodId'> | null;
  rods: Pick<RodDefinition, 'rodId' | 'name'>[];
  baits: Pick<BaitDefinition, 'baitId' | 'name' | 'luckBp' | 'sizeRolls'>[];
  baitLoadout: (Pick<BaitLoadout, 'baitId'> & Partial<Pick<BaitLoadout, 'secondaryBaitId'>>) | null;
  ownedBaits: Pick<BaitStack, 'baitId' | 'usesLeft'>[];
  config: Pick<GameConfig, 'version'> | null;
  rodStats: (rodId: number) => { power: number; luckBp: number; quality?: Pick<QualityDefinition, 'name'> };
};

// In the current catalog the strongest rod and quality also have the most luck;
// Trophy Chum has both the most size attempts and the greatest bait luck.
const rod = [...world.rods].sort((a, b) => b.power - a.power || b.luckBp - a.luckBp)[0];
const quality = [...crafting.qualities].sort((a, b) => b.powerBonus - a.powerBonus || b.luckBp - a.luckBp)[0];
const bait = [...crafting.baits].sort((a, b) => b.sizeRolls - a.sizeRolls || b.luckBp - a.luckBp)[0];
export const bestPerfectLoadout: RateLoadout = {
  name: `${quality.name} ${rod.name} · ${bait.name} ×2`,
  power: rod.power + quality.powerBonus,
  luckBp: rod.luckBp + quality.luckBp + bait.luckBp * 2,
  sizeRolls: bait.sizeRolls * 2,
  rulesVersion: baselineLoadout.rulesVersion,
  reinforcementLevel: raidRules.grades.length,
};


export function collectionFocusContext(game?: EquippedRateState): CollectionFocus | undefined {
  if (!game?.ready || !game.player || (game.config?.version ?? 0) < 13) return undefined;
  const discovered = new Set(game.collection?.filter(row => row.count > 0n).map(row => row.speciesId));
  const biomeIds = game.focusPreferences?.filter(row => row.enabled && catalog.species.filter(fish => fish.biomeId === row.biomeId && fish.countsForOrdinaryCollectionCompletion).every(fish => discovered.has(fish.speciesId))).map(row => row.biomeId) ?? [];
  const rankMasks = Object.fromEntries(game.collection?.map(row => [row.speciesId, row.rankCounts.reduce((mask, count, rank) => count > 0n ? mask | (1 << rank) : mask, 0)]) ?? []);
  return { biomeIds, rankMasks };
}

export function equippedLoadout(game?: EquippedRateState): RateLoadout | null {
  if (!game?.ready || !game.player) return null;
  const rod = game.rods.find(row => row.rodId === game.player!.equippedRodId);
  const stats = game.rodStats(game.player.equippedRodId);
  if (!rod || !stats.quality) return null;
  const selected = equippedBaits(game).map(row => row.bait);
  const bonuses = stackedBaitBonuses(selected);
  return {
    name: `${stats.quality.name} ${rod.name} · ${selected.map(bait => bait.name).join(' + ') || 'no bait'}`,
    power: stats.power,
    luckBp: stats.luckBp + bonuses.luckBp,
    sizeRolls: bonuses.sizeRolls,
    rulesVersion: game.config?.version ?? baselineLoadout.rulesVersion,
    reinforcementLevel: game.rodReinforcements?.find(row => row.rodId === rod.rodId)?.level ?? 0,
    focus: collectionFocusContext(game),
  };
}
