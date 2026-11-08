import assert from 'node:assert/strict';
import { test } from 'node:test';
import { equippedLoadout } from '../apps/web/src/lib/game/compendium-loadouts.ts';

test('equipped estimates stop when equipment details become unavailable and recover reactively', () => {
  let statsCalls = 0;
  const game = {
    ready: true, player: { playerId: 9n, equippedRodId: 2 },
    rods: [{ rodId: 2, name: 'River Rod' }], baits: [], baitLoadout: null, ownedBaits: [],
    config: { version: 17 },
    rodStats: () => { statsCalls++; return { power: 10, luckBp: 100, reinforcementLevel: 3, quality: { name: 'Common' } }; },
  };
  const available = equippedLoadout(game);
  assert.equal(available.name, 'Common River Rod · no bait');
  assert.equal(available.reinforcementLevel, 3);
  assert.equal(statsCalls, 1);
  game.equipmentInformationUnavailable = true;
  assert.equal(equippedLoadout(game), null);
  assert.equal(statsCalls, 1, 'unavailable equipment must not consult legacy rod stats');
  game.equipmentInformationUnavailable = false;
  assert.deepEqual(equippedLoadout(game), available);
  assert.equal(statsCalls, 2);
});
