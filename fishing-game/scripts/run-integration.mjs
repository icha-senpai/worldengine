import { spawnSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const uri = process.env.TEST_SPACETIMEDB_URI ?? 'http://127.0.0.1:3127';
if (new URL(uri).hostname !== '127.0.0.1') throw new Error('Proof tests require loopback');
function run(command, args, env = process.env) {
  const result = spawnSync(command, args, { cwd: root, stdio: 'inherit', windowsHide: true, env });
  if (result.error) throw result.error;
  if (result.status !== 0) process.exit(result.status ?? 1);
}
run(process.execPath, ['scripts/generate-bindings.mjs']);
run('cargo', ['build', '-p', 'game-client', '--bin', 'adapter-proof', '--locked']);
const database = `fishbound-proof-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
run('spacetime', ['publish', '--server', uri, '--no-config', '--yes=skip-login', '--bin-path',
  'spacetimedb/target/wasm32-unknown-unknown/release/fishing_game_module.wasm', database]);
mkdirSync(new URL('../.local/', import.meta.url), { recursive: true });
writeFileSync(new URL('../.local/proof-database.txt', import.meta.url), `${database}\n`);
run(process.execPath, ['node_modules/tsx/dist/cli.mjs', 'scripts/integration.ts'], {
  ...process.env, TEST_SPACETIMEDB_URI: uri, TEST_SPACETIMEDB_DATABASE: database,
});
