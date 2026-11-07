import { projectRoot, requireRustWorkflow } from './rust-build.mjs';
import { existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { join } from 'node:path';
import { prepareModuleOpenSsl } from './module-openssl.mjs';

requireRustWorkflow();

let env = { ...process.env };
if (process.platform === 'win32' && !env.OPENSSL_DIR && !env.OPENSSL_SRC_PERL) {
  const tool = join(projectRoot, '.local', 'tools', 'perl');
  const perl = join(tool, 'perl', 'bin', 'perl.exe');
  if (existsSync(perl)) {
    env.OPENSSL_SRC_PERL = perl;
    const pathKey = Object.keys(env).find(key => key.toUpperCase() === 'PATH') ?? 'PATH';
    env[pathKey] = [join(tool, 'perl', 'bin'), join(tool, 'c', 'bin'), env[pathKey] ?? ''].join(';');
  }
}
const privateWindowsOpenSsl = process.platform === 'win32' && !env.OPENSSL_DIR &&
  (env.OPENSSL_NO_VENDOR ?? '0') === '0';
if (privateWindowsOpenSsl) {
  env = prepareModuleOpenSsl(env);
}
const target = privateWindowsOpenSsl ? ['--target', 'x86_64-pc-windows-msvc'] : [];
const result = spawnSync('cargo', ['test', '--manifest-path', 'spacetimedb/Cargo.toml', '--features', 'module-tests', '--lib', '--locked', ...target], {
  env, stdio: 'inherit', windowsHide: true,
});
if (result.error) throw result.error;
process.exit(result.status ?? 1);
