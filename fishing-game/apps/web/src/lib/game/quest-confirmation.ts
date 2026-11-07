import type { QuestDefinition } from '@fishing-game/generated/types';
import type { Game } from './game.svelte';

export type PendingQuestClaim = {
  definition: QuestDefinition;
  baitId: number;
  catchId: bigint | undefined;
  week: bigint;
  cycle: bigint;
  playerId: bigint;
  identity: string;
};

export function questConfirmationProblem(
  game: Pick<Game, 'ready' | 'player' | 'authenticatedIdentity' | 'questDefinitions' | 'quests' | 'inventory'>,
  pending: PendingQuestClaim
): string {
  const changed = 'This quest changed. Review it again.';
  const { definition } = pending;
  if (!game.ready || !game.authenticatedIdentity || game.authenticatedIdentity !== pending.identity
    || game.player?.playerId !== pending.playerId
    || game.questDefinitions.find(row => row.questId === definition.questId) !== definition) return changed;
  const status = game.quests.find(row => row.questId === definition.questId);
  if (!status || !status.unlocked || status.claimed
    || definition.weekly && status.week !== pending.week
    || definition.goal.startsWith('repeat_') && status.cycle !== pending.cycle
    || (definition.baitChoices.length ? !definition.baitChoices.includes(pending.baitId) : pending.baitId !== 0)) return changed;
  if (definition.goal !== 'delivery') return status.current >= definition.target ? '' : changed;
  const fish = game.inventory.find(row => row.catchId === pending.catchId);
  return fish && fish.playerId === pending.playerId && !fish.favorite
    && ['F', 'D', 'C'].includes(fish.rarity) && status.deliverySpeciesIds.includes(fish.speciesId) ? '' : changed;
}
