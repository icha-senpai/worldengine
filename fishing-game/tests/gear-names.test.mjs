import assert from 'node:assert/strict';
import { test } from 'node:test';
import { gearNameValidationMessage, gearRenameErrorMessage, normalizeGearName } from '../apps/web/src/lib/game/gear-names.ts';
import { gearDefaultLabel, gearLabel, gearName, gearNickname, gearTitle } from '../apps/web/src/lib/game/tideforge-ui.ts';

test('normalization and validation match the server Unicode and byte boundaries', () => {
  assert.equal(normalizeGearName('\u0085\u3000  潮竿 🎣\u2003\u0085'), '潮竿 🎣');
  assert.equal(normalizeGearName('\ufeffname\ufeff'), '\ufeffname\ufeff');
  assert.equal(gearNameValidationMessage('\ufeffname\ufeff'), '');
  assert.equal(gearNameValidationMessage(''), '');
  assert.equal(gearNameValidationMessage(' \u0085\u3000 '), '');
  for (const name of ['a'.repeat(32), '🎣'.repeat(32), '<b>plain</b>']) assert.equal(gearNameValidationMessage(name), '');
  for (const name of ['a'.repeat(33), '🎣'.repeat(33), ' '.repeat(1025)]) assert.match(gearNameValidationMessage(name), /32 characters/);
  const atByteLimit = `${' '.repeat(512)}x${' '.repeat(511)}`;
  assert.equal(new TextEncoder().encode(atByteLimit).length, 1024);
  assert.equal(gearNameValidationMessage(atByteLimit), '');
  for (const c of ['\n', '\0', '\r', '\u0085', '\u061c', '\u200e', '\u200f', '\u202a', '\u202b', '\u202c', '\u202d', '\u202e', '\u2066', '\u2067', '\u2068', '\u2069']) {
    assert.match(gearNameValidationMessage(`a${c}b`), /cannot contain/);
  }
  assert.match(gearRenameErrorMessage('GEAR_NAME_TOO_LONG'), /32 characters/);
  assert.match(gearRenameErrorMessage('GEAR_NAME_INVALID'), /cannot contain/);
  assert.equal(gearRenameErrorMessage('GEAR_INSTANCE_UNAVAILABLE'), 'This gear is no longer available. Review your gear list and try again.');
  assert.equal(gearRenameErrorMessage('UNKNOWN_SECRET'), 'This name could not be saved. Please try again.');
});

test('aliases preserve model and distinct ordinals without leaking other owners', () => {
  const a = { instanceId: 123456789012345678901234567890n, playerId: 9n, kind: 'rod', definitionId: 2 };
  const b = { ...a, instanceId: a.instanceId + 1n };
  const game = {
    player: { playerId: 9n }, rods: [{ rodId: 2, name: 'River Rod' }], haulEquipment: [],
    gearInstances: [b, a], gearInstanceNames: [],
  };
  assert.equal(gearDefaultLabel(game, a), 'River Rod · Item 1');
  assert.equal(gearLabel(game, b), 'River Rod · Item 2');
  game.gearInstanceNames = [
    { instanceId: a.instanceId, playerId: 9n, name: 'Shared' },
    { instanceId: b.instanceId, playerId: 9n, name: 'Shared' },
  ];
  assert.equal(gearTitle(game, a), 'Shared');
  assert.equal(gearLabel(game, a), 'Shared · River Rod · Item 1');
  assert.equal(gearLabel(game, b), 'Shared · River Rod · Item 2');
  assert(gearNickname(game, a).toLowerCase().includes('shared'));
  assert(gearName(game, a).toLowerCase().includes('river'));
  game.gearInstanceNames[0] = { ...game.gearInstanceNames[0], playerId: 10n, name: 'Secret' };
  assert.equal(gearNickname(game, a), '');
  game.gearInstanceNames[0] = { ...game.gearInstanceNames[0], playerId: 9n };
  game.player = { playerId: 10n };
  assert.equal(gearNickname(game, a), '');
  game.player = null;
  assert.equal(gearNickname(game, a), '');
  delete game.player;
  assert.equal(gearNickname(game, a), 'Secret');
  delete game.gearInstanceNames;
  assert.equal(gearNickname(game, a), '');
});
