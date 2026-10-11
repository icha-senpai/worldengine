import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';

const database=`fishbound-proof-chum-migration-${Date.now()}`;
const uri='http://127.0.0.1:3127';
const run=(command:string,args:string[])=>{const r=spawnSync(command,args,{encoding:'utf8',windowsHide:true});if(r.status!==0)throw Error(r.stderr||r.stdout);return r.stdout;};
const publish=(wasm:string)=>run('spacetime',['publish','--server',uri,'--no-config','--yes=skip-login,migrate,break-clients','--delete-data=never','--bin-path',wasm,database]);
const call=(name:string,args:string[]=[])=>run('spacetime',['call','--server',uri,'--no-config','--yes',database,name,...args]);
const query=(table:string)=>JSON.parse(run('spacetime',['sql','--server',uri,'--no-config','--format','json',database,`SELECT * FROM ${table}`])).flatMap((b:any)=>b.rows.map((r:any[])=>Object.fromEntries(r.map((v,i)=>[b.schema.elements[i].name.some,v]))));
const connections:DbConnection[]=[];
const connect=()=>new Promise<DbConnection>((resolve,reject)=>{DbConnection.builder().withUri(uri).withDatabaseName(database).onConnect(c=>{connections.push(c);resolve(c);}).onConnectError((_,e)=>reject(e)).build();});
try {
  publish(process.argv[2]??'.local/pre-chum-tier-fixture.wasm');
  const bot=await connect();
  call('configure_service',[JSON.stringify(bot.identity!.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);
  await new Promise<void>((resolve,reject)=>bot.subscriptionBuilder().onApplied(()=>resolve()).onError(c=>reject(c.event)).subscribe(['adapter_player','adapter_shop_quote'].map(t=>`SELECT * FROM ${t}`)));
  call('prepare_trader_player',['9501','100000','1000000','1']);
  call('proof_materials',['9501','100','100']);
  let sequence=1n;
  const args=()=>({discordUserId:9501n,displayName:'Chum migration proof',interactionId:((BigInt(Date.now())-1420070400000n)<<22n)|sequence++});
  for(const listingId of [101,106,21]) {
    await new Promise(r=>setTimeout(r,1050));const a=args();await bot.reducers.selectDiscordPlayer(a);await bot.reducers.prepareShopFromDiscord({...a,listingId});
    await bot.reducers.commitShopFromDiscord({...a,nonce:[...bot.db.adapterShopQuote.iter()][0].nonce});
  }
  const a=args();await bot.reducers.selectDiscordPlayer(a);await bot.reducers.equipBaitFromDiscord({...a,baitId:1});await bot.reducers.fishFromDiscord({...a,guildId:5n,channelId:42n});
  call('activate_xp_progression');
  console.log(run(process.execPath,['scripts/xp-preservation.mjs','before',database]).trim());bot.disconnect();
  publish('spacetimedb/target/wasm32-unknown-unknown/release/fishing_game_module.wasm');
  assert(query('bait_definition').every((b:any)=>b.resource_quantity===0));
  assert(query('cast_equipment_receipt').every((r:any)=>r.bait_quantity===0));
  const guest=await connect();await assert.rejects(guest.reducers.activateXpProgression({}),/OWNER_REQUIRED/);
  call('activate_xp_progression');console.log(run(process.execPath,['scripts/xp-preservation.mjs','after',database]).trim());
  call('activate_xp_progression');console.log(run(process.execPath,['scripts/xp-preservation.mjs','after',database]).trim());
  console.log('PASS old 8-bait module migrated without reset; additive defaults, owner-only activation, 12 baits, retained wallets/XP/inventories/charges/gear/receipts, and repeat activation.');
} finally {for(const c of connections)c.disconnect();}
