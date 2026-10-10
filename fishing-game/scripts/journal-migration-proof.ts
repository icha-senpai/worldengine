import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';
const uri='http://127.0.0.1:3127';
const database=`fishbound-proof-journal-migration-${Date.now()}`;
const run=(command:string,args:string[])=>{const r=spawnSync(command,args,{encoding:'utf8',windowsHide:true});if(r.status!==0)throw Error(r.stderr||r.stdout);return r.stdout;};
const publish=(wasm:string)=>run('spacetime',['publish','--server',uri,'--no-config','--yes=skip-login,migrate,break-clients','--delete-data=never','--bin-path',wasm,database]);
const call=(name:string,args:string[]=[])=>run('spacetime',['call','--server',uri,'--no-config','--yes',database,name,...args]);
const connections:DbConnection[]=[];
async function connect(queries:string[],token?:string) {
  const auth=await new Promise<{c:DbConnection;token:string}>((resolve,reject)=>DbConnection.builder().withUri(uri).withDatabaseName(database).withToken(token).onConnect((c,_,token)=>resolve({c,token})).onConnectError((_,e)=>reject(e)).build());
  connections.push(auth.c);
  await new Promise<void>((resolve,reject)=>auth.c.subscriptionBuilder().onApplied(()=>resolve()).onError(c=>reject(c.event)).subscribe(queries.map(t=>`SELECT * FROM ${t}`)));
  return auth;
}
try {
  publish('.local/pre-journal.wasm');
  const {c:bot}=await connect(['adapter_player','adapter_receipt']);
  const {c:linker}=await connect(['my_service']);
  call('configure_service',[JSON.stringify(bot.identity!.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);
  call('configure_service',[JSON.stringify(linker.identity!.toHexString()),JSON.stringify({accountLinker:{}}),'true']);
  const args={discordUserId:9801n,displayName:'Journal migration proof',interactionId:(BigInt(Date.now())-1420070400000n)<<22n};
  await bot.reducers.selectDiscordPlayer(args); await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});
  const browser=await connect(['my_link_challenge']); await browser.c.reducers.beginLinkChallenge({});
  const ch=[...browser.c.db.myLinkChallenge.iter()][0];
  await linker.reducers.completeAccountLink({challengeId:ch.challengeId,proof:ch.proof,verifiedDiscordUserId:9801n});
  connections.forEach(c=>c.disconnect());
  console.log(run(process.execPath,['scripts/journal-preservation.mjs','before',database]).trim());
  publish('spacetimedb/target/wasm32-unknown-unknown/release/fishing_game_module.wasm');
  console.log(run(process.execPath,['scripts/backfill-journal.mjs',database]).trim());
  console.log(run(process.execPath,['scripts/journal-preservation.mjs','after',database]).trim());
  console.log(run(process.execPath,['scripts/backfill-journal.mjs',database]).trim());
  const {c}=await connect(['my_journal','my_recent_catches'],browser.token);
  const page=[...c.db.myJournal.iter()][0]; assert(page.totalEntries>0n);
  assert.equal(page.totalEntries,c.db.myRecentCatches.count());
  assert(page.entries.every(e=>e.xpGranted!==undefined && e.biomeId===1));
  console.log('PASS previous production WASM upgrades additively, imports exact saved pulls once, and preserves every existing table.');
} finally {connections.forEach(c=>c.disconnect());}
