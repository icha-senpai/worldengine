import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { spawnSync, spawn } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';

const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-social-${Date.now()}`;
const fixtureRoot = resolve('.local/social-boundary-module');
const targetRoot = resolve('spacetimedb/target');
const rustPath = (path: string) => JSON.stringify(resolve(path).replaceAll('\\', '/'));
function run(command: string, args: string[]) {
  const result = spawnSync(command, args, { encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw new Error(result.stderr || result.stdout);
  return result.stdout;
}
await mkdir(fixtureRoot, { recursive: true });
const manifest = (await readFile('spacetimedb/Cargo.toml', 'utf8'))
  .replace('name = "fishing-game-module"', 'name = "fishing-social-proof"')
  .replace('path = "../crates/game-rules"', `path = ${rustPath('crates/game-rules')}`)
  .replace('crate-type = ["cdylib"]', 'crate-type = ["cdylib"]\npath = "lib.rs"');
await writeFile(fixtureRoot + '/Cargo.toml', manifest + '\n[workspace]\n');
await writeFile(fixtureRoot + '/Cargo.lock', (await readFile('spacetimedb/Cargo.lock', 'utf8')).replace('name = "fishing-game-module"', 'name = "fishing-social-proof"'));
const source = (await readFile('spacetimedb/src/lib.rs', 'utf8')).replace(/^mod (\w+);$/gm, (_, name) => `#[path = ${rustPath(`spacetimedb/src/${name}.rs`)}]\nmod ${name};`);
await writeFile(fixtureRoot + '/lib.rs', source + `\ninclude!(${rustPath('scripts/fixtures/social-boundary.rs')});\n`);
run('cargo', ['build', '--manifest-path', fixtureRoot + '/Cargo.toml', '--target-dir', targetRoot, '--target', 'wasm32-unknown-unknown', '--release', '--locked']);
run('spacetime', ['publish', '--server', uri, '--no-config', '--yes=skip-login', '--bin-path', targetRoot + '/wasm32-unknown-unknown/release/fishing_social_proof.wasm', database]);
console.log('Built and published an isolated fixture module. Production WASM was not modified.');

const connections: DbConnection[] = [];
const ownerCall = (name: string, args: string[] = []) => run('spacetime', ['call', '--server', uri, '--no-config', '--yes', database, name, ...args]);
let sequence = 1n;
const snowflake = () => ((BigInt(Date.now()) - 1420070400000n) << 22n) | sequence++;
const pause = () => new Promise(resolve => setTimeout(resolve, 1050));
async function waitFor(check: () => boolean, message: string) {
  const end = Date.now() + 12000;
  while (!check() && Date.now() < end) await new Promise(resolve => setTimeout(resolve, 20));
  assert(check(), message);
}
async function connect(queries: string[]) {
  const connection = await new Promise<DbConnection>((resolve, reject) => {
    DbConnection.builder().withUri(uri).withDatabaseName(database).onConnect(connection => resolve(connection)).onConnectError((_, error) => reject(error)).build();
  });
  connections.push(connection);
  await new Promise<void>((resolve, reject) => connection.subscriptionBuilder().onApplied(() => resolve()).onError(ctx => reject(ctx.event)).subscribe(queries));
  return connection;
}
try {
  const tables = ['my_service','adapter_player','adapter_inventory','adapter_collection','adapter_sale_quote','adapter_achievement_progress','achievement_definition','earned_achievement','angler_title','public_profile','angler_standing'];
  const bot = await connect(tables.map(t=>`SELECT * FROM ${t}`));
  ownerCall('configure_service',[JSON.stringify(bot.identity!.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);
  const select = async (user=9401n) => { const args={discordUserId:user,displayName:'Social proof',interactionId:snowflake()}; await bot.reducers.selectDiscordPlayer(args); return args; };
  const player=()=>[...bot.db.adapterPlayer.iter()][0];
  const fish=()=>[...bot.db.adapterInventory.iter()];
  const earned=(id=player().playerId)=>[...bot.db.earnedAchievement.iter()].filter(row=>row.playerId===id).sort((a,b)=>a.achievementId-b.achievementId);
  assert.equal(bot.db.achievementDefinition.count(),113n);
  assert.equal([...bot.db.achievementDefinition.iter()].filter(row=>row.bonus).length,2);
  ownerCall('proof_cast_setup',['9401','0','0']);
  let args=await select();await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});
  assert(earned().some(row=>row.achievementId===1));assert(earned().some(row=>row.achievementId===2));
  const browser=await connect(['my_link_challenge','my_player','my_inventory','my_collection','my_action','my_achievement_progress','my_ledger','earned_achievement','angler_title'].map(t=>`SELECT * FROM ${t}`));
  const linker=await connect(['SELECT * FROM my_service']);ownerCall('configure_service',[JSON.stringify(linker.identity!.toHexString()),JSON.stringify({accountLinker:{}}),'true']);
  await browser.reducers.beginLinkChallenge({});const link=[...browser.db.myLinkChallenge.iter()][0];await linker.reducers.completeAccountLink({challengeId:link.challengeId,proof:link.proof,verifiedDiscordUserId:9401n});
  await assert.rejects(browser.reducers.equipTitle({achievementId:16}),/TITLE_NOT_EARNED/);
  await browser.reducers.equipTitle({achievementId:1});assert.equal([...browser.db.anglerTitle.iter()].find(row=>row.playerId===player().playerId)!.achievementId,1);
  await browser.reducers.equipTitle({achievementId:0});assert.equal([...browser.db.anglerTitle.iter()].find(row=>row.playerId===player().playerId)!.achievementId,0);
  const first=fish()[0];const history=[...browser.db.myCollection.iter()];const badges=earned();const before=player();
  await bot.reducers.prepareSaleFromDiscord({...args,catchIds:[first.catchId]});let quote=[...bot.db.adapterSaleQuote.iter()][0];
  assert.equal(quote.quotedCoins,first.saleValueCoins);
  await assert.rejects(bot.reducers.commitSaleFromDiscord({...args,nonce:quote.nonce^1n}),/ACTION_CONFLICT/);
  const other=await select(9402n);await assert.rejects(bot.reducers.commitSaleFromDiscord({...other,nonce:quote.nonce}),/ACTION_NOT_FOUND|ACTION_CONFLICT/);
  args=await select();await bot.reducers.commitSaleFromDiscord({...args,nonce:quote.nonce});
  assert.equal(player().coins,before.coins+quote.quotedCoins);assert.equal(player().keptCount,before.keptCount-1);
  assert.deepEqual([...browser.db.myCollection.iter()],history);assert.deepEqual(earned(),badges);assert.equal(player().totalXp,before.totalXp);
  const after=player();await bot.reducers.commitSaleFromDiscord({...args,nonce:quote.nonce});assert.deepEqual(player(),after);
  ownerCall('proof_expire_sale',[quote.nonce.toString()]);await bot.reducers.commitSaleFromDiscord({...args,nonce:quote.nonce});assert.deepEqual(player(),after,'Completed replay stays safe after expiry');
  const castFish=async()=>{ownerCall('proof_cast_setup',['9401','0','0']);args=await select();await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});return fish()[0];};
  let catchRow=await castFish();
  await pause();await assert.rejects(bot.reducers.prepareSaleFromDiscord({...args,catchIds:[catchRow.catchId,catchRow.catchId]}),/DUPLICATE_CATCH/);
  await assert.rejects(bot.reducers.prepareSaleFromDiscord({...args,catchIds:[]}),/BATCH_INVALID/);
  await assert.rejects(bot.reducers.prepareSaleFromDiscord({...args,catchIds:Array.from({length:51},(_,i)=>BigInt(i+1))}),/BATCH_INVALID/);
  await browser.reducers.prepareInventoryAction({kind:'favorite',catchIds:[catchRow.catchId],favorite:true});await browser.reducers.commitInventoryAction({nonce:[...browser.db.myAction.iter()][0].nonce});
  await assert.rejects(bot.reducers.prepareSaleFromDiscord({...args,catchIds:[catchRow.catchId]}),/FAVORITE_PROTECTED/);
  await pause();await browser.reducers.prepareInventoryAction({kind:'favorite',catchIds:[catchRow.catchId],favorite:false});await browser.reducers.commitInventoryAction({nonce:[...browser.db.myAction.iter()][0].nonce});
  await bot.reducers.prepareSaleFromDiscord({...args,catchIds:[catchRow.catchId]});quote=[...bot.db.adapterSaleQuote.iter()][0];
  await pause();await browser.reducers.prepareInventoryAction({kind:'favorite',catchIds:[catchRow.catchId],favorite:true});await browser.reducers.commitInventoryAction({nonce:[...browser.db.myAction.iter()][0].nonce});
  await assert.rejects(bot.reducers.commitSaleFromDiscord({...args,nonce:quote.nonce}),/FAVORITE_PROTECTED/);
  await pause();await browser.reducers.prepareInventoryAction({kind:'favorite',catchIds:[catchRow.catchId],favorite:false});await browser.reducers.commitInventoryAction({nonce:[...browser.db.myAction.iter()][0].nonce});
  ownerCall('proof_expire_sale',[quote.nonce.toString()]);await assert.rejects(bot.reducers.commitSaleFromDiscord({...args,nonce:quote.nonce}),/ACTION_EXPIRED/);
  await pause();await bot.reducers.prepareSaleFromDiscord({...args,catchIds:[catchRow.catchId]});quote=[...bot.db.adapterSaleQuote.iter()][0];
  ownerCall('proof_sale_value',[catchRow.catchId.toString(),(quote.quotedCoins+1n).toString()]);const beforeChanged=player();
  await assert.rejects(bot.reducers.commitSaleFromDiscord({...args,nonce:quote.nonce}),/QUOTE_CHANGED/);assert.deepEqual(player(),beforeChanged);assert(fish().some(row=>row.catchId===catchRow.catchId));
  await pause();await bot.reducers.prepareSaleFromDiscord({...args,catchIds:[catchRow.catchId]});quote=[...bot.db.adapterSaleQuote.iter()][0];
  await browser.reducers.prepareInventoryAction({kind:'sell',catchIds:[catchRow.catchId],favorite:false});const webQuote=[...browser.db.myAction.iter()][0];const raceBefore=player();
  const race=await Promise.allSettled([browser.reducers.commitInventoryAction({nonce:webQuote.nonce}),bot.reducers.commitSaleFromDiscord({...args,nonce:quote.nonce})]);
  assert.equal(race.filter(r=>r.status==='fulfilled').length,1);assert.equal(race.filter(r=>r.status==='rejected'&&/CATCH_UNAVAILABLE/.test(String(r.reason))).length,1);assert.equal(player().coins,raceBefore.coins+quote.quotedCoins);
  console.log('PASS sale exact quote, confirmation, replay after expiry, wrong nonce/account, duplicate/oversized batches, favorite protection at prepare and commit, expired/changed quotes and browser-Discord race.');
  await castFish();ownerCall('proof_social_history',['9401','false']);await waitFor(()=>player().completedCasts===1000n,'History subscription updated');const cosmeticBefore=player();const ledgerBefore=[...browser.db.myLedger.iter()];
  ownerCall('backfill_player_achievements',[player().playerId.toString()]);await waitFor(()=>earned().filter(row=>row.achievementId<=16).length===16,'All ordinary milestones exclude both impossible bonus finds');
  assert.deepEqual(player(),cosmeticBefore);assert.deepEqual([...browser.db.myLedger.iter()],ledgerBefore);
  const once=earned();ownerCall('backfill_player_achievements',[player().playerId.toString()]);assert.deepEqual(earned(),once,'Backfill preserves original award timestamps');
  ownerCall('proof_social_history',['9401','true']);ownerCall('backfill_player_achievements',[player().playerId.toString()]);await waitFor(()=>earned().filter(row=>row.achievementId<=18).length===18,'Both bonus badges unlocked');
  await browser.reducers.equipTitle({achievementId:16});assert.equal([...browser.db.anglerTitle.iter()].find(row=>row.playerId===player().playerId)!.achievementId,16);
  const allBadges=earned();await pause();await bot.reducers.prepareSaleFromDiscord({...args,catchIds:fish().map(row=>row.catchId)});quote=[...bot.db.adapterSaleQuote.iter()][0];await bot.reducers.commitSaleFromDiscord({...args,nonce:quote.nonce});assert.deepEqual(earned(),allBadges);
  ownerCall('activate_achievements');ownerCall('activate_achievements');assert.deepEqual(earned(),allBadges);
  const stranger=await connect(['my_achievement_progress','adapter_achievement_progress','adapter_sale_quote','my_player','my_inventory','public_profile','earned_achievement','angler_title'].map(t=>`SELECT * FROM ${t}`));
  for(const view of [stranger.db.myAchievementProgress,stranger.db.adapterAchievementProgress,stranger.db.adapterSaleQuote,stranger.db.myPlayer,stranger.db.myInventory])assert.equal(view.count(),0n);
  assert(stranger.db.publicProfile.count()>0n);assert(stranger.db.earnedAchievement.count()>0n);
  for(const row of stranger.db.publicProfile.iter())assert.deepEqual(Object.keys(row).sort(),['playerId','displayName','level','fishCount','discoveries'].sort());
  await assert.rejects(stranger.reducers.equipTitle({achievementId:1}),/ACCOUNT_NOT_LINKED/);await assert.rejects(stranger.reducers.activateAchievements({}),/OWNER_REQUIRED/);await assert.rejects(stranger.reducers.backfillPlayerAchievements({playerId:player().playerId}),/OWNER_REQUIRED/);
  await assert.rejects(stranger.reducers.prepareSaleFromDiscord({...args,catchIds:[1n]}),/SERVICE_UNAUTHORIZED/);
  for(const table of ['sale_quote','achievement_progress'])await assert.rejects(connect([`SELECT * FROM ${table}`]));
  await browser.reducers.unlinkBrowser({});assert.equal(browser.db.myAchievementProgress.count(),0n);await assert.rejects(browser.reducers.equipTitle({achievementId:1}),/ACCOUNT_NOT_LINKED/);
  console.log('PASS first-cast automatic unlocks, all 18 original milestones, retroactive sold-catch history, ordinary/bonus separation, permanent awards, cosmetic-only backfill, earned-title validation/clear, public profile privacy, unlink and owner/service boundaries.');
  await writeFile('.local/achievement-catalog.json', JSON.stringify([...bot.db.achievementDefinition.iter()].sort((a,b)=>a.achievementId-b.achievementId),(_,value)=>typeof value==='bigint'?value.toString():value));
  ownerCall('proof_cast_setup',['9408','0','0']);args=await select(9408n);await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});
  const native=await new Promise<{status:number|null,stdout:string,stderr:string}>((resolve,reject)=>{
    const child=spawn('target/debug/adapter-proof.exe',[],{windowsHide:true,env:{...process.env,TEST_SPACETIMEDB_URI:uri,TEST_SPACETIMEDB_DATABASE:database,TEST_BOT_TOKEN:bot.token!,TEST_SOCIAL_PROOF:'true'}});
    let stdout='',stderr='';child.stdout.on('data',data=>stdout+=data);child.stderr.on('data',data=>stderr+=data);child.on('error',reject);child.on('close',status=>resolve({status,stdout,stderr}));
  });assert.equal(native.status,0,native.stderr||native.stdout);
  ownerCall('configure_service',[JSON.stringify(bot.identity!.toHexString()),JSON.stringify({discordAdapter:{}}),'false']);
  const end=Date.now()+12000;while([bot.db.adapterSaleQuote,bot.db.adapterAchievementProgress].some(view=>view.count()!==0n)&&Date.now()<end)await new Promise(r=>setTimeout(r,20));
  assert.equal(bot.db.adapterSaleQuote.count(),0n);assert.equal(bot.db.adapterAchievementProgress.count(),0n);
  console.log('PASS native sale confirmation/reconnect replay, public snapshot and service revocation.');
} finally { for(const connection of connections)connection.disconnect(); }
