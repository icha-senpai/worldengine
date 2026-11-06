import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { appendFile } from 'node:fs/promises';
import { requireRustWorkflow, stageRustArtifacts } from './rust-build.mjs';

// One managed target is shared by these related gates and removed by its owner.
const target = requireRustWorkflow();
const evidence = '.local/luck-build-evidence.txt';
async function run(command: string, args: string[]) {
  const label = [command, ...args].join(' ');
  console.log(`Checking: ${label}`);
  const result = spawnSync(command, args, {
    encoding: 'utf8', windowsHide: true, maxBuffer: 64 * 1024 * 1024,
    env: { ...process.env, CARGO_NET_OFFLINE: 'true' },
  });
  await appendFile(evidence, `\n=== ${label} ===\n${result.stdout ?? ''}${result.stderr ?? ''}\nExit: ${result.status}\n`);
  if (result.error) throw result.error;
  if (result.stdout) console.log(result.stdout.trim());
  assert.equal(result.status, 0, result.stderr || result.stdout);
}

if (process.argv.includes('--resume')) {
  await run('cargo', ['test', '-p', 'game-rules', '--locked', 'luck::tests']);
  await run('cargo', ['build', '--manifest-path', 'spacetimedb/Cargo.toml', '--target', 'wasm32-unknown-unknown', '--release', '--locked']);
} else {
  await run('cargo', ['test', '-p', 'game-rules', '--locked']);
  await run('cargo', ['check', '--workspace', '--locked']);
  await run(process.execPath, ['scripts/generate-bindings.mjs']);
}
stageRustArtifacts(target, ['wasm32-unknown-unknown/release/fishing_game_module.wasm']);
await run(process.execPath, ['scripts/module-tests.mjs']);
for (const script of [
  'scripts/uncapped-luck-proof.ts',
  'scripts/compendium-loadout-proof.ts',
  'scripts/collection-focus-odds-proof.ts',
  'scripts/luck-preservation-proof.ts',
]) {
  await run(process.execPath, ['--import', 'tsx', script]);
}
console.log('PASS Luck verification workflow. This builds and tests only; it does not deploy production.');
