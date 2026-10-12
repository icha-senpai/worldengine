import type { BaitDefinition } from '@fishing-game/generated/types';

export function baitEffect(bait?: BaitDefinition) {
  if (!bait) return '';
  if (bait.sizeRolls > 1) {
    return `All fish · ${bait.sizeRolls} size rolls, keep the heaviest · +${bait.luckBp / 100}% luck`;
  }
  if (bait.resourceItem)
    return `+${bait.resourceQuantity || 1n} ${bait.resourceItem === 'scrap' ? 'scrap' : 'rusted tin'} alongside each cast`;
  if (bait.xpBonusBp) return `+${bait.xpBonusBp / 100}% fishing XP`;
  return `All fish · +${bait.luckBp / 100}% luck toward higher ranks`;
}
