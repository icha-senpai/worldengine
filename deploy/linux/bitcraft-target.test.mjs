import test from 'node:test';
import assert from 'node:assert/strict';
import { withBitcraftTarget } from './bitcraft-target.mjs';
test('release-world activation retains the original database as an archive and rejects arbitrary targets', () => {
  const release = { modules: [{ key: 'bitcraft', database: 'space-bitcraft-tools', identity: 'old', hooks: [['migrate_guides']] }, { key: 'evergather' }] };
  const target = { database: 'space-bitcraft-release', source: 'space-bitcraft-tools', world: 'release', identity: 'a'.repeat(64) };
  const result = withBitcraftTarget(release, target);
  assert.equal(result.modules[0].database, 'space-bitcraft-release');
  assert.equal(result.modules[1].database, 'space-bitcraft-tools');
  assert.equal(result.modules[1].identity, 'old');
  assert.equal(result.modules[1].archived, true);
  assert.deepEqual(result.modules[1].hooks, []);
  assert.equal(release.modules.length, 2);
  assert.equal(withBitcraftTarget(release, null), release);
  assert.throws(() => withBitcraftTarget(release, { ...target, database: 'other' }));
  assert.throws(() => withBitcraftTarget(release, { ...target, identity: '' }));
});
