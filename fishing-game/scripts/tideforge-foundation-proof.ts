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
const database = `fishbound-proof-tideforge-${Date.now()}`;
const statePath = join(root, '.local/tideforge-foundation-runtime.json');
const reportPath = join(root, '.local/tideforge-foundation-evidence.json');
const baselineWasm = join(root, '.local/tideforge-foundation-proof/baseline.wasm');
const candidateWasm = moduleArtifactPath();
const cli = process.env.FISHBOUND_TYPESCRIPT_CLI
  ?? join(process.env.LOCALAPPDATA!, 'SpacetimeDB/bin/2.12.0/spacetimedb-cli.exe');
const connections: DbConnection[] = [];
const sha = (bytes: Uint8Array | string) => createHash('sha256').update(bytes).digest('hex');
const textId = (value: unknown) => String(value);
const originalId = (player: unknown, kind: string, definition: unknown) => {
  const tag = { rod: 1n, boat: 2n, net: 3n }[kind];
  assert.notEqual(tag, undefined, 'Known gear kind required');
  return ((BigInt(textId(player)) << 64n) | (tag! << 32n) | BigInt(textId(definition))).toString();
};
assert(/^fishbound-proof-tideforge-\d+$/.test(database));
assert.equal(new URL(uri).hostname, '127.0.0.1');
assert.equal(new URL(uri).port, '3127');
const baselineHash = sha(await readFile(baselineWasm));
assert.equal(baselineHash, '5bfec5461067dad86938dd118e0bc222cc66d6529a564bf87db67b24cffa9cfc');
const candidateHash = sha(await readFile(candidateWasm));
assert.notEqual(candidateHash, baselineHash, 'Foundation candidate must differ from the committed baseline');
const allocation = { uri, database, baselineHash, candidateHash };
// One bounded fixture. An existing manifest requires review, not another database.
await writeFile(statePath, JSON.stringify({ ...allocation, phase: 'allocated' }), { flag: 'wx' });
let proofIdentity: string | undefined;
const phase = (value: string) => writeFile(statePath, JSON.stringify({ ...allocation, proofIdentity, phase: value }));

function run(args: string[]) {
  const result = spawnSync(cli, args, {
    cwd: root, encoding: 'utf8', windowsHide: true,
    maxBuffer: 64 * 1024 * 1024, timeout: 120_000,
  });
  if (result.error) throw result.error;
  assert.equal(result.status, 0, result.stderr || result.stdout);
  return result.stdout;
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
function query(table: string): Record<string, any>[] {
  assert(/^[a-z][a-z0-9_]*$/.test(table));
  const output = run(['sql', '--server', uri, '--no-config', '--format', 'json', database, `SELECT * FROM ${table}`]);
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
const tableNames = () => JSON.parse(run(['describe', '--server', uri, '--no-config', '--json', database, 'tables']))
  .map((table: any) => table.source_name as string).filter((name: string) => name !== 'maintenance_job').sort() as string[];
const snapshot = (names: string[]) => Object.fromEntries(names.map(name => [name, canonical(query(name))]));
const summarize = (captured: Record<string, string[]>) => Object.fromEntries(
  Object.entries(captured).map(([name, rows]) => [name, { rows: rows.length, sha256: sha(rows.join('\n')) }]),
);

async function connect(token?: string) {
  const connected = await new Promise<{ client: DbConnection; token: string }>((yes, no) => {
    const timer = setTimeout(() => no(new Error('Proof connection timed out')), 25_000);
    const client = DbConnection.builder().withUri(uri).withDatabaseName(database).withToken(token)
      .onConnect((client, _identity, token) => { clearTimeout(timer); yes({ client, token }); })
      .onConnectError((_context, error) => { clearTimeout(timer); no(error); }).build();
    connections.push(client);
  });
  await new Promise<void>((yes, no) => {
    const timer = setTimeout(() => no(new Error('Proof subscription timed out')), 25_000);
    connected.client.subscriptionBuilder()
      .onApplied(() => { clearTimeout(timer); yes(); })
      .onError(context => { clearTimeout(timer); no(context.event); })
      .subscribe(['SELECT * FROM adapter_player']);
  });
  return connected;
}
let serial = 1n;
const snowflake = () => ((BigInt(Date.now()) - 1420070400000n) << 22n) | serial++;
async function databaseIdentity(name: string) {
  const response = await fetch(`${uri}/v1/database/${name}`, { signal: AbortSignal.timeout(10_000) });
  assert(response.ok);
  const metadata = await response.json();
  assert.equal(typeof metadata.database_identity?.__identity__, 'string');
  return metadata.database_identity.__identity__ as string;
}

try {
  publish(baselineWasm);
  proofIdentity = await databaseIdentity(database);
  assert.notEqual(proofIdentity, await databaseIdentity('fishbound-dev-local'), 'Proof cannot alias Windows development');
  await phase('baseline-published');
  const { client: old, token } = await connect();
  call('configure_service', [JSON.stringify(old.identity!.toHexString()), JSON.stringify({ discordAdapter: {} }), 'true']);
  const casts: { discordUserId: bigint; interactionId: bigint; guildId: undefined; channelId: bigint }[] = [];
  for (const discordUserId of [989201n, 989202n]) {
    const interactionId = snowflake();
    await old.reducers.selectDiscordPlayer({ discordUserId, interactionId, displayName: 'Foundation preservation fixture' });
    const cast = { discordUserId, interactionId, guildId: undefined, channelId: 42n };
    await old.reducers.fishFromDiscord(cast);
    casts.push(cast);
    await old.reducers.dailyFromDiscord({ ...cast, interactionId: snowflake() });
    if (discordUserId === 989201n) {
      for (const kind of ['boat', 'net']) {
        await old.reducers.startHaulFromDiscord({ ...cast, interactionId: snowflake(), kind });
      }
    }
  }
  const active = query('haul_trip');
  assert.equal(active.length, 2);
  assert(active.every(row => row.status === 'running'));
  const oldRods = query('owned_rod');
  const oldHauls = query('owned_haul_equipment');
  const haulDefinitions = query('haul_equipment_definition');
  const oldPlayers = query('player');
  const oldLoadouts = query('haul_loadout');
  const oldReinforcements = query('rod_reinforcement');
  const retainedNames = tableNames();
  const before = snapshot(retainedNames);
  for (const name of ['player', 'owned_rod', 'owned_haul_equipment', 'command_receipt', 'cast_equipment_receipt', 'haul_trip']) {
    assert(before[name]?.length > 0, `Fixture must exercise ${name}`);
  }
  old.disconnect();
  publish(candidateWasm);
  assert.equal(await databaseIdentity(database), proofIdentity);
  await phase('candidate-published');
  const names = tableNames();
  for (const name of retainedNames) assert(names.includes(name), `Retained table missing: ${name}`);
  for (const name of ['gear_foundation_state', 'gear_instance', 'gear_loadout', 'gear_family_progress', 'gear_activity_snapshot']) {
    assert(names.includes(name), `Foundation table missing: ${name}`);
    assert.equal(query(name).length, 0, 'Publishing must not activate or mutate gameplay metadata');
  }
  assert.deepEqual(snapshot(retainedNames), before, 'Schema-only upgrade preserves every retained row');
  const { client: current } = await connect(token);
  await current.reducers.fishFromDiscord(casts[casts.length - 1]);
  assert.deepEqual(snapshot(retainedNames), before, 'Disabled saved-cast replay preserves old rows');
  await assert.rejects(current.reducers.activateGearFoundation({}), /NOT_AUTHORIZED|OWNER_REQUIRED/);
  assert.equal(query('gear_foundation_state').length, 0);
  call('activate_gear_foundation', []);
  await phase('activated');
  assert.deepEqual(snapshot(retainedNames), before, 'Explicit activation only adds foundation rows');
  const foundationState = query('gear_foundation_state');
  assert.equal(foundationState.length, 1);
  assert.equal(foundationState[0].version, 1);
  const descriptors = oldRods.map(row => ({
    player: row.player_id, kind: 'rod', definition: row.rod_id, family: row.rod_id,
    quality: row.upgrade_level,
    reinforcement: oldReinforcements.find(item => textId(item.key) === textId(row.key))?.level ?? 0,
  }));
  for (const row of oldHauls) {
    const definition = haulDefinitions.find(item => item.equipment_id === row.equipment_id);
    assert(definition);
    descriptors.push({
      player: row.player_id, kind: definition.kind, definition: row.equipment_id,
      family: definition.rod_id, quality: row.upgrade_level, reinforcement: 0,
    });
  }
  const instances = query('gear_instance');
  assert.equal(instances.length, descriptors.length);
  for (const expected of descriptors) {
    const instance = instances.find(row =>
      textId(row.instance_id) === originalId(expected.player, expected.kind, expected.definition));
    assert(instance, 'Every owned legacy item has one deterministic instance');
    assert.equal(textId(instance.player_id), textId(expected.player));
    assert.equal(instance.kind, expected.kind);
    assert.equal(instance.definition_id, expected.definition);
    assert.equal(instance.family_id, expected.family);
    assert.equal(instance.primary_biome_id, expected.family);
    assert.equal(instance.quality_level, expected.quality);
    assert.equal(instance.reinforcement_level, expected.reinforcement);
    assert.equal(instance.protected_original, true);
    assert.equal(instance.favorite, false);
    assert.equal(instance.revision, 1);
    assert.equal(instance.source, 'legacy_original');
  }
  const loadouts = query('gear_loadout');
  assert.equal(loadouts.length, oldPlayers.length);
  for (const player of oldPlayers) {
    const original = oldLoadouts.find(row => textId(row.player_id) === textId(player.player_id));
    const migrated = loadouts.find(row => textId(row.player_id) === textId(player.player_id));
    assert(original && migrated);
    assert.equal(textId(migrated.rod_instance_id), originalId(player.player_id, 'rod', player.equipped_rod_id));
    assert.equal(textId(migrated.boat_instance_id), originalId(player.player_id, 'boat', original.boat_id));
    assert.equal(textId(migrated.net_instance_id), originalId(player.player_id, 'net', original.net_id));
    assert.equal(migrated.revision, 1);
  }
  const expectedProgress = [...new Set(descriptors.map(row => originalId(row.player, row.kind, row.family)))].sort();
  const progress = query('gear_family_progress');
  assert.deepEqual(progress.map(row => textId(row.key)).sort(), expectedProgress);
  assert(progress.every(row => row.mastery_xp === 0 && row.curve_version === 0));
  const booked = query('gear_activity_snapshot');
  assert.equal(booked.length, active.length, 'Do not fabricate historical cast snapshots');
  for (const trip of active) {
    const capture = booked.find(row => row.key === `haul:${trip.interaction_id}`);
    assert(capture);
    assert.equal(capture.activity_kind, 'haul');
    assert.equal(textId(capture.activity_id), textId(trip.interaction_id));
    assert.equal(textId(capture.player_id), textId(trip.player_id));
    assert.equal(capture.legacy_booking, true);
    assert.deepEqual(capture.booked_at, trip.started_at);
    assert.equal(textId(capture.snapshot.instance_id), originalId(trip.player_id, trip.kind, trip.equipment_id));
    assert.equal(capture.snapshot.quality_level, trip.quality_level);
    assert.equal(capture.snapshot.power, 0);
    assert.equal(capture.snapshot.luck_bp, trip.luck_bp);
    assert.equal(capture.snapshot.xp_bonus_bp, trip.xp_bonus_bp);
    assert.equal(capture.snapshot.gear_revision, null);
    assert.equal(capture.snapshot.primary_biome_id, null);
    assert.equal(capture.snapshot.content_version, null, 'Legacy haul booking content version is unknown');
    assert.equal(capture.snapshot.rules_version, trip.rules.version);
    assert.equal(capture.snapshot.effects_version, 0);
  }
  const activated = snapshot(names);
  call('activate_gear_foundation', []);
  assert.deepEqual(snapshot(names), activated, 'Repeated activation is a true no-op');
  await current.reducers.fishFromDiscord(casts[casts.length - 1]);
  assert.deepEqual(snapshot(names), activated, 'Active saved-cast replay adds no rewards or metadata');
  const oldBoat = active.find(row => row.kind === 'boat');
  assert(oldBoat);
  await assert.rejects(current.reducers.finishHaulFromAdapter({
    interactionId: BigInt(textId(oldBoat.interaction_id)),
  }), /HAUL_NOT_READY/);
  assert.deepEqual(snapshot(names), activated, 'Early finish rejection has no partial writes');
  await phase('preservation-verified');

  async function gearSubscription(client: DbConnection) {
    await new Promise<void>((yes, no) => {
      const timer = setTimeout(() => no(new Error('Gear-view subscription timed out')), 25_000);
      client.subscriptionBuilder()
        .onApplied(() => { clearTimeout(timer); yes(); })
        .onError(context => { clearTimeout(timer); no(context.event); })
        .subscribe(['SELECT * FROM my_gear_instances', 'SELECT * FROM adapter_gear_instances']);
    });
  }
  await gearSubscription(current);
  assert.equal([...current.db.myGearInstances.iter()].length, 0, 'Unlinked adapter identity is not a browser player');
  const selectedPlayer = oldPlayers.find(row => textId(row.discord_user_id) === '989202');
  assert(selectedPlayer);
  const selectedInstances = [...current.db.adapterGearInstances.iter()];
  assert.equal(selectedInstances.length, descriptors.filter(row =>
    textId(row.player) === textId(selectedPlayer.player_id)).length);
  assert(selectedInstances.every(row => textId(row.playerId) === textId(selectedPlayer.player_id)));
  const { client: stranger } = await connect();
  await gearSubscription(stranger);
  assert.equal([...stranger.db.myGearInstances.iter()].length, 0);
  assert.equal([...stranger.db.adapterGearInstances.iter()].length, 0, 'Unregistered identity cannot read adapter gear');
  stranger.disconnect();

  const freshInteraction = snowflake();
  const freshRequest = {
    discordUserId: 989203n, interactionId: freshInteraction, guildId: undefined, channelId: 42n,
  };
  await current.reducers.selectDiscordPlayer({
    discordUserId: freshRequest.discordUserId, interactionId: freshInteraction,
    displayName: 'Post-foundation fixture',
  });
  await current.reducers.fishFromDiscord(freshRequest);
  const freshPlayer = query('player').find(row => textId(row.discord_user_id) === '989203');
  assert(freshPlayer);
  const freshInstances = query('gear_instance').filter(row => textId(row.player_id) === textId(freshPlayer.player_id));
  assert.equal(freshInstances.length, 3);
  assert(freshInstances.every(row => row.protected_original && row.quality_level === 0 && row.reinforcement_level === 0));
  const freshCast = query('gear_activity_snapshot').find(row => row.key === `cast:${freshInteraction}`);
  const freshReceipt = query('cast_equipment_receipt').find(row => textId(row.interaction_id) === freshInteraction.toString());
  assert(freshCast && freshReceipt);
  assert.equal(freshCast.legacy_booking, false);
  assert.equal(freshCast.snapshot.effects_version, 0);
  assert.equal(freshCast.snapshot.gear_revision, 1);
  assert.notEqual(freshCast.snapshot.content_version, null);
  for (const field of ['quality_level', 'power', 'luck_bp', 'xp_bonus_bp']) {
    assert.equal(freshCast.snapshot[field], freshReceipt[field], `New snapshot matches committed ${field}`);
  }
  assert.equal(freshReceipt.bait_id, 0);
  assert.equal(freshReceipt.secondary_bait_id, 0);
  const definition = query('rod_definition').find(row => row.rod_id === freshPlayer.equipped_rod_id);
  const bonus = query('rod_bonuses').find(row => row.rod_id === freshPlayer.equipped_rod_id);
  const quality = query('quality_definition').find(row => row.quality_level === 0);
  assert(definition && quality);
  assert.equal(freshReceipt.power, definition.power + quality.power_bonus);
  assert.equal(freshReceipt.luck_bp, (bonus?.luck_bp ?? 0) + quality.luck_bp, 'No proposed innate Luck buff activated');
  assert.equal(freshReceipt.xp_bonus_bp, (bonus?.xp_bonus_bp ?? 0) + quality.xp_bonus_bp);
  assert(query('gear_family_progress').every(row => row.mastery_xp === 0 && row.curve_version === 0));
  const freshNetId = snowflake();
  await current.reducers.startHaulFromDiscord({ ...freshRequest, interactionId: freshNetId, kind: 'net' });
  const freshBooking = query('gear_activity_snapshot').find(row => row.key === `haul:${freshNetId}`);
  assert(freshBooking);
  assert.equal(freshBooking.legacy_booking, false);
  assert.equal(freshBooking.snapshot.content_version, freshCast.snapshot.content_version, 'New haul pins the known booking version');

  const oldNet = active.find(row => row.kind === 'net');
  assert(oldNet);
  const deadline = Date.now() + 330_000;
  let settledNet: Record<string, any> | undefined;
  while (Date.now() < deadline) {
    settledNet = query('haul_trip').find(row => textId(row.interaction_id) === textId(oldNet.interaction_id));
    assert(settledNet, 'Old net trip must remain retained');
    if (settledNet.completed_at !== null && settledNet.status !== 'running') break;
    await new Promise(resolve => setTimeout(resolve, 1_000));
  }
  assert(settledNet && settledNet.completed_at !== null && settledNet.status !== 'running',
    'Old net must settle naturally within bounded wait');
  assert.equal(settledNet.results.length, 4, 'Existing normal-net pull count unchanged');
  const settledReceipt = query('cast_equipment_receipt').find(row =>
    textId(row.interaction_id) === textId(oldNet.interaction_id));
  assert(settledReceipt);
  assert.equal(settledReceipt.quality_level, oldNet.quality_level);
  assert.equal(settledReceipt.luck_bp, oldNet.luck_bp);
  assert.equal(settledReceipt.xp_bonus_bp, oldNet.xp_bonus_bp);
  const oldKeys = new Set(booked.map(row => row.key));
  assert.deepEqual(canonical(query('gear_activity_snapshot').filter(row => oldKeys.has(row.key))), canonical(booked),
    'Settling an old trip never rewrites its legacy metadata');
  const report = {
    ...allocation, proofIdentity, status: 'passed',
    retained: summarize(before), addedTableNames: names.filter(name => !retainedNames.includes(name)),
    migratedOriginals: instances.length, migratedLoadouts: loadouts.length,
    sharedFamilyRows: progress.length, legacyActivitiesPreserved: active.length,
    publishPreserved: true, activationPreserved: true, activationIdempotent: true,
    savedReplayPreserved: true, earlyFinishRejected: true, scopedViewsVerified: true,
    newPlayerAndBookingVerified: true, oldNetNaturalSettlementVerified: true,
    limitations: 'Live fixture uses starter equipment and ordinary hauls. Nonzero quality/reinforcement, raid variants, pending quotes, copies, seeded RNG and retention require the dedicated native reducer tests; this is not a production-data migration.',
    retention: 'One bounded isolated proof database and required baseline WASM fixture retained for review; no deletion, live mutation, external Discord delivery or deployment.',
  };
  await writeFile(reportPath, JSON.stringify(report, null, 2));
  await phase('passed');
  console.log(`PASS ${retainedNames.length} retained tables; ${instances.length} protected originals; ${active.length} old trips; idempotent activation/replay, scoped views and natural old-net settlement; ${database}.`);
} catch (error) {
  await phase('failed');
  throw error;
} finally {
  for (const connection of connections) connection.disconnect();
}
