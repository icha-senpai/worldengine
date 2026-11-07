import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { promisify } from 'node:util';
import { DbConnection } from '../packages/generated/src/index';
import { moduleArtifactPath, requireRustWorkflow } from './rust-build.mjs';

requireRustWorkflow();
const root = resolve(import.meta.dirname, '..');
const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-tideforge-effects-${Date.now()}`;
const retryOf = process.env.FISHBOUND_STAGE2_PROOF_RETRY_OF;
const initialStatePath = join(root, '.local/tideforge-stage2-runtime.json');
const statePath = retryOf
  ? join(root, '.local/tideforge-stage2-retry-runtime.json')
  : initialStatePath;
const reportPath = join(root, '.local/tideforge-stage2-evidence.json');
const baselineWasm = join(root, '.local/tideforge-stage2-proof/baseline.wasm');
const candidateWasm = moduleArtifactPath();
const cli = process.env.FISHBOUND_TYPESCRIPT_CLI
  ?? join(process.env.LOCALAPPDATA!, 'SpacetimeDB/bin/2.12.0/spacetimedb-cli.exe');
const connections: DbConnection[] = [];
const sha = (value: Uint8Array | string) => createHash('sha256').update(value).digest('hex');
const id = (value: unknown) => String(value);
assert(/^fishbound-proof-tideforge-effects-\d+$/.test(database));
assert.equal(new URL(uri).hostname, '127.0.0.1');
assert.equal(new URL(uri).port, '3127');
const baselineHash = sha(await readFile(baselineWasm));
assert.equal(baselineHash, 'cd2f1896961786656520b9272bb5cf35f3f9462faf02e8a7bb5bdafb149e3844');
const candidateHash = sha(await readFile(candidateWasm));
assert.notEqual(candidateHash, baselineHash);
if (retryOf) {
  assert(/^fishbound-proof-tideforge-effects-\d+$/.test(retryOf));
  const prior = JSON.parse(await readFile(initialStatePath, 'utf8'));
  assert.equal(prior.database, retryOf);
  assert.equal(prior.phase, 'failed');
  assert.equal(prior.uri, uri);
  assert.equal(prior.baselineHash, baselineHash);
  assert.equal(prior.candidateHash, candidateHash);
  assert.equal(await databaseIdentity(retryOf), prior.proofIdentity);
}
const allocation = { uri, database, baselineHash, candidateHash, priorFailedFixture: retryOf };
await writeFile(statePath, JSON.stringify({ ...allocation, phase: 'allocated' }), { flag: 'wx' });
let proofIdentity: string | undefined;
let lastCompletedPhase = 'allocated';
const phase = async (value: string) => {
  lastCompletedPhase = value;
  await writeFile(statePath, JSON.stringify({ ...allocation, proofIdentity, phase: value }));
};
const execute = promisify(execFile);

async function run(args: string[]) {
  // CLI waits must leave the event loop available for SDK heartbeats and socket closure.
  const { stdout } = await execute(cli, args, {
    cwd: root, encoding: 'utf8', windowsHide: true, timeout: 120_000, maxBuffer: 64 * 1024 * 1024,
  });
  return stdout;
}
const publish = (wasm: string) => run([
  'publish', '--server', uri, '--no-config', '--yes=skip-login,migrate,break-clients',
  '--delete-data=never', '--bin-path', wasm, database,
]);
const call = (name: string, args: string[]) => run([
  'call', '--server', uri, '--no-config', '--yes', database, name, ...args,
]);
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
async function query(table: string): Promise<Record<string, any>[]> {
  assert(/^[a-z][a-z0-9_]*$/.test(table));
  const output = await run(['sql', '--server', uri, '--no-config', '--format', 'json', database, `SELECT * FROM ${table}`]);
  const blocks = JSON.parse(output, (...args: any[]) => {
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
const tableNames = async () => JSON.parse(await run(['describe', '--server', uri, '--no-config', '--json', database, 'tables']))
  .map((table: any) => table.source_name as string).filter((name: string) => name !== 'maintenance_job').sort() as string[];
const snapshot = async (names: string[]) => {
  const captured: Record<string, string[]> = {};
  for (const name of names) captured[name] = canonical(await query(name));
  return captured;
};
const summarize = (captured: Record<string, string[]>) => Object.fromEntries(
  Object.entries(captured).map(([name, rows]) => [name, { rows: rows.length, sha256: sha(rows.join('\n')) }]),
);
const effectTables = [
  'tideforge_state', 'tideforge_gear', 'tideforge_library', 'tideforge_loadout',
  'tideforge_activity_snapshot', 'tideforge_fractional_credit', 'tideforge_daily_use',
  'tideforge_activity_receipt', 'tideforge_specimen_modifiers',
];
async function subscribe(client: DbConnection, queries: string[]) {
  await new Promise<void>((yes, no) => {
    const timer = setTimeout(() => no(new Error('Proof subscription timed out')), 25_000);
    client.subscriptionBuilder()
      .onApplied(() => { clearTimeout(timer); yes(); })
      .onError(context => { clearTimeout(timer); no(context.event); }).subscribe(queries);
  });
}
async function connect(token?: string) {
  const connection = await new Promise<{ client: DbConnection; token: string }>((yes, no) => {
    const timer = setTimeout(() => no(new Error('Proof connection timed out')), 25_000);
    const client = DbConnection.builder().withUri(uri).withDatabaseName(database).withToken(token)
      .onConnect((client, _identity, token) => { clearTimeout(timer); yes({ client, token }); })
      .onConnectError((_context, error) => { clearTimeout(timer); no(error); }).build();
    connections.push(client);
  });
  await subscribe(connection.client, ['SELECT * FROM adapter_player']);
  return connection;
}
async function databaseIdentity(name: string) {
  const response = await fetch(`${uri}/v1/database/${name}`, { signal: AbortSignal.timeout(10_000) });
  assert(response.ok);
  const metadata = await response.json();
  assert.equal(typeof metadata.database_identity?.__identity__, 'string');
  return metadata.database_identity.__identity__ as string;
}
let serial = 1n;
const snowflake = () => ((BigInt(Date.now()) - 1420070400000n) << 22n) | serial++;
const sleep = (ms: number) => new Promise(resolve => setTimeout(resolve, ms));
async function waitFor(predicate: () => boolean, message: string, timeout = 10_000) {
  const deadline = Date.now() + timeout;
  while (!predicate() && Date.now() < deadline) await sleep(50);
    assert(predicate(), message);
}

try {
  const absent = await fetch(`${uri}/v1/database/${database}`, { signal: AbortSignal.timeout(10_000) });
  assert.equal(absent.status, 404, 'Never overwrite an existing proof database');
  await publish(baselineWasm);
  proofIdentity = await databaseIdentity(database);
  assert.notEqual(proofIdentity, await databaseIdentity('fishbound-dev-local'));
  await phase('baseline-published');
  const { client: old, token } = await connect();
  await call('configure_service', [JSON.stringify(old.identity!.toHexString()), JSON.stringify({ discordAdapter: {} }), 'true']);
  const oldCasts: { discordUserId: bigint; interactionId: bigint; guildId: undefined; channelId: bigint }[] = [];
  for (const discordUserId of [989301n, 989302n]) {
    const interactionId = snowflake();
    await old.reducers.selectDiscordPlayer({ discordUserId, interactionId, displayName: 'Effects preservation fixture' });
    const cast = { discordUserId, interactionId, guildId: undefined, channelId: 42n };
    await old.reducers.fishFromDiscord(cast);
    oldCasts.push(cast);
    await old.reducers.dailyFromDiscord({ ...cast, interactionId: snowflake() });
  }
  await call('activate_gear_foundation', []);
  for (const kind of ['boat', 'net']) {
    await old.reducers.startHaulFromDiscord({ ...oldCasts[0], interactionId: snowflake(), kind });
  }
  const legacyTrips = await query('haul_trip');
  assert.equal(legacyTrips.length, 2);
  assert(legacyTrips.every(row => row.status === 'running'));
  const beforeNames = await tableNames();
  assert(!beforeNames.includes('tideforge_state'));
  const before = await snapshot(beforeNames);
  const oldProgress = await query('gear_family_progress');
  assert.equal(oldProgress.length, 6);
  assert(oldProgress.every(row => row.mastery_xp === 0 && row.curve_version === 0));
  assert.equal((await query('gear_foundation_state'))[0]?.version, 1);
  await writeFile(reportPath, JSON.stringify({
    ...allocation, proofIdentity, status: 'baseline-captured', retained: summarize(before),
    oldProgress, legacyTrips,
    oldCasts: oldCasts.map(cast => ({
      discordUserId: id(cast.discordUserId), interactionId: id(cast.interactionId), channelId: id(cast.channelId),
    })),
  }, null, 2), { flag: 'wx' });
  await phase('baseline-captured');
  old.disconnect();

  await publish(candidateWasm);
  assert.equal(await databaseIdentity(database), proofIdentity);
  await phase('candidate-published');
  const names = await tableNames();
  for (const name of beforeNames) assert(names.includes(name), `Retained table missing: ${name}`);
  for (const name of effectTables) {
    assert(names.includes(name), `Effects table missing: ${name}`);
    assert.equal((await query(name)).length, 0, 'Publishing must not activate effects or backfill rewards');
  }
  assert.deepEqual(await snapshot(beforeNames), before, 'Publication preserves every retained row');
  const { client: current } = await connect(token);
  await current.reducers.fishFromDiscord(oldCasts[1]);
  assert.deepEqual(await snapshot(beforeNames), before, 'Disabled replay preserves old gameplay state');
  await assert.rejects(current.reducers.activateTideforgeEffects({}), /NOT_AUTHORIZED|OWNER_REQUIRED/);
  assert.equal((await query('tideforge_state')).length, 0);
  await call('activate_tideforge_effects', []);
  await phase('activated');
  const expectedPromoted = { ...before, gear_family_progress: canonical(oldProgress.map(row => ({ ...row, curve_version: 1 }))) };
  assert.deepEqual(await snapshot(beforeNames), expectedPromoted, 'Activation only promotes the existing mastery curve');
  assert.equal((await query('tideforge_state'))[0]?.version, 1);
  assert.equal((await query('tideforge_activity_snapshot')).length, 0, 'Old bookings never gain new effects');
  assert.equal((await query('tideforge_activity_receipt')).length, 0, 'No historical mastery grant');
  const activated = await snapshot(names);
  await call('activate_tideforge_effects', []);
  assert.deepEqual(await snapshot(names), activated, 'Activation is idempotent');
  await current.reducers.fishFromDiscord(oldCasts[1]);
  assert.deepEqual(await snapshot(names), activated, 'Enabled replay does not add effects or mastery');
  const oldBoat = legacyTrips.find(row => row.kind === 'boat');
  const oldNet = legacyTrips.find(row => row.kind === 'net');
  assert(oldBoat && oldNet);
  await assert.rejects(current.reducers.finishHaulFromAdapter({
    interactionId: BigInt(id(oldBoat.interaction_id)),
  }), /HAUL_NOT_READY/);
  assert.deepEqual(await snapshot(names), activated, 'Early finish has no partial effects, tokens or credit');
  await phase('preservation-verified');

  const requests = [];
  for (const discordUserId of [989303n, 989304n]) {
    const interactionId = snowflake();
    await current.reducers.selectDiscordPlayer({ discordUserId, interactionId, displayName: 'New effects fixture' });
    const request = { discordUserId, interactionId, guildId: undefined, channelId: 42n };
    await current.reducers.fishFromDiscord(request);
    const player = (await query('player')).find(row => id(row.discord_user_id) === id(discordUserId));
    assert(player);
    const activity = (await query('tideforge_activity_snapshot')).find(row => row.key === `cast:${interactionId}`);
    const receipt = (await query('tideforge_activity_receipt')).find(row => row.key === `cast:${interactionId}`);
    const gear = (await query('gear_activity_snapshot')).find(row => row.key === `cast:${interactionId}`);
    assert(activity && receipt && gear);
    const effects = JSON.parse(activity.effects_json);
    assert.equal(effects.version, 1);
    assert.equal(effects.stats.luckBp, 100, 'Primary innate is one Luck-stat point');
    assert.equal(effects.unlockedSlots, 1);
    assert.equal(receipt.mastery_xp_granted, 100, 'Accepted cast credits one activity, not each pull');
    assert.equal(gear.snapshot.effects_version, 1);
    const progress = (await query('gear_family_progress')).filter(row => id(row.player_id) === id(player.player_id));
    assert.equal(progress.length, 3);
    assert(progress.every(row => row.curve_version === 1));
    assert.equal(progress.find(row => row.kind === 'rod')?.mastery_xp, 100);
    requests.push({ request, player, activity });
  }
  const fresh = requests[0];
  const freshNetId = snowflake();
  await current.reducers.startHaulFromDiscord({ ...fresh.request, interactionId: freshNetId, kind: 'net' });
  const netCapture = (await query('tideforge_activity_snapshot')).find(row => row.key === `haul:${freshNetId}`);
  assert(netCapture);
  assert.equal(netCapture.gear_kind, 'net');
  assert.equal(netCapture.mastery_xp_at_booking, 0, 'Net and rod tracks remain separate');
  assert.equal(JSON.parse(netCapture.effects_json).stats.luckBp, 100);
  const goalInteraction = snowflake();
  await current.reducers.selectDiscordPlayer({
    discordUserId: fresh.request.discordUserId, interactionId: goalInteraction, displayName: 'New effects fixture',
  });
  await current.reducers.setTideforgeGoalFromDiscord({
    discordUserId: fresh.request.discordUserId, interactionId: goalInteraction, goal: 'perfects',
  });
  assert.equal((await query('tideforge_activity_snapshot')).find(row => row.key === netCapture.key)?.effects_json,
    netCapture.effects_json, 'Goal changes cannot rewrite a booked haul');
  await assert.rejects(current.reducers.selectTideforgeSignaturesFromDiscord({
    discordUserId: fresh.request.discordUserId, interactionId: goalInteraction,
    rodSignature: 'echoed_line', haulSignature: undefined, watersSignature: undefined,
  }), /TIDEFORGE_SIGNATURE_NOT_OWNED/);
  const replayBefore = await snapshot(names);
  await current.reducers.fishFromDiscord(fresh.request);
  assert.deepEqual(await snapshot(names), replayBefore, 'New accepted-cast replay grants no second mastery');
  await subscribe(current, [
    'SELECT * FROM my_tideforge_activity_snapshots',
    'SELECT * FROM adapter_tideforge_activity_snapshots',
    'SELECT * FROM adapter_tideforge_activity_receipts',
    'SELECT * FROM adapter_tideforge_loadout',
  ]);
  await waitFor(() => {
    const rows = [...current.db.adapterTideforgeActivitySnapshots.iter()];
    return rows.length === 2 && rows.every(row => id(row.playerId) === id(fresh.player.player_id));
  }, 'Selected adapter sees only its own two booked activities');
  assert.equal([...current.db.myTideforgeActivitySnapshots.iter()].length, 0, 'Adapter is not a linked browser');
  assert([...current.db.adapterTideforgeActivityReceipts.iter()]
    .every(row => id(row.playerId) === id(fresh.player.player_id)));
  assert([...current.db.adapterTideforgeLoadout.iter()]
    .every(row => id(row.playerId) === id(fresh.player.player_id)));
  const { client: stranger } = await connect();
  await subscribe(stranger, [
    'SELECT * FROM my_tideforge_activity_snapshots', 'SELECT * FROM adapter_tideforge_activity_snapshots',
  ]);
  assert.equal([...stranger.db.myTideforgeActivitySnapshots.iter()].length, 0);
  assert.equal([...stranger.db.adapterTideforgeActivitySnapshots.iter()].length, 0);
  await assert.rejects(subscribe(stranger, ['SELECT * FROM tideforge_activity_snapshot']),
    'Raw effects snapshots must not become public tables');
  stranger.disconnect();
  await phase('new-booking-and-privacy-verified');

  const deadline = Date.now() + 330_000;
  let settled: Record<string, any>[] = [];
  while (Date.now() < deadline) {
    settled = (await query('haul_trip')).filter(row =>
      id(row.interaction_id) === id(oldNet.interaction_id) || id(row.interaction_id) === id(freshNetId));
    if (settled.length === 2 && settled.every(row => row.status !== 'running' && row.completed_at !== null)) break;
    await sleep(1000);
  }
  assert.equal(settled.length, 2);
  assert(settled.every(row => row.status !== 'running' && row.completed_at !== null),
    'Old and new nets must settle naturally within the bounded wait');
  for (const row of settled) assert.equal(row.results.length, 4, 'Vanilla nets keep four pulls');
  const finalCaptures = await query('tideforge_activity_snapshot');
  const finalAwards = await query('tideforge_activity_receipt');
  assert(!finalCaptures.some(row => row.key === `haul:${oldNet.interaction_id}`));
  assert(!finalAwards.some(row => row.key === `haul:${oldNet.interaction_id}`), 'Old net earns no retroactive mastery');
  const freshAward = finalAwards.find(row => row.key === `haul:${freshNetId}`);
  assert(freshAward);
  assert.equal(freshAward.mastery_xp_granted, 100);
  assert.equal(finalCaptures.find(row => row.key === netCapture.key)?.effects_json, netCapture.effects_json);
  const finalProgress = await query('gear_family_progress');
  const oldNetProgress = finalProgress.find(row =>
    id(row.player_id) === id(oldNet.player_id) && row.kind === 'net' && row.family_id === 1);
  assert.equal(oldNetProgress?.mastery_xp, 0);
  const freshNetProgress = finalProgress.find(row =>
    id(row.player_id) === id(fresh.player.player_id) && row.kind === 'net' && row.family_id === 1);
  assert.equal(freshNetProgress?.mastery_xp, 100);
  const completed = await snapshot(names);
  for (const row of settled) {
    await current.reducers.finishHaulFromAdapter({ interactionId: BigInt(id(row.interaction_id)) });
  }
  assert.deepEqual(await snapshot(names), completed, 'Completion replay never grants mastery or rewards twice');
  const report = {
    ...allocation, proofIdentity, status: 'passed',
    retained: summarize(before), addedTableNames: names.filter(name => !beforeNames.includes(name)),
    existingFamilyRowsPromotedWithoutXpBackfill: oldProgress.length,
    legacyActivitiesPreserved: legacyTrips.length,
    publishPreserved: true, activationOnlyPromotedCurve: true, activationIdempotent: true,
    oldAndNewSavedReplayPreserved: true, earlyFinishRejected: true,
    selectedAdapterAndUnlinkedViewsVerified: true, rawPrivateTableRejected: true,
    newPlayerCurvesAndOneActivityMasteryVerified: true, newBookingPinsVerified: true,
    oldAndNewNetNaturalSettlementVerified: true, oldBookingMasteryNotBackfilled: true,
    limitations: 'WASM fixture uses vanilla starter gear, no signatures/affixes or copies, ordinary hauls and an unlinked adapter. Nonzero effects, linked-browser ownership, quality/reinforcement, raids, deterministic RNG and retention require native tests. No production-data migration or advanced per-action odds claim.',
    retention: `${retryOf ? 'Two bounded Stage 2 proof databases (one failed acknowledgement fixture, one verification fixture)' : 'One bounded Stage 2 proof database'} and required baseline WASM retained for review; no deletion, development/production mutation, Discord delivery or deployment.`,
  };
  await writeFile(reportPath, JSON.stringify(report, null, 2));
  await phase('passed');
  console.log(`PASS ${beforeNames.length} retained tables; ${oldProgress.length} curve-only promotions; old/new replay, scoped privacy and two natural net settlements; ${database}.`);
} catch (error) {
  await writeFile(statePath, JSON.stringify({ ...allocation, proofIdentity, phase: 'failed', lastCompletedPhase }));
  throw error;
} finally {
  for (const connection of connections) connection.disconnect();
}
