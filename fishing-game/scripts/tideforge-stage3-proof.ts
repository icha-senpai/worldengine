import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { promisify } from 'node:util';
import economy from '../content/tideforge-economy.json';
import { DbConnection } from '../packages/generated/src/index';
import { moduleArtifactPath, requireRustWorkflow } from './rust-build.mjs';

requireRustWorkflow();
const root = resolve(import.meta.dirname, '..');
const uri = 'http://127.0.0.1:3127';
const statePath = join(root, '.local/tideforge-stage3-runtime.json');
const resumeBaseline = process.argv.includes('--resume-baseline');
const previous = resumeBaseline ? JSON.parse(await readFile(statePath, 'utf8')) : undefined;
const database: string = previous?.database ?? `fishbound-proof-tideforge-economy-${Date.now()}`;
const baselineWasm = join(root, '.local/tideforge-stage3-proof/baseline.wasm');
const candidateWasm = moduleArtifactPath();
const reportPath = join(root, '.local/tideforge-stage3-evidence.json');
const cli = process.env.FISHBOUND_TYPESCRIPT_CLI
  ?? join(process.env.LOCALAPPDATA!, 'SpacetimeDB/bin/2.12.0/spacetimedb-cli.exe');
const execute = promisify(execFile);
const clients: DbConnection[] = [];
const sha = (value: Uint8Array | string) => createHash('sha256').update(value).digest('hex');
const id = (value: unknown) => String(value);
const sleep = (ms: number) => new Promise(resolve => setTimeout(resolve, ms));
type Row = Record<string, any>;
type Capture = Record<string, string[]>;
const canonical = (rows: Row[]) => rows.map(row => JSON.stringify(row)).sort();
const summarize = (rows: Capture) => Object.fromEntries(Object.entries(rows)
  .map(([name, values]) => [name, { rows: values.length, sha256: sha(values.join('\n')) }]));
const economyTables = [
  'tideforge_economy_state', 'tideforge_economy_wallet', 'tideforge_affix_shard',
  'tideforge_codex', 'tideforge_economy_quote', 'tideforge_economy_receipt',
  'tideforge_mark_award', 'tideforge_raid_mark_booking',
];
const ranks = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];
assert(/^fishbound-proof-tideforge-economy-\d+$/.test(database));
assert.equal(new URL(uri).hostname, '127.0.0.1');
assert.equal(new URL(uri).port, '3127');
const baselineHash = sha(await readFile(baselineWasm));
assert.equal(baselineHash, '0359c31fc3e8c9b480468f730f8cfecf071a6aabc4143c9a19ff828bdf8b0dec');
const candidateHash = sha(await readFile(candidateWasm));
assert.notEqual(candidateHash, baselineHash, 'Build the Stage 3 candidate first');
const allocation = { uri, database, baselineHash, candidateHash };
// Exclusive allocation prevents a failed run from silently spawning more fixtures.
if (resumeBaseline) {
  assert.equal(previous.uri, uri);
  assert.equal(previous.phase, 'failed');
  assert.equal(previous.lastCompletedPhase, 'baseline-published');
  assert.equal(previous.baselineHash, baselineHash);
  assert.equal(previous.candidateHash, candidateHash);
} else {
  await writeFile(statePath, JSON.stringify({ ...allocation, phase: 'allocated' }), { flag: 'wx' });
}
let proofIdentity: string | undefined;
let developmentIdentity: string | undefined;
let lastCompletedPhase = previous?.lastCompletedPhase ?? 'allocated';
const phase = async (value: string) => {
  lastCompletedPhase = value;
  await writeFile(statePath, JSON.stringify({
    ...allocation, proofIdentity, developmentIdentity, phase: value,
  }));
  console.log(`Proof ${value}: ${database}`);
};
async function run(args: string[]) {
  const result = await execute(cli, args, {
    cwd: root, encoding: 'utf8', windowsHide: true, timeout: 120_000,
    maxBuffer: 64 * 1024 * 1024,
  });
  return result.stdout;
}
const publish = (wasm: string) => run([
  'publish', '--server', uri, '--no-config', '--yes=skip-login,migrate,break-clients',
  '--delete-data=never', '--bin-path', wasm, database,
]);
const ownerCall = (name: string, args: string[] = []) => run([
  'call', '--server', uri, '--no-config', '--yes', database, name, ...args,
]);
async function databaseIdentity(name: string) {
  const response = await fetch(`${uri}/v1/database/${name}`, {
    signal: AbortSignal.timeout(10_000),
  });
  assert(response.ok);
  const metadata = await response.json();
  assert.equal(typeof metadata.database_identity?.__identity__, 'string');
  return metadata.database_identity.__identity__ as string;
}
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
async function queryTables(names: string[]): Promise<Record<string, Row[]>> {
  assert(names.length > 0 && names.every(name => /^[a-z][a-z0-9_]*$/.test(name)));
  const rows: Record<string, Row[]> = {};
  let next = 0;
  // This server accepts one SQL statement per request. Bound CLI concurrency.
  await Promise.all(Array.from({ length: Math.min(4, names.length) }, async () => {
    while (next < names.length) {
      const name = names[next++]!;
      const text = await run(['sql', '--server', uri, '--no-config', '--format', 'json',
        database, `SELECT * FROM ${name}`]);
      const blocks = JSON.parse(text, (...args: any[]) => {
        const [, value, context] = args;
        return typeof value === 'number' && !Number.isSafeInteger(value) ? context.source : value;
      });
      assert.equal(blocks.length, 1);
      const block = blocks[0];
      rows[name] = block.rows.map((row: any[]) => Object.fromEntries(row.map((value, index) =>
        [block.schema.elements[index].name.some, decode(block.schema.elements[index].algebraic_type, value)])));
    }
  }));
  return Object.fromEntries(names.map(name => [name, rows[name]!]));
}
const query = async (name: string) => (await queryTables([name]))[name]!;
const snapshot = async (names: string[]): Promise<Capture> =>
  Object.fromEntries(Object.entries(await queryTables(names))
    .map(([name, rows]) => [name, canonical(rows)]));
const tableNames = async (): Promise<string[]> =>
  JSON.parse(await run(['describe', '--server', uri, '--no-config', '--json', database, 'tables']))
    .map((table: any) => table.source_name as string)
    .filter((name: string) => name !== 'maintenance_job').sort();
async function subscribe(client: DbConnection, queries: string[]) {
  await new Promise<void>((yes, no) => {
    const timer = setTimeout(() => no(new Error('Proof subscription timed out')), 25_000);
    client.subscriptionBuilder().onApplied(() => { clearTimeout(timer); yes(); })
      .onError(context => { clearTimeout(timer); no(context.event); }).subscribe(queries);
  });
}
async function connect(token?: string) {
  const connected = await new Promise<{ client: DbConnection; token: string }>((yes, no) => {
    const timer = setTimeout(() => no(new Error('Proof connection timed out')), 25_000);
    const client = DbConnection.builder().withUri(uri).withDatabaseName(database).withToken(token)
      .onConnect((client, _identity, token) => { clearTimeout(timer); yes({ client, token }); })
      .onConnectError((_context, error) => { clearTimeout(timer); no(error); }).build();
    clients.push(client);
  });
  await subscribe(connected.client, ['SELECT * FROM adapter_player']);
  return connected;
}
let serial = 1n;
const snowflake = () => ((BigInt(Date.now()) - 1420070400000n) << 22n) | serial++;
async function target(client: DbConnection, discordUserId: bigint) {
  const interactionId = snowflake();
  await client.reducers.selectDiscordPlayer({
    discordUserId, interactionId, displayName: 'Economy preservation fixture',
  });
  return { discordUserId, interactionId };
}
const playerFor = async (discordUserId: bigint) => {
  const player = (await query('player'))
    .find(row => id(row.discord_user_id) === id(discordUserId));
  assert(player);
  return player;
};

try {
  developmentIdentity = await databaseIdentity('fishbound-dev-local');
  if (!resumeBaseline) {
    const absent = await fetch(`${uri}/v1/database/${database}`, {
      signal: AbortSignal.timeout(10_000),
    });
    assert.equal(absent.status, 404, 'Never overwrite an existing database');
    await publish(baselineWasm);
  }
  proofIdentity = await databaseIdentity(database);
  assert.notEqual(proofIdentity, developmentIdentity);
  if (resumeBaseline) {
    assert.equal(proofIdentity, previous.proofIdentity);
    assert.equal(developmentIdentity, previous.developmentIdentity);
    assert(!(await tableNames()).includes('tideforge_economy_state'),
      'Baseline resumption must never roll back a published candidate');
    assert.equal((await query('gear_foundation_state'))[0]?.version, 1);
    assert.equal((await query('tideforge_state'))[0]?.version, 1);
  }
  await phase('baseline-published');
  const { client: old, token } = await connect();
  await ownerCall('configure_service', [
    JSON.stringify(old.identity!.toHexString()), JSON.stringify({ discordAdapter: {} }), 'true',
  ]);
  await ownerCall('activate_gear_foundation');
  await ownerCall('activate_tideforge_effects');
  const ordinary = new Set((await query('species_definition'))
    .filter(row => row.counts_for_ordinary_collection_completion).map(row => id(row.species_id)));
  const oldCasts: {
    discordUserId: bigint; interactionId: bigint; guildId: undefined; channelId: bigint;
  }[] = [];
  const idlePlayerIds = new Set<string>();
  if (resumeBaseline) {
    const receipts = (await query('command_receipt')).filter(row => row.command === 'fish')
      .sort((a, b) => Number(BigInt(id(a.discord_user_id)) - BigInt(id(b.discord_user_id))));
    for (const row of receipts) {
      const discordUserId = BigInt(id(row.discord_user_id));
      assert(discordUserId >= 989401n && discordUserId < 989409n);
      oldCasts.push({
        discordUserId, interactionId: BigInt(id(row.interaction_id)),
        guildId: undefined, channelId: BigInt(id(row.channel_id)),
      });
      if (discordUserId >= 989403n) idlePlayerIds.add(id(row.player_id));
    }
    assert(oldCasts.length >= 3 && oldCasts.length <= 8);
    await old.reducers.fishFromDiscord(oldCasts[1]!);
  }
  // Ordinary accepted casts supply a bounded vanilla fixture, not admin grants.
  for (let index = 0; !resumeBaseline && index < 8; index++) {
    const selected = await target(old, 989401n + BigInt(index));
    const request = { ...selected, guildId: undefined, channelId: 42n };
    await old.reducers.fishFromDiscord(request);
    await old.reducers.dailyFromDiscord({ ...request, interactionId: snowflake() });
    oldCasts.push(request);
    if (index >= 2) idlePlayerIds.add(id((await playerFor(request.discordUserId)).player_id));
    const specimens = await query('owned_specimen');
    if (oldCasts.length >= 3 && specimens.some(row =>
      ordinary.has(id(row.species_id)) && idlePlayerIds.has(id(row.player_id)))) break;
  }
  const legacyFish = (await query('owned_specimen'))
    .find(row => ordinary.has(id(row.species_id)) && idlePlayerIds.has(id(row.player_id)));
  assert(legacyFish, 'Bounded ordinary casts must supply a processing specimen');
  const bookingPlayer = await playerFor(oldCasts[0]!.discordUserId);
  const bookingDeadline = Date.now() + 12 * 60_000;
  let legacyTrips: Row[] = [];
  for (;;) {
    legacyTrips = (await query('haul_trip')).filter(row =>
      id(row.player_id) === id(bookingPlayer.player_id) && row.status === 'running');
    const expiring = legacyTrips.some(row =>
      row.due_at.__timestamp_micros_since_unix_epoch__ / 1000 - Date.now() < 180_000);
    if (expiring) {
      assert(resumeBaseline && Date.now() < bookingDeadline, 'Legacy booking window expired');
      await sleep(1000);
      continue;
    }
    for (const kind of ['boat', 'net']) {
      if (legacyTrips.some(row => row.kind === kind)) continue;
      await old.reducers.startHaulFromDiscord({
        ...oldCasts[0]!, interactionId: snowflake(), kind,
      });
    }
    legacyTrips = (await query('haul_trip')).filter(row =>
      id(row.player_id) === id(bookingPlayer.player_id) && row.status === 'running');
    break;
  }
  assert.equal(legacyTrips.length, 2);
  assert(legacyTrips.every(row => row.status === 'running'));
  const beforeNames = await tableNames();
  assert(beforeNames.includes('tideforge_state'));
  assert(!beforeNames.includes('tideforge_economy_state'));
  const before = await snapshot(beforeNames);
  const oldNet = legacyTrips.find(row => row.kind === 'net');
  const oldBoat = legacyTrips.find(row => row.kind === 'boat');
  assert(oldNet && oldBoat);
  const oldSnapshots = await query('tideforge_activity_snapshot');
  const oldNetEffects = oldSnapshots.find(row => row.key === `haul:${oldNet.interaction_id}`);
  assert(oldNetEffects);
  await writeFile(reportPath, JSON.stringify({
    ...allocation, proofIdentity, developmentIdentity, status: 'baseline-captured',
    resumedBaselineSetup: resumeBaseline,
    retained: summarize(before), legacyTrips, oldSnapshots,
    oldCasts: oldCasts.map(cast => ({
      discordUserId: id(cast.discordUserId), interactionId: id(cast.interactionId),
    })),
  }, null, 2), { flag: 'wx' });
  await phase('baseline-captured');
  old.disconnect();

  await publish(candidateWasm);
  assert.equal(await databaseIdentity(database), proofIdentity);
  await phase('candidate-published');
  const names = await tableNames();
  for (const name of beforeNames) assert(names.includes(name), `Retained table missing: ${name}`);
  for (const name of economyTables) {
    assert(names.includes(name), `Economy table missing: ${name}`);
    assert.equal((await query(name)).length, 0, 'Publication must not activate or grant anything');
  }
  const published = await snapshot(beforeNames);
  assert.deepEqual(published, before, 'Publication preserves every retained row');
  const { client: current } = await connect(token);
  const oldPlayer = await playerFor(oldCasts[0]!.discordUserId);
  const disabledTarget = await target(current, oldCasts[0]!.discordUserId);
  const disabledBefore = await snapshot(names);
  await assert.rejects(current.reducers.prepareTideforgeActionFromDiscord({
    ...disabledTarget, intentJson: JSON.stringify({
      action: 'camp_copy', kind: 'rod', definition_id: 2, secondary_biome_id: 1,
    }),
  }), /TIDEFORGE.*UNAVAILABLE/);
  assert.deepEqual(await snapshot(names), disabledBefore, 'Disabled action has no partial writes');
  await assert.rejects(current.reducers.activateTideforgeEconomy({}), /NOT_AUTHORIZED|OWNER_REQUIRED/);
  assert.equal((await query('tideforge_economy_state')).length, 0);
  const activationBefore = await snapshot(beforeNames);
  await ownerCall('activate_tideforge_economy');
  const afterActivation = await snapshot(beforeNames);
  assert.deepEqual(afterActivation, activationBefore,
    'Economy activation preserves all old rows, including existing effects and bookings');
  assert.equal((await query('tideforge_economy_state'))[0]?.version, economy.version);
  for (const name of economyTables.filter(name => name !== 'tideforge_economy_state')) {
    assert.equal((await query(name)).length, 0, 'No wallet, currency, discovery or activity backfill');
  }
  const activated = await snapshot(names);
  await ownerCall('activate_tideforge_economy');
  assert.deepEqual(await snapshot(names), activated, 'Activation is idempotent');
  await current.reducers.fishFromDiscord(oldCasts[1]!);
  assert.deepEqual(await snapshot(names), activated, 'Old cast replay grants no new rewards');
  await ownerCall('backfill_player_achievements', [id(oldPlayer.player_id)]);
  assert.equal((await query('tideforge_mark_award')).length, 0, 'Historical backfill pays no Marks');
  await phase('preservation-verified');

  const freshTarget = await target(current, 989499n);
  const freshPlayer = await playerFor(freshTarget.discordUserId);
  const earnedBefore = new Set((await query('earned_achievement')).map(row => id(row.key)));
  const markAwardsBefore = new Set((await query('tideforge_mark_award')).map(row => id(row.key)));
  const startingMarks = (await query('tideforge_economy_wallet'))
    .find(row => id(row.player_id) === id(freshPlayer.player_id))?.marks ?? 0;
  await current.reducers.fishFromDiscord({
    ...freshTarget, guildId: undefined, channelId: 42n,
  });
  const newAchievements = (await query('earned_achievement')).filter(row =>
    id(row.player_id) === id(freshPlayer.player_id) && !earnedBefore.has(id(row.key)));
  assert(newAchievements.length > 0, 'First accepted cast earns at least its ordinary milestone');
  const newAwards = (await query('tideforge_mark_award'))
    .filter(row => id(row.player_id) === id(freshPlayer.player_id) && !markAwardsBefore.has(id(row.key)));
  assert.equal(newAwards.length, newAchievements.length);
  assert(newAwards.every(row => row.source === 'achievement' && row.amount === economy.marks.newAchievement));
  assert.deepEqual(new Set(newAwards.map(row => id(row.source_id))),
    new Set(newAchievements.map(row => id(row.achievement_id))));
  const freshWallet = (await query('tideforge_economy_wallet'))
    .find(row => id(row.player_id) === id(freshPlayer.player_id));
  assert.equal(freshWallet?.marks, startingMarks + newAchievements.length * economy.marks.newAchievement);
  const earnedCapture = await snapshot(names);
  await current.reducers.fishFromDiscord({
    ...freshTarget, guildId: undefined, channelId: 42n,
  });
  assert.deepEqual(await snapshot(names), earnedCapture, 'New achievement replay cannot mint Marks');
  await phase('achievement-marks-verified');

  const processingPlayer = (await query('player'))
    .find(row => id(row.player_id) === id(legacyFish.player_id));
  assert(processingPlayer);
  const processingTarget = await target(current, BigInt(id(processingPlayer.discord_user_id)));
  const materialBefore = await query('item_stack');
  const carryBefore = await query('tideforge_fractional_credit');
  const pinned = (await query('tideforge_specimen_modifiers'))
    .find(row => id(row.catch_id) === id(legacyFish.catch_id));
  if (pinned) {
    assert.equal(id(pinned.player_id), id(processingPlayer.player_id));
    assert.equal(pinned.effects_version, 1);
  }
  const rank = ranks.indexOf(legacyFish.rarity);
  assert(rank >= 0);
  const carry = (resource: string, rows: Row[]) => rows.find(row =>
    id(row.player_id) === id(processingPlayer.player_id) && row.resource === resource)?.remainder_bp ?? 0;
  const filletCredit = BigInt(economy.processing.filletsByRank[rank]!)
    * BigInt(10_000 + (pinned?.fillet_bp ?? 0)) + BigInt(carry('normal_fillets', carryBefore));
  const scaleCredit = BigInt(economy.processing.scalesByRank[rank]!)
    * BigInt(10_000 + (pinned?.scale_bp ?? 0)) + BigInt(carry('normal_scales', carryBefore));
  const intentJson = JSON.stringify({
    action: 'fillet', catch_ids: [id(legacyFish.catch_id)], allow_trophies: true,
  });
  await current.reducers.prepareTideforgeActionFromDiscord({ ...processingTarget, intentJson });
  const prepared = (await query('tideforge_economy_quote')).filter(row =>
    id(row.player_id) === id(processingPlayer.player_id) && !row.consumed);
  assert.equal(prepared.length, 1);
  const nonce = BigInt(id(prepared[0]!.nonce));
  assert.equal(typeof prepared[0]!.preview_json, 'string');
  assert.deepEqual(await query('tideforge_fractional_credit'), carryBefore,
    'Preview must not spend material carry');
  const priorRows = await queryTables(names);
  const priorPlayer = priorRows.player!.find(row =>
    id(row.player_id) === id(processingPlayer.player_id));
  assert(priorPlayer);
  await current.reducers.commitTideforgeActionFromDiscord({ ...processingTarget, nonce });
  const afterRows = await queryTables(names);
  assert(!afterRows.owned_specimen!.some(row => id(row.catch_id) === id(legacyFish.catch_id)));
  assert(!afterRows.tideforge_specimen_modifiers!
    .some(row => id(row.catch_id) === id(legacyFish.catch_id)));
  const amount = (rows: Row[], item: string) => BigInt(id(rows.find(row =>
    id(row.player_id) === id(processingPlayer.player_id) && row.item === item)?.quantity ?? 0));
  assert.equal(amount(afterRows.item_stack!, 'normal_fillets') - amount(materialBefore, 'normal_fillets'),
    filletCredit / 10_000n);
  assert.equal(amount(afterRows.item_stack!, 'normal_scales') - amount(materialBefore, 'normal_scales'),
    scaleCredit / 10_000n);
  assert.equal(carry('normal_fillets', afterRows.tideforge_fractional_credit!),
    Number(filletCredit % 10_000n));
  assert.equal(carry('normal_scales', afterRows.tideforge_fractional_credit!),
    Number(scaleCredit % 10_000n));
  const afterPlayer = afterRows.player!.find(row => id(row.player_id) === id(processingPlayer.player_id));
  assert(afterPlayer);
  const processedPlayerEvidence = {
    coins: id(afterPlayer.coins), xp: id(afterPlayer.total_xp), kept: afterPlayer.kept_count,
  };
  assert.deepEqual(afterPlayer,
    { ...priorPlayer, kept_count: priorPlayer.kept_count - 1 },
    'Processing changes kept count, not saved coins, XP or player progress');
  const mutable = new Set([
    'owned_specimen', 'tideforge_specimen_modifiers', 'item_stack', 'economy_ledger', 'player',
    'tideforge_fractional_credit', 'tideforge_economy_quote', 'tideforge_economy_receipt',
  ]);
  for (const name of names.filter(name => !mutable.has(name))) {
    const owned = (rows: Row[]): string[] => canonical(rows.filter(row =>
      id(row.player_id) === id(processingPlayer.player_id)));
    assert.deepEqual(owned(afterRows[name]!), owned(priorRows[name]!),
      `Processing preserves the idle owner's ${name} rows`);
  }
  const processed = Object.fromEntries(Object.entries(afterRows).map(([name, rows]) =>
    [name, canonical(rows)]));
  await current.reducers.commitTideforgeActionFromDiscord({ ...processingTarget, nonce });
  assert.deepEqual(await snapshot(names), processed, 'Processing confirmation replay pays nothing twice');
  await assert.rejects(current.reducers.prepareSaleFromDiscord({
    ...processingTarget, catchIds: [BigInt(id(legacyFish.catch_id))],
  }), /CATCH_UNAVAILABLE/);
  await phase('processing-verified');

  const netTarget = await target(current, oldCasts[1]!.discordUserId);
  const freshNetId = snowflake();
  await current.reducers.startHaulFromDiscord({
    ...netTarget, interactionId: freshNetId, guildId: undefined, channelId: 42n, kind: 'net',
  });
  const freshNetEffects = (await query('tideforge_activity_snapshot'))
    .find(row => row.key === `haul:${freshNetId}`);
  assert(freshNetEffects);
  assert.equal((await query('tideforge_raid_mark_booking')).length, 0,
    'Ordinary hauls are not raid Mark bookings');
  await target(current, freshTarget.discordUserId);
  await subscribe(current, [
    'SELECT * FROM my_tideforge_economy_wallet',
    'SELECT * FROM adapter_tideforge_economy_wallet',
    'SELECT * FROM adapter_tideforge_vault',
    'SELECT * FROM adapter_tideforge_codex',
    'SELECT * FROM adapter_tideforge_economy_receipts',
  ]);
  assert.equal([...current.db.myTideforgeEconomyWallet.iter()].length, 0,
    'Trusted adapter identity is not a linked browser');
  const selectedWallets = [...current.db.adapterTideforgeEconomyWallet.iter()];
  assert.equal(selectedWallets.length, 1, 'Selected fixture has an earned Mark wallet');
  assert.equal(id(selectedWallets[0]!.playerId), id(freshPlayer.player_id));
  const { client: stranger } = await connect();
  await subscribe(stranger, [
    'SELECT * FROM my_tideforge_economy_wallet',
    'SELECT * FROM adapter_tideforge_economy_wallet',
    'SELECT * FROM my_tideforge_vault', 'SELECT * FROM my_tideforge_codex',
  ]);
  assert.equal([...stranger.db.myTideforgeEconomyWallet.iter()].length, 0);
  assert.equal([...stranger.db.adapterTideforgeEconomyWallet.iter()].length, 0);
  assert.equal([...stranger.db.myTideforgeVault.iter()].length, 0);
  assert.equal([...stranger.db.myTideforgeCodex.iter()].length, 0);
  await assert.rejects(subscribe(stranger, ['SELECT * FROM tideforge_economy_wallet']),
    'Raw Mark wallets must not be public');
  await assert.rejects(subscribe(stranger, ['SELECT * FROM tideforge_affix_shard']),
    'Raw Vault resources must not be public');
  stranger.disconnect();
  await phase('privacy-verified');

  const deadline = Date.now() + 330_000;
  let settled: Row[] = [];
  while (Date.now() < deadline) {
    settled = (await query('haul_trip')).filter(row =>
      id(row.interaction_id) === id(oldNet.interaction_id) || id(row.interaction_id) === id(freshNetId));
    if (settled.length === 2 && settled.every(row => row.status !== 'running' && row.completed_at !== null)) break;
    await sleep(1000);
  }
  assert.equal(settled.length, 2);
  assert(settled.every(row => row.status !== 'running' && row.completed_at !== null));
  assert(settled.every(row => row.results.length === 4), 'Old and new vanilla nets keep four pulls');
  const finalSnapshots = await query('tideforge_activity_snapshot');
  for (const frozen of [oldNetEffects, freshNetEffects]) {
    assert.equal(finalSnapshots.find(row => row.key === frozen.key)?.effects_json, frozen.effects_json);
  }
  const completionBeforeReplay = await snapshot(names);
  for (const trip of settled) {
    await current.reducers.finishHaulFromAdapter({ interactionId: BigInt(id(trip.interaction_id)) });
  }
  assert.deepEqual(await snapshot(names), completionBeforeReplay, 'Haul replay grants no duplicate rewards');
  assert.equal(await databaseIdentity('fishbound-dev-local'), developmentIdentity);
  const report = {
    ...allocation, proofIdentity, developmentIdentity, status: 'passed',
    retained: summarize(before), retainedAfterPublication: summarize(published),
    activationBefore: summarize(activationBefore), activationAfter: summarize(afterActivation),
    addedTables: names.filter(name => !beforeNames.includes(name)),
    publishPreserved: true, activationPreserved: true, activationIdempotent: true,
    oldReplayPreserved: true, noHistoricalMarksOrCodex: true, achievementMarksExactlyOnce: true,
    legacyFishProcessingExactlyOnce: true, pinnedYieldsAndCarryVerified: true,
    selectedAdapterAndUnlinkedPrivacyVerified: true, oldAndNewNetNaturalSettlementVerified: true,
    achievementEvidence: {
      playerId: id(freshPlayer.player_id), startingMarks, finalMarks: freshWallet!.marks,
      newlyEarnedIds: newAchievements.map(row => id(row.achievement_id)),
      newMarkAwards: newAwards,
    },
    processingEvidence: {
      playerId: id(processingPlayer.player_id), catchId: id(legacyFish.catch_id),
      rarity: legacyFish.rarity, pinnedFilletBp: pinned?.fillet_bp ?? 0, pinnedScaleBp: pinned?.scale_bp ?? 0,
      filletsBefore: id(amount(materialBefore, 'normal_fillets')),
      filletsAfter: id(amount(afterRows.item_stack!, 'normal_fillets')),
      scalesBefore: id(amount(materialBefore, 'normal_scales')),
      scalesAfter: id(amount(afterRows.item_stack!, 'normal_scales')),
      filletCarryBefore: carry('normal_fillets', carryBefore),
      filletCarryAfter: carry('normal_fillets', afterRows.tideforge_fractional_credit!),
      scaleCarryBefore: carry('normal_scales', carryBefore),
      scaleCarryAfter: carry('normal_scales', afterRows.tideforge_fractional_credit!),
      coinsBefore: id(priorPlayer.coins), coinsAfter: processedPlayerEvidence.coins,
      xpBefore: id(priorPlayer.total_xp), xpAfter: processedPlayerEvidence.xp,
      keptBefore: priorPlayer.kept_count, keptAfter: processedPlayerEvidence.kept,
    },
    haulEvidence: {
      legacyTrips, completedNets: settled,
      preservedEffects: [oldNetEffects, freshNetEffects],
    },
    limitations: 'Actual-WASM fixture uses vanilla starter gear and ordinary gameplay, not production data. Purchases, high-tier Forge, recovery/signatures, raid rewards, rich-gear preservation and hostile-state cases require native module tests. No production migration or affordability forecast is claimed.',
    retention: 'One bounded Stage 3 proof database and the required baseline/evidence remain for review; no automatic deletion, deployment, service restart or Discord delivery.',
  };
  await writeFile(reportPath, JSON.stringify(report, null, 2));
  await phase('passed');
  console.log(`PASS ${beforeNames.length} retained tables; activation, Marks, processing, privacy and old/new net settlement; ${database}.`);
} catch (error) {
  await writeFile(statePath, JSON.stringify({
    ...allocation, proofIdentity, developmentIdentity, phase: 'failed', lastCompletedPhase,
  }));
  throw error;
} finally {
  for (const client of clients) client.disconnect();
}
