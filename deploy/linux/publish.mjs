import { chmod, cp, lstat, mkdir, readFile, readdir, rename, rm, writeFile } from 'node:fs/promises';
import { spawn, execFileSync } from 'node:child_process';
import { parseEnv } from 'node:util';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';


const checkout = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
function paths(app, root) {
  if (process.platform !== 'linux') throw new Error('This publisher is for Linux.');
  if (!['space', 'fishbound'].includes(app)) throw new Error('Choose space or fishbound.');
  const project = resolve(root, app === 'space' ? 'space' : 'fishing-game');
  const environmentPath = resolve(project, app === 'space' ? '.runtime/production-web.env' : '.local/production-web.env');
  return { project, environmentPath };
}
export async function buildWebsite(app, root = checkout) {
  const { project, environmentPath } = paths(app, root);
  const settings = parseEnv(await readFile(environmentPath, 'utf8'));
  const allowed = key => app === 'space' ? key.startsWith('VITE_') : key.startsWith('PUBLIC_') || ['HOST', 'PORT', 'NODE_ENV'].includes(key);
  if (Object.keys(settings).some(key => !allowed(key))) throw new Error('Unexpected private setting in public website environment.');
  const release = execFileSync('git', ['rev-parse', '--short=12', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim();
  const env = { ...process.env, ...settings, [app === 'space' ? 'VITE_DEPLOY_COMMIT' : 'PUBLIC_DEPLOY_COMMIT']: release };
  const run = (command, args) => new Promise((accept, reject) => {
    const child = spawn(command, args, { cwd: project, env, stdio: 'inherit' });
    child.on('error', reject);
    child.on('close', code => code === 0 ? accept() : reject(new Error(`${command} failed (${code}).`)));
  });
  await run('npm', ['ci', '--include=dev']);
  if (app === 'fishbound') await run('npm', ['run', 'check']);
  await run('npm', ['run', 'build']);

}
export async function publishWebsite(app, root = checkout) {
  const { project, environmentPath } = paths(app, root);
  const base = app === 'space' ? '/var/www/dataverse' : resolve(project, '.runtime');
  const current = resolve(base, app === 'space' ? 'bitcraft' : 'published-web');
  const stage = resolve(base, app === 'space' ? '.bitcraft-stage' : 'publish-web-stage');
  const previous = resolve(base, app === 'space' ? '.bitcraft-previous' : 'publish-web-previous');
  const source = resolve(project, app === 'space' ? 'dist' : 'apps/web/build');
  async function exists(path) {
    try { await lstat(path); return true; } catch (error) { if (error.code === 'ENOENT') return false; throw error; }
  }
  async function verifyTree(path) {
    if (!(await exists(path))) return;
    if ((await lstat(path)).isSymbolicLink()) throw new Error(`Linked publish path refused: ${path}`);
    for (const entry of await readdir(path, { withFileTypes: true })) {
      if (entry.isSymbolicLink()) throw new Error(`Linked build entry refused: ${entry.name}`);
      if (entry.isDirectory()) await verifyTree(resolve(path, entry.name));
    }
  }
  async function removeOwned(path) {
    if (![stage, previous].includes(path)) throw new Error('Unexpected publish cleanup target.');
    await verifyTree(path);
    await rm(path, { recursive: true, force: true });
  }
  await mkdir(base, { recursive: true });
  for (const path of [base, current, stage, previous]) {
    if ((await exists(path)) && (await lstat(path)).isSymbolicLink()) throw new Error(`Linked publishing directory refused: ${path}`);
  }
  if (await exists(previous)) throw new Error('An unfinished previous build requires inspection.');
  await verifyTree(source);
  await readFile(resolve(source, app === 'space' ? 'index.html' : 'index.js'));
  await removeOwned(stage);
  await cp(source, stage, { recursive: true });
  if (app === 'space') {
    const readable = async path => {
      const info = await lstat(path);
      if (info.isSymbolicLink()) throw new Error('Linked static asset refused.');
      await chmod(path, info.isDirectory() ? 0o755 : 0o644);
      if (info.isDirectory()) for (const name of await readdir(path)) await readable(resolve(path, name));
    };
    await readable(stage);
  }
  if (app === 'fishbound') {
    const release = execFileSync('git', ['rev-parse', '--short=12', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim();
    await writeFile(resolve(stage, '.env'), (await readFile(environmentPath, 'utf8')).trimEnd() + `\nPUBLIC_DEPLOY_COMMIT=${release}\n`, { mode: 0o600 });
  }
  const serviceEnv = { ...process.env, XDG_RUNTIME_DIR: `/run/user/${process.getuid()}`,
    DBUS_SESSION_BUS_ADDRESS: `unix:path=/run/user/${process.getuid()}/bus` };
  const service = action => execFileSync('systemctl', ['--user', action, 'dataverse-fish-web.service'], { env: serviceEnv, stdio: 'inherit' });
  let promoted = false;
  let stopped = false;
  try {
    if (app === 'fishbound') { service('stop'); stopped = true; }
    if (await exists(current)) await rename(current, previous);
    await rename(stage, current);
    promoted = true;
    if (app === 'fishbound') {
      service('start');
      let ready = false;
      for (let attempt = 0; attempt < 30; attempt++) {
        try { if ((await fetch('http://127.0.0.1:5173/')).ok) { ready = true; break; } } catch {}
        await new Promise(accept => setTimeout(accept, 1000));
      }
      if (!ready) throw new Error('The published website did not become healthy.');
    }
  } catch (error) {
    if (app === 'fishbound' && stopped) service('stop');
    if (promoted) {
      await verifyTree(current);
      await rm(current, { recursive: true });
    }
    if (await exists(previous)) await rename(previous, current);
    if (app === 'fishbound' && stopped && await exists(current)) service('start');
    throw error;
  }
  await removeOwned(previous);
  console.log(`${app} website published. Database modules and data were not changed.`);
}
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const app = process.argv[2];
  await buildWebsite(app);
  if (process.argv.includes('--build-only')) console.log(`${app} production build verified; published files were not changed.`);
  else await publishWebsite(app);
}
