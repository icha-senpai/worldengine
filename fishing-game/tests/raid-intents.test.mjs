import test from 'node:test';
import assert from 'node:assert/strict';
import { canRetryRaidIntent, createBrowserRequestId, matchesRaidAction, raidActionFingerprint } from '../apps/web/src/lib/game/raid-intents.ts';
import { itemSlot } from '../apps/web/src/lib/game/raid-items.ts';

test('browser raid request IDs use UTC milliseconds plus cryptographic 16-bit entropy', () => {
  const requestId = createBrowserRequestId(1_800_000_000_123, () => 0xabcd);
  assert.equal(requestId, (1_800_000_000_123n << 16n) | 0xabcdn);
  assert.ok(requestId <= 0xffff_ffff_ffff_ffffn);
  assert.throws(() => createBrowserRequestId(Number.MAX_SAFE_INTEGER + 1, () => 0));
  assert.throws(() => createBrowserRequestId(1, () => 0x1_0000));
});

test('raid action retries keep the original intent and only its exact receipt confirms it', () => {
  const fingerprint = raidActionFingerprint({ kind: 'prepare', speciesId: 74, grade: 0 });
  const pending = { requestId: 123n, fingerprint, playerId: 7n };
  assert.equal(canRetryRaidIntent(pending, fingerprint, 7n), true);
  assert.equal(canRetryRaidIntent(pending, fingerprint, 8n), false);
  assert.equal(canRetryRaidIntent(pending, 'deploy_raid:456', 7n), false);
  assert.equal(matchesRaidAction(pending, { requestId: 123n, fingerprint, playerId: 7n }), true);
  assert.equal(matchesRaidAction(pending, { requestId: 123n, fingerprint: 'prepare_raid:74:1', playerId: 7n }), false);
  assert.equal(matchesRaidAction(pending, { requestId: 123n, fingerprint, playerId: 8n }), false);
  assert.equal(matchesRaidAction(pending, { requestId: 124n, fingerprint, playerId: 7n }), false);
});

test('graded inventory slots remain distinct in the composite item key', () => {
  assert.equal(itemSlot((123n << 32n) | 1000n), 1000);
  assert.equal(itemSlot((123n << 32n) | 1109n), 1109);
});
