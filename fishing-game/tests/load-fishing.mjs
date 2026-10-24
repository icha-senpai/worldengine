// Managed, bounded load proof. Never accepts a public host or an existing DB.
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { createInterface } from 'node:readline';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { monitorEventLoopDelay, performance } from 'node:perf_hooks';
import { withRustBuild, runBuildCommand } from '../scripts/rust-build.mjs';

const root = resolve(import.meta.dirname, '..');
process.chdir(root);
const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-load-${Date.now()}`;
assert.match(database, /^fishbound-proof-load-\d+$/);
const resultFile = resolve('.local/load-test-results.json');
const requested = process.argv.find(arg => arg.startsWith('--stages='))?.slice(9);
const stages = requested ? requested.split(',').map(Number) : [25, 100, 250];
assert(stages.length > 0 && stages.length <= 3 && new Set(stages).size === stages.length
  && stages.every(n => [25, 100, 250].includes(n)));
const report = { at: new Date().toISOString(), database, host: process.platform,
  isolation: 'Fresh local proof DB; no Discord API calls; no production data',
  delivery: 'Synthetic 50ms ACK and 250ms reply, not measured Discord latency',
  clients: 'Real TypeScript SDK with production core/view queries; not rendered browser tabs',
  population: 'Fresh T1 Meadow Pond anglers, first-cast achievements, growing shared directories; no mature inventories',
  node: process.version,
  sourceCommit: spawnSync('git', ['rev-parse', 'HEAD'], { encoding: 'utf8' }).stdout.trim(),
  scenarios: [], cleanup: false };
const databasePid = process.platform === 'win32' ? Number(spawnSync('powershell.exe', ['-NoProfile', '-Command',
  '(Get-NetTCPConnection -LocalPort 3127 -State Listen | Select-Object -First 1).OwningProcess'],
  { encoding: 'utf8', windowsHide: true, timeout: 10_000 }).stdout?.trim()) : null;
report.databasePid = databasePid;
const allConnections = new Set();
let interrupted = false, activeDriver = null;
function interrupt() { interrupted = true; activeDriver?.kill(); }
process.on('SIGINT', interrupt);
process.on('SIGTERM', interrupt);
let serial = 1n;
const snowflake = () => ((BigInt(Date.now()) - 1420070400000n) << 22n) | serial++;
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
async function processSample(pids) {
  if (process.platform !== 'win32') return [];
  assert(pids.every(pid => Number.isSafeInteger(pid) && pid > 0));
  return new Promise(resolve => {
    const child = spawn('powershell.exe', ['-NoProfile', '-Command',
      `Get-Process -Id ${pids.join(',')} -ErrorAction SilentlyContinue | Select-Object Id,ProcessName,WorkingSet64,PeakWorkingSet64,CPU | ConvertTo-Json -Compress`],
      { windowsHide: true, stdio: ['ignore', 'pipe', 'ignore'] });
    let output = '';
    const timeout = setTimeout(() => child.kill(), 5000);
    child.stdout.on('data', bytes => { output += bytes; });
    child.on('error', () => { clearTimeout(timeout); resolve([]); });
    child.on('close', () => { clearTimeout(timeout); try { const data = JSON.parse(output); resolve(Array.isArray(data) ? data : [data]); } catch { resolve([]); } });
  });
}
const stats = values => {
  if (!values.length) return null;
  const sorted = [...values].sort((a, b) => a - b);
  const at = p => +sorted[Math.max(0, Math.ceil(sorted.length * p) - 1)].toFixed(2);
  return { samples: values.length, p50: at(.5), p95: at(.95), p99: at(.99), max: at(1) };
};
function cli(args) {
  // CLI arguments are fixed or generated here, never arbitrary shell input.
  const command = process.platform === 'win32'
    ? resolve(process.env.LOCALAPPDATA, 'SpacetimeDB/bin/2.12.0/spacetimedb-cli.exe') : 'spacetime';
  const result = spawnSync(command, args, { encoding: 'utf8', windowsHide: true,
    timeout: 60_000 });
  if (result.status !== 0) throw new Error(`Proof CLI failed: ${result.stderr || result.stdout || result.error}`);
  return result.stdout;
}
function query(sql) {
  return JSON.parse(cli(['sql', '--server', uri, '--no-config', '--format', 'json', database, sql]))
    .flatMap(batch => batch.rows.map(row => Object.fromEntries(row.map((v, i) => [batch.schema.elements[i].name.some, v]))));
}
function ownerGrant(identity, role) {
  const identityJson = JSON.stringify(identity.toHexString());
  const roleJson = JSON.stringify({ [role]: {} });
  cli(['call', '--server', uri, '--no-config', '--yes', database, 'configure_service', identityJson, roleJson, 'true']);
}
async function until(predicate, label, milliseconds = 30_000) {
  const limit = performance.now() + milliseconds;
  while (!predicate()) {
    if (interrupted) throw new Error('Load proof interrupted');
    if (performance.now() > limit) throw new Error(`Timeout: ${label}`);
    await sleep(10);
  }
}
async function connect(DbConnection, onDisconnect = () => {}) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('Connection timeout')), 30_000);
    const connection = DbConnection.builder().withUri(uri).withDatabaseName(database)
      .onConnect((connection, identity, token) => { clearTimeout(timer); resolve({ connection, identity, token }); })
      .onConnectError((_, error) => { clearTimeout(timer); reject(error); })
      .onDisconnect(onDisconnect).build();
    allConnections.add(connection);
  });
}
async function subscribe(connection, queries) {
  await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('Subscription timeout')), 30_000);
    connection.subscriptionBuilder().onApplied(() => { clearTimeout(timer); resolve(); })
      .onError(ctx => { clearTimeout(timer); reject(ctx.event); }).subscribe(queries);
  });
}
async function scenario({ DbConnection, coreQueries, viewQueries, bot, linker, binary, env, users, spacing, firstUser }) {
  const website = [], samples = [], records = [], nativeEvents = [], observerFailures = [], resources = [];
  const loop = monitorEventLoopDelay({ resolution: 20 });
  loop.enable();
  const startUsers = performance.now();
  let disconnects = 0, closing = false;
  // Limit setup concurrency; the measured burst begins only when all views are ready.
  for (let start = 0; start < users; start += 10) {
    await Promise.all(Array.from({ length: Math.min(10, users - start) }, async (_, offset) => {
      const index = start + offset, user = firstUser + index;
      const began = performance.now();
      const { connection } = await connect(DbConnection, () => { if (!closing) disconnects++; });
      const observer = { connection, user, view: ['camp', 'inventory', 'records', 'anglers'][index % 4], seenAt: null };
      website.push(observer);
      const changed = player => {
        if (player.completedCasts === 1n && observer.seenAt === null) {
          observer.seenAt = Date.now();
        }
      };
      // Scoped views can reconcile as delete/insert rather than an update.
      connection.db.myPlayer.onInsert((_ctx, player) => changed(player));
      connection.db.myPlayer.onUpdate((_ctx, _old, player) => changed(player));
      await subscribe(connection, [...new Set([...coreQueries, ...viewQueries(observer.view, undefined, 9)])]);
      await connection.reducers.beginLinkChallenge({});
      await until(() => connection.db.myLinkChallenge.count() === 1n, 'browser link challenge');
      const challenge = [...connection.db.myLinkChallenge.iter()][0];
      await linker.connection.reducers.completeAccountLink({ challengeId: challenge.challengeId, proof: challenge.proof, verifiedDiscordUserId: BigInt(user) });
      await until(() => connection.db.myPlayer.count() === 1n, 'linked browser player');
      observer.setupMs = performance.now() - began;
    }));
  }
  const baseline = process.memoryUsage().rss;
  const began = performance.now();
  const memoryTimer = setInterval(() => samples.push(process.memoryUsage().rss), 100);
  let child, resourceTimer, sampling;
  try {
    child = spawn(binary, [], { cwd: root, windowsHide: true, env: { ...env,
      TEST_SPACETIMEDB_URI: uri, TEST_SPACETIMEDB_DATABASE: database, TEST_BOT_TOKEN: bot.token,
      LOAD_USERS: String(users), LOAD_FIRST_USER: String(firstUser), LOAD_SPACING_MS: String(spacing),
    }, stdio: ['ignore', 'pipe', 'pipe'] });
    activeDriver = child;
    const sample = () => {
      if (sampling || !databasePid || !child.pid) return;
      sampling = processSample([databasePid, child.pid]).then(rows => { resources.push(...rows); }).finally(() => { sampling = null; });
    };
    sample();
    resourceTimer = setInterval(sample, 2000);
    let stderr = '';
    child.stderr.on('data', bytes => { stderr = (stderr + bytes).slice(-4000); });
    const timeout = setTimeout(() => child.kill(), 90_000);
    const done = new Promise((yes, no) => {
      child.once('error', no);
      child.once('close', code => code === 0 ? yes() : no(new Error(`Load driver exit ${code}: ${stderr}`)));
    });
    for await (const line of createInterface({ input: child.stdout })) {
      const event = JSON.parse(line);
      nativeEvents.push(event);
      if (event.type === 'result') records.push(event);
    }
    await done.finally(() => clearTimeout(timeout));
    clearInterval(resourceTimer);
    await sampling;
    assert.equal(records.length, users, 'Every submitted command has an explicit outcome');
    const accepted = records.filter(r => r.status === 'ok');
    await until(() => website.filter(w => w.seenAt !== null).length >= accepted.length, 'all accepted catches reach website views');
    const outcomes = Object.fromEntries(['ok', 'busy', 'game-error'].map(status => [status, records.filter(r => r.status === status).length]));
    for (const observer of website) {
      const result = records.find(r => r.user === observer.user);
      const player = [...observer.connection.db.myPlayer.iter()][0];
      if (player.completedCasts !== BigInt(result.status === 'ok' ? 1 : 0)) observerFailures.push(`Unexpected cast count for synthetic user ${observer.user}`);
      if (result.status === 'ok' && player.playerId !== BigInt(result.player)) observerFailures.push('Cross-player result mismatch');
      if (result.status === 'ok' && observer.view === 'inventory'
        && observer.connection.db.myInventory.count() !== BigInt(player.keptCount)) observerFailures.push('Inventory view did not reconcile');
    }
    // Exercise replay and cooldown protection under the accumulated test data.
    for (const result of accepted.slice(0, 3)) {
      const interactionId = BigInt(result.interaction), discordUserId = BigInt(result.user);
      await bot.connection.reducers.selectDiscordPlayer({ discordUserId, displayName: `Load ${result.user}`, interactionId });
      await bot.connection.reducers.fishFromDiscord({ discordUserId, interactionId, guildId: 1n, channelId: 1n });
      const races = await Promise.allSettled([0, 1].map(() => bot.connection.reducers.fishFromDiscord({ discordUserId, interactionId: snowflake(), guildId: 1n, channelId: 1n })));
      assert(races.every(r => r.status === 'rejected' && String(r.reason).includes('COOLDOWN_ACTIVE')), 'Concurrent repeat casts must not bypass cooldown');
    }
    const result = { users, spacingMs: spacing, setupSeconds: +( (began - startUsers) / 1000).toFixed(2), durationSeconds: +((performance.now() - began) / 1000).toFixed(2),
      outcomes, viewMix: Object.fromEntries(['camp','inventory','records','anglers'].map(view => [view, website.filter(w => w.view === view).length])),
      ackMsSynthetic: stats(records.map(r => r.ack_ms)), gameMs: stats(accepted.map(r => r.game_ms)), artworkMs: stats(accepted.map(r => r.art_ms)),
      totalMsWithSyntheticDelivery: stats(accepted.map(r => r.total_ms)),
      websiteRequestToViewMs: stats(website.filter(w => w.seenAt !== null).map(w => w.seenAt - nativeEvents.find(r => r.type === 'cast-start' && r.user === w.user).at_ms)),
      browserSetupMs: stats(website.map(w => w.setupMs)),
      artworkFallbacks: accepted.reduce((sum, r) => sum + r.art_errors, 0), attachmentBytes: accepted.reduce((sum, r) => sum + r.attachment_bytes, 0),
      errors: records.filter(r => r.status === 'game-error').map(r => r.error), portraitErrors: nativeEvents.filter(r => r.type === 'portrait-error').length,
      observerFailures, generatorRssBeforeBurstMb: +(baseline / 1048576).toFixed(1), generatorPeakRssMb: +(Math.max(baseline, ...samples) / 1048576).toFixed(1),
      generatorEventLoopP99Ms: +(loop.percentile(99) / 1e6).toFixed(2),
      processMemory: [...new Set(resources.map(row => row.ProcessName))].map(name => ({
        name, samples: resources.filter(row => row.ProcessName === name).length,
        peakObservedRssMb: +(Math.max(...resources.filter(row => row.ProcessName === name).map(row => row.WorkingSet64)) / 1048576).toFixed(1),
      })),
      disconnects, replayAndCooldownChecks: Math.min(3, accepted.length),
    };
    report.scenarios.push(result);
    console.log(JSON.stringify(result));
  } catch (error) {
    report.scenarioFailure = { users, spacingMs: spacing, error: String(error), records,
      websiteUpdated: website.filter(w => w.seenAt !== null).length,
      websiteFinalCounts: website.map(w => [...w.connection.db.myPlayer.iter()].map(p => String(p.completedCasts))) };
    throw error;
  } finally {
    closing = true;
    if (child?.exitCode === null) child.kill();
    activeDriver = null;
    clearInterval(memoryTimer);
    clearInterval(resourceTimer);
    loop.disable();
    for (const observer of website) { observer.connection.disconnect(); allConnections.delete(observer.connection); }
    await sleep(300);
  }
}

await withRustBuild(async ({ target, env }) => {
  let published = false;
  try {
    const buildCommands = [
      ['build', '--manifest-path', 'spacetimedb/Cargo.toml', '--target', 'wasm32-unknown-unknown', '--release', '--locked'],
      ['build', '-p', 'discord-bot', '--example', 'load-fishing', '--release', '--locked'],
    ];
    for (const args of buildCommands) {
      if (await runBuildCommand('cargo', args, { env })) throw new Error('Managed load-test build failed');
    }
    const { DbConnection } = await import('../packages/generated/src/index.ts');
    const { coreQueries, viewQueries } = await import('../apps/web/src/lib/game/subscriptions.ts');
    const module = resolve(target, 'wasm32-unknown-unknown/release/fishing_game_module.wasm');
    const existing = await fetch(`${uri}/v1/database/${database}`);
    assert.equal(existing.status, 404, 'Refuse to reuse an existing proof database');
    cli(['publish', '--server', uri, '--no-config', '--yes=skip-login', '--delete-data=never', '--bin-path', module, database]);
    published = true;
    const bot = await connect(DbConnection), linker = await connect(DbConnection);
    ownerGrant(bot.identity, 'discordAdapter'); ownerGrant(linker.identity, 'accountLinker');
    await subscribe(bot.connection, ['SELECT * FROM my_service']);
    const binary = resolve(target, 'release/examples/load-fishing' + (process.platform === 'win32' ? '.exe' : ''));
    let firstUser = 7_000_000;
    for (const users of stages) {
      for (const spacing of [0, 100]) {
        if (interrupted) throw new Error('Load proof interrupted');
        console.log(`LOAD START ${users} anglers, ${spacing ? '10 casts/second' : 'simultaneous burst'}, ${users} website subscribers`);
        await scenario({ DbConnection, coreQueries, viewQueries, bot, linker, binary, env, users, spacing, firstUser });
        firstUser += users;
      }
    }
    const players = query('SELECT completed_casts, fish_count, junk_count, treasure_count FROM player');
    const accepted = report.scenarios.reduce((sum, s) => sum + s.outcomes.ok, 0);
    const castCount = players.reduce((sum, p) => sum + p.completed_casts, 0);
    const pulls = players.reduce((sum, p) => sum + p.fish_count + p.junk_count + p.treasure_count, 0);
    const journal = query('SELECT key FROM journal_entry');
    const receipts = query('SELECT interaction_id FROM command_receipt');
    report.integrity = { accepted, casts: castCount, pulls, journalEntries: journal.length, receipts: receipts.length, duplicateJournalKeys: journal.length - new Set(journal.map(r => r.key)).size };
    assert.equal(castCount, accepted); assert.equal(receipts.length, accepted); assert.equal(journal.length, pulls);
    assert.equal(report.integrity.duplicateJournalKeys, 0);
  } catch (error) {
    report.error = String(error);
    throw error;
  } finally {
    for (const connection of allConnections) connection.disconnect();
    await sleep(300);
    if (published) {
      try {
        cli(['delete', '--server', uri, '--no-config', '--yes', database]);
        report.cleanup = true;
      } catch (error) {
        report.cleanupError = String(error);
      }
    }
    mkdirSync(resolve('.local'), { recursive: true });
    writeFileSync(resultFile, JSON.stringify(report, null, 2) + '\n');
    console.log(`Bounded latest results: ${resultFile}; proof deleted: ${report.cleanup}`);
    if (published && !report.cleanup) throw new Error(`Proof cleanup failed; review ${resultFile}`);
  }
  assert(report.scenarios.every(s => s.outcomes['game-error'] === 0 && s.observerFailures.length === 0), 'Unexpected load failures; review results');
  return 0;
}).finally(() => {
  process.off('SIGINT', interrupt);
  process.off('SIGTERM', interrupt);
});
