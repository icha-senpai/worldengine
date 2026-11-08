import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import raids from '../content/raids.json' with { type: 'json' };
import { processingProtectionLabel, tideforgeActionLabel, tideforgeErrorMessage, tideforgeMaterialLabel, tideforgeValidationMessage } from '../apps/web/src/lib/game/tideforge-copy.ts';
import { affixName, canConsume, catchLabel, gearLabel, gearName, gearProtectionMessage, signatureName } from '../apps/web/src/lib/game/tideforge-ui.ts';

test('action and material labels are closed and match every known grade', () => {
  const actions = {
    camp_copy: 'Buy gear', wandering_gamble: 'Try the wandering trader', add_affix: 'Add an affix',
    change_affix: 'Change an affix', upgrade_affix: 'Upgrade an affix', refine_affix: 'Improve affix strength',
    dismantle: 'Dismantle gear', extract_signature: 'Extract a signature', fillet: 'Fillet fish',
    convert_normal: 'Make F-grade materials', refine_material: 'Refine materials',
  };
  for (const [id, label] of Object.entries(actions)) assert.equal(tideforgeActionLabel(id), label);
  assert.equal(tideforgeMaterialLabel('normal_fillets'), 'Basic fillets');
  assert.equal(tideforgeMaterialLabel('normal_scales'), 'Basic scales');
  assert.equal(raids.grades.length, 10);
  for (const grade of raids.grades) for (const material of raids.materials) {
    assert.equal(tideforgeMaterialLabel(`raid_${material.key}_${grade.name.toLowerCase()}`), `${grade.name}-grade ${material.key}`);
  }
  assert.equal(tideforgeActionLabel('hostile_unknown_action'), 'Review action');
  assert.equal(tideforgeMaterialLabel('raid_hostile_unknown'), 'Unknown material');
  assert.equal(affixName('hostile_unknown_affix'), 'Unknown affix');
  assert.equal(signatureName('hostile_unknown_signature'), 'Unknown signature');
});

test('errors and validation have safe known and unknown messages', () => {
  for (const [code, expected] of [
    ['TIDEFORGE_GEAR_PROTECTED', /protected/i], ['GEAR_LOCKED', /fishing trip or raid/],
    ['INSUFFICIENT_MATERIALS', /required material grades.*balances in Materials/], ['TIDEFORGE_AFFIX_SLOT_LOCKED', /mastery/],
  ]) assert.match(tideforgeErrorMessage(code), expected);
  assert.equal(tideforgeErrorMessage('TIDEFORGE_SECRET_CODE <hostile_unknown>'), 'This action could not be completed. Check your gear and materials, then try again.');
  assert.equal(tideforgeValidationMessage('Choose a water.'), 'Choose a water.');
  assert.equal(tideforgeValidationMessage('hostile_unknown_field'), 'Review your selection and try again.');
});

test('trader, materials and shard errors use actionable safe language', () => {
  const trader = tideforgeErrorMessage('TIDEFORGE_WANDERING_CLOSED');
  assert.equal(trader, 'The wandering trader is away. Check Trader for the next visit in your local time.');
  assert.doesNotMatch(trader, /monthly|refresh|TIDEFORGE_/i);
  const materials = 'You do not have enough materials for this action. Review the required material grades and your balances in Materials.';
  assert.equal(tideforgeErrorMessage('RAID_MATERIALS_REQUIRED'), materials);
  assert.equal(tideforgeErrorMessage('INSUFFICIENT_MATERIALS'), materials);
  assert.equal(tideforgeErrorMessage('TIDEFORGE_SHARDS_REQUIRED'), 'You need more shards for this affix. Dismantle eligible gear with that affix, then review your Materials.');
  assert.equal(tideforgeErrorMessage('TIDEFORGE_SHARD_OVERFLOW'), 'This action could not be completed. Check your gear and materials, then try again.');
});

test('retry and unknown raid guidance avoid refresh and internal request terminology', async () => {
  const tideforge = await readFile(new URL('../apps/web/src/lib/game/Tideforge.svelte', import.meta.url), 'utf8');
  const game = await readFile(new URL('../apps/web/src/lib/game/game.svelte.ts', import.meta.url), 'utf8');
  assert.match(tideforge, /Tideforge could not load\. Retry Tideforge to reconnect this section; your saved gear is unchanged\./);
  assert.match(tideforge, /This preview could not be read safely\. Open a new preview; do not confirm this one\./);
  assert.doesNotMatch(tideforge, /Refresh the page/);
  assert.match(game, /The connection dropped before confirmation\. Check the same raid action again safely; do not start a different action\./);
  assert.doesNotMatch(game, /reuse its request ID/);
});

test('numbered gear and catches are stable across cache order and isolated models', () => {
  const low = { instanceId: 9007199254740993n, kind: 'rod', definitionId: 2 };
  const high = { instanceId: 9007199254740995n, kind: 'rod', definitionId: 2 };
  const other = { instanceId: 4n, kind: 'boat', definitionId: 2 };
  const single = { instanceId: 3n, kind: 'rod', definitionId: 3 };
  const game = { gearInstances: [high, other, single, low], inventory: [{ catchId: 9007199254740995n }, { catchId: 9007199254740993n }],
    rods: [{ rodId: 2, name: 'River Rod' }, { rodId: 3, name: 'Lake Rod' }], haulEquipment: [{ equipmentId: 2, name: 'Boat' }] };
  assert.equal(gearLabel(game, low), 'River Rod · Item 1');
  assert.equal(gearLabel(game, high), 'River Rod · Item 2');
  assert.equal(gearLabel(game, other), 'Boat');
  assert.equal(gearLabel(game, single), 'Lake Rod');
  assert.equal(gearName(game, { kind: 'internal_unknown', definitionId: 2 }), 'Gear');
  assert.equal(catchLabel(game, 9007199254740993n), 'Catch 1');
  assert.equal(catchLabel(game, 9007199254740995n), 'Catch 2');
  assert.equal(catchLabel(game, 7n), 'Selected fish');
  game.gearInstances.reverse(); game.inventory.reverse();
  assert.equal(gearLabel(game, low), 'River Rod · Item 1');
  assert.equal(gearLabel(game, high), 'River Rod · Item 2');
  assert.equal(catchLabel(game, 9007199254740993n), 'Catch 1');
});

test('protection explanations preserve the existing eligibility priority', () => {
  const item = { instanceId: 1n, protectedOriginal: true, favorite: true };
  const game = { gearIsEquipped: () => true, gearIsBooked: () => true };
  assert.match(gearProtectionMessage(game, item), /kept safe/);
  assert.match(gearProtectionMessage(game, { ...item, protectedOriginal: false }), /Unfavorite/);
  assert.match(gearProtectionMessage(game, { ...item, protectedOriginal: false, favorite: false }), /Unequip/);
  assert.match(gearProtectionMessage({ ...game, gearIsEquipped: () => false }, { ...item, protectedOriginal: false, favorite: false }), /fishing trip or raid/);
  assert.equal(canConsume(game, item), false);
  assert.equal(canConsume({ gearIsEquipped: () => false, gearIsBooked: () => false }, { ...item, protectedOriginal: false, favorite: false }), true);
  assert.equal(processingProtectionLabel('ordinary'), 'Ready to fillet');
  assert.equal(processingProtectionLabel('favorite'), 'Favorite');
  assert.equal(processingProtectionLabel('bonus'), 'Bonus fish: protected');
  assert.equal(processingProtectionLabel('trophy'), 'Rank maximum or perfect catch');
  assert.equal(processingProtectionLabel('future_internal'), 'Protected: fish details unavailable');
});
