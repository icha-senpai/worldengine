import { moduleArtifactPath, requireRustWorkflow } from './rust-build.mjs';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';
requireRustWorkflow();

const uri='http://127.0.0.1:3127';
const database=`fishbound-proof-statistics-migration-${Date.now()}`;
const run=(command:string,args:string[])=>{const r=spawnSync(command,args,{encoding:'utf8',windowsHide:true});if(r.status!==0)throw Error(r.stderr||r.stdout);return r.stdout;};
const publish=(wasm:string)=>run('spacetime',['publish','--server',uri,'--no-config','--yes=skip-login,migrate,break-clients','--delete-data=never','--bin-path',wasm,database]);
const call=(name:string,args:string[]=[])=>run('spacetime',['call','--server',uri,'--no-config','--yes',database,name,...args]);
const connections:DbConnection[]=[];
async function connect(tables:string[]) {
 const c=await new Promise<DbConnection>((resolve,reject)=>DbConnection.builder().withUri(uri).withDatabaseName(database).onConnect(c=>resolve(c)).onConnectError((_,e)=>reject(e)).build());connections.push(c);
 await new Promise<void>((resolve,reject)=>c.subscriptionBuilder().onApplied(()=>resolve()).onError(ctx=>reject(ctx.event)).subscribe(tables.map(t=>`SELECT * FROM ${t}`)));return c;
}
try {
 publish('.local/pre-catch-records.wasm');
 const bot=await connect(['adapter_player','adapter_receipt']);
 call('configure_service',[JSON.stringify(bot.identity!.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);
 const args={discordUserId:99601n,displayName:'Statistics migration proof',interactionId:(BigInt(Date.now())-1420070400000n)<<22n};
 await bot.reducers.selectDiscordPlayer(args);await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});
 const id=[...bot.db.adapterPlayer.iter()][0].playerId;connections.forEach(c=>c.disconnect());
 console.log(run(process.execPath,['scripts/catch-record-preservation.mjs','before',database]).trim());
 publish(moduleArtifactPath());
 call('backfill_player_statistics',[id.toString()]);
 call('backfill_catch_records');
 console.log(run(process.execPath,['scripts/catch-record-preservation.mjs','after',database]).trim());
 const c=await connect(['angler_statistics']);const stats=[...c.db.anglerStatistics.iter()][0];assert(stats);assert.equal(stats.totalCasts,1n);assert(stats.historyComplete);
 call('backfill_player_statistics',[id.toString()]);assert.deepEqual([...c.db.anglerStatistics.iter()][0],stats);
 await assert.rejects(c.reducers.backfillPlayerStatistics({playerId:id}),/OWNER_REQUIRED/);
 console.log('PASS previous production WASM upgrades without deleting data, all saved tables survive, historical stats recover, and repeated owner backfill is safe.');
} finally {connections.forEach(c=>c.disconnect());}
