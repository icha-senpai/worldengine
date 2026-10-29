import type { Game } from './game.svelte.ts';
import type { ShopListing } from '@fishing-game/generated/types';

type TraderGame = Pick<Game, 'player' | 'rods' | 'haulEquipment' | 'haulLoadout' | 'rodStats' | 'haulStats'>;
type TraderListing = Pick<ShopListing, 'kind' | 'targetId'>;

const signed = (n: number) => (n >= 0 ? '+' : '') + n;

export function equipmentComparison(game: TraderGame, row: TraderListing): string {
  if (row.kind === 'rod') {
    const equipped = game.rods.find(rod => rod.rodId === game.player?.equippedRodId);
    if (!equipped) return '';
    const next = game.rodStats(row.targetId);
    const current = game.rodStats(equipped.rodId);
    return `${signed(next.power - current.power)} power · ${signed((next.luckBp - current.luckBp) / 100)}% luck · ${signed((next.xpBonusBp - current.xpBonusBp) / 100)}% XP vs equipped`;
  }
  if (row.kind === 'boat' || row.kind === 'net') {
    if (!game.player || !game.haulLoadout) return '';
    const equippedId = row.kind === 'boat' ? game.haulLoadout.boatId : game.haulLoadout.netId;
    const listed = game.haulEquipment.find(gear => gear.equipmentId === row.targetId && gear.kind === row.kind);
    const baseline = game.haulEquipment.find(gear => gear.equipmentId === equippedId && gear.kind === row.kind);
    if (!listed || !baseline) return '';
    const next = game.haulStats(row.targetId);
    const current = game.haulStats(equippedId);
    return `${signed((next.luckBp - current.luckBp) / 100)}% luck · ${signed((next.xpBonusBp - current.xpBonusBp) / 100)}% XP vs equipped`;
  }
  return '';
}
