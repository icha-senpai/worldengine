import test from 'node:test';
import assert from 'node:assert/strict';
import { deploymentPlan, groups, publishArguments, sourceFingerprint } from './update-plan.mjs';
import { checkedBindings } from './bindings.mjs';
import { execFileSync } from 'node:child_process';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { basename, dirname, resolve } from 'node:path';

const baseline = Object.fromEntries(Object.keys(groups).map(key => [key, 'old']));
test('a website-only update does not publish databases or rebuild native programs', () => {
  const plan = deploymentPlan({ ...baseline, spaceWeb: 'new' }, baseline);
  assert.deepEqual(Object.keys(plan).filter(key => plan[key]), ['spaceWeb']);
});

test('shared browser connection diagnostics invalidate both websites only', () => {
  const files = new Map([['shared/connection-events.ts', Buffer.from('new diagnostics')]]);
  const changed = Object.keys(groups).filter(key => sourceFingerprint(files, groups[key]) !== sourceFingerprint(new Map(), groups[key]));
  assert.deepEqual(changed, ['spaceWeb', 'fishWeb']);
});
test('module changes include the affected clients and leave the other application alone', () => {
  const plan = deploymentPlan({ ...baseline, fishModule: 'new' }, baseline);
  assert.deepEqual(Object.keys(plan).filter(key => plan[key]), ['fishModule', 'native', 'fishWeb']);
  const bitcraft = deploymentPlan({ ...baseline, bitcraft: 'new' }, baseline);
  assert.deepEqual(Object.keys(bitcraft).filter(key => bitcraft[key]), ['bitcraft', 'collector', 'spaceWeb']);
});
test('repeat runs skip unchanged components; force still covers everything', () => {
  assert.equal(Object.values(deploymentPlan(baseline, baseline)).some(Boolean), false);
  assert.equal(Object.values(deploymentPlan(baseline, baseline, true)).every(Boolean), true);
});
test('fingerprints cover file deletions, shared rules and private environment changes', () => {
  const files = new Map([['fishing-game/crates/game-rules/src/lib.rs', Buffer.from('rules')], ['space/src/App.vue', Buffer.from('web')]]);
  const full = sourceFingerprint(files, groups.fishModule, 'env-one');
  assert.notEqual(full, sourceFingerprint(files, groups.fishModule, 'env-two'));
  files.delete('fishing-game/crates/game-rules/src/lib.rs');
  assert.notEqual(full, sourceFingerprint(files, groups.fishModule, 'env-one'));
  assert.equal(sourceFingerprint(files, groups.fishModule), sourceFingerprint(new Map(), groups.fishModule));
});
test('production publication always prohibits data deletion and ignores dev targets', () => {
  for (const kind of ['js', 'wasm']) {
    const args = publishArguments({ kind, port: 3127, database: 'fishbound-dev-local', artifact: 'module' });
    assert.ok(args.includes('--delete-data=never'));
    assert.ok(args.includes('--no-config'));
    assert.ok(args.includes('http://127.0.0.1:3127'));
    assert.ok(!args.includes('--yes'));
  }
  assert.throws(() => publishArguments({ kind: 'wasm', port: 3000 }));
});
test('a failed or mismatched generator restores only its own generated source', async () => {
  const root = await mkdtemp(resolve(tmpdir(), 'dataverse-update-test-'));
  const git = args => execFileSync('git', args, { cwd: root, stdio: 'pipe' });
  try {
    git(['init']);
    git(['config', 'core.autocrlf', 'false']);
    await writeFile(resolve(root, 'contract.ts'), 'export const version = 1;\n');
    await writeFile(resolve(root, 'unrelated.txt'), 'keep this');
    git(['add', '.']);
    git(['-c', 'user.name=Update Test', '-c', 'user.email=update-test@example.invalid', 'commit', '-m', 'Fixture']);
    // The helper expects directory boundaries, so exercise a real generated folder.
    const { mkdir } = await import('node:fs/promises');
    await mkdir(resolve(root, 'generated'));
    git(['mv', 'contract.ts', 'generated/contract.ts']);
    git(['-c', 'user.name=Update Test', '-c', 'user.email=update-test@example.invalid', 'commit', '-m', 'Generated directory']);
    await assert.rejects(checkedBindings(root, ['generated'], async () => {
      await writeFile(resolve(root, 'generated/contract.ts'), 'export const version = 2;\n');
      await writeFile(resolve(root, 'generated/new.ts'), 'new contract');
    }), /Regenerate and commit/);
    assert.equal(await readFile(resolve(root, 'generated/contract.ts'), 'utf8'), 'export const version = 1;\n');
    assert.equal(git(['status', '--porcelain']).toString().trim(), '');
    assert.equal(await readFile(resolve(root, 'unrelated.txt'), 'utf8'), 'keep this');
    await writeFile(resolve(root, 'generated/local-note.txt'), 'local work');
    await assert.rejects(checkedBindings(root, ['generated'], async () => {}), /local changes/);
    assert.equal(await readFile(resolve(root, 'generated/local-note.txt'), 'utf8'), 'local work');
  } finally {
    assert.equal(dirname(root), resolve(tmpdir()));
    assert.ok(basename(root).startsWith('dataverse-update-test-'));
    await rm(root, { recursive: true, force: true });
  }
});
