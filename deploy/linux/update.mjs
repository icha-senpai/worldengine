import { spawn, execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { access, chmod, copyFile, lstat, mkdir, readFile, rename, unlink, writeFile } from 'node:fs/promises';
import { homedir } from 'node:os';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import https from 'node:https';
import { parseEnv } from 'node:util';
import { buildWebsite, publishWebsite } from './publish.mjs';
import { deploymentPlan, groups, includeDependencies, publishArguments, sourceFingerprint } from './update-plan.mjs';
import { checkedBindings } from './bindings.mjs';

const scripts = dirname(fileURLToPath(import.meta.url));
const root = process.env.DATAVERSE_CHECKOUT || resolve(scripts, '../..');
const stateDirectory = resolve(homedir(), '.local/state/dataverse-update');
const statePath = resolve(stateDirectory, 'state.json');
const pendingPath = resolve(stateDirectory, 'pending.json');
const maintenance = '/var/www/dataverse/.maintenance';
const serviceEnv = { ...process.env, XDG_RUNTIME_DIR: `/run/user/${process.getuid?.()}`,
  DBUS_SESSION_BUS_ADDRESS: `unix:path=/run/user/${process.getuid?.()}/bus` };
const allServices = ['dataverse-space-db', 'dataverse-space-collector', 'dataverse-fish-db',
  'dataverse-fish-web', 'dataverse-fish-linker', 'dataverse-fish-bot'];
const wait = ms => new Promise(done => setTimeout(done, ms));
const digest = bytes => createHash('sha256').update(bytes).digest('hex');
const git = args => execFileSync('git', args, { cwd: root, encoding: 'utf8', maxBuffer: 20 * 1024 * 1024 }).trim();
function deploymentEvent(event, components) {
  const record = JSON.stringify({ type: 'dataverse-deployment', at: new Date().toISOString(), event, commit: git(['rev-parse', '--short=12', 'HEAD']), components });
  console.log(record);
  try { execFileSync('logger', ['--tag', 'dataverse-update', '--', record], { stdio: 'ignore' }); } catch { /* Diagnostics must not block an update. */ }
}
async function exists(path) {
  try { await lstat(path); return true; } catch (error) { if (error.code === 'ENOENT') return false; throw error; }
}
async function regular(path) {
  if ((await lstat(path)).isSymbolicLink()) throw new Error(`Linked deployment path refused: ${path}`);
}
async function json(path, fallback = {}) {
  if (!await exists(path)) return fallback;
  await regular(path);
  return JSON.parse(await readFile(path, 'utf8'));
}
async function atomicJson(path, data) {
  if (await exists(path)) await regular(path);
  const stage = path + '.new';
  await writeFile(stage, JSON.stringify(data, null, 2) + '\n', { mode: 0o600 });
  await rename(stage, path);
}
async function run(command, args, cwd = root, env = process.env) {
  await new Promise((accept, reject) => {
    const child = spawn(command, args, { cwd, env, stdio: 'inherit' });
    child.once('error', reject);
    child.once('close', code => code === 0 ? accept() : reject(new Error(`${command} failed (${code}).`)));
  });
}
function services(action, names) {
  if (names.length) execFileSync('systemctl', ['--user', action, ...names.map(name => name + '.service')], { env: serviceEnv, stdio: 'inherit' });
}
async function retry(description, check, timeout = 90000) {
  const until = Date.now() + timeout;
  while (Date.now() < until) {
    try { if (await check()) return; } catch { /* Wait for the owned service. */ }
    await wait(1000);
  }
  throw new Error(`${description} did not become healthy.`);
}
const healthyHttp = async url => (await fetch(url, { signal: AbortSignal.timeout(3000) })).ok;
async function databaseInfo(module) {
  const response = await fetch(`http://127.0.0.1:${module.port}/v1/database/${module.database}`, { signal: AbortSignal.timeout(15000) });
  if (!response.ok) throw new Error(`${module.database}: existing production database is unavailable.`);
  const info = await response.json();
  if (info.database_identity.__identity__.replace(/^0x/, '') !== module.identity) throw new Error(`${module.database}: production identity mismatch.`);
  return info;
}
async function productionEnvironment(path) {
  await regular(path);
  return { ...process.env, ...parseEnv(await readFile(path, 'utf8')) };
}
async function originHealthy(host) {
  return new Promise((accept, reject) => {
    const request = https.get({ hostname: '127.0.0.1', servername: host, headers: { Host: host }, path: '/', timeout: 5000 }, response => {
      response.resume(); accept(response.statusCode === 200);
    });
    request.on('error', reject);
    request.on('timeout', () => request.destroy(new Error('Origin health timeout.')));
  });
}
async function checkServices() {
  for (const name of allServices) await retry(name, () => {
    try { execFileSync('systemctl', ['--user', 'is-active', '--quiet', name + '.service'], { env: serviceEnv }); return true; } catch { return false; }
  });
  for (const port of [3100, 3127]) await retry(`Database ${port}`, () => healthyHttp(`http://127.0.0.1:${port}/v1/ping`));
  await retry('Fishbound website', () => healthyHttp('http://127.0.0.1:5173/'));
  await retry('Account linker', () => healthyHttp('http://127.0.0.1:3001/health/ready'));
  await retry('Collector authentication', async () => {
    const current = Number(execFileSync('systemctl', ['--user', 'show', 'dataverse-space-collector.service', '-p', 'MainPID', '--value'], { env: serviceEnv, encoding: 'utf8' }));
    const ready = await json(resolve(root, 'space/.runtime/services/collector.ready.json'));
    return current > 0 && ready.pid === current && ready.database === 'space-bitcraft-tools';
  });
  await access(resolve(root, 'fishing-game/.runtime/bin/discord-bot'));
  await access('/var/www/dataverse/bitcraft/index.html');
}
async function installNative(name) {
  const source = resolve(root, 'fishing-game/.local/rust-artifacts', name);
  const output = resolve(root, 'fishing-game/.runtime/bin', name);
  await regular(source);
  const magic = (await readFile(source)).subarray(0, 4);
  if (!magic.equals(Buffer.from([0x7f, 0x45, 0x4c, 0x46]))) throw new Error('Expected a Linux native program.');
  if (await exists(output)) await regular(output);
  const stage = output + '.new';
  await copyFile(source, stage);
  await chmod(stage, 0o700);
  if (digest(await readFile(source)) !== digest(await readFile(stage))) throw new Error('Native install hash mismatch.');
  await rename(stage, output);
}

async function main() {
  if (process.platform !== 'linux' || process.env.DATAVERSE_UPDATE_LOCKED !== '1') throw new Error('Run the Linux dataverse-update command.');
  const args = process.argv.slice(2);
  if (args.some(arg => !['--no-pull', '--plan', '--force'].includes(arg))) throw new Error('Unknown update option.');
  await mkdir(stateDirectory, { recursive: true, mode: 0o700 });
  await regular(stateDirectory);
  const release = await json(resolve(scripts, 'release.json'));
  const cli = `/stdb/bin/${release.spacetimeVersion}/spacetimedb-cli`;
  const config = resolve(homedir(), '.config/spacetime-production/cli.toml');
  await access(config);
  const cliVersion = execFileSync(cli, ['--version'], { encoding: 'utf8' });
  if (!cliVersion.includes(`tool version ${release.spacetimeVersion};`)) throw new Error('Installed SpacetimeDB CLI does not match the release.');
  const cliRun = args => run(cli, ['--config-path', config, ...args]);
  const previous = await json(statePath);
  const pending = await json(pendingPath, null);
  const tracked = execFileSync('git', ['ls-files', '-z'], { cwd: root, maxBuffer: 20 * 1024 * 1024 }).toString().split('\0').filter(Boolean);
  const files = new Map();
  for (const name of tracked.filter(name => name.startsWith('space/') || name.startsWith('fishing-game/') || name.startsWith('deploy/linux/') || name.startsWith('shared/connection-'))) {
    await regular(resolve(root, name));
    files.set(name, digest(await readFile(resolve(root, name))));
  }
  const extras = {
    spaceWeb: 'space/.runtime/production-web.env', fishWeb: 'fishing-game/.local/production-web.env',
    collector: 'space/.runtime/production-collector.env', native: 'fishing-game/.local/production-services.env',
  };
  const fingerprints = {};
  for (const [key, prefixes] of Object.entries(groups)) {
    const extra = extras[key] ? await readFile(resolve(root, extras[key])) : '';
    const module = release.modules.find(module => module.key === key);
    fingerprints[key] = sourceFingerprint(files, prefixes, `${process.version}\0${extra}\0${module ? JSON.stringify(module) : ''}`);
  }
  const plan = deploymentPlan(fingerprints, previous.fingerprints, args.includes('--force'));
  // Refuse to silently target another database; detect code changed outside this updater.
  for (const module of release.modules) {
    const info = await databaseInfo(module);
    if (previous.programs?.[module.key] && previous.programs[module.key] !== info.initial_program) plan[module.key] = true;
    if (pending?.modules.includes(module.key)) plan[module.key] = true;
  }
  includeDependencies(plan);
  const changed = Object.keys(plan).filter(key => plan[key]);
  console.log(changed.length ? `Updating: ${changed.join(', ')}.` : 'Source and production environments are already current.');
  if (args.includes('--plan')) return;
  if (await exists(maintenance) && !pending) throw new Error('Maintenance was enabled outside this updater; leaving it intact.');
  const space = resolve(root, 'space'), fish = resolve(root, 'fishing-game');
  const collectorEnv = await productionEnvironment(resolve(space, '.runtime/production-collector.env'));
  const fishBuildEnv = { ...process.env, FISHBOUND_TYPESCRIPT_CLI: cli,
    FISHBOUND_RUST_CLI: '/stdb/bin/2.10.2/spacetimedb-cli' };
  if (changed.length) console.log('Building and checking candidates before switching production.');
  if (plan.spaceWeb) await buildWebsite('space', root);
  else if (plan.collector || plan.bitcraft || plan.evergather) await run('npm', ['ci', '--include=dev'], space);
  if (plan.bitcraft || plan.evergather) {
    for (const name of ['bitcraft', 'spacetimedb']) await run('npm', ['ci', '--include=dev'], resolve(space, name));
    await checkedBindings(root, ['space/src/bindings'], () => run('npm', ['run', 'generate'], space, collectorEnv));
  }
  if (plan.collector) await run('npm', ['run', 'typecheck:scripts'], space);
  if (plan.fishModule || plan.native || plan.fishWeb) await run('npm', ['ci', '--include=dev'], fish);
  if (plan.fishModule) {
    await run('npm', ['run', 'content:validate'], fish);
    await checkedBindings(root, ['fishing-game/packages/generated', 'fishing-game/crates/game-client/src'],
      () => run('npm', ['run', 'bindings'], fish, fishBuildEnv));
  }
  if (plan.native) await run('npm', ['run', 'rust:build'], fish, fishBuildEnv);
  if (plan.fishWeb) await buildWebsite('fishbound', root);
  const modules = release.modules.filter(module => plan[module.key]);
  let applying = false;
  try {
    if (changed.length) {
      await atomicJson(pendingPath, { commit: git(['rev-parse', 'HEAD']), modules: modules.map(module => module.key) });
      applying = true;
      if (await exists(maintenance)) await regular(maintenance);
      await writeFile(maintenance, '', { mode: 0o600 });
      // NGINX's worker needs to be able to stat the flag; its content is empty.
      await chmod(maintenance, 0o644);
      deploymentEvent('maintenance-start', changed);
      const stop = [];
      if (plan.collector || plan.bitcraft || plan.evergather) stop.push('dataverse-space-collector');
      if (plan.native || plan.fishModule) stop.push('dataverse-fish-bot', 'dataverse-fish-linker');
      if (plan.fishWeb || plan.fishModule) stop.push('dataverse-fish-web');
      services('stop', stop);
      if (plan.services) await run(process.execPath, [resolve(scripts, 'install-user-services.mjs')]);
      // Drop old public WebSockets before capturing protected state. Reconnects see maintenance.
      const databases = [];
      if (plan.bitcraft || plan.evergather) databases.push('dataverse-space-db');
      if (plan.fishModule) databases.push('dataverse-fish-db');
      services('restart', databases);
      for (const module of modules) {
        await retry(module.database, async () => { await databaseInfo(module); return true; });
        const proof = resolve(stateDirectory, module.key + '-proof.json');
        if (!await exists(proof)) await run('python3', [resolve(scripts, 'database.py'), 'capture', module.key, proof]);
        await regular(proof);
        await cliRun(publishArguments(module));
        await run('python3', [resolve(scripts, 'database.py'), 'verify', module.key, proof]);
        if (module.key === 'bitcraft') await run(process.execPath, ['--import', 'tsx', resolve(scripts, 'space-module.mjs'), 'seal'], space, collectorEnv);
        for (const [name, ...values] of module.hooks) {
          await cliRun(['call', module.database, name, ...values.map(value => JSON.stringify(value)), '--server', `http://127.0.0.1:${module.port}`, '--no-config']);
        }
        await run('python3', [resolve(scripts, 'database.py'), 'verify', module.key, proof]);
      }
      if (plan.native) for (const name of ['discord-bot', 'account-link']) await installNative(name);
      const accountEnvironment = await productionEnvironment(resolve(fish, '.local/production-services.env'));
      if (accountEnvironment.EVERGATHER_WEBSITE_URL && (plan.native || plan.evergather))
        await run(process.execPath, ['--import', 'tsx', 'scripts/configure-discord-linker.ts'], space,
          { ...accountEnvironment, SPACETIME_CLI: cli, SPACETIME_CONFIG_FILE: config });
      if (plan.spaceWeb) await publishWebsite('space', root);
      if (plan.fishWeb) await publishWebsite('fishbound', root);
    }
    services('start', allServices);
    await checkServices();
    if (applying) {
      await regular(maintenance);
      await unlink(maintenance);
      deploymentEvent('maintenance-end', changed);
    }
    for (const host of ['bitcraft.ichaa.dev', 'fishbound.ichaa.dev']) await retry(host, () => originHealthy(host), 15000);
    const programs = {};
    for (const module of release.modules) programs[module.key] = (await databaseInfo(module)).initial_program;
    await atomicJson(statePath, { commit: git(['rev-parse', 'HEAD']), fingerprints, programs });
    for (const module of modules) {
      const proof = resolve(stateDirectory, module.key + '-proof.json');
      if (await exists(proof)) { await regular(proof); await unlink(proof); }
    }
    if (await exists(pendingPath)) { await regular(pendingPath); await unlink(pendingPath); }
    console.log('Update complete. Both public sites and production services are healthy.');
  } catch (error) {
    if (applying) {
      deploymentEvent('update-failed', changed);
      await writeFile(maintenance, '', { mode: 0o644 });
      services('stop', ['dataverse-space-collector', 'dataverse-fish-bot', 'dataverse-fish-linker', 'dataverse-fish-web']);
      console.error('Update stopped safely. Public maintenance remains enabled; private preservation fingerprints are retained. Fix the reported failure and run dataverse-update again.');
    }
    throw error;
  }
}
main().catch(error => { console.error(error.message); process.exitCode = 1; });
