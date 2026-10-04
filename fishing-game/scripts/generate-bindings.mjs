import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
function run(command, args) {
  const result = spawnSync(command, args, { cwd: root, stdio: 'inherit' });
  if (result.error) throw result.error;
  if (result.status !== 0) process.exit(result.status ?? 1);
}
const version = spawnSync('spacetime', ['--version'], { encoding: 'utf8' });
if (version.error) throw version.error;
if (version.status !== 0 || !version.stdout.includes('tool version 2.10.2;')) {
  throw new Error('Bindings require SpaceTimeDB CLI 2.10.2. Check spacetime --version.');
}
run('cargo', ['build', '--manifest-path', 'spacetimedb/Cargo.toml', '--target', 'wasm32-unknown-unknown', '--release', '--locked']);
const wasm = 'spacetimedb/target/wasm32-unknown-unknown/release/fishing_game_module.wasm';
for (const [lang, out] of [['typescript', 'packages/generated/src'], ['rust', 'crates/game-client/src/module_bindings']]) {
  run('spacetime', ['generate', '--lang', lang, '--bin-path', wasm, '--out-dir', out, '--yes', '--no-config']);
}
// Format generated Rust with the pinned toolchain so workspace formatting stays reproducible.
run('cargo', ['fmt', '--package', 'game-client']);
