import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';
import { pathToFileURL } from 'node:url';
import catalog from '../content/species.json';
import rules from '../content/game-rules.json';

const uri='http://127.0.0.1:3127';
const database=`fishbound-proof-focus-${Date.now()}`;
assert(/^fishbound-proof-focus-\d+$/.test(database));
const oldWasm=resolve('.local/pre-collection-focus-fixture.wasm');
const { DbConnection: OldDbConnection } = await import(pathToFileURL(resolve('.local/pre-collection-focus-bindings/index.ts')).href);
const newWasm=resolve('spacetimedb/target/wasm32-unknown-unknown/release/fishing_game_module.wasm');
function run(command:string,args:string[]) { const r=spawnSync(command,args,{encoding:'utf8',windowsHide:true}); if(r.status!==0) throw Error(r.stderr||r.stdout); return r.stdout; }
const call=(name:string,args:string[]=[])=>run('spacetime',['call','--server',uri,'--no-config','--yes',database,name,...args]);
const publish=(wasm:string)=>run('spacetime',['publish','--server',uri,'--no-config','--yes=skip-login,migrate,break-clients','--delete-data=never','--bin-path',wasm,database]);
function decode(type:any,value:any):any {
  if(type.Product) return Object.fromEntries(type.Product.elements.map((e:any,i:number)=>[e.name.some,decode(e.algebraic_type,value[i])]));
  if(type.Sum) { const variant=type.Sum.variants[value[0]]; return variant.name.some==='none'?null:decode(variant.algebraic_type,value[1]); }
  return value;
}
const query=(table:string)=>JSON.parse(run('spacetime',['sql','--server',uri,'--no-config','--format','json',database,`SELECT * FROM ${table}`]),(_key:string,value:any,ctx?:{source:string})=>typeof value==='number'&&!Number.isSafeInteger(value)?ctx!.source:value).flatMap((block:any)=>block.rows.map((row:any[])=>Object.fromEntries(row.map((v,i)=>[block.schema.elements[i].name.some,decode(block.schema.elements[i].algebraic_type,v)]))));
const tables=[...(await readFile('spacetimedb/src/tables.rs','utf8')).matchAll(/accessor = (\w+)/g)].map(m=>m[1]).filter(t=>t!=='maintenance_job'&&t!=='rank_maximum'&&t!=='collection_focus_preference');
const snapshot=()=>Object.fromEntries(tables.map(t=>[t,query(t)]));
const canonical=(rows:any[])=>rows.map(r=>JSON.stringify(r)).sort();
const connections:any[]=[];
async function connect(ctor:any,queries:string[],savedToken?:string) { let token=''; const c=await new Promise<any>((yes,no)=>ctor.builder().withToken(savedToken).withUri(uri).withDatabaseName(database).onConnect((c:any,_id:any,t:string)=>{token=t;yes(c);}).onConnectError((_c:any,e:any)=>no(e)).build()); connections.push(c); await new Promise<void>((yes,no)=>c.subscriptionBuilder().onApplied(()=>yes()).onError((ctx:any)=>no(ctx.event)).subscribe(queries.map(t=>`SELECT * FROM ${t}`))); return {c,token}; }
const pause=()=>new Promise(r=>setTimeout(r,1050));
const settle=()=>new Promise(r=>setTimeout(r,100));
async function until(check:()=>boolean) {const end=Date.now()+12000;while(!check()){assert(Date.now()<end,'subscriber update must arrive');await new Promise(r=>setTimeout(r,20));}}
let seq=1n; const snowflake=()=>((BigInt(Date.now())-1420070400000n)<<22n)|seq++;
const ordinary=catalog.species.find(f=>f.key==='carp')!;
try {
  publish(oldWasm);
  const {c:old}=await connect(OldDbConnection,['my_service','adapter_player','adapter_shop_quote','adapter_upgrade_quote','adapter_baits','adapter_cast_equipment']);
  call('configure_service',[JSON.stringify(old.identity.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);
  call('prepare_trader_player',['9701','10000000','5000','1']);
  call('proof_materials',['9701','2000','1500']);
  const args={discordUserId:9701n,displayName:'Focus migration',interactionId:snowflake()};
  await old.reducers.selectDiscordPlayer(args);
  await old.reducers.prepareShopFromDiscord({...args,listingId:113});
  const offer=[...old.db.adapterShopQuote.iter()][0];
  await old.reducers.commitShopFromDiscord({...args,nonce:offer.nonce});
  await old.reducers.equipBaitFromDiscord({...args,baitId:13});
  await old.reducers.prepareUpgradeFromDiscord({...args,rodId:1});
  const upgrade=[...old.db.adapterUpgradeQuote.iter()][0];
  await old.reducers.commitUpgradeFromDiscord({...args,nonce:upgrade.nonce});
  call('proof_cast_setup',['9701','0','0']);
  await old.reducers.fishFromDiscord({...args,guildId:undefined,channelId:42n});
  call('activate_xp_progression'); // Restore catalog metadata changed by proof-only cast controls.
  const before=snapshot();
  assert(before.owned_specimen.length>0&&before.player_species_progress.length>0&&before.cast_equipment_receipt.length>0);
  await writeFile(`.local/${database}-before.json`,JSON.stringify({database,uri,at:new Date().toISOString(),tables:before}));
  old.disconnect();
  publish(newWasm);
  const after=snapshot();
  for(const table of tables) {
    const expected=table==='bait_loadout'?before[table].map((r:any)=>({...r,secondary_bait_id:0})):table==='cast_equipment_receipt'?before[table].map((r:any)=>({...r,collection_focus_enabled:false,collection_focus_rerolls:0,secondary_bait_id:0,secondary_bait_uses_left:0,secondary_bait_item:'',secondary_bait_quantity:0})):before[table];
    assert.deepEqual(canonical(after[table]),canonical(expected),`${table} preserved after no-delete migration`);
  }
  assert.deepEqual(query('collection_focus_preference'),[]);
  assert.equal(Math.max(...query('game_config').map((r:any)=>r.version)),12);
  console.log(`PASS old rules-12 fixture -> actual current production WASM: ${tables.length} retained tables identical, receipt defaults false/0, empty private preferences, no activation or player changes.`);
  let {c:bot}=await connect(DbConnection,['my_service','adapter_player','adapter_collection_focus','adapter_collection','adapter_cast_pulls','adapter_cast_equipment','adapter_baits','bait_definition','game_config']);
  call('configure_service',[JSON.stringify(bot.identity.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);
  let selected={discordUserId:9701n,displayName:'Focus proof',interactionId:snowflake()};
  await bot.reducers.selectDiscordPlayer(selected);
  await assert.rejects(bot.reducers.setCollectionFocusFromDiscord({...selected,biomeId:1,enabled:true}),/COLLECTION_FOCUS_UNAVAILABLE/);
  let {c:guest}=await connect(DbConnection,['public_profile','my_collection_focus']);
  await assert.rejects(guest.reducers.activateXpProgression({}),/OWNER_REQUIRED/);
  const activationBefore=snapshot();
  call('activate_xp_progression');
  const activated=snapshot();
  for(const table of tables.filter(t=>t!=='game_config')) assert.deepEqual(canonical(activated[table]),canonical(activationBefore[table]),`${table} preserved by focus activation`);
  assert.equal(Math.max(...activated.game_config.map((r:any)=>r.version)),rules.version);
  assert.deepEqual(canonical(activated.game_config.filter((r:any)=>r.version!==rules.version)),canonical(activationBefore.game_config));
  call('activate_xp_progression');
  assert.deepEqual(snapshot(),activated,'activation is idempotent');
  console.log('PASS owner-only, idempotent metadata activation preserves XP, coins, equipment, inventory, rewards, records and collection.');
  // Add owner-only controls in a separate artifact; production never includes them.
  const root=resolve('.local/collection-focus-proof-module'); await mkdir(root,{recursive:true});
  const rustPath=(path:string)=>JSON.stringify(resolve(path).replaceAll('\\','/'));
  await writeFile(root+'/Cargo.toml',(await readFile('spacetimedb/Cargo.toml','utf8')).replace('name = "fishing-game-module"','name = "fishing-focus-proof"').replace('path = "../crates/game-rules"',`path = ${rustPath('crates/game-rules')}`).replace('crate-type = ["cdylib"]','crate-type = ["cdylib"]\npath = "lib.rs"')+'\n[workspace]\n');
  await writeFile(root+'/Cargo.lock',(await readFile('spacetimedb/Cargo.lock','utf8')).replace('name = "fishing-game-module"','name = "fishing-focus-proof"'));
  await writeFile(root+'/lib.rs',(await readFile('spacetimedb/src/lib.rs','utf8')).replace(/^mod (\w+);$/gm,(_,name)=>`#[path = ${rustPath(`spacetimedb/src/${name}.rs`)}]\nmod ${name};`)+`\ninclude!(${rustPath('scripts/fixtures/crafting-boundary.rs')});\ninclude!(${rustPath('scripts/fixtures/collection-focus.rs')});\n`);
  run('cargo',['build','--manifest-path',root+'/Cargo.toml','--target-dir',resolve('spacetimedb/target'),'--target','wasm32-unknown-unknown','--release','--locked']);
  bot.disconnect(); guest.disconnect();
  publish(resolve('spacetimedb/target/wasm32-unknown-unknown/release/fishing_focus_proof.wasm'));
  const nextBot=await connect(DbConnection,['my_service','adapter_player','adapter_collection_focus','adapter_collection','adapter_cast_pulls','adapter_cast_equipment','adapter_baits','bait_definition','game_config']);
  bot=nextBot.c;
  guest=(await connect(DbConnection,['public_profile','my_collection_focus'])).c;
  call('configure_service',[JSON.stringify(bot.identity.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);
  selected={...selected,interactionId:snowflake()}; await bot.reducers.selectDiscordPlayer(selected);
  await assert.rejects(bot.reducers.setCollectionFocusFromDiscord({...selected,biomeId:1,enabled:true}),/COLLECTION_FOCUS_LOCKED/);
  await assert.rejects(guest.reducers.setCollectionFocus({biomeId:1,enabled:true}),/ACCOUNT_NOT_LINKED/);
  await assert.rejects(new Promise<void>((yes,no)=>guest.subscriptionBuilder().onApplied(()=>yes()).onError((ctx:any)=>no(Error(String(ctx.event)))).subscribe(['SELECT * FROM collection_focus_preference'])));
  await assert.rejects(bot.reducers.setCollectionFocusFromDiscord({...selected,discordUserId:9702n,biomeId:1,enabled:true}),/REQUEST_CONFLICT/);
  call('proof_focus_setup',['9701','1',String(ordinary.speciesId),'511','198','true','false']); // Uncommon quality has +2 power: force exactly 2 pulls.
  await settle();
  const playerBefore=[...bot.db.adapterPlayer.iter()][0];
  await bot.reducers.setCollectionFocusFromDiscord({...selected,biomeId:1,enabled:true});
  assert.equal([...bot.db.adapterCollectionFocus.iter()][0].enabled,true);
  assert.deepEqual([...bot.db.adapterPlayer.iter()][0],playerBefore,'toggle has no XP, gold or cooldown cost');
  await bot.reducers.setCollectionFocusFromDiscord({...selected,biomeId:1,enabled:true});
  assert.equal(bot.db.adapterCollectionFocus.count(),1n);
  const {c:linker}=await connect(DbConnection,['my_service']);
  call('configure_service',[JSON.stringify(linker.identity.toHexString()),JSON.stringify({accountLinker:{}}),'true']);
  let {c:browser,token}=await connect(DbConnection,['my_player','my_collection','my_collection_focus','my_link_challenge','my_baits','my_bait_loadout','my_rods','my_licences','game_config','species_definition','biome_definition','rod_definition','rod_bonuses','quality_definition','bait_definition','public_profile']);
  await browser.reducers.beginLinkChallenge({}); const challenge=[...browser.db.myLinkChallenge.iter()][0];
  await linker.reducers.completeAccountLink({challengeId:challenge.challengeId,proof:challenge.proof,verifiedDiscordUserId:9701n});
  await settle(); assert.equal(browser.db.myCollectionFocus.count(),1n); assert.equal(guest.db.myCollectionFocus.count(),0n);
  await browser.reducers.setCollectionFocus({biomeId:1,enabled:false}); await until(()=>[...bot.db.adapterCollectionFocus.iter()][0]?.enabled===false); assert.equal([...bot.db.adapterCollectionFocus.iter()][0].enabled,false);
  await browser.reducers.setCollectionFocus({biomeId:1,enabled:true}); await until(()=>[...bot.db.adapterCollectionFocus.iter()][0]?.enabled===true);
  await assert.rejects(browser.reducers.setCollectionFocus({biomeId:2,enabled:true}),/BIOME_LICENCE_REQUIRED/);
  let changed=0;
  // Each first UUR discovery disables rerolls on the second pull immediately.
  // Repeat controlled histories using the real casting reducer and real bait.
  call('configure_service',[JSON.stringify(query('deployment_owner')[0].identity.__identity__),JSON.stringify({discordAdapter:{}}),'true']);
  for(let i=0;i<50;i++) {
    const baitBefore=query('bait_stack').find((r:any)=>r.bait_id===13)?.uses_left ?? 0;
    const id=snowflake(); call('proof_focus_cast',['9701',id.toString(),String(ordinary.speciesId),'511']); await settle();
    const baitAfter=query('bait_stack').find((r:any)=>r.bait_id===13)?.uses_left ?? 0;
    assert.equal(baitAfter,Math.max(0,baitBefore-1),'exactly one bait use per cast, regardless of focus rerolls or pulls');
    const receipts=query('cast_equipment_receipt'); const receipt=receipts.find((r:any)=>String(r.interaction_id)===id.toString());
    assert(receipt.collection_focus_enabled); assert(receipt.collection_focus_rerolls<=1,'second pull cannot reroll after first fills final rank');
    changed+=receipt.collection_focus_rerolls;
    const pulls=query('cast_pull').filter((r:any)=>String(r.receipt.interaction_id)===id.toString());
    assert.equal(pulls.length,2);
    assert(pulls.every((r:any)=>r.receipt.species_id===ordinary.speciesId&&['F','UUR'].includes(r.receipt.rarity)));
    if(receipt.collection_focus_rerolls) assert(pulls.some((r:any)=>r.receipt.rarity==='UUR'));
    const replayBefore=query('cast_equipment_receipt');
    const replayState=i===0?snapshot():null;
    const replayPreferences=query('collection_focus_preference');
    // Direct replay bypasses the proof helper so it cannot reset any progress.
    call('fish_from_discord',['9701',id.toString(),'null','42']);
    assert.deepEqual(query('cast_equipment_receipt'),replayBefore);
    assert.deepEqual(query('collection_focus_preference'),replayPreferences);
    if(replayState)assert.deepEqual(snapshot(),replayState,'replayed cast preserves every gameplay table');
  }
  assert(changed>0,'real casts must exercise a successful focus reroll');
  console.log(`PASS ${changed} successful rerolls in 50 real two-pull casts; same fish, reachable ranks, current collection on second pull, immutable replay, no extra bait charges.`);
  // CLI snapshots block Node's socket heartbeat; reconnect before subsequent
  // browser assertions so fresh scoped views, rather than stale caches, prove privacy.
  browser.disconnect(); bot.disconnect(); guest.disconnect();
  browser=(await connect(DbConnection,['my_player','my_collection_focus'],token)).c;
  bot=(await connect(DbConnection,['my_service','adapter_collection_focus'],nextBot.token)).c;
  guest=(await connect(DbConnection,['public_profile','my_collection_focus'])).c;
  const abyssal=catalog.species.find(f=>f.biomeId===7&&f.countsForOrdinaryCollectionCompletion)!;
  call('proof_focus_setup',['9701','7',String(abyssal.speciesId),'1023','198','true','true']); await settle();
  await browser.reducers.setCollectionFocus({biomeId:7,enabled:true});
  const exceptionIds=new Set(catalog.species.filter(f=>!f.countsForOrdinaryCollectionCompletion).map(f=>f.speciesId)); let exceptions=0;
  for(let i=0;i<8;i++){const id=snowflake();call('proof_focus_cast',['9701',id.toString(),String(abyssal.speciesId),'1023']);const receipt=query('cast_equipment_receipt').find((r:any)=>String(r.interaction_id)===id.toString());assert.equal(receipt.collection_focus_rerolls,0); exceptions+=query('cast_pull').filter((r:any)=>String(r.receipt.interaction_id)===id.toString()&&exceptionIds.has(r.receipt.species_id)).length;}
  assert(exceptions>0);
  await browser.reducers.unlinkBrowser({}); await settle(); assert.equal(browser.db.myCollectionFocus.count(),0n);
  call('configure_service',[JSON.stringify(bot.identity.toHexString()),JSON.stringify({discordAdapter:{}}),'false']);await settle();assert.equal(bot.db.adapterCollectionFocus.count(),0n);
  // Return to Pond for a separate browser fixture review; the private token stays local.
  call('proof_focus_setup',['9701','1',String(ordinary.speciesId),'511','198','true','false']);
  await writeFile(`.local/${database}-result.json`,JSON.stringify({database,uri,changed,casts:50,exceptions,passed:true,at:new Date().toISOString()}));
  const {c:ui,token:uiToken}=await connect(DbConnection,['my_link_challenge']);
  await ui.reducers.beginLinkChallenge({}); const uiChallenge=[...ui.db.myLinkChallenge.iter()][0];
  await linker.reducers.completeAccountLink({challengeId:uiChallenge.challengeId,proof:uiChallenge.proof,verifiedDiscordUserId:9701n});
  await writeFile('.local/focus-ui.json',JSON.stringify({database,uri,token:uiToken}));
  console.log('PASS both client toggles, per-biome settings, guest isolation, unlink/service-revocation privacy, and unchanged Fihs/Sock ranks. Proof namespace: '+database);
} finally { for(const c of connections)c.disconnect(); }
