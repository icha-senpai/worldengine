import { moduleArtifactPath, requireRustWorkflow, rustTargetPath } from './rust-build.mjs';
import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';
import { pathToFileURL } from 'node:url';
import catalog from '../content/species.json';

requireRustWorkflow();

const uri='http://127.0.0.1:3127';
const database=`fishbound-proof-rank-maxima-${Date.now()}`;
assert(/^fishbound-proof-rank-maxima-\d+$/.test(database));
const oldWasm=resolve('.local/pre-rank-maxima-fixture.wasm');
const { DbConnection: OldDbConnection } = await import(pathToFileURL(resolve('.local/pre-rank-recognition-bindings/index.ts')).href);
const newWasm=moduleArtifactPath();
function run(command:string,args:string[]) { const r=spawnSync(command,args,{encoding:'utf8',windowsHide:true}); if(r.status!==0) throw Error(r.stderr||r.stdout); return r.stdout; }
const call=(name:string,args:string[]=[])=>run('spacetime',['call','--server',uri,'--no-config','--yes',database,name,...args]);
const publish=(wasm:string)=>run('spacetime',['publish','--server',uri,'--no-config','--yes=skip-login,migrate,break-clients','--delete-data=never','--bin-path',wasm,database]);
function decode(type:any,value:any):any {
  if(type.Product) return Object.fromEntries(type.Product.elements.map((e:any,i:number)=>[e.name.some,decode(e.algebraic_type,value[i])]));
  if(type.Sum) { const variant=type.Sum.variants[value[0]]; return variant.name.some==='none'?null:decode(variant.algebraic_type,value[1]); }
  return value;
}
const query=(table:string)=>JSON.parse(run('spacetime',['sql','--server',uri,'--no-config','--format','json',database,`SELECT * FROM ${table}`]),(_key:string,value:any,ctx?:{source:string})=>typeof value==='number'&&!Number.isSafeInteger(value)?ctx!.source:value).flatMap((block:any)=>block.rows.map((row:any[])=>Object.fromEntries(row.map((v,i)=>[block.schema.elements[i].name.some,decode(block.schema.elements[i].algebraic_type,v)]))));
const tables=[...(await readFile('spacetimedb/src/tables.rs','utf8')).matchAll(/accessor = (\w+)/g)].map(m=>m[1]).filter(t=>t!=='maintenance_job'&&t!=='rank_maximum');
const snapshot=()=>Object.fromEntries(tables.map(t=>[t,query(t)]));
const canonical=(rows:any[])=>rows.map(r=>JSON.stringify(r)).sort();
const connections:any[]=[];
async function connect(ctor:any,queries:string[],savedToken?:string) { let token=''; const c=await new Promise<any>((yes,no)=>ctor.builder().withToken(savedToken).withUri(uri).withDatabaseName(database).onConnect((c:any,_id:any,t:string)=>{token=t;yes(c);}).onConnectError((_c:any,e:any)=>no(e)).build()); connections.push(c); await new Promise<void>((yes,no)=>c.subscriptionBuilder().onApplied(()=>yes()).onError((ctx:any)=>no(ctx.event)).subscribe(queries.map(t=>`SELECT * FROM ${t}`))); return {c,token}; }
const pause=()=>new Promise(r=>setTimeout(r,1050));
const settle=()=>new Promise(r=>setTimeout(r,100));
async function until(check:()=>boolean) {const end=Date.now()+12000;while(!check()){assert(Date.now()<end,'subscriber update must arrive');await new Promise(r=>setTimeout(r,20));}}
let seq=1n; const snowflake=()=>((BigInt(Date.now())-1420070400000n)<<22n)|seq++;
const carp=catalog.species.find(f=>f.key==='carp')!;
const minnow=catalog.species.find(f=>f.key==='minnow')!;
const sock=catalog.species.find(f=>f.key==='nidalees-lost-sock')!;
const fihs=catalog.species.find(f=>f.key==='fihs')!;
try {
  publish(oldWasm);
  const {c:old,token:oldToken}=await connect(OldDbConnection,['my_service','adapter_player','adapter_baits','adapter_bait_loadout','adapter_cast_equipment']);
  call('configure_service',[JSON.stringify(old.identity.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);
  call('proof_multi_setup',['9871','13','10','13','10','0','1']);
  let args={discordUserId:9871n,displayName:'Rank maxima proof',interactionId:snowflake()};
  await old.reducers.selectDiscordPlayer(args);
  await old.reducers.setBaitSlotFromDiscord({...args,baitId:13,slot:1});await old.reducers.setBaitSlotFromDiscord({...args,baitId:13,slot:2});
  await old.reducers.fishFromDiscord({...args,guildId:undefined,channelId:42n});
  // Owned, sold journal, receipt-only, pull-only; duplicate and wrong-rank evidence.
  for (const [fish,rank,ld,wd,source] of [[carp,0,0,0,0],[carp,0,0,0,0],[carp,8,0,0,1],[carp,9,0,0,0],
      [carp,1,0,0,3],[carp,2,0,0,4],[carp,3,0,0,2],[minnow,0,1,0,0],[minnow,0,0,1,0],
      [sock,0,0,0,0],[fihs,9,0,0,1]] as const) {
    call('proof_rank_evidence',['9871',String(fish.speciesId),String(rank),String(ld),String(wd),String(source)]);
  }
  // A second angler completes every ordinary rank, including one UUR perfect.
  for(let rank=0;rank<10;rank++)call('proof_rank_evidence',['9872',String(minnow.speciesId),String(rank),'0','0','0']);
  call('activate_xp_progression');
  call('activate_perfect_catches'); // existing badge/title and UUR ledger must survive unchanged.
  const before=snapshot();assert(before.bait_loadout.some((row:any)=>row.secondary_bait_id===13));
  assert(before.perfect_species.length===3&&before.earned_achievement.length>=2);
  await writeFile(`.local/${database}-before.json`,JSON.stringify({database,uri,at:new Date().toISOString(),tables:before}));
  old.disconnect();publish(newWasm);
  const migrated=snapshot();for(const table of tables)assert.deepEqual(canonical(migrated[table]),canonical(before[table]),`${table} retained`);
  assert.equal(query('rank_maximum').length,0);
  assert.throws(()=>call('activate_rank_maxima'),/RANK_MAXIMA_UNAVAILABLE/);
  console.log(`PASS rules-14 -> actual rules-15 production WASM: all ${tables.length} existing tables retained exactly; new rank ledger empty; activation gate enforced.`);
  call('activate_xp_progression');const activated=snapshot();
  for(const table of tables.filter(t=>t!=='game_config'))assert.deepEqual(canonical(activated[table]),canonical(migrated[table]),`${table} retained by activation`);
  assert.equal(Math.max(...activated.game_config.map((row:any)=>row.version)),15);
  const {c:guest}=await connect(DbConnection,['rank_maximum']);
  await assert.rejects(guest.reducers.activateRankMaxima({}),/OWNER_REQUIRED/);
  call('activate_rank_maxima');const rows=query('rank_maximum');assert.equal(rows.length,17);
  const first=before.player.find((row:any)=>String(row.discord_user_id)==='9871');
  const second=before.player.find((row:any)=>String(row.discord_user_id)==='9872');
  const playerRows=rows.filter((row:any)=>row.player_id===first.player_id);assert.equal(playerRows.length,7);
  assert.deepEqual(playerRows.filter((row:any)=>row.species_id===carp.speciesId).map((row:any)=>row.rarity).sort(),['F','D','C','UR','UUR'].sort());
  assert(!playerRows.some((row:any)=>row.species_id===minnow.speciesId),'independent bests never qualify');
  const earliest=before.owned_specimen.filter((row:any)=>row.player_id===first.player_id&&row.species_id===carp.speciesId&&row.rarity==='F').sort((a:any,b:any)=>a.catch_id-b.catch_id)[0];
  assert.equal(playerRows.find((row:any)=>row.species_id===carp.speciesId&&row.rarity==='F').first_catch_id,earliest.catch_id);
  for(const table of tables)assert.deepEqual(canonical(query(table)),canonical(activated[table]),`${table} unchanged by backfill`);
  call('activate_rank_maxima');assert.deepEqual(canonical(query('rank_maximum')),canonical(rows));
  console.log('PASS paired-evidence recovery from inventory/journal/receipt/pull, earliest stamp, deduplication, near misses, wrong rank, exceptional species, authority and idempotence; no wallet/XP/title/badge changes.');
  const {rankMaximaSummary}=await import('../apps/web/src/lib/game/rank-maxima');
  const modelRows=rows.map((row:any)=>({playerId:BigInt(row.player_id),speciesId:row.species_id,rarity:row.rarity}));
  const species=query('species_definition').map((row:any)=>({speciesId:row.species_id,allowedRarities:row.allowed_rarities}));
  const one=rankMaximaSummary([...modelRows,...modelRows] as any,species as any,BigInt(first.player_id));
  const two=rankMaximaSummary(modelRows as any,species as any,BigInt(second.player_id));
  assert.equal(one.count,7);assert.equal(one.fullyMaxedSpecies,2);assert.equal(two.count,10);assert.equal(two.fullyMaxedSpecies,1);
  console.log('PASS web counters count unique pairs, every allowed rank for full completion, one-rank bonus species, and correct angler ownership.');
  // Fresh proof fixture includes the real new casting path, with deterministic F endpoints.
  const root=resolve('.local/rank-maxima-proof-module');await mkdir(root,{recursive:true});
  const rustPath=(path:string)=>JSON.stringify(resolve(path).replaceAll('\\','/'));
  await writeFile(root+'/Cargo.toml',(await readFile('spacetimedb/Cargo.toml','utf8')).replace('name = "fishing-game-module"','name = "fishing-rank-new-proof"').replace('path = "../crates/game-rules"',`path = ${rustPath('crates/game-rules')}`).replace('crate-type = ["cdylib"]','crate-type = ["cdylib"]\npath = "lib.rs"')+'\n[workspace]\n');
  await writeFile(root+'/Cargo.lock',(await readFile('spacetimedb/Cargo.lock','utf8')).replace('name = "fishing-game-module"','name = "fishing-rank-new-proof"'));
  await writeFile(root+'/lib.rs',(await readFile('spacetimedb/src/lib.rs','utf8')).replace(/^mod (\w+);$/gm,(_,name)=>`#[path = ${rustPath(`spacetimedb/src/${name}.rs`)}]\nmod ${name};`)+`\ninclude!(${rustPath('scripts/fixtures/crafting-boundary.rs')});\ninclude!(${rustPath('scripts/fixtures/multi-bait.rs')});\ninclude!(${rustPath('scripts/fixtures/rank-maxima.rs')});\ninclude!(${rustPath('scripts/fixtures/rank-maxima-casting.rs')});\n`);
  run('cargo',['build','--manifest-path',root+'/Cargo.toml','--target-dir',rustTargetPath(),'--target','wasm32-unknown-unknown','--release','--locked']);
  guest.disconnect();publish(rustTargetPath('wasm32-unknown-unknown', 'release', 'fishing_rank_new_proof.wasm'));
  const {c:bot}=await connect(DbConnection,['my_service','adapter_player','adapter_cast_pulls','rank_maximum']);
  call('configure_service',[JSON.stringify(bot.identity.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);
  call('proof_rank_cast_setup',['9873',String(carp.speciesId)]);
  await pause();args={discordUserId:9873n,displayName:'New rank cast',interactionId:snowflake()};await bot.reducers.selectDiscordPlayer(args);
  await bot.reducers.fishFromDiscord({...args,guildId:undefined,channelId:42n});
  const p=[...bot.db.adapterPlayer.iter()][0];const pull=[...bot.db.adapterCastPulls.iter()][0];
  assert.equal(pull.receipt.rarity,'F');assert.equal(pull.receipt.lengthMm,1);assert.equal(pull.receipt.weightG,1n);
  assert.equal(query('rank_maximum').filter((r:any)=>BigInt(r.player_id)===p.playerId).length,1);
  const ledger=canonical(query('rank_maximum'));
  await bot.reducers.fishFromDiscord({...args,guildId:undefined,channelId:42n});assert.deepEqual(canonical(query('rank_maximum')),ledger);
  call('proof_rank_cast_setup',['9873',String(carp.speciesId)]);await bot.reducers.fishFromDiscord({...args,interactionId:snowflake(),guildId:undefined,channelId:42n});
  assert.deepEqual(canonical(query('rank_maximum')),ledger,'repeated maxima never duplicate the pair');
  call('proof_rank_prune',['9873']);assert.deepEqual(canonical(query('rank_maximum')),ledger,'progress outlives inventory and every history source');
  console.log('PASS actual casting saves paired rank maximum; replay/repeat catches count once; inventory/history removal preserves marks.');
  // Restore canonical metadata for UI fixture, and link the seeded collection owner.
  call('activate_xp_progression');call('activate_species_measurements');
  const {c:linker}=await connect(DbConnection,['my_service']);call('configure_service',[JSON.stringify(linker.identity.toHexString()),JSON.stringify({accountLinker:{}}),'true']);
  const {c:ui,token}=await connect(DbConnection,['my_link_challenge','my_player','rank_maximum']);
  await ui.reducers.beginLinkChallenge({});const challenge=[...ui.db.myLinkChallenge.iter()][0];
  await linker.reducers.completeAccountLink({challengeId:challenge.challengeId,proof:challenge.proof,verifiedDiscordUserId:9871n});
  await until(()=>ui.db.myPlayer.count()===1n);
  await writeFile('.local/rank-maxima-ui.json',JSON.stringify({database,uri,token,playerId:first.player_id,otherId:second.player_id}));
  await writeFile(`.local/${database}-result.json`,JSON.stringify({passed:true,database,uri,retainedTables:tables.length,backfilledPairs:rows.length,at:new Date().toISOString()}));
  console.log('PASS profile/collection fixture ready; isolated database: '+database);
} finally {for(const c of connections)c.disconnect();}
