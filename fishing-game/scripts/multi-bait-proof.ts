import { moduleArtifactPath, requireRustWorkflow, rustTargetPath } from './rust-build.mjs';
import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';
import { pathToFileURL } from 'node:url';
import catalog from '../content/species.json';
import rules from '../content/game-rules.json';

requireRustWorkflow();

const uri='http://127.0.0.1:3127';
const database=`fishbound-proof-multi-bait-${Date.now()}`;
assert(/^fishbound-proof-multi-bait-\d+$/.test(database));
const oldWasm=resolve('.local/pre-multi-bait-fixture.wasm');
const { DbConnection: OldDbConnection } = await import(pathToFileURL(resolve('.local/pre-multi-bait-bindings/index.ts')).href);
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
const ordinary=catalog.species.find(f=>f.key==='carp')!;
try {
  publish(oldWasm);
  const {c:old,token:oldToken}=await connect(OldDbConnection,['my_service','adapter_player','adapter_shop_quote','adapter_bait_loadout','adapter_cast_equipment']);
  call('configure_service',[JSON.stringify(old.identity.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);
  call('prepare_trader_player',['9871','0','2000','1']); call('proof_materials',['9871','100','100']);
  let args={discordUserId:9871n,displayName:'Multi-bait proof',interactionId:snowflake()};
  await old.reducers.selectDiscordPlayer(args);
  await old.reducers.prepareShopFromDiscord({...args,listingId:113}); const quote=[...old.db.adapterShopQuote.iter()][0];
  await old.reducers.commitShopFromDiscord({...args,nonce:quote.nonce}); await old.reducers.equipBaitFromDiscord({...args,baitId:13});
  call('proof_cast_setup',['9871','0','1']); await old.reducers.fishFromDiscord({...args,guildId:undefined,channelId:42n});
  call('proof_focus_setup',['9872','1',String(ordinary.speciesId),'511','1','true','false']);
  await pause();args={...args,discordUserId:9872n,interactionId:snowflake()};await old.reducers.selectDiscordPlayer(args);
  await old.reducers.setCollectionFocusFromDiscord({...args,biomeId:1,enabled:true});
  call('activate_xp_progression');
  const before=snapshot();assert(before.cast_equipment_receipt.length>0&&before.bait_loadout.length>0&&before.collection_focus_preference.some((r:any)=>r.enabled));
  await writeFile(`.local/${database}-before.json`,JSON.stringify({database,uri,at:new Date().toISOString(),tables:before}));
  old.disconnect(); publish(newWasm);
  const migrated=snapshot();
  for(const table of tables) {
    let expected=before[table];
    if(table==='bait_loadout')expected=expected.map((r:any)=>({...r,secondary_bait_id:0}));
    if(table==='cast_equipment_receipt')expected=expected.map((r:any)=>({...r,secondary_bait_id:0,secondary_bait_uses_left:0,secondary_bait_item:'',secondary_bait_quantity:0}));
    assert.deepEqual(canonical(migrated[table]),canonical(expected),`${table} retained after migration`);
  }
  console.log(`PASS rules-13 -> actual rules-${rules.version} production WASM: all ${tables.length} prior tables preserved; secondary slots/receipt fields default zero/empty; existing Focus setting retained.`);
  const {c:gate}=await connect(DbConnection,['my_service'],oldToken);
  await assert.rejects(gate.reducers.setBaitSlotFromDiscord({...args,baitId:13,slot:2}),/MULTI_BAIT_UNAVAILABLE/);gate.disconnect();
  call('activate_xp_progression');const activated=snapshot();
  for(const table of tables.filter(t=>t!=='game_config'))assert.deepEqual(canonical(activated[table]),canonical(migrated[table]),`${table} preserved by activation`);
  assert.equal(Math.max(...activated.game_config.map((r:any)=>r.version)),rules.version);
  call('activate_xp_progression');assert.deepEqual(snapshot(),activated);
  console.log('PASS metadata-only, idempotent activation keeps wallets, XP, equipment, bait stock, collections, records and rewards.');
  const root=resolve('.local/multi-bait-proof-module'); await mkdir(root,{recursive:true});
  const rustPath=(path:string)=>JSON.stringify(resolve(path).replaceAll('\\','/'));
  await writeFile(root+'/Cargo.toml',(await readFile('spacetimedb/Cargo.toml','utf8')).replace('name = "fishing-game-module"','name = "fishing-multi-bait-proof"').replace('path = "../crates/game-rules"',`path = ${rustPath('crates/game-rules')}`).replace('crate-type = ["cdylib"]','crate-type = ["cdylib"]\npath = "lib.rs"')+'\n[workspace]\n');
  await writeFile(root+'/Cargo.lock',(await readFile('spacetimedb/Cargo.lock','utf8')).replace('name = "fishing-game-module"','name = "fishing-multi-bait-proof"'));
  await writeFile(root+'/lib.rs',(await readFile('spacetimedb/src/lib.rs','utf8')).replace(/^mod (\w+);$/gm,(_,name)=>`#[path = ${rustPath(`spacetimedb/src/${name}.rs`)}]\nmod ${name};`)+`\ninclude!(${rustPath('scripts/fixtures/crafting-boundary.rs')});\ninclude!(${rustPath('scripts/fixtures/multi-bait.rs')});\n`);
  run('cargo',['build','--manifest-path',root+'/Cargo.toml','--target-dir',rustTargetPath(),'--target','wasm32-unknown-unknown','--release','--locked']);
  publish(rustTargetPath('wasm32-unknown-unknown', 'release', 'fishing_multi_bait_proof.wasm'));
  const queries=['my_service','adapter_player','adapter_baits','adapter_bait_loadout','adapter_cast_equipment','adapter_cast_pulls','adapter_items','adapter_receipt','adapter_collection','game_config','public_profile','bait_definition','rod_definition','rod_bonuses','quality_definition'];
  const {c:bot}=await connect(DbConnection,queries);
  call('configure_service',[JSON.stringify(bot.identity.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);
  const {c:guest}=await connect(DbConnection,['public_profile','my_bait_loadout']);
  await assert.rejects(guest.reducers.activateXpProgression({}),/OWNER_REQUIRED/);
  await assert.rejects(guest.reducers.setBaitSlot({baitId:13,slot:2}),/ACCOUNT_NOT_LINKED/);
  const player=()=>[...bot.db.adapterPlayer.iter()][0];
  const uses=(id:number)=>[...bot.db.adapterBaits.iter()].find(r=>r.baitId===id)?.usesLeft??0n;
  const item=(name:string)=>[...bot.db.adapterItems.iter()].find(r=>r.item===name)?.quantity??0n;
  const select=async()=>{await pause();const selection={discordUserId:9871n,displayName:'Multi-bait proof',interactionId:snowflake()};await bot.reducers.selectDiscordPlayer(selection);return selection;};
  let selected=await select();
  await assert.rejects(bot.reducers.setBaitSlotFromDiscord({...selected,baitId:13,slot:3}),/INVALID_BAIT_SLOT/);
  await assert.rejects(bot.reducers.setBaitSlotFromDiscord({...selected,discordUserId:9873n,baitId:13,slot:2}),/REQUEST_CONFLICT/);
  const scenarios=[[10,10,6,0],[12,12,0,6],[10,12,3,3],[6,7,0,0],[8,8,0,0],[13,13,0,0],[3,5,0,0],[13,8,0,0]];
  for(const [first,second,tin,scrap] of scenarios) {
    call('proof_multi_setup',['9871',String(first),'10',String(second),'10','200',first===13||first===3?'0':'1']);
    selected=await select();assert.equal(player().totalXp,0n,'both slots work at level 1');
    await bot.reducers.setBaitSlotFromDiscord({...selected,baitId:first,slot:1});await bot.reducers.setBaitSlotFromDiscord({...selected,baitId:second,slot:2});
    const wallet=player().coins;const nextCast=player().nextCastAt.microsSinceUnixEpoch;
    assert.equal([...bot.db.adapterBaitLoadout.iter()][0].secondaryBaitId,second);assert.equal(player().nextCastAt.microsSinceUnixEpoch,nextCast);
    const tinBefore=item('rusted_tin'),scrapBefore=item('scrap');
    await bot.reducers.fishFromDiscord({...selected,guildId:undefined,channelId:42n});
    const equipment=[...bot.db.adapterCastEquipment.iter()][0];const pulls=[...bot.db.adapterCastPulls.iter()];
    assert.equal(pulls.length,2);assert.equal(equipment.baitId,first);assert.equal(equipment.secondaryBaitId,second);
    assert.equal(uses(first),first===second?8n:9n);assert.equal(uses(second),first===second?8n:9n);
    assert.equal(equipment.baitUsesLeft,uses(first));assert.equal(equipment.secondaryBaitUsesLeft,uses(second));
    const definitions=[...bot.db.baitDefinition.iter()];const a=definitions.find(b=>b.baitId===first)!;const b=definitions.find(b=>b.baitId===second)!;
    assert.equal(equipment.luckBp,a.luckBp+b.luckBp);assert.equal(equipment.xpBonusBp,a.xpBonusBp+b.xpBonusBp);
    assert.equal(equipment.baitSizeRolls,Math.max(1,(a.luckBp>0?a.sizeRolls:0)+(b.luckBp>0?b.sizeRolls:0)));
    const base=pulls.reduce((n,r)=>n+r.baseXp,0n);const receipt=[...bot.db.adapterReceipt.iter()][0];
    assert.equal(receipt.xpGranted,base*(10000n+BigInt(equipment.xpBonusBp))/10000n);
    if(tin||scrap){assert.equal(item('rusted_tin')-tinBefore,BigInt(tin+2));assert.equal(item('scrap')-scrapBefore,BigInt(scrap));} // two normal junk pulls add 2 tin
    assert.equal(player().coins,wallet+receipt.coinsGranted,'equipping/consumption never spends extra gold');
    const state=JSON.stringify({player:player(),baits:[...bot.db.adapterBaits.iter()],loadout:[...bot.db.adapterBaitLoadout.iter()],items:[...bot.db.adapterItems.iter()],equipment},(_key,value)=>typeof value==='bigint'?value.toString():value);
    await bot.reducers.fishFromDiscord({...selected,guildId:undefined,channelId:42n});
    assert.equal(JSON.stringify({player:player(),baits:[...bot.db.adapterBaits.iter()],loadout:[...bot.db.adapterBaitLoadout.iter()],items:[...bot.db.adapterItems.iter()],equipment:[...bot.db.adapterCastEquipment.iter()][0]},(_key,value)=>typeof value==='bigint'?value.toString():value),state,'replay consumes neither bait or rewards');
    await assert.rejects(bot.reducers.fishFromDiscord({...selected,interactionId:snowflake(),guildId:undefined,channelId:42n}),/COOLDOWN_ACTIVE/);
    assert.equal(uses(first),first===second?8n:9n);
  }
  console.log('PASS all categories, matching bait, mixed materials, additive XP/luck, 16 attempts, level-1 eligibility, two pulls/one use per slot, cooldown and replay.');
  call('proof_multi_setup',['9871','10','3','10','3','200','1']);selected=await select();
  await bot.reducers.setBaitSlotFromDiscord({...selected,baitId:10,slot:1});await bot.reducers.setBaitSlotFromDiscord({...selected,baitId:10,slot:2});
  await bot.reducers.fishFromDiscord({...selected,guildId:undefined,channelId:42n});assert.equal(uses(10),1n);assert.equal([...bot.db.adapterBaitLoadout.iter()][0].secondaryBaitId,0);
  await assert.rejects(bot.reducers.setBaitSlotFromDiscord({...selected,baitId:10,slot:2}),/BAIT_NOT_OWNED/);
  const tinBefore=item('rusted_tin');call('proof_cast_setup',['9871','200','1']);await bot.reducers.fishFromDiscord({...selected,interactionId:snowflake(),guildId:undefined,channelId:42n});
  assert.equal(uses(10),0n);assert.equal(item('rusted_tin')-tinBefore,5n);assert.equal([...bot.db.adapterBaitLoadout.iter()][0].baitId,0);
  // Either slot can remain active alone, and clearing slot 1 preserves slot 2.
  call('proof_multi_setup',['9871','6','1','13','2','200','1']);selected=await select();await bot.reducers.setBaitSlotFromDiscord({...selected,baitId:6,slot:1});await bot.reducers.setBaitSlotFromDiscord({...selected,baitId:13,slot:2});
  await bot.reducers.fishFromDiscord({...selected,guildId:undefined,channelId:42n});assert.equal([...bot.db.adapterBaitLoadout.iter()][0].baitId,0);assert.equal([...bot.db.adapterBaitLoadout.iter()][0].secondaryBaitId,13);
  assert.equal(uses(6),0n);assert.equal(uses(13),1n);
  console.log('PASS odd shared stock spends its final use once; depletion clears only unavailable slots; secondary bait continues when primary runs out.');
  const {c:linker}=await connect(DbConnection,['my_service']);call('configure_service',[JSON.stringify(linker.identity.toHexString()),JSON.stringify({accountLinker:{}}),'true']);
  const {c:browser,token}=await connect(DbConnection,['my_player','my_bait_loadout','my_baits','my_link_challenge']);
  await browser.reducers.beginLinkChallenge({});const challenge=[...browser.db.myLinkChallenge.iter()][0];await linker.reducers.completeAccountLink({challengeId:challenge.challengeId,proof:challenge.proof,verifiedDiscordUserId:9871n});
  await until(()=>browser.db.myPlayer.count()===1n);
  await browser.reducers.setBaitSlot({baitId:0,slot:1});assert.equal([...browser.db.myBaitLoadout.iter()][0].secondaryBaitId,13);
  await browser.reducers.setBaitSlot({baitId:0,slot:2});assert.equal([...browser.db.myBaitLoadout.iter()][0].secondaryBaitId,0);
  assert.equal(guest.db.myBaitLoadout.count(),0n);
  await browser.reducers.unlinkBrowser({});await until(()=>browser.db.myBaitLoadout.count()===0n);
  call('configure_service',[JSON.stringify(bot.identity.toHexString()),JSON.stringify({discordAdapter:{}}),'false']);await until(()=>bot.db.adapterBaitLoadout.count()===0n);
  call('proof_multi_setup',['9871','13','10','8','10','1','0']);
  const {c:ui,token:uiToken}=await connect(DbConnection,['my_link_challenge','my_bait_loadout']);await ui.reducers.beginLinkChallenge({});const link=[...ui.db.myLinkChallenge.iter()][0];await linker.reducers.completeAccountLink({challengeId:link.challengeId,proof:link.proof,verifiedDiscordUserId:9871n});
  await ui.reducers.setBaitSlot({baitId:13,slot:1});await ui.reducers.setBaitSlot({baitId:8,slot:2});
  await writeFile('.local/multi-bait-ui.json',JSON.stringify({database,uri,token:uiToken}));
  await writeFile(`.local/${database}-result.json`,JSON.stringify({passed:true,database,uri,scenarios:scenarios.length,at:new Date().toISOString()}));
  console.log('PASS browser/Discord identity gates, persisted slot control, unlink/revocation privacy. Proof database: '+database);
} finally {for(const c of connections)c.disconnect();}
