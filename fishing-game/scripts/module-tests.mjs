import { requireRustWorkflow } from './rust-build.mjs';
import { existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

requireRustWorkflow();

const env = { ...process.env };
// The native harness links OpenSSL. Reuse the installed ServBay libraries on
// Windows instead of requiring a separate Perl/OpenSSL build toolchain.
if (process.platform === 'win32' && !env.OPENSSL_DIR && existsSync('C:/ServBay/packages/openssl/lib/libcrypto.lib')) {
  env.OPENSSL_DIR = 'C:/ServBay/packages/openssl';
  env.OPENSSL_NO_VENDOR = '1';
}
const result = spawnSync('cargo', ['test', '--manifest-path', 'spacetimedb/Cargo.toml', '--features', 'module-tests', '--lib', '--locked'], {
  env, stdio: 'inherit', windowsHide: true,
});
if (result.error) throw result.error;
process.exit(result.status ?? 1);
