import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';
const uri='http://127.0.0.1:3127';
const database=`fishbound-proof-achievements-migration-${Date.now()}`;
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
  publish('.local/pre-biome-achievements.wasm');
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
  console.log(run(process.execPath,['scripts/achievement-preservation.mjs','before',database]).trim());
  publish('spacetimedb/target/wasm32-unknown-unknown/release/fishing_game_module.wasm');
  console.log(run(process.execPath,['scripts/backfill-biome-achievements.mjs',database]).trim());
  console.log(run(process.execPath,['scripts/achievement-preservation.mjs','after',database]).trim());
  console.log(run(process.execPath,['scripts/backfill-biome-achievements.mjs',database]).trim());
  const {c}=await connect(['my_achievement_progress','achievement_definition','achievement_collection','earned_achievement'],browser.token);
  assert.equal(c.db.achievementDefinition.count(),113n);
  assert.equal(c.db.achievementCollection.count(),96n);
  assert.equal(c.db.myAchievementProgress.count(),113n);
  assert.equal([...c.db.myAchievementProgress.iter()].find(r=>r.achievementId===30)!.current,[...c.db.myAchievementProgress.iter()].find(r=>r.achievementId===2)!.current);
  const awards=[...c.db.earnedAchievement.iter()];
  console.log(run(process.execPath,['scripts/backfill-biome-achievements.mjs',database]).trim());
  await new Promise(r=>setTimeout(r,100));
  assert.deepEqual([...c.db.earnedAchievement.iter()],awards);
  console.log('PASS previous production WASM upgrades additively, credits saved collections once, and preserves gameplay plus original awards.');
} finally {connections.forEach(c=>c.disconnect());}
