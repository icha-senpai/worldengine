import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { spawnSync, spawn } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';

const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-biome-achievements-${Date.now()}`;
const fixtureRoot = resolve('.local/biome-achievements-module');
const targetRoot = resolve('spacetimedb/target');
const rustPath = (path: string) => JSON.stringify(resolve(path).replaceAll('\\', '/'));
function run(command: string, args: string[]) {
  const result = spawnSync(command, args, { encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw new Error(result.stderr || result.stdout);
  return result.stdout;
}
await mkdir(fixtureRoot, { recursive: true });
const manifest = (await readFile('spacetimedb/Cargo.toml', 'utf8'))
  .replace('name = "fishing-game-module"', 'name = "fishing-biome-achievements-proof"')
  .replace('path = "../crates/game-rules"', `path = ${rustPath('crates/game-rules')}`)
  .replace('crate-type = ["cdylib"]', 'crate-type = ["cdylib"]\npath = "lib.rs"');
await writeFile(fixtureRoot + '/Cargo.toml', manifest + '\n[workspace]\n');
await writeFile(fixtureRoot + '/Cargo.lock', (await readFile('spacetimedb/Cargo.lock', 'utf8')).replace('name = "fishing-game-module"', 'name = "fishing-biome-achievements-proof"'));
const source = (await readFile('spacetimedb/src/lib.rs', 'utf8')).replace(/^mod (\w+);$/gm, (_, name) => `#[path = ${rustPath(`spacetimedb/src/${name}.rs`)}]\nmod ${name};`);
await writeFile(fixtureRoot + '/lib.rs', source + `\ninclude!(${rustPath('scripts/fixtures/biome-achievements-boundary.rs')});\n`);
run('cargo', ['build', '--manifest-path', fixtureRoot + '/Cargo.toml', '--target-dir', targetRoot, '--target', 'wasm32-unknown-unknown', '--release', '--locked']);
run('spacetime', ['publish', '--server', uri, '--no-config', '--yes=skip-login', '--bin-path', targetRoot + '/wasm32-unknown-unknown/release/fishing_biome_achievements_proof.wasm', database]);
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
  const bot = await connect(['adapter_player','adapter_inventory','adapter_collection','adapter_achievement_progress','achievement_definition','achievement_collection','earned_achievement','species_definition','biome_definition','angler_title'].map(t => `SELECT * FROM ${t}`));
  ownerCall('configure_service', [JSON.stringify(bot.identity!.toHexString()), JSON.stringify({discordAdapter:{}}), 'true']);
  const select = async (user: bigint) => { const args = {discordUserId:user, displayName:'Collection proof', interactionId:snowflake()}; await bot.reducers.selectDiscordPlayer(args); return args; };
  const player = () => [...bot.db.adapterPlayer.iter()][0];
  const earned = () => [...bot.db.earnedAchievement.iter()].filter(r => r.playerId === player().playerId);
  const progress = (id: number) => [...bot.db.adapterAchievementProgress.iter()].find(r => r.achievementId === id)!.current;
  const backfill = async () => {
    const id=player().playerId;
    ownerCall('backfill_player_achievements', [id.toString()]);
    const expected=JSON.parse(run('spacetime',['sql','--server',uri,'--no-config','--format','json',database,`SELECT achievement_id, current FROM achievement_progress WHERE player_id = ${id}`])).flatMap((block:any)=>block.rows).map((row:any)=>[row[0],String(row[1])]).sort((a:any,b:any)=>a[0]-b[0]);
    await waitFor(()=>JSON.stringify([...bot.db.adapterAchievementProgress.iter()].map(r=>[r.achievementId,r.current.toString()]).sort((a:any,b:any)=>a[0]-b[0]))===JSON.stringify(expected),'Backfill progress arrived');
    await waitFor(()=>[...bot.db.earnedAchievement.iter()].filter(r=>r.playerId===id).length===expected.filter((r:any)=>BigInt(r[1])>=definitions.find(d=>d.achievementId===r[0])!.target).length,'Backfill badges arrived');
  };
  const ordinary = [...bot.db.speciesDefinition.iter()].filter(s => s.countsForOrdinaryCollectionCompletion);
  const definitions = [...bot.db.achievementDefinition.iter()];
  const collections = [...bot.db.achievementCollection.iter()];
  assert.equal(definitions.length, 113); assert.equal(collections.length, 96); assert.equal(ordinary.length, 249);
  assert.equal(definitions.filter(r => r.bonus).length, 2);
  for (const c of collections) {
    const count = ordinary.filter(s => c.biomeId === 0 || s.biomeId === c.biomeId).length;
    assert.equal(definitions.find(d => d.achievementId === c.achievementId)!.target, BigInt(count * (c.allRanks ? 10 : 1)));
  }
  assert.equal(collections.filter(c => c.biomeId === 1 && !c.rarity && !c.allRanks).length, 1, 'Pond badge reused');
  await select(9901n);
  await backfill(); assert.equal(progress(310), 0n);
  const pond = ordinary.filter(s => s.biomeId === 1);
  const missing = pond[0].speciesId;
  ownerCall('proof_collection_history', ['9901','1','1',String(missing),'999']);
  ownerCall('proof_collection_history', ['9901','2','1','0','1']);
  await backfill();
  assert.equal(progress(10), 27n); assert.equal(progress(100), 27n); assert.equal(progress(101), 0n);
  assert.equal(progress(300), 27n); assert(!earned().some(r => r.achievementId === 10 || r.achievementId === 100));
  assert.equal(progress(200), BigInt(27 + ordinary.filter(s => s.biomeId === 2).length));
  console.log('PASS partial biome boundaries, distinct species, duplicate catches, rank separation and catalog targets.');

  ownerCall('proof_last_collection_fish', ['9901',String(missing)]);
  const args = await select(9901n);
  await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});
  assert.equal(progress(10), 28n); assert.equal(progress(100), 28n);
  assert(earned().some(r => r.achievementId === 10)); assert(earned().some(r => r.achievementId === 100));
  assert.equal([...bot.db.adapterInventory.iter()][0].rarity,'F');
  const once = earned(); await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n}); assert.deepEqual(earned(), once);
  console.log('PASS a real final-species cast unlocks both badges immediately; replay keeps original dates.');

  ownerCall('proof_collection_history', ['9901','1','1023','0','1']);
  await backfill(); assert.equal(progress(300), 280n); assert(earned().some(r => r.achievementId === 300));
  for (let id=100;id<110;id++) assert(earned().some(r => r.achievementId === id));
  assert(!earned().some(r => r.achievementId === 310));
  ownerCall('proof_social_history', ['9902','false']); await select(9902n);
  ownerCall('proof_collection_history', ['9902','0','16','0','1']); await backfill();
  assert.equal(progress(30),249n); assert.equal(progress(204),249n); assert.equal(progress(310),249n);
  assert(earned().some(r => r.achievementId === 30)); assert(earned().some(r => r.achievementId === 204));
  assert(!earned().some(r => r.achievementId === 310));
  ownerCall('proof_collection_history', ['9902','0','1023','0','1']);
  await waitFor(()=>bot.db.adapterCollection.count()===249n && [...bot.db.adapterCollection.iter()].every(r=>r.rankCounts.every(n=>n>0n)),'Full lifetime collection arrived');
  const cosmeticBefore = player(); const inventoryBefore = [...bot.db.adapterInventory.iter()]; const historyBefore = [...bot.db.adapterCollection.iter()];
  await backfill(); assert.equal(progress(310),2490n); assert.equal(earned().length,111);
  assert.deepEqual(player(),cosmeticBefore); assert.deepEqual([...bot.db.adapterInventory.iter()],inventoryBefore); assert.deepEqual([...bot.db.adapterCollection.iter()],historyBefore);
  const full = earned(); await backfill(); assert.deepEqual(earned(),full);
  assert(!earned().some(r => r.achievementId === 17 || r.achievementId === 18));
  assert.equal(bot.db.adapterInventory.count(),0n, 'Lifetime history qualifies with no kept specimens');
  console.log('PASS all seven biome and world collections, all ten ranks and capstones, sold-fish credit, cosmetic-only idempotent backfill; both bonus fish excluded.');

  const browser = await connect(['my_link_challenge','my_player','my_collection','my_achievement_progress','earned_achievement','angler_title'].map(t => `SELECT * FROM ${t}`));
  const linker = await connect(['SELECT * FROM my_service']);
  ownerCall('configure_service',[JSON.stringify(linker.identity!.toHexString()),JSON.stringify({accountLinker:{}}),'true']);
  await browser.reducers.beginLinkChallenge({}); const ch=[...browser.db.myLinkChallenge.iter()][0];
  await linker.reducers.completeAccountLink({challengeId:ch.challengeId,proof:ch.proof,verifiedDiscordUserId:9902n});
  await browser.reducers.equipTitle({achievementId:310});
  assert.equal([...browser.db.anglerTitle.iter()].find(r=>r.playerId===player().playerId)!.achievementId,310);
  const stranger=await connect(['SELECT * FROM my_achievement_progress','SELECT * FROM achievement_collection']);
  assert.equal(stranger.db.myAchievementProgress.count(),0n); assert.equal(stranger.db.achievementCollection.count(),96n);
  await assert.rejects(stranger.reducers.backfillPlayerAchievements({playerId:player().playerId}),/OWNER_REQUIRED/);
  assert.equal(browser.db.myAchievementProgress.count(),113n);
  await writeFile('.local/browser-proof.json',JSON.stringify({uri,database,token:browser.token}),{mode:0o600});
  console.log('PASS new earned title, private progress and owner boundaries. Saved linked isolated browser fixture.');
} finally { connections.forEach(c=>c.disconnect()); }
