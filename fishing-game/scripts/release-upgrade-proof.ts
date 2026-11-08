import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { DbConnection } from '../packages/generated/src/index';
import { moduleArtifactPath, requireRustWorkflow } from './rust-build.mjs';

requireRustWorkflow();
const root = resolve(import.meta.dirname, '..');
const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-release-${Date.now()}`;
const output = join(root, '.local/prod-readiness');
const statePath = join(output, 'release-upgrade-state.json');
const cli = process.env.FISHBOUND_TYPESCRIPT_CLI
  ?? join(process.env.LOCALAPPDATA!, 'SpacetimeDB/bin/2.12.0/spacetimedb-cli.exe');
const release = JSON.parse(await readFile(join(root, '../deploy/linux/release.json'), 'utf8'))
  .modules.find((module: any) => module.key === 'fishModule');
const hooks: string[] = release.hooks.map((entry: unknown) => {
  assert(Array.isArray(entry) && entry.length === 1 && typeof entry[0] === 'string');
  return entry[0];
});
assert.deepEqual(hooks.slice(-4), [
  'activate_gear_foundation', 'activate_tideforge_effects',
  'activate_tideforge_economy', 'activate_live_calendar',
]);
const baseline = join(root, '.local/tideforge-foundation-proof/baseline.wasm');
const candidate = moduleArtifactPath();
const sha = (value: Uint8Array | string) => createHash('sha256').update(value).digest('hex');
const baselineHash = sha(await readFile(baseline));
const candidateHash = sha(await readFile(candidate));
assert.equal(baselineHash, '5bfec5461067dad86938dd118e0bc222cc66d6529a564bf87db67b24cffa9cfc');
assert.notEqual(candidateHash, baselineHash);
assert(/^fishbound-proof-release-\d+$/.test(database));
const allocation = { uri, database, baselineHash, candidateHash };
await writeFile(statePath, JSON.stringify({ ...allocation, phase: 'allocated' }), { flag: 'wx' });
const phase = (value: string) => writeFile(statePath, JSON.stringify({ ...allocation, phase: value }));
function run(args: string[]) {
  const result = spawnSync(cli, args, {
    cwd: root, encoding: 'utf8', windowsHide: true, timeout: 120_000,
    maxBuffer: 64 * 1024 * 1024,
  });
  if (result.error) throw result.error;
  assert.equal(result.status, 0, result.stderr || result.stdout);
  return result.stdout;
}
const publish = (wasm: string) => run([
  'publish', '--server', uri, '--no-config', '--yes=skip-login,migrate,break-clients',
  '--delete-data=never', '--bin-path', wasm, database,
]);
const call = (name: string, args: string[] = []) => run([
  'call', '--server', uri, '--no-config', '--yes', database, name, ...args,
]);
function decode(type: any, value: any): any {
  if (type.Product) return Object.fromEntries(type.Product.elements.map((field: any, index: number) =>
    [field.name.some, decode(field.algebraic_type, value[index])]));
  if (type.Sum) {
    const variant = type.Sum.variants[value[0]];
    if (variant.name.some === 'none') return null;
    const decoded = decode(variant.algebraic_type, value[1]);
    return variant.name.some === 'some' ? decoded : { [variant.name.some]: decoded };
  }
  return value;
}
function query(name: string) {
  assert(/^[a-z][a-z0-9_]*$/.test(name));
  const blocks = JSON.parse(run(['sql', '--server', uri, '--no-config', '--format', 'json',
    database, `SELECT * FROM ${name}`]), (...args: any[]) => {
    const [, value, context] = args;
    return typeof value === 'number' && !Number.isSafeInteger(value) ? context.source : value;
  });
  assert.equal(blocks.length, 1);
  const block = blocks[0];
  const columns: string[] = block.schema.elements.map((field: any) => field.name.some);
  const rows: Record<string, any>[] = block.rows.map((row: any[]) => Object.fromEntries(
    row.map((value, index) => [columns[index], decode(block.schema.elements[index].algebraic_type, value)])));
  return { columns, rows };
}
const names = (): string[] => JSON.parse(run([
  'describe', '--server', uri, '--no-config', '--json', database, 'tables',
])).map((table: any) => table.source_name).sort();
type Capture = Record<string, { columns: string[]; hashes: string[] }>;
const hashes = (rows: Record<string, any>[], columns: string[]) =>
  rows.map(row => sha(JSON.stringify(columns.map(column => row[column])))).sort();
function capture(): Capture {
  return Object.fromEntries(names().filter(name => !release.mutableTables.includes(name)).map(name => {
    const table = query(name);
    const columns = table.columns.filter(column => !(release.mutableColumns[name] ?? []).includes(column));
    return [name, { columns, hashes: hashes(table.rows, columns) }];
  }));
}
function verify(before: Capture, exact = false) {
  const installed = names();
  for (const [name, saved] of Object.entries(before)) {
    assert(installed.includes(name), `${name} disappeared`);
    const table = query(name);
    assert(saved.columns.every(column => table.columns.includes(column)), `${name} lost a column`);
    const actual = hashes(table.rows, saved.columns);
    if (exact) assert.deepEqual(actual, saved.hashes, `${name} changed on repeated activation`);
    else {
      const counts = new Map<string, number>();
      for (const hash of actual) counts.set(hash, (counts.get(hash) ?? 0) + 1);
      for (const hash of saved.hashes) {
        assert((counts.get(hash) ?? 0) > 0, `${name} changed or lost a retained record`);
        counts.set(hash, counts.get(hash)! - 1);
      }
    }
  }
}
async function identity(name: string) {
  const response = await fetch(`${uri}/v1/database/${name}`, { signal: AbortSignal.timeout(10_000) });
  assert(response.ok);
  return (await response.json()).database_identity.__identity__ as string;
}
let client: DbConnection | undefined;
let serial = 1n;
const snowflake = () => ((BigInt(Date.now()) - 1420070400000n) << 22n) | serial++;
try {
  publish(baseline);
  const proofIdentity = await identity(database);
  assert.notEqual(proofIdentity, await identity('fishbound-dev-local'));
  await phase('baseline-published');
  client = await new Promise<DbConnection>((yes, no) => {
    const timer = setTimeout(() => no(new Error('Proof connection timed out')), 25_000);
    DbConnection.builder().withUri(uri).withDatabaseName(database)
      .onConnect(connection => { clearTimeout(timer); yes(connection); })
      .onConnectError((_, error) => { clearTimeout(timer); no(error); }).build();
  });
  call('configure_service', [JSON.stringify(client.identity!.toHexString()), JSON.stringify({ discordAdapter: {} }), 'true']);
  for (const discordUserId of [989301n, 989302n, 989303n, 989304n]) {
    await client.reducers.selectDiscordPlayer({
      discordUserId, interactionId: snowflake(), displayName: 'Isolated release fixture',
    });
    const request = { discordUserId, guildId: undefined, channelId: 42n };
    await client.reducers.fishFromDiscord({ ...request, interactionId: snowflake() });
    await client.reducers.dailyFromDiscord({ ...request, interactionId: snowflake() });
    if (discordUserId === 989301n) {
      await client.reducers.startHaulFromDiscord({ ...request, interactionId: snowflake(), kind: 'boat' });
    }
  }
  client.disconnect();
  client = undefined;
  const before = capture();
  for (const name of ['player', 'owned_rod', 'owned_haul_equipment', 'command_receipt', 'cast_equipment_receipt', 'haul_trip']) {
    assert(before[name]?.hashes.length > 0, `${name} fixture is empty`);
  }
  await phase('legacy-records-captured');
  publish(candidate);
  assert.equal(await identity(database), proofIdentity);
  verify(before);
  let foundation: Capture | undefined;
  for (const hook of hooks) {
    call(hook);
    if (hook === 'activate_gear_foundation') {
      foundation = capture();
      assert(query('gear_family_progress').rows.every(row => row.curve_version === 0));
      verify(before);
    }
  }
  assert(foundation);
  verify(before);
  verify(foundation);
  for (const name of ['gear_foundation_state', 'tideforge_state', 'tideforge_economy_state']) {
    const states = query(name).rows;
    assert.equal(states.length, 1);
    assert.equal(states[0].version, 1);
  }
  const progress = query('gear_family_progress').rows;
  assert(progress.length > 0 && progress.every(row => row.curve_version === 1 && row.mastery_xp === 0));
  const gear = query('gear_instance').rows;
  assert(gear.length > 0 && gear.every(row => row.protected_original && !row.favorite));
  assert(query('gear_activity_snapshot').rows.some(row => row.activity_kind === 'haul' && row.legacy_booking));
  assert.equal(query('calendar_job').rows.length, 1);
  const activated = capture();
  for (const hook of hooks) call(hook);
  verify(activated, true);
  assert(query('gear_family_progress').rows.every(row => row.curve_version === 1));
  const summarize = (value: Capture) => Object.fromEntries(Object.entries(value).map(([name, table]) =>
    [name, { rows: table.hashes.length, sha256: sha(table.hashes.join('\n')) }]));
  await writeFile(join(output, 'release-upgrade-results.json'), JSON.stringify({
    ...allocation, proofIdentity, status: 'passed', hooks,
    legacy: summarize(before), foundation: summarize(foundation), activated: summarize(activated),
    identityPreserved: true, legacyRowsPreserved: true, curveBackfillPreserved: true,
    repeatedHooksIdempotent: true, scheduledCalendar: true,
    retention: 'One isolated fishbound-proof-release database retained for review; no production data copied or deleted.',
  }, null, 2));
  await phase('passed');
  console.log(`PASS legacy schema upgrade, ${hooks.length} production hooks, curve-only backfill and idempotency; ${database}`);
} catch (error) {
  await phase('failed');
  throw error;
} finally {
  client?.disconnect();
}
