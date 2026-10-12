import world from '../../../../../content/world.json';
import crafting from '../../../../../content/crafting.json';
import { baselineLoadout, type RateLoadout } from './compendium';
import type { BaitDefinition, BaitLoadout, BaitStack, GameConfig, Player, QualityDefinition, RodDefinition } from '@fishing-game/generated/types';

type EquippedRateState = {
  ready: boolean;
  player: Pick<Player, 'equippedRodId'> | null;
  rods: Pick<RodDefinition, 'rodId' | 'name'>[];
  baits: Pick<BaitDefinition, 'baitId' | 'name' | 'luckBp' | 'sizeRolls'>[];
  baitLoadout: Pick<BaitLoadout, 'baitId'> | null;
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
  name: `${quality.name} ${rod.name} · ${bait.name}`,
  power: rod.power + quality.powerBonus,
  luckBp: rod.luckBp + quality.luckBp + bait.luckBp,
  sizeRolls: bait.sizeRolls,
  rulesVersion: baselineLoadout.rulesVersion,
};

export function equippedLoadout(game?: EquippedRateState): RateLoadout | null {
  if (!game?.ready || !game.player) return null;
  const rod = game.rods.find(row => row.rodId === game.player!.equippedRodId);
  const stats = game.rodStats(game.player.equippedRodId);
  if (!rod || !stats.quality) return null;
  const baitId = game.baitLoadout?.baitId;
  const stocked = game.ownedBaits.some(row => row.baitId === baitId && row.usesLeft > 0n);
  const bait = stocked ? game.baits.find(row => row.baitId === baitId) : undefined;
  return {
    name: `${stats.quality.name} ${rod.name} · ${bait?.name ?? 'no bait'}`,
    power: stats.power,
    luckBp: stats.luckBp + (bait?.luckBp ?? 0),
    sizeRolls: bait?.sizeRolls ?? 1,
    rulesVersion: game.config?.version ?? baselineLoadout.rulesVersion,
  };
}
