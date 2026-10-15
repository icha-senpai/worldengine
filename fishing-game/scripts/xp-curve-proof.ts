import { moduleArtifactPath, requireRustWorkflow } from './rust-build.mjs';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';
import { levelForXp, xpToNextLevel } from '../apps/web/src/lib/game/progression';
import world from '../content/world.json';

requireRustWorkflow();

// Only this fresh proof database receives fixture mutations.
const database=`fishbound-proof-xp-curve-${Date.now()}`;
const uri='http://127.0.0.1:3127';
const run=(command:string,args:string[])=>{const r=spawnSync(command,args,{encoding:'utf8',windowsHide:true});if(r.status!==0)throw Error(r.stderr||r.stdout);return r.stdout;};
const publish=(wasm:string)=>run('spacetime',['publish','--server',uri,'--no-config','--yes=skip-login,migrate,break-clients','--delete-data=never','--bin-path',wasm,database]);
const call=(name:string,args:string[]=[])=>run('spacetime',['call','--server',uri,'--no-config','--yes',database,name,...args]);
const connections:DbConnection[]=[];
const connect=(token?:string)=>new Promise<DbConnection>((resolve,reject)=>{const c=DbConnection.builder().withUri(uri).withDatabaseName(database).withToken(token).onConnect(c=>resolve(c)).onConnectError((_,e)=>reject(e)).build();connections.push(c);});
const subscribe=(c:DbConnection)=>new Promise<void>((resolve,reject)=>c.subscriptionBuilder().onApplied(()=>resolve()).onError(ctx=>reject(ctx.event)).subscribe(['adapter_player','adapter_rods','adapter_baits','adapter_receipt','adapter_shop_quote','adapter_upgrade_quote','public_profile','game_config'].map(t=>`SELECT * FROM ${t}`)));
let sequence=0n;
const args=(user=9401n)=>({discordUserId:user,displayName:'XP curve proof',interactionId:((BigInt(Date.now())-1420070400000n)<<22n)|sequence++});
const pause=()=>new Promise(resolve=>setTimeout(resolve,1050));
try {
 assert.equal(Array.from({length:119},(_,i)=>xpToNextLevel(i+1,10)).reduce((a,b)=>a+b,0n),304395n);
 for(let level=1,total=0n;level<120;level++) {
  assert.equal(levelForXp(total,120,10),level);
  const needed=xpToNextLevel(level,10);
  assert.equal(levelForXp(total+needed-1n,120,10),level);
  assert.equal(levelForXp(total+needed,120,10),level+1);
  assert(needed<=xpToNextLevel(level,9));
  total+=needed;
 }
 publish(process.argv[2]??'.local/pre-casual-xp-fixture.wasm');
 let bot=await connect();call('configure_service',[JSON.stringify(bot.identity!.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);await subscribe(bot);
 const seeds=[100000,0,3190,60000,304395,3032120];
 for(const [i,xp] of seeds.entries())call('prepare_trader_player',[String(9401+i),String(xp),'1000000','1']);
 let gateArgs=args();
 const prepareLicence=async()=>{const a=args();gateArgs=a;await bot.reducers.selectDiscordPlayer(a);return bot.reducers.prepareShopFromDiscord({...a,listingId:60});};
 const purchase=async(listingId:number)=>{await pause();const a=args();await bot.reducers.selectDiscordPlayer(a);await bot.reducers.prepareShopFromDiscord({...a,listingId});const q=[...bot.db.adapterShopQuote.iter()][0];await bot.reducers.commitShopFromDiscord({...a,nonce:q.nonce});};
 for(const id of [20,30,40,50,21,106])await purchase(id);
 call('proof_materials',['9401','100','100']);
 await pause();const a=args();await bot.reducers.selectDiscordPlayer(a);await bot.reducers.prepareUpgradeFromDiscord({...a,rodId:2});const q=[...bot.db.adapterUpgradeQuote.iter()][0];await bot.reducers.commitUpgradeFromDiscord({...a,nonce:q.nonce});
 await bot.reducers.changeLoadoutFromDiscord({...a,rodId:2});await bot.reducers.equipBaitFromDiscord({...a,baitId:6});await bot.reducers.fishFromDiscord({...a,guildId:5n,channelId:42n});
 call('activate_xp_progression');
 await new Promise(resolve=>setTimeout(resolve,100));
 for(const p of bot.db.publicProfile.iter()) {const xp=seeds[Number(p.playerId)-1];if(p.playerId!==1n)assert.equal(p.level,levelForXp(BigInt(xp),120,9));}
 const selected=[...bot.db.adapterPlayer.iter()][0];assert.equal(levelForXp(selected.totalXp,120,9)<40,true);assert.equal(levelForXp(selected.totalXp,120,10)>=55,true);
 await assert.rejects(prepareLicence(),/SHOP_LEVEL_REQUIRED/);
 console.log(run(process.execPath,['scripts/xp-preservation.mjs','before',database]).trim());const botToken=bot.token;bot.disconnect();
 publish(moduleArtifactPath());bot=await connect(botToken);await subscribe(bot);
 await assert.rejects(bot.reducers.prepareShopFromDiscord({...gateArgs,listingId:60}),/SHOP_LEVEL_REQUIRED/);
 const guest=await connect();await assert.rejects(guest.reducers.activateXpProgression({}),/OWNER_REQUIRED/);
 call('activate_xp_progression');await new Promise(resolve=>setTimeout(resolve,100));console.log(run(process.execPath,['scripts/xp-preservation.mjs','after',database]).trim());
 call('activate_xp_progression');await new Promise(resolve=>setTimeout(resolve,100));console.log(run(process.execPath,['scripts/xp-preservation.mjs','after',database]).trim());
 await prepareLicence();
 assert.equal([...bot.db.adapterPlayer.iter()][0].coins,selected.coins,'Level activation and quote preparation do not spend gold');
 assert.equal([...bot.db.publicProfile.iter()].find(p=>p.playerId===1n)!.level,levelForXp(selected.totalXp,world.levelCap,10));
 console.log('PASS rules-9 gates retained until owner activation; rules-10 levels match frontend at boundaries and for retained XP; saved rewards, gear, wallets and inventories preserved; repeated activation and new licence eligibility.');
} finally {for(const c of connections)c.disconnect();}
