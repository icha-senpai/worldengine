import type { ItemStack } from '@fishing-game/generated/types';
import economy from '../../../../../content/tideforge-economy.json';
import raidPolicy from '../../../../../content/raids.json';
import { itemSlot } from './raid-items';
import { raidArtwork } from './raid-art';

export type MaterialGroup = 'supplies' | 'basic' | 'graded' | 'unknown';
export type MaterialBalance = { id: string; label: string; group: MaterialGroup; quantity: bigint; image?: string };
type Definition = Omit<MaterialBalance, 'quantity'> & { item?: string; slot?: number };

const definitions: Definition[] = [
  { id: 'rusted_tin', item: 'rusted_tin', label: 'Rusted tin', group: 'supplies', image: '/items/rusted-tin.png' },
  { id: 'scrap', item: 'scrap', label: 'Scrap', group: 'supplies', image: '/items/scrap.png' },
  { id: 'raid_supplies', item: 'raid_supplies', label: 'Expedition Chum', group: 'supplies', image: raidArtwork('supply') },
  { id: 'basic_fillets', slot: economy.processing.filletsSlot, label: 'Basic fillets', group: 'basic', image: raidArtwork('fillets', 0) },
  { id: 'basic_scales', slot: economy.processing.scalesSlot, label: 'Basic scales', group: 'basic', image: raidArtwork('scales', 0) },
  ...raidPolicy.materials.flatMap(material => raidPolicy.grades.map(grade => ({
    id: `${material.key}_${grade.name}`,
    slot: material.slotBase + grade.grade,
    label: `${grade.name}-grade ${material.key}`,
    group: 'graded' as const,
    image: raidArtwork(material.key, grade.grade),
  }))),
];

export function materialBalances(items: readonly Pick<ItemStack, 'key' | 'item' | 'quantity'>[], showEmpty = false): MaterialBalance[] {
  const quantities = new Map<string, bigint>();
  const unknown: MaterialBalance[] = [];
  for (const item of items) {
    const definition = definitions.find(row => row.item === item.item && row.item !== undefined)
      ?? definitions.find(row => row.slot !== undefined && row.slot === itemSlot(item.key));
    if (definition) quantities.set(definition.id, (quantities.get(definition.id) ?? 0n) + item.quantity);
    else if (item.quantity > 0n) unknown.push({ id: `unknown-${item.key}`, label: 'Unknown item', group: 'unknown', quantity: item.quantity });
  }
  return [
    ...definitions.map(({ id, label, group, image }) => ({ id, label, group, image, quantity: quantities.get(id) ?? 0n }))
      .filter(row => showEmpty || row.quantity > 0n),
    ...unknown,
  ];
}
