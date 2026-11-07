import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  canonicalTideforgeIntent, currentTideforgeForm, matchesTideforgeQuote, parseTideforgeJson, parseTideforgePreview,
  sameTideforgeAccount, tideforgeFingerprint, tideforgeResultUncertain, tideforgeRetryNonce, tideforgeStateAvailability,
} from '../apps/web/src/lib/game/tideforge-intents.ts';

const big = '170141183460469231731687303715884105729';
const actions = [
  { action: 'camp_copy', kind: 'rod', definition_id: 2, secondary_biome_id: 1 },
  { action: 'wandering_gamble', kind: 'net', definition_id: 7, secondary_biome_id: 1 },
  { action: 'add_affix', instance_id: big, affix_id: 'fortune', secondary_biome_id: null },
  { action: 'upgrade_affix', instance_id: big, slot: 0, target_tier: 10 },
  { action: 'refine_affix', instance_id: big, slot: 1 },
  { action: 'change_affix', instance_id: big, slot: 0, affix_id: 'secondary_affinity', secondary_biome_id: 7 },
  { action: 'dismantle', instance_id: big },
  { action: 'extract_signature', instance_id: big },
  { action: 'fillet', catch_ids: ['18446744073709551615'], allow_trophies: false },
  { action: 'convert_normal', material_id: 0, batches: 1000 },
  { action: 'refine_material', material_id: 1, from_grade: 1, to_grade: 9, batches: 1 },
];
const identity = '0xabc', playerId = 99n, nowMicros = 1_000_000n;
const source = actions[0];
const guard = { identity, playerId, nowMicros, fingerprint: tideforgeFingerprint(source), nonce: 123n };
const purchasePreview = { action: source.action, kind: source.kind, definition_id: source.definition_id,
  secondary_biome_id: source.secondary_biome_id, affix_count: 1,
  coins_before: 100, coins_cost: 1, coins_after: 99, marks_before: 10, marks_cost: 0, marks_after: 10 };
function quote(intent = source, overrides = {}) {
  return {
    identity: { toHexString: () => identity }, playerId, nonce: 123n,
    intentJson: JSON.stringify(intent),
    previewJson: JSON.stringify(purchasePreview),
    catalogVersion: 1, consumed: false, expiresAt: { microsSinceUnixEpoch: 2_000_000n },
    ...overrides,
  };
}

test('all eleven action shapes serialize canonical IDs without numeric loss', () => {
  assert.equal(new Set(actions.map(row => row.action)).size, 11);
  for (const action of actions) assert.deepEqual(JSON.parse(JSON.stringify(canonicalTideforgeIntent(action))), action);
  for (const invalid of ['0', '01', '1e2', '-1', '340282366920938463463374607431768211456'])
    assert.throws(() => canonicalTideforgeIntent({ action: 'dismantle', instance_id: invalid }));
  assert.throws(() => canonicalTideforgeIntent({ ...actions[8], catch_ids: ['18446744073709551616'] }));
  assert.throws(() => canonicalTideforgeIntent({ ...actions[9], batches: 1001 }));
});

test('null normalization and sorted fingerprint include secondary water', () => {
  const { secondary_biome_id: _, ...without } = actions[2];
  assert.equal(tideforgeFingerprint(without), tideforgeFingerprint(actions[2]));
  assert.equal(tideforgeFingerprint({ definition_id: 2, action: 'camp_copy', secondary_biome_id: 1, kind: 'rod' }), tideforgeFingerprint(source));
  assert.notEqual(tideforgeFingerprint({ ...source, secondary_biome_id: 3 }), tideforgeFingerprint(source));
  assert.throws(() => canonicalTideforgeIntent({ ...actions[2], secondary_biome_id: 1 }));
  assert.throws(() => canonicalTideforgeIntent({ ...actions[5], secondary_biome_id: null }));
});

test('large preview integers are exact and unsupported runtimes fail closed', () => {
  const data = parseTideforgeJson('{"coins":18446744073709551615,"small":42}');
  assert.equal(data.coins, 18446744073709551615n);
  assert.equal(data.small, 42);
  const original = JSON.parse;
  try {
    JSON.parse = (text, reviver) => original(text, (key, value) => reviver(key, value));
    assert.throws(() => parseTideforgeJson('{"coins":18446744073709551615}'));
  } finally { JSON.parse = original; }
});

test('quote rejects tampered target, water, nonce, account, version, expiry or consumption', () => {
  assert(matchesTideforgeQuote(quote(), guard));
  for (const changes of [
    { nonce: 124n }, { playerId: 100n }, { identity: { toHexString: () => '0xother' } },
    { catalogVersion: 2 }, { consumed: true }, { expiresAt: { microsSinceUnixEpoch: nowMicros } },
    { intentJson: JSON.stringify({ ...source, definition_id: 3 }) },
    { previewJson: JSON.stringify({ ...purchasePreview, definition_id: 3 }) },
    { previewJson: JSON.stringify({ ...purchasePreview, secondary_biome_id: 7 }) },
  ]) assert.equal(matchesTideforgeQuote(quote(source, changes), guard), false);
});

test('gear and fish previews must match their exact targets', () => {
  const gear = actions[2], fingerprint = tideforgeFingerprint(gear);
  const preview = { action: gear.action, gear_before: { instance_id: big, affixes: [] },
    gear_after: { instance_id: big, affixes: [{ slot: 0, id: 'fortune', tier: 1, finishBp: 0, secondaryBiomeId: null }] },
    coins_before: 100, coins_cost: 0, coins_after: 100, materials: [], shards: [] };
  const q = quote(gear, { previewJson: JSON.stringify(preview) });
  assert(matchesTideforgeQuote(q, { ...guard, fingerprint }));
  preview.gear_after.affixes[0].id = 'scholar';
  assert(!matchesTideforgeQuote({ ...q, previewJson: JSON.stringify(preview) }, { ...guard, fingerprint }));
  const fish = actions[8], fishGuard = { ...guard, fingerprint: tideforgeFingerprint(fish) };
  const fishPreview = { action: 'fillet', allow_trophies: false, saved_sale_coins_forgone: 5, materials: [],
    fish: [{ catch_id: fish.catch_ids[0], species_id: 1, rarity: 'F' }] };
  const fishQuote = quote(fish, { previewJson: JSON.stringify(fishPreview) });
  assert(matchesTideforgeQuote(fishQuote, fishGuard));
  assert(!matchesTideforgeQuote({ ...fishQuote, previewJson: JSON.stringify({ ...fishPreview, fish: [{ ...fishPreview.fish[0], catch_id: '2' }] }) }, fishGuard));
});

test('preview requires complete authoritative costs and destruction information', () => {
  assert(parseTideforgePreview(JSON.stringify(purchasePreview)));
  const { coins_cost: _, ...missingCost } = purchasePreview;
  assert.equal(parseTideforgePreview(JSON.stringify(missingCost)), null);
  assert.equal(parseTideforgePreview('{"action":"dismantle","gear_before":{"instance_id":"1","affixes":[]},"gear_after":null}'), null);
  assert.equal(parseTideforgePreview('{"action":"fillet","allow_trophies":false,"materials":[],"fish":[]}'), null);
});

test('form revisions, account changes and optional-table failure fail closed', () => {
  const snapshot = { revision: 7, identity, playerId };
  const state = { ...snapshot, active: true, ready: true };
  assert(currentTideforgeForm(snapshot, state));
  for (const changes of [{ revision: 8 }, { identity: '0xother' }, { playerId: 100n }, { active: false }, { ready: false }])
    assert(!currentTideforgeForm(snapshot, { ...state, ...changes }));
  assert(sameTideforgeAccount(snapshot, identity, playerId));
  assert(!sameTideforgeAccount(snapshot, identity, 100n));
  assert.equal(tideforgeStateAvailability(null, true), 'unavailable');
  assert.equal(tideforgeStateAvailability(null), 'inactive');
  assert.equal(tideforgeStateAvailability({ version: 1, catalogVersion: 1 }), 'active');
  assert.equal(tideforgeStateAvailability({ version: 1, catalogVersion: 2 }), 'unavailable');
});

test('uncertain commit retries its original nonce only for its original account', () => {
  const pending = { identity, playerId, quote: { nonce: 340282366920938463463374607431768211455n } };
  const unknown = new Error('dropped');
  unknown.name = 'UnknownCallResultError';
  assert(tideforgeResultUncertain(unknown, false));
  assert(tideforgeResultUncertain(new Error('late'), true));
  assert(!tideforgeResultUncertain(new Error('rejected'), false));
  assert.equal(tideforgeRetryNonce(pending, identity, playerId), pending.quote.nonce);
  assert.equal(tideforgeRetryNonce(pending, '0xother', playerId), null);
  assert.equal(tideforgeRetryNonce(pending, identity, 1n), null);
});
