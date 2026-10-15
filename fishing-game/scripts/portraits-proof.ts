import { moduleArtifactPath, requireRustWorkflow } from './rust-build.mjs';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { writeFile } from 'node:fs/promises';
import { DbConnection } from '../packages/generated/src/index';
requireRustWorkflow();

const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-portraits-${Date.now()}`;
const connections: DbConnection[] = [];
function run(command:string,args:string[]) {
  const r=spawnSync(command,args,{encoding:'utf8',windowsHide:true});
  if(r.status!==0)throw Error(r.stderr||r.stdout);return r.stdout;
}
const publish=(wasm:string)=>run('spacetime',['publish','--server',uri,'--no-config','--yes=skip-login,migrate,break-clients','--delete-data=never','--bin-path',wasm,database]);
const owner=(name:string,args:string[])=>run('spacetime',['call','--server',uri,'--no-config','--yes',database,name,...args]);
async function connect(tables:string[],token?:string) {
  const auth=await new Promise<{c:DbConnection;token:string}>((resolve,reject)=>DbConnection.builder().withUri(uri).withDatabaseName(database).withToken(token).onConnect((c,_,token)=>resolve({c,token})).onConnectError((_,error)=>reject(error)).build());
  connections.push(auth.c);await new Promise<void>((resolve,reject)=>auth.c.subscriptionBuilder().onApplied(()=>resolve()).onError(ctx=>reject(ctx.event)).subscribe(tables.map(t=>`SELECT * FROM ${t}`)));return auth;
}
try {
  publish('.local/pre-portraits.wasm');
  const bot=await connect(['my_service','adapter_player']);
  const linker=await connect(['my_service']);
  owner('configure_service',[JSON.stringify(bot.c.identity!.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);
  owner('configure_service',[JSON.stringify(linker.c.identity!.toHexString()),JSON.stringify({accountLinker:{}}),'true']);
  const user=99701n;
  const args={discordUserId:user,displayName:'Portrait proof',interactionId:(BigInt(Date.now())-1420070400000n)<<22n};
  await bot.c.reducers.selectDiscordPlayer(args);
  await bot.c.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});
  const id=[...bot.c.db.adapterPlayer.iter()][0].playerId;
  const browser=await connect(['my_link_challenge']);await browser.c.reducers.beginLinkChallenge({});
  const challenge=[...browser.c.db.myLinkChallenge.iter()][0];
  await linker.c.reducers.completeAccountLink({challengeId:challenge.challengeId,proof:challenge.proof,verifiedDiscordUserId:user});
  await browser.c.reducers.equipTitle({achievementId:1});
  console.log(run(process.execPath,['scripts/portrait-preservation.mjs','before',database]).trim());
  connections.forEach(c=>c.disconnect());publish(moduleArtifactPath());
  const adapter=await connect(['angler_portrait'],bot.token);
  const publicView=await connect(['angler_portrait']);
  const account=await connect(['angler_portrait'],linker.token);
  const player=await connect(['angler_portrait'],browser.token);
  assert.equal(adapter.c.db.anglerPortrait.count(),0n);
  const hash='1269e74af4df7417b13759eae50c83dc';
  const portraitArgs={discordUserId:user,avatarHash:hash,discriminator:0};
  await assert.rejects(player.c.reducers.syncDiscordPortrait(portraitArgs),/SERVICE_UNAUTHORIZED/);
  await assert.rejects(publicView.c.reducers.syncDiscordPortrait(portraitArgs),/SERVICE_UNAUTHORIZED/);
  await assert.rejects(adapter.c.reducers.syncDiscordPortrait({...portraitArgs,avatarHash:'https://example.com/avatar.png'}),/INVALID_DISCORD_PORTRAIT/);
  await assert.rejects(adapter.c.reducers.syncDiscordPortrait({...portraitArgs,discordUserId:123n}),/PLAYER_MISSING/);
  await adapter.c.reducers.syncDiscordPortrait(portraitArgs);
  let row=[...adapter.c.db.anglerPortrait.iter()][0];assert.equal(row.playerId,id);assert.equal(row.avatarUrl,`https://cdn.discordapp.com/avatars/${user}/${hash}.webp?size=128`);assert.equal(row.avatarUrl,row.stillUrl);
  await adapter.c.reducers.syncDiscordPortrait(portraitArgs);assert.deepEqual([...adapter.c.db.anglerPortrait.iter()][0],row,'Unchanged refresh must not create writes');
  await account.c.reducers.syncDiscordPortrait({...portraitArgs,avatarHash:'a_'+hash});
  row=[...account.c.db.anglerPortrait.iter()][0];assert(row.avatarUrl.endsWith('.gif?size=128'));assert(row.stillUrl.endsWith('.webp?size=128'));
  for(let i=0;i<100&&![...publicView.c.db.anglerPortrait.iter()][0]?.avatarUrl.endsWith('.gif?size=128');i++)await new Promise(resolve=>setTimeout(resolve,20));
  assert.equal([...publicView.c.db.anglerPortrait.iter()][0].avatarUrl,row.avatarUrl,'Public subscription must update');
  await adapter.c.reducers.syncDiscordPortrait({...portraitArgs,avatarHash:undefined});
  assert.equal([...adapter.c.db.anglerPortrait.iter()][0].avatarUrl,`https://cdn.discordapp.com/embed/avatars/${(user>>22n)%6n}.png`);
  await account.c.reducers.syncDiscordPortrait({...portraitArgs,avatarHash:undefined,discriminator:1234});
  assert.equal([...account.c.db.anglerPortrait.iter()][0].avatarUrl,'https://cdn.discordapp.com/embed/avatars/4.png');
  // Leave a default portrait for browser QA without invented real user artwork.
  await adapter.c.reducers.syncDiscordPortrait({...portraitArgs,avatarHash:undefined});
  await assert.rejects(adapter.c.reducers.syncDiscordPortrait({...portraitArgs,discriminator:10000}),/INVALID_DISCORD_PORTRAIT/);
  console.log(run(process.execPath,['scripts/portrait-preservation.mjs','after',database]).trim());
  console.log('PASS additive migration, unchanged existing player/link/collection/statistics data, adapter and OAuth authority, browser rejection, hash validation, static/animated avatars, both default avatar systems, idempotency and live public updates.');
  await writeFile('.local/portraits-proof.json',JSON.stringify({database,passed:true,date:new Date().toISOString()},null,2));
} finally { connections.forEach(c=>c.disconnect()); }
