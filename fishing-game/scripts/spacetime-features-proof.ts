import { moduleArtifactPath, requireRustWorkflow } from './rust-build.mjs';
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src';

requireRustWorkflow();

const uri='http://127.0.0.1:3127';
const database=`fishbound-proof-spacetime-${Date.now()}`;
const keep=process.argv.includes('--browser');
let retained=false;
const connections:DbConnection[]=[];
function run(args:string[]) {
 const result=spawnSync('spacetime',args,{encoding:'utf8',windowsHide:true});
 if(result.status!==0)throw Error(result.stderr||String(result.error));
 return result.stdout;
}
const call=(name:string,args:string[]=[])=>run(['call',database,name,...args,'--server',uri,'--yes','--no-config']);
const publish=(wasm:string)=>run(['publish',database,'--server',uri,'--bin-path',wasm,'--delete-data=never','--yes','--no-config']);
const querySql=(sql:string):any[]=>JSON.parse(run(['sql',database,sql,'--server',uri,'--format','json','--no-config']),
 (_key:string,value:any,context?:{source:string})=>typeof value==='number'&&!Number.isSafeInteger(value)?context!.source:value).flatMap((block:any)=>block.rows);
const query=(table:string)=>querySql(`SELECT * FROM ${table}`);
const canonical=(rows:any[])=>rows.map(row=>JSON.stringify(row)).sort();
async function connect(queries:string[],token?:string) {
 const result=await new Promise<{c:DbConnection;token:string}>((yes,no)=>DbConnection.builder().withUri(uri).withDatabaseName(database).withToken(token)
 .onConnect((c,_identity,token)=>yes({c,token})).onConnectError((_c,error)=>no(error)).build());
 connections.push(result.c);
 await new Promise<void>((yes,no)=>result.c.subscriptionBuilder().onApplied(()=>yes()).onError(ctx=>no(ctx.event)).subscribe(queries.map(t=>`SELECT * FROM ${t}`)));
 return result;
}
let serial=1n;
const snowflake=()=>((BigInt(Date.now())-1420070400000n)<<22n)|serial++;
const added=new Set(['journal_count','achievement_route','achievement_delivery']);
const source=await readFile('spacetimedb/src/tables.rs','utf8');
const tables=[...source.matchAll(/#\[spacetimedb::table\(accessor\s*=\s*(\w+)/g)].map(m=>m[1]).filter(t=>!added.has(t));
try {
 publish('.runtime/module/fishing-game-module.wasm');
 const bot=await connect(['adapter_player','adapter_receipt']);
 const linker=await connect(['my_service']);
 for(const [client,role] of [[bot,'discordAdapter'],[linker,'accountLinker']] as const)
  call('configure_service',[JSON.stringify(client.c.identity!.toHexString()),JSON.stringify({[role]:{}}),'true']);
 const interaction=snowflake();
 await bot.c.reducers.selectDiscordPlayer({discordUserId:9701n,displayName:'Migration proof',interactionId:interaction});
 await bot.c.reducers.fishFromDiscord({discordUserId:9701n,interactionId:interaction,guildId:undefined,channelId:42n});
 const browser=await connect(['my_link_challenge','my_player']);
 await browser.c.reducers.beginLinkChallenge({});
 const challenge=[...browser.c.db.myLinkChallenge.iter()][0];
 await linker.c.reducers.completeAccountLink({challengeId:challenge.challengeId,proof:challenge.proof,verifiedDiscordUserId:9701n});
 for(const c of connections)c.disconnect();
 const installedTables=new Set(querySql('SELECT table_name FROM st_table').map(row=>row[0]));
 const priorDeliveries=installedTables.has('achievement_delivery')?canonical(query('achievement_delivery')):[];
 const before=Object.fromEntries(tables.map(t=>[t,canonical(query(t))]));
 publish(moduleArtifactPath());
 for(const t of tables)assert.deepEqual(canonical(query(t)),before[t],`${t} must be preserved in full`);
 assert.deepEqual(canonical(query('achievement_delivery')),priorDeliveries,'migration must preserve pending deliveries without queuing historical awards');
 call('activate_history_queries');
 const counts=canonical(query('journal_count'));
 call('activate_history_queries');
 assert.deepEqual(canonical(query('journal_count')),counts,'count activation is idempotent');
 for(const t of tables)assert.deepEqual(canonical(query(t)),before[t],`${t} must survive activation`);
 const fresh=await connect(['adapter_achievement_deliveries','achievement_definition','public_profile'],bot.token);
 const replay=await connect(['my_journal','my_player'],browser.token);
 assert.equal([...replay.c.db.myJournal.iter()][0]?.totalEntries,BigInt(query('journal_entry').length));
 const id=snowflake();
 await fresh.c.reducers.recordAchievementRoute({discordUserId:9702n,channelId:42n});
 await fresh.c.reducers.selectDiscordPlayer({discordUserId:9702n,displayName:'Outbox proof',interactionId:id});
 await fresh.c.reducers.fishFromDiscord({discordUserId:9702n,interactionId:id,guildId:undefined,channelId:42n});
 const pending=[...fresh.c.db.adapterAchievementDeliveries.iter()].filter(row=>row.discordUserId===9702n);
 assert(pending.length>0,'new committed awards create durable deliveries');
 const guest=await connect(['adapter_achievement_deliveries']);
 assert.equal(guest.c.db.adapterAchievementDeliveries.count(),0n,'pending deliveries are service-private');
 await assert.rejects(guest.c.reducers.claimAchievementDelivery({key:pending[0].key,leaseNonce:7n}),/UNAUTHORIZED/);
 await fresh.c.reducers.claimAchievementDelivery({key:pending[0].key,leaseNonce:7n});
 fresh.c.disconnect();
 const resumed=await connect(['adapter_achievement_deliveries'],bot.token);
 assert.equal([...resumed.c.db.adapterAchievementDeliveries.iter()].find(row=>row.key===pending[0].key)?.leaseNonce,7n);
 await resumed.c.reducers.acknowledgeAchievementDelivery({key:pending[0].key,leaseNonce:7n});
 await resumed.c.reducers.acknowledgeAchievementDelivery({key:pending[0].key,leaseNonce:7n});
 assert(![...resumed.c.db.adapterAchievementDeliveries.iter()].some(row=>row.key===pending[0].key));
 console.log(`PASS ${tables.length} tables preserved field-for-field; idempotent journal counts, linked private journal, durable service-only deliveries and reconnect acknowledgements. No Discord messages sent.`);
 if(keep){await writeFile('.local/spacetime-features-preview.json',JSON.stringify({uri,database,token:browser.token}));retained=true;console.log('Private disposable browser fixture prepared.');}
} finally {
 for(const c of connections)c.disconnect();
 if(!retained)run(['delete',database,'--server',uri,'--yes','--no-config']);
}
