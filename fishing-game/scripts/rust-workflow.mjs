import { existsSync } from 'node:fs';
import { isAbsolute, join, relative, resolve } from 'node:path';
import { projectRoot, runBuildCommand, stageRustArtifacts, withRustBuild } from './rust-build.mjs';

const [mode, ...args] = process.argv.slice(2);
const suffix = process.platform === 'win32' ? '.exe' : '';
let command;
let commandArgs;
let artifacts = [];
if (mode === 'cargo' && args.length) {
  if (!['build', 'check', 'test', 'run', 'clippy', 'fmt', 'metadata', 'update'].includes(args[0])) throw new Error('Use a Cargo build, check, test, run, clippy, fmt, metadata or update command.');
  command = 'cargo';
  commandArgs = args;
  if (args.some(arg => arg === '--target-dir' || arg.startsWith('--target-dir='))) throw new Error('The workflow owns its target directory; remove --target-dir.');
} else if (mode === 'services') {
  command = 'cargo';
  commandArgs = ['build', '-p', 'discord-bot', '-p', 'account-link', '--release', '--locked', ...args];
  artifacts = [`release/discord-bot${suffix}`, `release/account-link${suffix}`];
} else if (mode === 'module') {
  command = 'cargo';
  commandArgs = ['build', '--manifest-path', 'spacetimedb/Cargo.toml', '--target', 'wasm32-unknown-unknown', '--release', '--locked', ...args];
  artifacts = ['wasm32-unknown-unknown/release/fishing_game_module.wasm'];
} else if (mode === 'script' && args.length) {
  const script = resolve(projectRoot, args[0]);
  const part = relative(join(projectRoot, 'scripts'), script);
  if (part.startsWith('..') || isAbsolute(part) || !existsSync(script)) throw new Error('Choose an existing Fishbound script.');
  command = process.execPath;
  commandArgs = ['--import', 'tsx', script, ...args.slice(1)];
  if (args[0].replaceAll('\\', '/').endsWith('generate-bindings.mjs') && !args.includes('--check-cli')) artifacts = ['wasm32-unknown-unknown/release/fishing_game_module.wasm'];
} else {
  throw new Error('Use cargo <command...>, services, module, or script scripts/<name> [args...].');
}

if (artifacts.length && args.some(arg => arg === '--target-dir' || arg.startsWith('--target-dir='))) throw new Error('The workflow owns its target directory.');
process.exitCode = await withRustBuild(async ({ target, env }) => {
  const code = await runBuildCommand(command, commandArgs, { env });
  if (code === 0 && artifacts.length) stageRustArtifacts(target, artifacts);
  return code;
});
