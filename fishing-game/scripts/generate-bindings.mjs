import { moduleArtifactPath, requireRustWorkflow } from './rust-build.mjs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import { existsSync } from 'node:fs';

const root = fileURLToPath(new URL('../', import.meta.url));
function run(command, args) {
  const result = spawnSync(command, args, { cwd: root, stdio: 'inherit' });
  if (result.error) throw result.error;
  if (result.status !== 0) process.exit(result.status ?? 1);
}
function resolveCli(version, override) {
  const installed = process.platform === 'win32' && process.env.LOCALAPPDATA
    ? join(process.env.LOCALAPPDATA, 'SpacetimeDB', 'bin', version, 'spacetimedb-cli.exe') : '';
  const command = process.env[override] || (installed && existsSync(installed) ? installed : 'spacetime');
  const result = spawnSync(command, ['--version'], { encoding: 'utf8' });
  if (result.error || result.status !== 0 || !result.stdout.includes(`tool version ${version};`)) {
    throw new Error(`Bindings require SpaceTimeDB CLI ${version}. Install it or set ${override} to its executable.`);
  }
  return command;
}
// The module/browser use 2.12; Rust client codegen stays with its 2.10.2 SDK.
const typescriptCli = resolveCli('2.12.0', 'FISHBOUND_TYPESCRIPT_CLI');
const rustCli = resolveCli('2.10.2', 'FISHBOUND_RUST_CLI');
if (process.argv.includes('--check-cli')) {
  console.log('Binding generators verified: TypeScript 2.12.0, Rust 2.10.2.');
  process.exit(0);
}
requireRustWorkflow();
run('cargo', ['build', '--manifest-path', 'spacetimedb/Cargo.toml', '--target', 'wasm32-unknown-unknown', '--release', '--locked']);
const wasm = moduleArtifactPath();
for (const [lang, out] of [['typescript', 'packages/generated/src'], ['rust', 'crates/game-client/src/module_bindings']]) {
  run(lang === 'typescript' ? typescriptCli : rustCli, ['generate', '--lang', lang, '--bin-path', wasm, '--out-dir', out, '--yes', '--no-config']);
}
// Format generated Rust with the pinned toolchain so workspace formatting stays reproducible.
run('cargo', ['fmt', '--package', 'game-client']);
