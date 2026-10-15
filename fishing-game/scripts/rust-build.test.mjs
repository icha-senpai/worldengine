import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { pathToFileURL } from 'node:url';
import { after, test } from 'node:test';
import { projectRoot, runBuildCommand, stageRustArtifacts, withRustBuild } from './rust-build.mjs';

const local = join(projectRoot, '.local');
mkdirSync(local, { recursive: true });
const root = mkdtempSync(join(local, 'rust-workflow-tests-'));
mkdirSync(join(root, 'src'));
writeFileSync(join(root, 'Cargo.toml'), '[package]\nname="cache-cleanup-proof"\nversion="0.1.0"\nedition="2024"\n[workspace]\n');
writeFileSync(join(root, 'src', 'main.rs'), 'fn main() { println!("cleanup proof"); }');
writeFileSync(join(root, 'keep.txt'), 'outside the cache');
after(() => {
  assert.equal(dirname(root), local);
  assert.ok(relative(local, root).startsWith('rust-workflow-tests-'));
  rmSync(root, { recursive: true });
});

test('successful build uses its own target, stages output, and removes compiled files', async () => {
  let target;
  const code = await withRustBuild(async context => {
    target = context.target;
    assert.equal(context.env.CARGO_TARGET_DIR, target);
    assert.equal(context.env.CARGO_INCREMENTAL, '0');
    const code = await runBuildCommand('cargo', ['build', '--offline'], { cwd: root, env: context.env });
    assert.equal(code, 0);
    const binary = process.platform === 'win32' ? 'cache-cleanup-proof.exe' : 'cache-cleanup-proof';
    // MSVC may still emit a linker PDB for prebuilt standard libraries; it is
    // disposable output too. Rust source debuginfo is disabled by the profile.
    assert.ok(!existsSync(join(target, 'debug', 'incremental')) || readdirSync(join(target, 'debug', 'incremental')).length === 0);
    stageRustArtifacts(target, ['debug/' + binary], root);
    return code;
  }, { root });
  assert.equal(code, 0);
  assert.equal(existsSync(target), false);
  const binary = process.platform === 'win32' ? 'cache-cleanup-proof.exe' : 'cache-cleanup-proof';
  assert.equal(spawnSync(join(root, '.local', 'rust-artifacts', binary), [], { encoding: 'utf8' }).stdout.trim(), 'cleanup proof');
  assert.equal(readFileSync(join(root, 'keep.txt'), 'utf8'), 'outside the cache');
});

test('failed command keeps its exit status and clears its target', async () => {
  let target;
  const code = await withRustBuild(async context => {
    target = context.target;
    writeFileSync(join(target, 'partial-output'), 'incomplete');
    return runBuildCommand(process.execPath, ['-e', 'process.exit(17)'], { cwd: root, env: context.env });
  }, { root });
  assert.equal(code, 17);
  assert.equal(existsSync(target), false);
});

test('startup failure still removes partial output', async () => {
  let target;
  await assert.rejects(withRustBuild(async context => {
    target = context.target;
    return runBuildCommand(join(root, 'missing-command'), [], { cwd: root, env: context.env });
  }, { root }), /ENOENT/);
  assert.equal(existsSync(target), false);
});

test('a broken project manifest does not prevent cleanup', async () => {
  const manifest = join(root, 'Cargo.toml');
  const original = readFileSync(manifest);
  let target;
  try {
    const code = await withRustBuild(async context => {
      target = context.target;
      writeFileSync(manifest, 'invalid manifest [');
      writeFileSync(join(target, 'partial-output'), 'incomplete');
      return 17;
    }, { root });
    assert.equal(code, 17);
    assert.equal(existsSync(target), false);
  } finally {
    writeFileSync(manifest, original);
  }
});

test('overlapping workflows have separate caches and preserve each other', async () => {
  const targets = [];
  await Promise.all([1, 2].map(value => withRustBuild(async ({ target }) => {
    targets.push(target);
    writeFileSync(join(target, 'owned'), String(value));
    await new Promise(resolve => setTimeout(resolve, value * 20));
    assert.equal(readFileSync(join(target, 'owned'), 'utf8'), String(value));
    return 0;
  }, { root })));
  assert.equal(new Set(targets).size, 2);
  assert.ok(targets.every(target => !existsSync(target)));
});

test('artifact traversal is refused and failure cleans the cache', async () => {
  let target;
  await assert.rejects(withRustBuild(async context => {
    target = context.target;
    stageRustArtifacts(target, ['../../keep.txt'], root);
  }, { root }), /escapes/);
  assert.equal(existsSync(target), false);
  assert.equal(readFileSync(join(root, 'keep.txt'), 'utf8'), 'outside the cache');
});

test('changed cache ownership prevents cleanup', async () => {
  let target;
  await assert.rejects(withRustBuild(async context => {
    target = context.target;
    const marker = join(target, '.fishbound-rust-owner.json');
    const owner = JSON.parse(readFileSync(marker, 'utf8'));
    writeFileSync(marker, JSON.stringify({ ...owner, id: 'changed-owner' }));
  }, { root }), /ownership changed/);
  assert.equal(existsSync(target), true);
  assert.equal(dirname(target), join(root, '.local'));
  // This test created the whole directory and deliberately invalidated its marker.
  rmSync(target, { recursive: true });
});

test('handled interruption stops the workflow child before cleaning', () => {
  const helper = pathToFileURL(join(projectRoot, 'scripts', 'rust-build.mjs')).href;
  const marker = join(root, 'interrupted-target.txt');
  const code = `
    import { writeFileSync } from 'node:fs';
    import { withRustBuild, runBuildCommand } from ${JSON.stringify(helper)};
    process.exitCode = await withRustBuild(async ({ target, env }) => {
      writeFileSync(${JSON.stringify(marker)}, target);
      setTimeout(() => process.emit('SIGINT'), 300);
      return runBuildCommand(process.execPath, ['-e', 'setInterval(() => {}, 1000)'], { env });
    }, { root: ${JSON.stringify(root)} });
  `;
  const result = spawnSync(process.execPath, ['--input-type=module', '-e', code], { encoding: 'utf8', windowsHide: true, timeout: 15000 });
  assert.equal(result.status, 130, result.stderr);
  assert.equal(existsSync(readFileSync(marker, 'utf8')), false);
});

test('an unwrapped proof refuses to create the old shared caches', () => {
  const before = ['target', 'spacetimedb/target'].map(path => existsSync(join(projectRoot, path)));
  const result = spawnSync(process.execPath, ['--import', 'tsx', 'scripts/compendium-proof.ts'], { cwd: projectRoot, encoding: 'utf8', windowsHide: true });
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /npm run rust:script/);
  assert.deepEqual(['target', 'spacetimedb/target'].map(path => existsSync(join(projectRoot, path))), before);
});
