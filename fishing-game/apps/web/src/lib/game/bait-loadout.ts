import type { BaitDefinition, BaitLoadout, BaitStack, GameConfig } from '@fishing-game/generated/types';
export type BaitRateDefinition = Pick<BaitDefinition, 'baitId' | 'name' | 'luckBp' | 'sizeRolls'> & Partial<Pick<BaitDefinition, 'xpBonusBp' | 'resourceItem' | 'resourceQuantity'>>;
export type BaitState = {
  baits: BaitRateDefinition[];
  baitLoadout: (Pick<BaitLoadout, 'baitId'> & Partial<Pick<BaitLoadout, 'secondaryBaitId'>>) | null;
  ownedBaits: Pick<BaitStack, 'baitId' | 'usesLeft'>[];
  config: Pick<GameConfig, 'version'> | null;
};
/** Reserve shared stock in slot order, matching the authoritative cast. */
export function equippedBaits(game?: BaitState) {
  if (!game) return [];
  const remaining = new Map(game.ownedBaits.map(row => [row.baitId, row.usesLeft]));
  const ids = [game.baitLoadout?.baitId ?? 0, (game.config?.version ?? 0) >= 14 ? game.baitLoadout?.secondaryBaitId ?? 0 : 0];
  return ids.flatMap((id, index) => {
    const bait = game.baits.find(row => row.baitId === id);
    const stock = remaining.get(id) ?? 0n;
    if (!bait || stock === 0n) return [];
    remaining.set(id, stock - 1n);
    return [{ slot: index + 1, bait }];
  });
}
export function stackedBaitBonuses(baits: readonly Pick<BaitRateDefinition, 'luckBp' | 'sizeRolls' | 'xpBonusBp'>[]) {
  return {
    luckBp: baits.reduce((total, bait) => total + bait.luckBp, 0),
    xpBonusBp: baits.reduce((total, bait) => total + (bait.xpBonusBp ?? 0), 0),
    sizeRolls: Math.max(1, baits.reduce((total, bait) => total + (bait.luckBp > 0 ? bait.sizeRolls : 0), 0)),
  };
}

export function stackedMaterials(baits: readonly BaitRateDefinition[]) {
  const resources = new Map<string, bigint>();
  for (const bait of baits) {
    if (!bait.resourceItem) continue;
    const quantity = bait.resourceQuantity && bait.resourceQuantity > 0n ? bait.resourceQuantity : 1n;
    resources.set(bait.resourceItem, (resources.get(bait.resourceItem) ?? 0n) + quantity);
  }
  return [...resources].map(([item, quantity]) => `+${quantity} ${item.replaceAll('_', ' ')}`).join(' · ');
}
