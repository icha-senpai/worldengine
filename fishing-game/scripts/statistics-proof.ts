import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { spawnSync, spawn } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';

const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-statistics-${Date.now()}`;
const fixtureRoot = resolve('.local/statistics-boundary-module');
const targetRoot = resolve('spacetimedb/target');
const rustPath = (path: string) => JSON.stringify(resolve(path).replaceAll('\\', '/'));
function run(command: string, args: string[]) {
  const result = spawnSync(command, args, { encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw new Error(result.stderr || result.stdout);
  return result.stdout;
}
await mkdir(fixtureRoot, { recursive: true });
const manifest = (await readFile('spacetimedb/Cargo.toml', 'utf8'))
  .replace('name = "fishing-game-module"', 'name = "fishing-statistics-proof"')
  .replace('path = "../crates/game-rules"', `path = ${rustPath('crates/game-rules')}`)
  .replace('crate-type = ["cdylib"]', 'crate-type = ["cdylib"]\npath = "lib.rs"');
await writeFile(fixtureRoot + '/Cargo.toml', manifest + '\n[workspace]\n');
await writeFile(fixtureRoot + '/Cargo.lock', (await readFile('spacetimedb/Cargo.lock', 'utf8')).replace('name = "fishing-game-module"', 'name = "fishing-statistics-proof"'));
const source = (await readFile('spacetimedb/src/lib.rs', 'utf8')).replace(/^mod (\w+);$/gm, (_, name) => `#[path = ${rustPath(`spacetimedb/src/${name}.rs`)}]\nmod ${name};`);
await writeFile(fixtureRoot + '/lib.rs', source + `\ninclude!(${rustPath('scripts/fixtures/statistics-boundary.rs')});\n`);
run('cargo', ['build', '--manifest-path', fixtureRoot + '/Cargo.toml', '--target-dir', targetRoot, '--target', 'wasm32-unknown-unknown', '--release', '--locked']);
run('spacetime', ['publish', '--server', uri, '--no-config', '--yes=skip-login', '--bin-path', targetRoot + '/wasm32-unknown-unknown/release/fishing_statistics_proof.wasm', database]);
console.log('Built and published an isolated fixture module. Production WASM was not modified.');

const connections: DbConnection[] = [];
const ownerCall=(name:string,args:string[]=[])=>run('spacetime',['call','--server',uri,'--no-config','--yes',database,name,...args]);
let sequence=1n;
const snowflake=()=>((BigInt(Date.now())-1420070400000n)<<22n)|sequence++;
async function connect(tables:string[]) {
 const c=await new Promise<DbConnection>((resolve,reject)=>DbConnection.builder().withUri(uri).withDatabaseName(database).onConnect(c=>resolve(c)).onConnectError((_,e)=>reject(e)).build());connections.push(c);
 await new Promise<void>((resolve,reject)=>c.subscriptionBuilder().onApplied(()=>resolve()).onError(ctx=>reject(ctx.event)).subscribe(tables.map(t=>`SELECT * FROM ${t}`)));return c;
}
try {
 const bot=await connect(['adapter_player','adapter_inventory','adapter_sale_quote','angler_statistics','species_record','species_definition']);
 ownerCall('configure_service',[JSON.stringify(bot.identity!.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);
 const user=99501n; const args=()=>({discordUserId:user,displayName:'Statistics proof',interactionId:snowflake()});
 await bot.reducers.selectDiscordPlayer(args()); const id=[...bot.db.adapterPlayer.iter()][0].playerId;
 const stats=()=>[...bot.db.anglerStatistics.iter()].find(s=>s.playerId===id)!;
 for(const category of [0,1,2]) {
  ownerCall('proof_cast_setup',[user.toString(),'200',category.toString()]);
  const cast={...args(),guildId:5n,channelId:42n};await bot.reducers.fishFromDiscord(cast);
  const before=stats();await bot.reducers.fishFromDiscord(cast);assert.deepEqual(stats(),before,'Cast replay must not increment');
 }
 assert.equal(stats().totalCasts,3n);assert.equal(stats().bonusPulls,3n);
 assert.equal(stats().fishCount,2n);assert.equal(stats().junkCount,2n);assert.equal(stats().treasureCount,2n);
 assert.equal(stats().bonusFish,1n);assert.equal(stats().bonusJunk,1n);assert.equal(stats().bonusTreasure,1n);
 assert.equal(stats().daysFished,1);assert.equal(stats().favoriteBiomeId,1);assert.equal(stats().favoriteBiomeCasts,3n);
 assert(stats().historyComplete);assert(stats().biomeHistoryComplete);assert(stats().bonusHistoryComplete);
 assert.equal(stats().rankCounts.reduce((a,b)=>a+b,0n),2n);assert(stats().collectionPairs>=1);
 const fish=[...bot.db.adapterInventory.iter()];const weight=fish.reduce((sum,f)=>sum+f.weightG,0n);
 assert.equal(stats().totalWeightG,weight);assert(stats().recordsSet>=1n&&stats().recordsSet<=2n);
 const catalog=[...bot.db.speciesDefinition.iter()];
 const ratio=Math.max(...fish.map(f=>Math.floor(f.lengthMm*1000000/catalog.find(s=>s.speciesId===f.speciesId)!.typicalLengthMm)));
 assert.equal(stats().bestRelativeLengthMillionths,BigInt(ratio));
 const mostExpensive=fish.reduce((max,f)=>f.saleValueCoins>max?f.saleValueCoins:max,0n);
 assert.equal(stats().biggestSingleSale,mostExpensive,'Most expensive fish counts before selling');
 const historicRecords=stats().recordsSet;
 await assert.rejects(bot.reducers.backfillCatchRecords({}),/OWNER_REQUIRED/);
 ownerCall('proof_reset_catch_records',[id.toString()]);ownerCall('backfill_catch_records');
 await new Promise(resolve=>setTimeout(resolve,100));
 assert.equal(stats().recordsSet,historicRecords);assert.equal(stats().biggestSingleSale,mostExpensive);
 const catchHistory=stats();ownerCall('backfill_catch_records');await new Promise(resolve=>setTimeout(resolve,100));assert.deepEqual(stats(),catchHistory);
 ownerCall('proof_hide_fish_history',['true']);assert.throws(()=>ownerCall('backfill_catch_records'),/INCOMPLETE_CATCH_HISTORY/);ownerCall('proof_hide_fish_history',['false']);
 ownerCall('proof_mismatch_record',['true']);assert.throws(()=>ownerCall('backfill_catch_records'),/RECORD_HISTORY_MISMATCH/);ownerCall('proof_mismatch_record',['false']);
 await new Promise(resolve=>setTimeout(resolve,100));assert.deepEqual(stats(),catchHistory,'Rejected recovery must leave every statistic untouched');
 const saleArgs=args();await bot.reducers.selectDiscordPlayer(saleArgs);await bot.reducers.prepareSaleFromDiscord({...saleArgs,catchIds:fish.map(f=>f.catchId)});
 const quote=[...bot.db.adapterSaleQuote.iter()][0];await bot.reducers.commitSaleFromDiscord({...saleArgs,nonce:quote.nonce});
 assert.equal(stats().saleEarnings,quote.quotedCoins);assert.equal(stats().biggestSaleBatch,quote.quotedCoins);
 assert.equal(stats().biggestSingleSale,fish.reduce((max,f)=>f.saleValueCoins>max?f.saleValueCoins:max,0n));assert.equal(stats().totalWeightG,weight);
 const afterSale=stats();await bot.reducers.commitSaleFromDiscord({...saleArgs,nonce:quote.nonce});assert.deepEqual(stats(),afterSale);
 await assert.rejects(bot.reducers.backfillPlayerStatistics({playerId:id}),/OWNER_REQUIRED/);
 ownerCall('backfill_player_statistics',[id.toString()]);assert.deepEqual(stats(),afterSale,'Backfill cannot reset tracked counters');
 ownerCall('proof_statistics_history',[id.toString()]);ownerCall('backfill_player_statistics',[id.toString()]);
 for(let attempt=0;stats().daysFished!==2&&attempt<200;attempt++) await new Promise(resolve=>setTimeout(resolve,20));
 assert.equal(stats().daysFished,2);assert.equal(stats().bonusPulls,3n);assert.equal(stats().bonusFish,1n);assert.equal(stats().bonusJunk,1n);assert.equal(stats().bonusTreasure,1n);assert(stats().bonusHistoryComplete);assert.equal(stats().totalWeightG,weight);assert.equal(stats().saleEarnings,quote.quotedCoins);
 assert.equal(stats().recordsSet,0n);assert.equal(stats().biggestSingleSale,0n);assert.equal(stats().saleHistoryComplete,false);
 const recovered=stats();ownerCall('backfill_player_statistics',[id.toString()]);await new Promise(resolve=>setTimeout(resolve,100));assert.deepEqual(stats(),recovered);
 const singleUser=99502n;ownerCall('proof_cast_setup',[singleUser.toString(),'0','0']);
 const singleArgs={discordUserId:singleUser,displayName:'Single cast proof',interactionId:snowflake(),guildId:5n,channelId:42n};
 await bot.reducers.fishFromDiscord(singleArgs);
 const single=[...bot.db.anglerStatistics.iter()].find(s=>s.playerId!==id)!;assert.equal(single.totalCasts,1n);assert.equal(single.bonusPulls,0n);assert.equal(single.fishCount,1n);assert(single.historyComplete);
 await assert.rejects(bot.reducers.fishFromDiscord({...singleArgs,interactionId:snowflake()}),/COOLDOWN_ACTIVE/);
 assert.deepEqual([...bot.db.anglerStatistics.iter()].find(s=>s.playerId===single.playerId),single,'Rejected cast must leave stats unchanged');
 console.log('PASS double pulls for fish/junk/treasure, replay, sold weight, earnings, single and batch records, rank pairs, relative size, UTC days, biome activity, owner authorization and idempotent historical recovery.');
 await writeFile('.local/statistics-proof.json',JSON.stringify({database,passed:true,date:new Date().toISOString()},null,2));
} finally {connections.forEach(c=>c.disconnect());}
