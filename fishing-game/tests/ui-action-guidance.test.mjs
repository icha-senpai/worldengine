import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { forgeActionProblem, processingActionProblem } from '../apps/web/src/lib/game/ui-action-guidance.ts';
import { hasOrdinaryRaidClearForGrade } from '../apps/web/src/lib/game/raid-progress.ts';

const roll = (slot, tier = 2, finishBp = 5000) => ({ slot, id: 'fortune', tier, finishBp, secondaryBiomeId: null });
const forge = (overrides = {}) => ({
  ready: true, busy: false, pending: false, hasItem: true, detailsUnavailable: false,
  rolls: [], mastery: { level: 1, slots: 1 }, booked: false,
  action: 'add_affix', slot: 0, choices: ['secondary_affinity'],
  affix: 'secondary_affinity', waterValid: true, changeAllowed: true, tier: 3,
  ...overrides,
});
const processing = (overrides = {}) => ({
  ready: true, busy: false, pending: false, action: 'convert_normal',
  selectedCount: 0, validSelectedCount: 0, material: 0, batches: 1, from: 0, to: 1,
  clear: () => true, ...overrides,
});

test('Forge guidance prioritizes availability, missing details and booked gear', () => {
  assert.match(forgeActionProblem(forge({ ready: false, busy: true })), /Reconnect/);
  assert.match(forgeActionProblem(forge({ busy: true, pending: true })), /Wait/);
  assert.match(forgeActionProblem(forge({ pending: true, hasItem: false })), /previous action/);
  assert.equal(forgeActionProblem(forge({ hasItem: false })), 'Choose gear.');
  assert.match(forgeActionProblem(forge({ detailsUnavailable: true, booked: true })), /Affix details could not load/);
  assert.match(forgeActionProblem(forge({ rolls: null })), /Retry Tideforge/);
  assert.match(forgeActionProblem(forge({ mastery: null })), /mastery.*loading/i);
  assert.match(forgeActionProblem(forge({ booked: true })), /fishing trip or raid/);
});

test('Forge reports full unlocked slots before missing choices or selected affix', () => {
  assert.equal(forgeActionProblem(forge({ rolls: [roll(0)], choices: [], affix: '' })),
    'No empty unlocked slots. Reach mastery 10 to open another slot, or choose Change to replace an affix.');
  assert.match(forgeActionProblem(forge({ mastery: { level: 10, slots: 2 }, rolls: [roll(0), roll(1)] })), /mastery 25/);
  assert.match(forgeActionProblem(forge({ mastery: { level: 25, slots: 3 }, rolls: [roll(0), roll(1), roll(2)] })), /mastery 50/);
  assert.equal(forgeActionProblem(forge({ mastery: { level: 50, slots: 4 }, rolls: [roll(0), roll(1), roll(2), roll(3)] })),
    'All unlocked slots are filled. Choose Change to replace an affix.');
});

test('Forge explains choices, water, occupied slots and tier/strength limits', () => {
  assert.match(forgeActionProblem(forge({ choices: [] })), /No discovered compatible affixes.*Codex/);
  assert.match(forgeActionProblem(forge({ affix: '' })), /Choose a discovered/);
  assert.match(forgeActionProblem(forge({ waterValid: false })), /secondary water/);
  assert.match(forgeActionProblem(forge({ action: 'change_affix', rolls: [roll(0)], changeAllowed: false })), /different affix or secondary water/);
  assert.match(forgeActionProblem(forge({ action: 'change_affix', rolls: [] })), /occupied unlocked slot/);
  assert.match(forgeActionProblem(forge({ action: 'change_affix', rolls: [roll(1)], slot: 1 })), /mastery 10/);
  assert.match(forgeActionProblem(forge({ action: 'change_affix', rolls: [roll(0)], choices: [] })), /No discovered compatible affixes/);
  assert.match(forgeActionProblem(forge({ action: 'upgrade_affix', rolls: [roll(0, 10)] })), /already at tier 10/);
  assert.match(forgeActionProblem(forge({ action: 'upgrade_affix', rolls: [roll(0, 2)], tier: 2 })), /higher tier/);
  assert.match(forgeActionProblem(forge({ action: 'refine_affix', rolls: [roll(0, 2, 10000)] })), /fully improved/);
  assert.equal(forgeActionProblem(forge()), '');
  assert.equal(forgeActionProblem(forge({ action: 'change_affix', rolls: [roll(0)] })), '');
  assert.equal(forgeActionProblem(forge({ action: 'upgrade_affix', rolls: [roll(0)] })), '');
  assert.equal(forgeActionProblem(forge({ action: 'refine_affix', rolls: [roll(0)] })), '');
});

test('Processing guards fish, material, batches and grade boundaries', () => {
  assert.match(processingActionProblem(processing({ ready: false })), /Reconnect/);
  assert.match(processingActionProblem(processing({ busy: true })), /Wait/);
  assert.match(processingActionProblem(processing({ pending: true })), /previous action/);
  assert.match(processingActionProblem(processing({ action: 'fillet' })), /Choose fish/);
  assert.match(processingActionProblem(processing({ action: 'fillet', selectedCount: 2, validSelectedCount: 1 })), /protected/);
  assert.match(processingActionProblem(processing({ action: 'fillet', selectedCount: 51, validSelectedCount: 51 })), /50 fish/);
  assert.equal(processingActionProblem(processing({ action: 'fillet', selectedCount: 1, validSelectedCount: 1 })), '');
  for (const batches of [0, 1001, 1.5, NaN]) assert.match(processingActionProblem(processing({ batches })), /1–1000/);
  assert.match(processingActionProblem(processing({ material: 2 })), /material/);
  for (const [from, to] of [[0, 0], [0, 10], [-1, 1], [1.5, 2]]) {
    assert.match(processingActionProblem(processing({ action: 'refine_material', from, to })), /valid grades/);
  }
});

test('Processing requires F for conversion and each intermediate destination for refining', () => {
  assert.match(processingActionProblem(processing({ clear: () => false })), /F grade/);
  const visited = [];
  const clear = grade => { visited.push(grade); return grade !== 2; };
  assert.match(processingActionProblem(processing({ action: 'refine_material', from: 0, to: 3, clear })), /C grade/);
  assert.deepEqual(visited, [1, 2]);
  assert.equal(processingActionProblem(processing({ action: 'refine_material', from: 0, to: 3, clear: () => true })), '');
});

test('only the current player’s ordinary-fish raid clear counts, not bonus or duplicate rows', () => {
  const ordinary = new Set([1]);
  const badges = [
    { playerId: 9n, speciesId: 2, clearedGrades: 10 },
    { playerId: 8n, speciesId: 1, clearedGrades: 10 },
  ];
  const check = grade => hasOrdinaryRaidClearForGrade(badges, ordinary, 9n, grade);
  assert.match(processingActionProblem(processing({ clear: check })), /F grade/);
  badges.push({ playerId: 9n, speciesId: 1, clearedGrades: 1 }, { playerId: 9n, speciesId: 1, clearedGrades: 1 });
  assert.equal(processingActionProblem(processing({ clear: check })), '');
  assert.match(processingActionProblem(processing({ action: 'refine_material', from: 0, to: 1, clear: check })), /D grade/);
});

test('Forge and Processing preview handlers recheck the current guidance before dispatch', async () => {
  for (const name of ['TideforgeForge', 'TideforgeProcessing']) {
    const component = await readFile(new URL(`../apps/web/src/lib/game/${name}.svelte`, import.meta.url), 'utf8');
    assert.match(component, /const blockedReason = \$derived\(currentProblem\(\)\)/);
    assert.match(component, /function preview\(\) \{[\s\S]*?if \([^)]*currentProblem\(\)[^)]*\) return;[\s\S]*?onpreview\(/);
    assert.match(component, /disabled=\{!!blockedReason\} aria-describedby=\{blockedReason \?/);
  }
});
