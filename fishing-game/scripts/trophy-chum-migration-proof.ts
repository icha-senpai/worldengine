import { moduleArtifactPath, requireRustWorkflow } from './rust-build.mjs';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';

requireRustWorkflow();

const database = `fishbound-proof-trophy-migration-${Date.now()}`;
const uri = 'http://127.0.0.1:3127';
const run = (command: string, args: string[]) => {
  const r = spawnSync(command, args, { encoding: 'utf8', windowsHide: true });
  if (r.status !== 0) throw Error(r.stderr || r.stdout);
  return r.stdout;
};
const publish = (wasm: string) =>
  run('spacetime', [
    'publish',
    '--server',
    uri,
    '--no-config',
    '--yes=skip-login,migrate,break-clients',
    '--delete-data=never',
    '--bin-path',
    wasm,
    database,
  ]);
const call = (name: string, args: string[] = []) =>
  run('spacetime', ['call', '--server', uri, '--no-config', '--yes', database, name, ...args]);
const query = (table: string) =>
  JSON.parse(
    run('spacetime', [
      'sql',
      '--server',
      uri,
      '--no-config',
      '--format',
      'json',
      database,
      `SELECT * FROM ${table}`,
    ]),
  ).flatMap((b: any) =>
    b.rows.map((r: any[]) =>
      Object.fromEntries(r.map((v, i) => [b.schema.elements[i].name.some, v])),
    ),
  );
const connections: DbConnection[] = [];
const connect = () =>
  new Promise<DbConnection>((resolve, reject) => {
    DbConnection.builder()
      .withUri(uri)
      .withDatabaseName(database)
      .onConnect((c) => {
        connections.push(c);
        resolve(c);
      })
      .onConnectError((_, e) => reject(e))
      .build();
  });
try {
  publish(process.argv[2] ?? '.local/pre-trophy-chum-fixture.wasm');
  const bot = await connect();
  call('configure_service', [
    JSON.stringify(bot.identity!.toHexString()),
    JSON.stringify({ discordAdapter: {} }),
    'true',
  ]);
  await new Promise<void>((resolve, reject) =>
    bot
      .subscriptionBuilder()
      .onApplied(() => resolve())
      .onError((c) => reject(c.event))
      .subscribe(['adapter_player', 'adapter_shop_quote'].map((t) => `SELECT * FROM ${t}`)),
  );
  call('prepare_trader_player', ['9501', '100000', '1000000', '1']);
  call('proof_materials', ['9501', '100', '100']);
  let sequence = 1n;
  const args = () => ({
    discordUserId: 9501n,
    displayName: 'Chum migration proof',
    interactionId: ((BigInt(Date.now()) - 1420070400000n) << 22n) | sequence++,
  });
  for (const listingId of [101, 106, 21]) {
    await new Promise((r) => setTimeout(r, 1050));
    const a = args();
    await bot.reducers.selectDiscordPlayer(a);
    await bot.reducers.prepareShopFromDiscord({ ...a, listingId });
    await bot.reducers.commitShopFromDiscord({
      ...a,
      nonce: [...bot.db.adapterShopQuote.iter()][0].nonce,
    });
  }
  const a = args();
  await bot.reducers.selectDiscordPlayer(a);
  await bot.reducers.equipBaitFromDiscord({ ...a, baitId: 1 });
  await bot.reducers.fishFromDiscord({ ...a, guildId: 5n, channelId: 42n });
  const speciesContent=JSON.parse(await readFile('content/species.json','utf8'));
  const carp=speciesContent.species.find((f:any)=>f.key==='carp');
  call('proof_perfect_history',['9501',String(carp.speciesId),'855','12291','800001']);
  call('proof_perfect_history',['9502',String(carp.speciesId),'854','12291','800002']);
  call('proof_perfect_history',['9502',String(carp.speciesId),'855','12290','800003']);
  call('activate_xp_progression');
  console.log(run(process.execPath, ['scripts/xp-preservation.mjs', 'before', database]).trim());
  bot.disconnect();
  publish(moduleArtifactPath());
  assert(
    query('bait_definition').every(
      (b: any) => b.size_rolls === 1,
    ),
  );
  assert(
    query('cast_equipment_receipt').every(
      (r: any) => r.bait_size_rolls === 1,
    ),
  );
  const guest = await connect();
  await assert.rejects(guest.reducers.activateXpProgression({}), /OWNER_REQUIRED/);
  call('activate_xp_progression');
  console.log(run(process.execPath, ['scripts/xp-preservation.mjs', 'after', database]).trim());
  call('activate_xp_progression');
  console.log(run(process.execPath, ['scripts/xp-preservation.mjs', 'after', database]).trim());
  await assert.rejects(guest.reducers.activatePerfectCatches({}), /OWNER_REQUIRED/);
  const names=[...(await readFile('spacetimedb/src/tables.rs','utf8')).matchAll(/accessor = (\w+)/g)].map(m=>m[1]).filter(t=>t!=='maintenance_job');
  const beforePerfect=Object.fromEntries(names.map(t=>[t,query(t)]));
  call('activate_perfect_catches');
  const afterPerfect=Object.fromEntries(names.map(t=>[t,query(t)]));
  const canonical=(rows:any[])=>rows.map(r=>JSON.stringify(r)).sort();
  const modified=['achievement_definition','achievement_progress','earned_achievement','perfect_species'];
  for(const table of names.filter(t=>!modified.includes(t))) assert.deepEqual(canonical(afterPerfect[table]),canonical(beforePerfect[table]),table+' preserved through perfect backfill');
  assert.equal(afterPerfect.perfect_species.length,1,'Paired perfect journal earns credit; separate near misses never combine');
  assert.equal(afterPerfect.perfect_species[0].first_catch_id,800001);
  assert.equal(afterPerfect.achievement_definition.length,114);
  for(const table of ['achievement_definition','achievement_progress','earned_achievement']) {
    assert.deepEqual(canonical(afterPerfect[table].filter((r:any)=>r.achievement_id!==19)),canonical(beforePerfect[table]),table+' old rows unchanged');
    const added=afterPerfect[table].filter((r:any)=>r.achievement_id===19);assert.equal(added.length,1);
  }
  assert.equal(afterPerfect.achievement_definition.find((r:any)=>r.achievement_id===19).title,'Perfect Angler');
  call('activate_perfect_catches');
  for(const table of modified) assert.deepEqual(canonical(query(table)),canonical(afterPerfect[table]),table+' repeat activation');
  console.log('PASS perfect-catch schema/backfill: sold historical fish credited from paired journal, near misses excluded, one badge/title, old achievement rows and all other gameplay tables preserved, owner-only and repeat activation.');
  console.log(
    'PASS old 12-bait module migrated without reset; additive defaults, owner-only activation, 13 baits, retained wallets/XP/inventories/charges/gear/receipts, and repeat activation.',
  );
} finally {
  for (const c of connections) c.disconnect();
}
