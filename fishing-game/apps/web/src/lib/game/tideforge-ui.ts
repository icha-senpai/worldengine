import catalog from '../../../../../content/tideforge-effects.json';
import type { GearInstance, TideforgeCodex } from '@fishing-game/generated/types';
import type { Game } from './game.svelte';
import { masteryLevel, rolledAffixValue, unlockedAffixSlots, type AffixRoll } from './tideforge';

export const affixes = catalog.affixes;
export const signatures = catalog.signatures;

export function orderGearForDisplay(game: Pick<Game, 'gearIsEquipped'>, items: readonly GearInstance[]): GearInstance[] {
  const priority = (item: GearInstance) => game.gearIsEquipped(item.instanceId) ? 0 : item.favorite ? 1 : 2;
  return [...items].sort((a, b) => priority(a) - priority(b));
}

export function gearName(game: Game, item: GearInstance): string {
  return item.kind === 'rod' ? game.rods.find(row => row.rodId === item.definitionId)?.name ?? 'Rod'
    : item.kind === 'boat' || item.kind === 'net'
      ? game.haulEquipment.find(row => row.equipmentId === item.definitionId)?.name ?? 'Gear' : 'Gear';
}

export function gearSprite(game: Game, item: GearInstance): string | null {
  if (item.kind === 'rod') return game.rodBonuses?.find(row => row.rodId === item.definitionId)?.spriteAsset || null;
  if (item.kind === 'boat' || item.kind === 'net') {
    return game.haulEquipment?.find(row => row.equipmentId === item.definitionId && row.kind === item.kind)?.spriteAsset || null;
  }
  return null;
}

export function gearDefaultLabel(game: Game, item: GearInstance): string {
  const matching = game.gearInstances.filter(row => row.kind === item.kind && row.definitionId === item.definitionId)
    .map(row => row.instanceId).sort((a, b) => a < b ? -1 : a > b ? 1 : 0);
  const index = matching.findIndex(id => id === item.instanceId);
  return matching.length > 1 && index >= 0 ? `${gearName(game, item)} · Item ${index + 1}` : gearName(game, item);
}

export function gearNickname(game: Game, item: GearInstance): string {
  if ('player' in game && (!game.player || item.playerId !== game.player.playerId)) return '';
  return game.gearInstanceNames?.find(row => row.instanceId === item.instanceId && row.playerId === item.playerId)?.name ?? '';
}

export function gearTitle(game: Game, item: GearInstance): string {
  return gearNickname(game, item) || gearDefaultLabel(game, item);
}

export function gearLabel(game: Game, item: GearInstance): string {
  const nickname = gearNickname(game, item);
  return nickname ? `${nickname} · ${gearDefaultLabel(game, item)}` : gearDefaultLabel(game, item);
}

export function catchLabel(game: Game, catchId: bigint): string {
  const ids = game.inventory.map(row => row.catchId).sort((a, b) => a < b ? -1 : a > b ? 1 : 0);
  const index = ids.findIndex(id => id === catchId);
  return index < 0 ? 'Selected fish' : `Catch ${index + 1}`;
}

export function affixName(id: string): string {
  return affixes.find(row => row.id === id)?.name ?? 'Unknown affix';
}

export function signatureName(id: string): string {
  return signatures.find(row => row.id === id)?.name ?? 'Unknown signature';
}

const friendlyStats: Record<string, string> = {
  luckBp: 'Luck', playerXpBp: 'Fishing XP', masteryXpBp: 'Mastery XP', saleBp: 'Fish sale value',
  salvageBp: 'Salvage yield', treasureBp: 'Treasure value', filletBp: 'Fillet yield', scaleBp: 'Scale yield',
  baitPreserveBp: 'Bait preservation', powerCenti: 'Power',
};
const effectDescriptions: Record<string, string> = {
  luckBp: 'Improves Luck.', playerXpBp: 'Improves fishing XP.', masteryXpBp: 'Improves mastery XP.',
  saleBp: 'Improves fish sale value.', salvageBp: 'Improves salvage yield.', treasureBp: 'Improves treasure value.',
  filletBp: 'Improves fillet yield.', scaleBp: 'Improves scale yield.', baitPreserveBp: 'Helps preserve bait.',
  powerCenti: 'Improves Power.', baitAttemptBp: 'Helps with bait-assisted attempts.',
  weightFinishBp: 'Helps improve weight measurements.', lengthFinishBp: 'Helps improve length measurements.',
  endpointPpm: 'Helps with maximum measurements.', rankFocusBp: 'Helps Collection Focus find missing ranks.',
  extraPullBp: 'Can add an extra catch.', fullAttemptCenti: 'Helps with fishing attempts.',
  maximaFocusBp: 'Helps focus on rank maxima.', perfectFocusBp: 'Helps focus on perfect catches.',
  durationBp: 'Can shorten haul duration.', duplicateRerollBp: 'Helps with duplicate ranks.',
  haulAttemptCenti: 'Helps with extra measurement attempts.',
};
const signatureDescriptions: Record<string, string> = {
  pairedFinishBp: 'Helps finish both measurements.',
  duplicateRerolls: 'Provides extra help with duplicate ranks.',
  dailyBaseMasteryBp: 'Offers a daily mastery bonus.',
  firstJunkScrap: 'First junk in an activity adds scrap.', firstJunkTin: 'First junk in an activity adds rusted tin.',
  firstJunkFillets: 'First junk in a normal haul adds basic fillets.', firstJunkScales: 'First junk in a normal haul adds basic scales.',
  firstTreasureDoubleBp: 'Can double the first treasure coin payout in an activity.',
  firstFishAttemptCenti: 'Offers an extra net specimen opportunity.',
  outsidePrimaryLuckBp: 'Improves Luck away from the item’s primary water.',
  dailyNewRankPlayerXpBp: 'Offers extra XP for daily new-rank progress.',
  dailyOrdinaryFish: 'Offers a daily extra ordinary-fish opportunity.',
  firstFishFillets: 'First ordinary fish in an activity adds basic fillets.', firstFishScales: 'First ordinary fish in an activity adds basic scales.',
};

export function affixDescription(id: string): string {
  const row = affixes.find(item => item.id === id);
  if (!row) return 'An unknown affix.';
  return Object.keys(row.stats).map(key => effectDescriptions[key] ?? 'Adds a gear effect.').join(' ');
}

export function affixValues(id: string, tier: number, finish: number): string {
  const row = affixes.find(item => item.id === id);
  if (!row) return '';
  return Object.entries(row.stats).flatMap(([key, levels]) => {
    if (!friendlyStats[key] || !Array.isArray(levels)) return [];
    const value = rolledAffixValue(levels[tier - 1], finish) / 100;
    return [`${friendlyStats[key]} +${value}${key === 'powerCenti' ? '' : '%'}`];
  }).join(' · ');
}

export function signatureDescription(id: string): string {
  const row = signatures.find(item => item.id === id) as { stats?: Record<string, unknown>; parameters?: Record<string, unknown> } | undefined;
  if (!row) return 'An unknown signature.';
  return [...Object.keys(row.stats ?? {}), ...Object.keys(row.parameters ?? {})]
    .map(key => signatureDescriptions[key] ?? effectDescriptions[key] ?? 'Adds a gear effect.').join(' ');
}

export function rolls(game: Game, item: GearInstance): AffixRoll[] | null {
  const row = game.tideforgeGear.find(value => value.instanceId === item.instanceId);
  if (!row) return [];
  try {
    const parsed: unknown = JSON.parse(row.affixesJson);
    if (!Array.isArray(parsed) || parsed.length > 4) return null;
    const slots = new Set<number>(), ids = new Set<string>();
    for (const roll of parsed) {
      if (!roll || typeof roll !== 'object' || !Number.isInteger(roll.slot) || roll.slot < 0 || roll.slot > 3
        || typeof roll.id !== 'string' || !Number.isInteger(roll.tier) || roll.tier < 1 || roll.tier > 10
        || !Number.isInteger(roll.finishBp) || roll.finishBp < 0 || roll.finishBp > 10000
        || slots.has(roll.slot) || ids.has(roll.id)) return null;
      slots.add(roll.slot); ids.add(roll.id);
    }
    return parsed as AffixRoll[];
  } catch { return null; }
}

export function familyMastery(game: Game, item: GearInstance) {
  const xp = game.gearFamilies.find(row => row.playerId === item.playerId && row.kind === item.kind && row.familyId === item.familyId)?.masteryXp ?? 0n;
  return { xp, level: masteryLevel(xp), slots: unlockedAffixSlots(xp) };
}

export function canConsume(game: Game, item: GearInstance): boolean {
  return !item.protectedOriginal && !item.favorite && !game.gearIsEquipped(item.instanceId)
    && !game.gearIsBooked(item.instanceId);
}

export function gearProtectionMessage(game: Game, item: GearInstance): string {
  if (item.protectedOriginal) return 'This item is kept safe and cannot be dismantled or used for signature extraction.';
  if (item.favorite) return 'Unfavorite this item before dismantling or extracting its signature.';
  if (game.gearIsEquipped(item.instanceId)) return 'Unequip this item before dismantling or extracting its signature.';
  if (game.gearIsBooked(item.instanceId)) return 'This gear is being used on a fishing trip or raid. Wait until it finishes.';
  return '';
}

export function waterOptions(game: Game, primary: number) {
  return game.biomes.filter(row => row.biomeId !== primary)
    .map(row => ({ value: row.biomeId, label: row.name }));
}

export function shortId(id: bigint): string {
  const text = id.toString();
  return text.length > 10 ? `…${text.slice(-8)}` : text;
}

export function affixSlotState(slot: number, unlocked: number, occupied: boolean) {
  return slot >= unlocked ? occupied ? 'dormant' : 'locked' : occupied ? 'active' : 'empty';
}

export function toggleProcessingSelection(selected: bigint[], id: bigint, eligible: boolean): bigint[] {
  if (selected.includes(id)) return selected.filter(value => value !== id);
  return eligible && selected.length < 50 ? [...selected, id] : selected;
}

export function forgeAffixChoices(kind: string, discoveries: Pick<TideforgeCodex, 'affixId' | 'naturalCount'>[],
  itemRolls: readonly AffixRoll[], action: 'add_affix' | 'change_affix' | 'upgrade_affix' | 'refine_affix', slot: number) {
  const replacedSlot = action === 'change_affix' ? slot : -1;
  const selected = itemRolls.find(row => row.slot === slot);
  const conditionalCount = itemRolls.filter(row => row.slot !== replacedSlot
    && !!affixes.find(definition => definition.id === row.id)?.condition).length;
  return affixes.filter(definition => definition.kinds.includes(kind)
    && discoveries.some(row => row.affixId === definition.id && row.naturalCount > 0n)
    && !itemRolls.some(row => row.id === definition.id && row.slot !== replacedSlot)
    && (action !== 'change_affix' || definition.id !== selected?.id || definition.id === 'secondary_affinity')
    && (!definition.condition || conditionalCount < 2));
}

export function forgeWaterChangeAllowed(action: string, existing: AffixRoll | undefined, affixId: string, water: number) {
  return action !== 'change_affix' || existing?.id !== affixId
    || affixId === 'secondary_affinity' && existing?.secondaryBiomeId !== water;
}
