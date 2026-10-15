import { spawn, spawnSync } from 'node:child_process';
import { createHash, randomUUID } from 'node:crypto';
import { copyFileSync, existsSync, lstatSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, realpathSync, renameSync, rmdirSync, writeFileSync } from 'node:fs';
import { basename, dirname, isAbsolute, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const projectRoot = realpathSync(fileURLToPath(new URL('../', import.meta.url)));
const markerName = '.fishbound-rust-owner.json';

function assertInside(root, path) {
  const part = relative(root, path);
  if (!part || part.startsWith('..') || isAbsolute(part)) throw new Error(`Rust build path escapes its owner: ${path}`);
}

function assertNoLinks(root, path) {
  assertInside(root, path);
  for (let current = path; current !== root; current = dirname(current)) {
    if (existsSync(current) && lstatSync(current).isSymbolicLink()) throw new Error(`Rust build path crosses a filesystem link: ${current}`);
  }
}

function verifyOwner(target, owner) {
  assertInside(projectRoot, target);
  if (dirname(target) !== join(owner.root, '.local') || !basename(target).startsWith('rust-build-')) throw new Error('Unexpected Rust cache directory');
  assertNoLinks(projectRoot, target);
  assertNoLinks(projectRoot, join(target, markerName));
  const saved = JSON.parse(readFileSync(join(target, markerName), 'utf8'));
  if (saved.id !== owner.id || saved.root !== owner.root || saved.target !== target || saved.pid !== owner.pid) throw new Error('Rust cache ownership changed');
}

export function requireRustWorkflow() {
  const target = process.env.FISHBOUND_RUST_TARGET_DIR;
  const id = process.env.FISHBOUND_RUST_OWNER;
  if (!target || !id) throw new Error('Use npm run rust:script -- scripts/<name>.ts (or the documented npm build/test command) so Rust output is cleaned automatically.');
  assertNoLinks(projectRoot, join(target, markerName));
  const owner = JSON.parse(readFileSync(join(target, markerName), 'utf8'));
  if (owner.id !== id) throw new Error('Rust workflow token does not match its cache');
  verifyOwner(target, owner);
  return target;
}

export function rustTargetPath(...parts) {
  const target = requireRustWorkflow();
  if (!parts.length) return target;
  const path = resolve(target, ...parts);
  assertInside(target, path);
  return path;
}

export function moduleArtifactPath() {
  const built = rustTargetPath('wasm32-unknown-unknown', 'release', 'fishing_game_module.wasm');
  if (existsSync(built)) return built;
  const staged = join(projectRoot, '.local', 'rust-artifacts', 'fishing_game_module.wasm');
  assertNoLinks(projectRoot, staged);
  if (!existsSync(staged)) throw new Error('Build the current module with npm run module:build or npm run bindings before running this proof.');
  return staged;
}

export function stageRustArtifacts(target, paths, root = projectRoot) {
  const destination = join(root, '.local', 'rust-artifacts');
  assertNoLinks(projectRoot, destination);
  mkdirSync(destination, { recursive: true });
  // Validate every requested output before replacing any staged candidate.
  const outputs = paths.map(path => {
    const source = resolve(target, path);
    assertInside(target, source);
    assertNoLinks(projectRoot, source);
    if (!lstatSync(source).isFile()) throw new Error(`Missing Rust artifact: ${source}`);
    const output = join(destination, basename(source));
    assertNoLinks(projectRoot, output);
    return { source, output };
  });
  for (const { source, output } of outputs) {
    const ready = join(target, basename(source) + '.install');
    assertNoLinks(projectRoot, ready);
    copyFileSync(source, ready);
    const hash = path => createHash('sha256').update(readFileSync(path)).digest('hex');
    if (hash(source) !== hash(ready)) throw new Error(`Staged Rust artifact differs: ${output}`);
    renameSync(ready, output);
    console.log(`Ready to install/publish: ${relative(root, output)}`);
  }
}

export async function runBuildCommand(command, args, { cwd = projectRoot, env = process.env } = {}) {
  const child = spawn(command, args, { cwd, env, stdio: 'inherit', windowsHide: true, detached: process.platform !== 'win32' });
  let interrupted;
  let escalation;
  const stop = signal => {
    interrupted ??= signal;
    if (!child.pid || child.exitCode !== null || child.signalCode !== null) return;
    if (process.platform === 'win32') {
      spawnSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], { stdio: 'ignore', windowsHide: true });
    } else {
      try { process.kill(-child.pid, 'SIGTERM'); } catch (error) { if (error.code !== 'ESRCH') throw error; }
      escalation ??= setTimeout(() => { try { process.kill(-child.pid, 'SIGKILL'); } catch { /* Already stopped. */ } }, 5000);
    }
  };
  const interrupt = () => stop('SIGINT');
  const terminate = () => stop('SIGTERM');
  process.on('SIGINT', interrupt);
  process.on('SIGTERM', terminate);
  try {
    const code = await new Promise((resolveExit, reject) => {
      child.once('error', reject);
      child.once('close', (code, signal) => resolveExit(code ?? (signal === 'SIGINT' ? 130 : 143)));
    });
    return interrupted ? (interrupted === 'SIGINT' ? 130 : 143) : code;
  } finally {
    clearTimeout(escalation);
    process.off('SIGINT', interrupt);
    process.off('SIGTERM', terminate);
  }
}

export async function withRustBuild(action, { root = projectRoot } = {}) {
  root = realpathSync(root);
  if (root !== projectRoot) assertInside(projectRoot, root);
  const local = join(root, '.local');
  assertNoLinks(projectRoot, local);
  mkdirSync(local, { recursive: true });
  const target = mkdtempSync(join(local, 'rust-build-'));
  // Cargo requires a valid cache tag before it will clean an existing directory.
  writeFileSync(join(target, 'CACHEDIR.TAG'), 'Signature: 8a477f597d28d172789f06886806bc55\n');
  // Cleanup must also work when the project's Cargo.toml caused the build failure.
  const cleanupManifest = join(target, 'Cargo.toml');
  writeFileSync(cleanupManifest, '[workspace]\nmembers = []\n');
  const owner = { id: randomUUID(), root, target, pid: process.pid };
  writeFileSync(join(target, markerName), JSON.stringify(owner));
  const env = {
    ...process.env, CARGO_TARGET_DIR: target, FISHBOUND_RUST_TARGET_DIR: target, FISHBOUND_RUST_OWNER: owner.id,
    CARGO_INCREMENTAL: '0', CARGO_PROFILE_DEV_DEBUG: '0', CARGO_PROFILE_TEST_DEBUG: '0',
    CARGO_PROFILE_RELEASE_DEBUG: '0', CARGO_PROFILE_BENCH_DEBUG: '0',
    CARGO_PROFILE_DEV_STRIP: 'debuginfo', CARGO_PROFILE_TEST_STRIP: 'debuginfo',
    CARGO_PROFILE_RELEASE_STRIP: 'debuginfo', CARGO_PROFILE_BENCH_STRIP: 'debuginfo'
  };
  try {
    return await action({ target, env });
  } finally {
    verifyOwner(target, owner);
    // Reject links throughout this task-owned tree before Cargo removes it.
    const pending = [target];
    while (pending.length) {
      for (const entry of readdirSync(pending.pop(), { withFileTypes: true })) {
        const path = join(entry.parentPath, entry.name);
        if (entry.isSymbolicLink()) throw new Error(`Rust cleanup refuses a filesystem link: ${path}`);
        if (entry.isDirectory()) pending.push(path);
      }
    }
    const result = spawnSync('cargo', ['clean', '--manifest-path', cleanupManifest, '--target-dir', target], { cwd: root, env, stdio: 'inherit', windowsHide: true });
    if (result.error) throw result.error;
    if (result.status !== 0) throw new Error(`Rust cleanup failed; review ${target}`);
    if (existsSync(target)) rmdirSync(target); // Only an empty directory may remain.
  }
}
