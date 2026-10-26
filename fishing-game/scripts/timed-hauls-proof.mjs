import { moduleArtifactPath, requireRustWorkflow, rustTargetPath } from './rust-build.mjs';
import assert from 'node:assert/strict';
import { readFile, writeFile, mkdir, rm } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index.ts';

requireRustWorkflow();
const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-timed-haul-${Date.now()}`;
const proofRoot = resolve('.local', database);
assert.equal(resolve(proofRoot, '..'), resolve('.local'));
assert(/^fishbound-proof-timed-haul-\d+$/.test(database));
const cli = join(process.env.LOCALAPPDATA, 'SpacetimeDB/bin/2.12.0/spacetimedb-cli.exe');
const connections = [];
const keepBrowser = process.argv.includes('--browser');
let retained = false;
function run(command, args) {
  const result = spawnSync(command, args, { encoding: 'utf8', windowsHide: true });
  if (result.error || result.status !== 0) throw Error(result.stderr || result.stdout || String(result.error));
  return result.stdout;
}
const call = (name, args = []) => run(cli, ['call', '--server', uri, '--no-config', '--yes', database, name, ...args]);
const publish = wasm => run(cli, ['publish', '--server', uri, '--no-config', '--yes=skip-login,migrate,break-clients', '--delete-data=never', '--bin-path', wasm, database]);
const canonical = rows => rows.map(row => JSON.stringify(row)).sort();
function decode(type, value) {
  if (type.Product) return Object.fromEntries(type.Product.elements.map((e,i) => [e.name.some, decode(e.algebraic_type, value[i])]));
  if (type.Sum) { const v = type.Sum.variants[value[0]]; return v.name.some === 'none' ? null : decode(v.algebraic_type, value[1]); }
  return value;
}
const query = table => JSON.parse(run(cli, ['sql', '--server', uri, '--no-config', '--format', 'json', database, `SELECT * FROM ${table}`]),
  (_key, value, context) => typeof value === 'number' && !Number.isSafeInteger(value) ? context.source : value)
  .flatMap(block => block.rows.map(row => Object.fromEntries(row.map((v,i) => [block.schema.elements[i].name.some, decode(block.schema.elements[i].algebraic_type, v)]))));
const tables = () => JSON.parse(run(cli, ['describe', '--server', uri, '--no-config', '--json', database, 'tables'])).map(t => t.source_name).filter(t => t !== 'maintenance_job');
const snapshot = names => Object.fromEntries(names.map(name => [name, canonical(query(name))]));
async function connect(names, token) {
  const connected = await new Promise((yes,no) => {
    const c = DbConnection.builder().withUri(uri).withDatabaseName(database).withToken(token)
      .onConnect((c,_identity,token) => yes({ c, token })).onConnectError((_c,e) => no(e)).build();
    connections.push(c);
  });
  await new Promise((yes,no) => connected.c.subscriptionBuilder().onApplied(yes).onError(ctx => no(ctx.event)).subscribe(names.map(name => `SELECT * FROM ${name}`)));
  return connected;
}
async function until(check) {
  const deadline = Date.now()+10000;
  while (!check()) { assert(Date.now()<deadline,'Subscription update timed out'); await new Promise(r => setTimeout(r,20)); }
}
const pause = () => new Promise(r => setTimeout(r,1050));
let serial = 1n;
const snowflake = () => ((BigInt(Date.now())-1420070400000n)<<22n)|serial++;

try {
  publish('.local/timed-haul-previous.wasm');
  const {c: old} = await connect(['adapter_player']);
  call('configure_service',[JSON.stringify(old.identity.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);
  for (const discordUserId of [9451n,9452n]) {
    const interactionId=snowflake();
    await old.reducers.selectDiscordPlayer({discordUserId,interactionId,displayName:'Retained angler'});
    await old.reducers.fishFromDiscord({discordUserId,interactionId,guildId:undefined,channelId:42n});
    await old.reducers.dailyFromDiscord({discordUserId,interactionId:snowflake(),guildId:undefined,channelId:42n});
  }
  old.disconnect();
  const oldTables=tables(), before=snapshot(oldTables);
  publish(moduleArtifactPath());
  assert.deepEqual(snapshot(oldTables),before,'All prior tables and retained player/reward records survive migration exactly');
  assert.equal(query('haul_trip').length,0);
  assert.equal(query('haul_job').length,0);
  assert.equal(query('haul_request').length,0);
  console.log(`PASS additive migration: ${oldTables.length} existing tables unchanged; new private timer/trip tables empty.`);

  await mkdir(proofRoot);
  const rustPath = path => JSON.stringify(resolve(path).replaceAll('\\','/'));
  await writeFile(join(proofRoot,'Cargo.toml'),(await readFile('spacetimedb/Cargo.toml','utf8'))
    .replace('name = "fishing-game-module"','name = "fishing-timed-haul-proof"')
    .replace('path = "../crates/game-rules"',`path = ${rustPath('crates/game-rules')}`)
    .replace('crate-type = ["cdylib"]','crate-type = ["cdylib"]\npath = "lib.rs"')+'\n[workspace]\n');
  await writeFile(join(proofRoot,'Cargo.lock'),(await readFile('spacetimedb/Cargo.lock','utf8')).replace('name = "fishing-game-module"','name = "fishing-timed-haul-proof"'));
  await writeFile(join(proofRoot,'lib.rs'),(await readFile('spacetimedb/src/lib.rs','utf8')).replace(/^mod (\w+);$/gm,(_m,name) => `#[path = ${rustPath(`spacetimedb/src/${name}.rs`)}]\nmod ${name};`)+`\ninclude!(${rustPath('scripts/fixtures/timed-hauls.rs')});\n`);
  run('cargo',['build','--manifest-path',join(proofRoot,'Cargo.toml'),'--target','wasm32-unknown-unknown','--release','--locked']);
  publish(rustTargetPath('wasm32-unknown-unknown','release','fishing_timed_haul_proof.wasm'));
  const names=['adapter_player','adapter_haul_work','adapter_haul_status','adapter_baits','adapter_bait_loadout'];
  let {c: adapter,token: adapterToken}=await connect(names);
  const {c: other}=await connect(names);
  const {c: browser,token}=await connect(['my_haul_trips','my_player','my_haul_equipment','my_haul_loadout']);
  const {c: guest}=await connect(['my_haul_trips','adapter_haul_work','adapter_haul_status']);
  for (const service of [adapter,other]) call('configure_service',[JSON.stringify(service.identity.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);
  call('proof_timed_gear',['9461','107','6','1']);
  call('proof_grant_haul_licences',['9461']);
  call('proof_link_haul_player',['9461',JSON.stringify(browser.identity.toHexString())]);
  call('proof_timed_bait',['9461']);
  const discordUserId=9461n;
  const select=async (c,id=snowflake())=>c.reducers.selectDiscordPlayer({discordUserId,interactionId:id,displayName:'Timed angler'});
  await select(adapter);
  const trip=id=>[...adapter.db.adapterHaulWork.iter(),...adapter.db.adapterHaulStatus.iter()].find(t=>t.interactionId===id);
  const player=()=>query('player').find(p=>p.discord_user_id===9461);
  const start=async(kind,id=snowflake(),channelId=42n)=>{
    await adapter.reducers.startHaulFromDiscord({discordUserId,interactionId:id,guildId:undefined,channelId,kind});
    return id;
  };
  const unchanged=snapshot(['player','bait_stack','bait_loadout','item_stack','owned_specimen','command_receipt']);
  const boat=await start('boat');
  const net=await start('net');
  assert.equal(trip(boat).dueAt.microsSinceUnixEpoch-trip(boat).startedAt.microsSinceUnixEpoch,600_000_000n);
  assert.equal(trip(net).dueAt.microsSinceUnixEpoch-trip(net).startedAt.microsSinceUnixEpoch,240_000_000n);
  assert.equal(trip(boat).luckBp,26600);assert.equal(trip(boat).xpBonusBp,37400);
  assert.deepEqual(snapshot(Object.keys(unchanged)),unchanged,'Starting both trips spends no bait/materials and grants nothing early');
  const boatStatus=snowflake();
  await start('boat',boat);await start('boat',boatStatus);await start('net');
  assert.equal(query('haul_trip').length,2,'One trip of each kind; repeat command does not reset deadline or create a second trip');
  await assert.rejects(start('net',boat),/REQUEST_CONFLICT/);
  await assert.rejects(start('boat',boat,43n),/REQUEST_CONFLICT/);
  await assert.rejects(start('rod'),/INVALID_HAUL_KIND/);
  await assert.rejects(adapter.reducers.finishHaulFromAdapter({interactionId:boat}),/HAUL_NOT_READY/);
  await assert.rejects(guest.reducers.finishHaulFromAdapter({interactionId:boat}),/SERVICE_REQUIRED|SERVICE_UNAUTHORIZED/);
  await assert.rejects(guest.reducers.startHaulFromDiscord({discordUserId,interactionId:snowflake(),guildId:undefined,channelId:42n,kind:'boat'}),/SERVICE_REQUIRED|SERVICE_UNAUTHORIZED/);
  assert.equal(guest.db.adapterHaulWork.count(),0n);assert.equal(guest.db.adapterHaulStatus.count(),0n);assert.equal(guest.db.myHaulTrips.count(),0n);
  await until(()=>browser.db.myHaulTrips.count()===2n);
  const castId=snowflake();
  await adapter.reducers.fishFromDiscord({discordUserId,interactionId:castId,guildId:undefined,channelId:42n});
  assert.equal(player().completed_casts,1,'Normal /fish is available while both trips run');
  const nextCast=player().next_cast_at;
  const baitBefore=snapshot(['bait_stack','bait_loadout']);
  call('proof_timed_gear',['9461','101','0','2']);
  assert.equal(trip(boat).equipmentId,107);assert.equal(trip(boat).biome.biomeId,1);
  assert.equal(trip(boat).qualityLevel,6);assert.equal(trip(boat).luckBp,26600);
  for (const id of [boat,net]) call('proof_timed_due',[String(id),'0','false']);
  await Promise.all([adapter.reducers.finishHaulFromAdapter({interactionId:boat}),other.reducers.finishHaulFromAdapter({interactionId:boat})]);
  await adapter.reducers.finishHaulFromAdapter({interactionId:net});
  assert.equal(trip(boat).results.length,10);assert.equal(trip(net).results.length,4);
  assert.deepEqual(player().next_cast_at,nextCast,'Haul completion leaves the existing rod cooldown untouched');
  assert.deepEqual(snapshot(Object.keys(baitBefore)),baitBefore,'Neither haul consumes equipped bait');
  for (const id of [boat,net]) {
    const pulls=query('cast_pull').filter(p=>BigInt(p.interaction_id)===id);
    const equipment=query('cast_equipment_receipt').find(p=>BigInt(p.interaction_id)===id);
    const totalBase=pulls.reduce((n,p)=>n+BigInt(p.base_xp),0n);
    assert.equal(trip(id).totalXp,totalBase*BigInt(10000+trip(id).xpBonusBp)/10000n);
    assert.equal(trip(id).results.reduce((n,r)=>n+r.xpGranted,0n),trip(id).totalXp);
    assert.equal(equipment.bait_id,0);assert.equal(equipment.secondary_bait_id,0);assert.equal(equipment.power,0);
    assert.equal(query('journal_entry').filter(e=>e.key.startsWith(`pull:`)&&pulls.some(p=>e.key===`pull:${p.key}`)).length,pulls.length);
  }
  await select(adapter,boatStatus);
  await start('boat',boatStatus);
  assert.equal(query('haul_trip').length,2,'Replaying a status check after completion never starts a second trip');
  assert.equal([...adapter.db.adapterHaulStatus.iter()][0].interactionId,boat,'Status receipt still points to its original completed trip');
  const rewards=snapshot(['player','owned_specimen','item_stack','economy_ledger','player_species_progress','journal_entry','cast_pull','command_receipt','angler_statistics']);
  await adapter.reducers.finishHaulFromAdapter({interactionId:boat});
  await adapter.reducers.finishHaulFromAdapter({interactionId:net});
  assert.deepEqual(snapshot(Object.keys(rewards)),rewards,'Completion replay cannot reroll or grant anything twice');
  await assert.rejects(adapter.reducers.fishFromDiscord({discordUserId,interactionId:boat,guildId:undefined,channelId:42n}),/REQUEST_CONFLICT/);
  await assert.rejects(adapter.reducers.dailyFromDiscord({discordUserId,interactionId:boat,guildId:undefined,channelId:42n}),/REQUEST_CONFLICT/);
  await assert.rejects(start('boat',castId),/REQUEST_CONFLICT/);
  console.log('PASS fixed deadlines, independent slots, normal fishing, no bait/power, snapshot stats/biome, private views, per-pull journal/XP allocation and atomic replay.');

  await adapter.reducers.claimHaulDelivery({interactionId:boat,leaseNonce:11n});
  await other.reducers.claimHaulDelivery({interactionId:boat,leaseNonce:22n});
  assert.equal(trip(boat).leaseNonce,11n);
  await assert.rejects(other.reducers.acknowledgeHaulDelivery({interactionId:boat,leaseNonce:11n}),/DELIVERY_LEASE_CHANGED/);
  await assert.rejects(adapter.reducers.acknowledgeHaulDelivery({interactionId:boat,leaseNonce:22n}),/DELIVERY_LEASE_CHANGED/);
  adapter.disconnect();
  ({c:adapter}=await connect(names,adapterToken));
  await select(adapter);
  assert.equal(trip(boat).status,'ready','Committed rewards and pending delivery recover on a fresh adapter connection');
  call('proof_timed_expire_lease',[String(boat)]);
  await other.reducers.claimHaulDelivery({interactionId:boat,leaseNonce:22n});
  await assert.rejects(adapter.reducers.acknowledgeHaulDelivery({interactionId:boat,leaseNonce:11n}),/DELIVERY_LEASE_CHANGED/);
  await other.reducers.acknowledgeHaulDelivery({interactionId:boat,leaseNonce:22n});
  await other.reducers.acknowledgeHaulDelivery({interactionId:boat,leaseNonce:22n});
  await until(()=>!([...adapter.db.adapterHaulWork.iter()].some(t=>t.interactionId===boat)));
  await adapter.reducers.claimHaulDelivery({interactionId:net,leaseNonce:33n});
  await adapter.reducers.acknowledgeHaulDelivery({interactionId:net,leaseNonce:33n});
  console.log('PASS restart recovery, competing adapter leases, failed-send retry availability, stale ACK rejection and durable acknowledgement.');

  // Real scheduler execution, with only the proof deadline shortened after checking the production duration.
  call('proof_timed_gear',['9461','101','0','1']);
  call('proof_timed_category',['2']);
  const tins=await start('boat');
  call('proof_timed_category',['1']); // The underway trip must keep its original category weights.
  const beforeTin=query('item_stack').find(r=>r.player_id===player().player_id&&r.item==='rusted_tin')?.quantity ?? 0;
  call('proof_timed_due',[String(tins),'2','true']);
  await until(()=>trip(tins)?.status==='ready');
  assert.equal(trip(tins).results.length,10);assert(trip(tins).results.every(r=>r.outcome==='junk'));
  assert.equal(query('item_stack').find(r=>r.player_id===player().player_id&&r.item==='rusted_tin').quantity,beforeTin+10);
  assert(!query('haul_job').some(j=>BigInt(j.scheduled_id)===tins),'Scheduled job removed after real scheduled completion');
  await adapter.reducers.claimHaulDelivery({interactionId:tins,leaseNonce:44n});
  await adapter.reducers.acknowledgeHaulDelivery({interactionId:tins,leaseNonce:44n});
  assert.equal(query('haul_trip').filter(t=>t.status==='running').length,0,'Completion never auto-starts the next trip');
  console.log('PASS real scheduled completion and ten-tin haul; frozen category weights and no automatic restart.');

  for (const [kind,category,outcome] of [['net',1,'fish'],['boat',3,'treasure']]) {
    call('proof_timed_category',[String(category)]);
    call('proof_timed_gear',['9461',kind==='net'?'207':'107','6','1']);
    const id=await start(kind);
    call('proof_timed_due',[String(id),'0','false']);
    await adapter.reducers.finishHaulFromAdapter({interactionId:id});
    assert(trip(id).results.every(r=>r.outcome===outcome));
    assert.equal(trip(id).results.length,kind==='net'?4:10);
    if (outcome==='fish') for (const result of trip(id).results) assert(query('owned_specimen').some(s=>s.catch_id===Number(result.catchId)));
    if (outcome==='treasure') assert(trip(id).totalCoins>=250n && trip(id).totalCoins<=600n);
    await adapter.reducers.claimHaulDelivery({interactionId:id,leaseNonce:id});
    await adapter.reducers.acknowledgeHaulDelivery({interactionId:id,leaseNonce:id});
  }
  // Every equipped family reaches the real shared catch pipeline at maximum quality.
  const catalog=JSON.parse(await readFile('content/haul-equipment.json','utf8'));
  call('proof_timed_category',['0']);
  for (const gear of catalog.equipment) {
    call('proof_timed_gear',['9461',String(gear.equipmentId),'6','7']);
    const id=await start(gear.kind);
    assert.equal(trip(id).luckBp,gear.luckBp+1400);
    assert.equal(trip(id).xpBonusBp,gear.xpBonusBp+2600);
    call('proof_timed_due',[String(id),'0','false']);
    await adapter.reducers.finishHaulFromAdapter({interactionId:id});
    assert.equal(trip(id).results.length,gear.kind==='boat'?10:4);
    await adapter.reducers.claimHaulDelivery({interactionId:id,leaseNonce:id});
    await adapter.reducers.acknowledgeHaulDelivery({interactionId:id,leaseNonce:id});
  }
  console.log('PASS guaranteed fish/treasure flows and all fourteen Prismatic equipment families including maximum boat luck/XP; existing collections, records, quests and Journal receive shared rewards.');
  for (const original of before.owned_rod.map(row=>JSON.parse(row))) assert.deepEqual(query('owned_rod').find(row=>String(row.key)===String(original.key)),original,'Existing rod ownership and qualities retained');
  // Exercise the same native Client used by slash commands and the delivery worker.
  run('cargo',['build','-p','game-client','--bin','haul-proof','--release','--locked']);
  const nativeIds=[snowflake(),snowflake(),snowflake()];
  const native=rustTargetPath('release',process.platform==='win32'?'haul-proof.exe':'haul-proof');
  const nativeEnv={...process.env,FISHBOUND_PROOF_DATABASE:database,FISHBOUND_PROOF_TOKEN:adapterToken};
  function runNative(phase) {
    const result=spawnSync(native,[phase,...nativeIds.map(String)],{env:nativeEnv,encoding:'utf8',windowsHide:true});
    if(result.error||result.status!==0) throw Error(result.stderr||result.stdout||String(result.error));
    console.log(result.stdout.trim());
  }
  runNative('start');
  for(const id of nativeIds.slice(0,2))call('proof_timed_due',[String(id),'0','false']);
  runNative('complete');
  if(keepBrowser) {
    call('proof_timed_gear',['9461','107','6','1']);
    await start('boat');await start('net');
    await writeFile('.local/timed-hauls-browser.json',JSON.stringify({uri,database,token,proofRoot},null,2));
    retained=true;
    console.log(`Browser fixture ready: ${database}. Delete after UI verification.`);
  }
} finally {
  for(const c of connections)c.disconnect();
  if(!retained) {
    run(cli,['delete','--server',uri,'--no-config','--yes',database]);
    await rm(proofRoot,{recursive:true,force:true});
  }
}
