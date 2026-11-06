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
const database = `fishbound-proof-luck-${Date.now()}`;
const statePath = join(root, '.local/luck-preservation-runtime.json');
const reportPath = join(root, '.local/luck-preservation-evidence.json');
const oldWasm = join(root, '.local/luck-proof-base.wasm');
const candidateWasm = moduleArtifactPath();
const cli = process.env.FISHBOUND_TYPESCRIPT_CLI
  ?? join(process.env.LOCALAPPDATA!, 'SpacetimeDB/bin/2.12.0/spacetimedb-cli.exe');
const connections: DbConnection[] = [];
const sha = (bytes: Uint8Array | string) => createHash('sha256').update(bytes).digest('hex');
assert(/^fishbound-proof-luck-\d+$/.test(database));
assert.equal(new URL(uri).hostname, '127.0.0.1');
assert.equal(new URL(uri).port, '3127');
const oldHash = sha(await readFile(oldWasm));
assert.equal(oldHash, '484733635e815021c69c4d4f5ca9f3839d94c183b771c15ce9173ac94a407afa');
const candidateHash = sha(await readFile(candidateWasm));
assert.notEqual(candidateHash, oldHash);
// Refuse another retained fixture rather than accumulating databases on reruns.
await writeFile(statePath, JSON.stringify({ uri, database, oldHash, candidateHash, phase: 'created' }), { flag: 'wx' });
const phase = (phase: string) => writeFile(statePath, JSON.stringify({ uri, database, oldHash, candidateHash, phase }));

function run(args: string[]) {
  const result = spawnSync(cli, args, { cwd: root, encoding: 'utf8', windowsHide: true, maxBuffer: 64 * 1024 * 1024 });
  if (result.error) throw result.error;
  assert.equal(result.status, 0, result.stderr || result.stdout);
  return result.stdout;
}
const publish = (wasm: string) => run([
  'publish', '--server', uri, '--no-config', '--yes=skip-login,migrate,break-clients',
  '--delete-data=never', '--bin-path', wasm, database,
]);
const call = (name: string, args: string[]) => run(['call', '--server', uri, '--no-config', '--yes', database, name, ...args]);
function decode(type: any, value: any): any {
  if (type.Product) return Object.fromEntries(type.Product.elements.map((field: any, index: number) => [
    field.name.some, decode(field.algebraic_type, value[index]),
  ]));
  if (type.Sum) {
    const variant = type.Sum.variants[value[0]];
    if (variant.name.some === 'none') return null;
    const decoded = decode(variant.algebraic_type, value[1]);
    return variant.name.some === 'some' ? decoded : { [variant.name.some]: decoded };
  }
  return value;
}
function query(table: string): Record<string, any>[] {
  assert(/^[a-z][a-z0-9_]*$/.test(table));
  const text = run(['sql', '--server', uri, '--no-config', '--format', 'json', database, `SELECT * FROM ${table}`]);
  const blocks = JSON.parse(text, (...args: any[]) => {
    const [, value, context] = args;
    return typeof value === 'number' && !Number.isSafeInteger(value) ? context.source : value;
  });
  assert.equal(blocks.length, 1);
  const block = blocks[0];
  return block.rows.map((row: any[]) => Object.fromEntries(row.map((value, index) => [
    block.schema.elements[index].name.some, decode(block.schema.elements[index].algebraic_type, value),
  ])));
}
const canonical = (rows: Record<string, any>[]) => rows.map(row => JSON.stringify(row)).sort();
const tableNames = () => JSON.parse(run(['describe', '--server', uri, '--no-config', '--json', database, 'tables']))
  .map((table: any) => table.source_name as string).filter((name: string) => name !== 'maintenance_job').sort() as string[];
const snapshot = (names: string[]) => Object.fromEntries(names.map(name => [name, canonical(query(name))]));

async function connect(token?: string) {
  const connected = await new Promise<{ client: DbConnection; token: string }>((yes, no) => {
    const client = DbConnection.builder().withUri(uri).withDatabaseName(database).withToken(token)
      .onConnect((client, _identity, token) => yes({ client, token }))
      .onConnectError((_context, error) => no(error)).build();
    connections.push(client);
  });
  await new Promise<void>((yes, no) => connected.client.subscriptionBuilder()
    .onApplied(() => yes()).onError(context => no(context.event))
    .subscribe(['SELECT * FROM adapter_player']));
  return connected;
}
let serial = 1n;
const snowflake = () => ((BigInt(Date.now()) - 1420070400000n) << 22n) | serial++;
async function databaseIdentity(name: string) {
  const response = await fetch(`${uri}/v1/database/${name}`);
  assert(response.ok);
  const metadata = await response.json();
  assert.equal(typeof metadata.database_identity?.__identity__, 'string');
  return metadata.database_identity.__identity__ as string;
}

try {
  publish(oldWasm);
  const proofIdentity = await databaseIdentity(database);
  assert.notEqual(proofIdentity, await databaseIdentity('fishbound-dev-local'), 'Proof must not alias the Windows development database');
  await phase('baseline-published');
  const { client: old, token } = await connect();
  call('configure_service', [JSON.stringify(old.identity!.toHexString()), JSON.stringify({ discordAdapter: {} }), 'true']);
  const casts: { discordUserId: bigint; interactionId: bigint; guildId: undefined; channelId: bigint }[] = [];
  for (const discordUserId of [989101n, 989102n]) {
    const interactionId = snowflake();
    await old.reducers.selectDiscordPlayer({ discordUserId, interactionId, displayName: 'Luck preservation fixture' });
    const cast = { discordUserId, interactionId, guildId: undefined, channelId: 42n };
    await old.reducers.fishFromDiscord(cast);
    casts.push(cast);
    await old.reducers.dailyFromDiscord({ ...cast, interactionId: snowflake() });
    if (discordUserId === 989101n) {
      for (const kind of ['boat', 'net']) {
        await old.reducers.startHaulFromDiscord({ ...cast, interactionId: snowflake(), kind });
      }
    }
  }
  const active = query('haul_trip');
  assert.equal(active.length, 2);
  assert(active.every(row => row.status === 'running'), 'Both old-module trips must still be running');
  const names = tableNames();
  const before = snapshot(names);
  for (const table of ['player', 'owned_rod', 'owned_haul_equipment', 'command_receipt', 'cast_equipment_receipt', 'haul_trip']) {
    assert(before[table]?.length > 0, `Fixture must exercise retained ${table}`);
  }
  const oldConfig = query('game_config');
  old.disconnect();
  publish(candidateWasm);
  assert.equal(await databaseIdentity(database), proofIdentity);
  await phase('candidate-published');
  assert.deepEqual(tableNames(), names, 'No schema or table changes');
  assert.deepEqual(snapshot(names), before, 'Every retained non-maintenance table is byte-for-byte equivalent');
  assert.deepEqual(query('game_config'), oldConfig, 'No content activation or rules-version rewrite');
  assert.deepEqual(query('haul_trip'), active, 'Active deadlines, gear snapshots and rewards stay unchanged');

  const { client: current } = await connect(token);
  const lastCast = casts[casts.length - 1];
  await current.reducers.fishFromDiscord(lastCast);
  assert.deepEqual(snapshot(names), before, 'Replaying the saved cast neither rerolls nor grants a second reward');
  await assert.rejects(current.reducers.finishHaulFromAdapter({ interactionId: BigInt(active[0].interaction_id) }), /HAUL_NOT_READY/);
  assert.deepEqual(snapshot(names), before, 'Rejected early completion has no partial mutations');

  const report = {
    database, uri, proofIdentity, oldHash, candidateHash, status: 'passed',
    preservation: Object.fromEntries(names.map(name => [name, {
      rows: before[name].length, sha256: sha(before[name].join('\n')),
    }])),
    activeTripsPreserved: active.length, castReplayPreserved: true, earlyCompletionRejected: true,
    limitations: 'Representative starter fixtures; no attempt to synthesize raid/reinforcement history. Active trips are preserved, not waited to natural settlement.',
    retention: 'One bounded isolated proof database retained for review; no deletion or live database access.',
  };
  await writeFile(reportPath, JSON.stringify(report, null, 2));
  await phase('passed');
  console.log(`PASS ${names.length} retained tables, two active trips, saved-cast replay and rejected early settlement; ${database}.`);
} catch (error) {
  await phase('failed');
  throw error;
} finally {
  for (const connection of connections) connection.disconnect();
}
