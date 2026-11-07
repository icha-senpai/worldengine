import assert from 'node:assert/strict';
import { test } from 'node:test';
import { questConfirmationProblem } from '../apps/web/src/lib/game/quest-confirmation.ts';

function setup(overrides = {}) {
  const definition = { questId: 17, goal: 'repeat_fish', weekly: false, target: 5n, baitChoices: [2, 3], ...overrides };
  const status = { questId: 17, unlocked: true, claimed: false, current: 5n, week: 10n, cycle: 2n, deliverySpeciesIds: [7] };
  const game = { ready: true, authenticatedIdentity: 'owner', player: { playerId: 9n }, questDefinitions: [definition], quests: [status],
    inventory: [{ playerId: 9n, catchId: 99n, favorite: false, rarity: 'F', speciesId: 7 }] };
  const pending = { definition, playerId: 9n, identity: 'owner', baitId: 2, catchId: undefined, week: 10n, cycle: 2n };
  return { game, definition, status, pending };
}

test('quest confirmations preserve valid progress and reject stale status or definition', () => {
  const { game, definition, status, pending } = setup();
  assert.equal(questConfirmationProblem(game, pending), '');
  game.quests = [{ ...status, current: 10n, week: 11n }];
  assert.equal(questConfirmationProblem(game, pending), '');
  for (const row of [{ ...status, claimed: true }, { ...status, unlocked: false }, { ...status, current: 4n }, { ...status, cycle: 3n }]) {
    game.quests = [row];
    assert.notEqual(questConfirmationProblem(game, pending), '');
  }
  game.quests = [];
  assert.notEqual(questConfirmationProblem(game, pending), '');
  game.quests = [status];
  game.questDefinitions = [{ ...definition }];
  assert.notEqual(questConfirmationProblem(game, pending), '');
  game.questDefinitions = [];
  assert.notEqual(questConfirmationProblem(game, pending), '');
});

test('week and cycle guards apply only to their respective quest types', () => {
  const weekly = setup({ weekly: true, goal: 'fish' });
  weekly.game.quests = [{ ...weekly.status, cycle: 9n }];
  assert.equal(questConfirmationProblem(weekly.game, weekly.pending), '');
  weekly.game.quests = [{ ...weekly.status, week: 11n }];
  assert.notEqual(questConfirmationProblem(weekly.game, weekly.pending), '');
  const ordinary = setup({ goal: 'fish' });
  ordinary.game.quests = [{ ...ordinary.status, week: 11n, cycle: 3n }];
  assert.equal(questConfirmationProblem(ordinary.game, ordinary.pending), '');
});

test('account, connection and chosen bait are checked at confirmation', () => {
  const { game, pending } = setup();
  for (const state of [{ ready: false }, { player: null }, { player: { playerId: 10n } }, { authenticatedIdentity: 'other' }, { authenticatedIdentity: '' }]) {
    assert.notEqual(questConfirmationProblem({ ...game, ...state }, pending), '');
  }
  assert.notEqual(questConfirmationProblem(game, { ...pending, baitId: 4 }), '');
});

test('delivery confirmation retains the exact owned eligible fish', () => {
  const { game, pending } = setup({ goal: 'delivery', baitChoices: [] });
  pending.baitId = 0; pending.catchId = 99n;
  assert.equal(questConfirmationProblem(game, pending), '');
  const fish = game.inventory[0];
  for (const inventory of [[], [{ ...fish, playerId: 10n }], [{ ...fish, favorite: true }], [{ ...fish, rarity: 'B' }], [{ ...fish, speciesId: 8 }]]) {
    assert.notEqual(questConfirmationProblem({ ...game, inventory }, pending), '');
  }
  assert.notEqual(questConfirmationProblem(game, { ...pending, catchId: undefined }), '');
});
