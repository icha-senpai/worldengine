import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';
const uri='http://127.0.0.1:3127';
const database=`fishbound-proof-xp-migration-${Date.now()}`;
const run=(command:string,args:string[])=>{const r=spawnSync(command,args,{encoding:'utf8',windowsHide:true});if(r.status!==0)throw Error(r.stderr||r.stdout);return r.stdout;};
const publish=(wasm:string)=>run('spacetime',['publish','--server',uri,'--no-config','--yes=skip-login,migrate,break-clients','--delete-data=never','--bin-path',wasm,database]);
const call=(name:string,args:string[]=[])=>run('spacetime',['call','--server',uri,'--no-config','--yes',database,name,...args]);
const connections:DbConnection[]=[];
const connect=()=>new Promise<DbConnection>((resolve,reject)=>{const c=DbConnection.builder().withUri(uri).withDatabaseName(database).onConnect(c=>resolve(c)).onConnectError((_,e)=>reject(e)).build();connections.push(c);});
try {
 publish('.local/pre-xp-bait.wasm');
 const bot=await connect();call('configure_service',[JSON.stringify(bot.identity!.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);
 await new Promise<void>((resolve,reject)=>bot.subscriptionBuilder().onApplied(()=>resolve()).onError(c=>reject(c.event)).subscribe(['SELECT * FROM adapter_player','SELECT * FROM adapter_receipt']));
 const args={discordUserId:9801n,displayName:'XP migration proof',interactionId:(BigInt(Date.now())-1420070400000n)<<22n};
 await bot.reducers.selectDiscordPlayer(args);await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});
 bot.disconnect();
 console.log(run(process.execPath,['scripts/xp-preservation.mjs','before',database]).trim());
 publish('spacetimedb/target/wasm32-unknown-unknown/release/fishing_game_module.wasm');
 const guest=await connect();await assert.rejects(guest.reducers.activateXpProgression({}),/OWNER_REQUIRED/);
 call('activate_xp_progression');
 console.log(run(process.execPath,['scripts/xp-preservation.mjs','after',database]).trim());
 call('activate_xp_progression');
 console.log(run(process.execPath,['scripts/xp-preservation.mjs','after',database]).trim());
 console.log('PASS old WASM additive column migration, saved cast preservation, owner-only activation and idempotency.');
} finally {for(const c of connections)c.disconnect();}
