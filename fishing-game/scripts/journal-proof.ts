import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';
import type { JournalEntry } from '../packages/generated/src/types';

const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-journal-${Date.now()}`;
const fixtureRoot = resolve('.local/journal-boundary-module');
const targetRoot = resolve('spacetimedb/target');
const rustPath = (path: string) => JSON.stringify(resolve(path).replaceAll('\\', '/'));
function run(command: string, args: string[]) {
  const result = spawnSync(command, args, { encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw new Error(result.stderr || result.stdout);
  return result.stdout;
}
await mkdir(fixtureRoot, { recursive: true });
const manifest = (await readFile('spacetimedb/Cargo.toml', 'utf8'))
  .replace('name = "fishing-game-module"', 'name = "fishing-journal-proof"')
  .replace('path = "../crates/game-rules"', `path = ${rustPath('crates/game-rules')}`)
  .replace('crate-type = ["cdylib"]', 'crate-type = ["cdylib"]\npath = "lib.rs"');
await writeFile(fixtureRoot + '/Cargo.toml', manifest + '\n[workspace]\n');
await writeFile(fixtureRoot + '/Cargo.lock', (await readFile('spacetimedb/Cargo.lock', 'utf8')).replace('name = "fishing-game-module"', 'name = "fishing-journal-proof"'));
const source = (await readFile('spacetimedb/src/lib.rs', 'utf8')).replace(/^mod (\w+);$/gm, (_, name) => `#[path = ${rustPath(`spacetimedb/src/${name}.rs`)}]\nmod ${name};`);
await writeFile(fixtureRoot + '/lib.rs', source + `\ninclude!(${rustPath('scripts/fixtures/journal-boundary.rs')});\n`);
run('cargo', ['build', '--manifest-path', fixtureRoot + '/Cargo.toml', '--target-dir', targetRoot, '--target', 'wasm32-unknown-unknown', '--release', '--locked']);
run('spacetime', ['publish', '--server', uri, '--no-config', '--yes=skip-login', '--bin-path', targetRoot + '/wasm32-unknown-unknown/release/fishing_journal_proof.wasm', database]);
console.log('Published isolated journal fixture module.');
const connections: DbConnection[] = [];
const ownerCall = (name: string, args: string[] = []) => run('spacetime', ['call', '--server', uri, '--no-config', '--yes', database, name, ...args]);
let sequence = 1n;
const snowflake = () => ((BigInt(Date.now()) - 1420070400000n) << 22n) | sequence++;
const wait = (ms: number) => new Promise(r => setTimeout(r, ms));
async function until(predicate: () => boolean) {
  const deadline=Date.now()+12000;
  while(!predicate()) { if(Date.now()>deadline) throw Error('Timed out waiting for journal projection'); await wait(15); }
}
async function connect(queries: string[], token?: string) {
  const auth = await new Promise<{connection: DbConnection; token: string}>((resolve, reject) => {
    DbConnection.builder().withUri(uri).withDatabaseName(database).withToken(token).onConnect((connection, _, token) => resolve({connection, token})).onConnectError((_, error) => reject(error)).build();
  });
  connections.push(auth.connection);
  await new Promise<void>((resolve, reject) => auth.connection.subscriptionBuilder().onApplied(() => resolve()).onError(ctx => reject(ctx.event)).subscribe(queries.map(t => `SELECT * FROM ${t}`)));
  return auth;
}
const defaultQuery = {page:1, pageSize:50, sort:'date', descending:true, search:'', biomeId:0, rarity:'', outcome:''};
try {
  const {connection:bot} = await connect(['my_service','adapter_player','adapter_receipt','adapter_cast_pulls','adapter_inventory']);
  const {connection:linker} = await connect(['my_service']);
  ownerCall('configure_service',[JSON.stringify(bot.identity!.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);
  ownerCall('configure_service',[JSON.stringify(linker.identity!.toHexString()),JSON.stringify({accountLinker:{}}),'true']);
  ownerCall('proof_legacy_journal',['9301']);
  await bot.reducers.selectDiscordPlayer({discordUserId:9301n,displayName:'Journal proof',interactionId:snowflake()});
  const playerId = [...bot.db.adapterPlayer.iter()][0].playerId;
  const queries = ['my_journal','my_player','my_inventory','my_recent_catches','my_link_challenge','my_action','species_definition','biome_definition'];
  const browser = await connect(queries); const c = browser.connection;
  await c.reducers.beginLinkChallenge({});
  const challenge = [...c.db.myLinkChallenge.iter()][0];
  await linker.reducers.completeAccountLink({challengeId:challenge.challengeId,proof:challenge.proof,verifiedDiscordUserId:9301n});
  await until(()=>c.db.myPlayer.count()===1n && c.db.myInventory.count()===2n);
  const journal = () => [...c.db.myJournal.iter()][0];
  const beforePlayer = [...c.db.myPlayer.iter()][0]; const beforeFish = [...c.db.myInventory.iter()];
  await assert.rejects(c.reducers.backfillJournal({playerId}), /OWNER_REQUIRED/);
  ownerCall('backfill_journal',[String(playerId)]);
  await c.reducers.selectJournal(defaultQuery);
  assert.equal(journal().totalEntries,8n);
  let saved = journal().entries;
  assert.equal(saved.filter(e=>e.outcome==='junk').length,3);
  assert.equal(saved.find(e=>e.catchId===50001n)!.xpGranted,undefined);
  assert.equal(saved.find(e=>e.catchId===50002n)!.xpGranted,111n);
  assert.equal(saved.find(e=>e.speciesId===3)!.biomeId,undefined);
  assert.equal(saved.find(e=>e.catchId===50004n)!.xpGranted,30n, 'Never substitute aggregate cast XP for fish XP');
  assert.equal(saved.find(e=>e.catchId===50005n)!.xpGranted,undefined);
  ownerCall('backfill_journal',[String(playerId)]);
  await c.reducers.selectJournal(defaultQuery);
  assert.deepEqual(journal().entries,saved);
  assert.deepEqual([...c.db.myPlayer.iter()][0],beforePlayer);
  assert.deepEqual([...c.db.myInventory.iter()],beforeFish);
  console.log('PASS legacy overlap, duplicate bonus pulls, unknown XP/biome, aggregate reward safety, idempotent import, unchanged balances and catches.');

  const ranks = ['F','D','C','B','A','S','SS','SSS','UR','UUR'];
  for (const sort of ['date','rank','weight','length','xp']) for (const descending of [true,false]) {
    await c.reducers.selectJournal({...defaultQuery,sort,descending});
    const values = journal().entries.map(e=> sort==='date' ? e.caughtAt.microsSinceUnixEpoch
      : sort==='rank' ? (e.rarity ? BigInt(ranks.indexOf(e.rarity)) : undefined)
      : sort==='xp' ? e.xpGranted : e.outcome==='fish' ? BigInt(sort==='length'? e.lengthMm:e.weightG) : undefined);
    let unknown=false;
    for(let i=0;i<values.length;i++) {
      if(values[i]===undefined) {unknown=true;continue;}
      assert(!unknown,'Unknown sort values must stay last');
      if(i>0 && values[i-1]!==undefined) assert(descending ? values[i-1]!>=values[i]! : values[i-1]!<=values[i]!);
    }
  }
  await c.reducers.selectJournal({...defaultQuery,pageSize:3}); const first=journal().entries.map(e=>e.key);
  await c.reducers.selectJournal({...defaultQuery,pageSize:3,page:2}); const second=journal().entries.map(e=>e.key);
  assert.equal(new Set([...first,...second]).size,6);
  await c.reducers.selectJournal({...defaultQuery,pageSize:3}); assert.deepEqual(journal().entries.map(e=>e.key),first);
  await c.reducers.selectJournal({...defaultQuery,page:999,pageSize:3}); assert.equal(journal().page,3);assert.equal(journal().entries.length,2);
  for (const filter of [{rarity:'C'},{outcome:'junk'},{biomeId:1},{search:'  RUSTED TIN  '}]) {
    await c.reducers.selectJournal({...defaultQuery,...filter}); const rows=journal().entries;
    assert(rows.length>0 && rows.every(e=>filter.rarity?e.rarity===filter.rarity:filter.outcome?e.outcome===filter.outcome:filter.biomeId?e.biomeId===filter.biomeId:e.name==='Rusted tin'));
  }
  await c.reducers.selectJournal({...defaultQuery,search:'no such fish'}); assert.equal(journal().matchingEntries,0n);
  for(const invalid of [{page:0},{pageSize:51},{sort:'coins'},{rarity:'Z'},{biomeId:999},{outcome:'daily'},{search:'x'.repeat(81)}]) await assert.rejects(c.reducers.selectJournal({...defaultQuery,...invalid}), /JOURNAL_QUERY_INVALID/);
  const stranger = await connect(['my_journal']); assert.equal(stranger.connection.db.myJournal.count(),0n);
  await assert.rejects(stranger.connection.reducers.selectJournal(defaultQuery),/LINK_REQUIRED/);
  console.log('PASS all ten sort directions, stable page boundaries/clamping, filters, bounded input, caller privacy.');

  await c.reducers.selectJournal(defaultQuery);
  const fish = [...c.db.myInventory.iter()].find(e=>e.catchId===50002n)!;
  await c.reducers.prepareInventoryAction({kind:'sell',catchIds:[fish.catchId],favorite:false});
  const quote = [...c.db.myAction.iter()][0];
  await c.reducers.commitInventoryAction({nonce:quote.nonce});
  assert.equal(journal().totalEntries,8n); assert(journal().entries.some(e=>e.catchId===50002n));
  console.log('PASS selling removes inventory only and retains journal history.');

  // A full-power fixture guarantees two actual authoritative pulls per cast.
  for(let cast=0;cast<52;cast++) {
    ownerCall('proof_cast_setup',['9301','200',String(cast===0?0:1)]);
    const args = {discordUserId:9301n,displayName:'Journal proof',interactionId:snowflake()};
    await bot.reducers.selectDiscordPlayer(args);
    await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});
    const pulls=[...bot.db.adapterCastPulls.iter()]; const receipt=[...bot.db.adapterReceipt.iter()][0];
    assert.equal(pulls.length,2); assert.equal(pulls.reduce((s,p)=>s+p.receipt.xpGranted,0n),receipt.xpGranted);
    await c.reducers.selectJournal({...defaultQuery,pageSize:50,sort:'date'});
    for(const pull of pulls) {
      const entry=journal().entries.find(e=>e.key===`pull:${pull.key}`)!;
      assert(entry); assert.equal(entry.xpGranted,pull.receipt.xpGranted); assert.equal(entry.weightG,pull.receipt.weightG);
    }
    const count = journal().totalEntries;
    await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n}); assert.equal(journal().totalEntries,count);
  }
  assert.equal(journal().totalEntries,112n); assert.equal(c.db.myRecentCatches.count(),100n);
  console.log('PASS exact allocated per-pull XP, retry idempotency and permanent history beyond 100 recent results.');
  saved = journal().entries;
  ownerCall('proof_age_receipts');
  const deadline=Date.now()+75000;
  while(Date.now()<deadline) {
    const blocks=JSON.parse(run('spacetime',['sql','--server',uri,'--no-config','--format','json',database,'SELECT COUNT(*) AS remaining FROM command_receipt']));
    if(Number(blocks[0].rows[0][0])===0) break;
    await wait(1000);
  }
  assert.equal(JSON.parse(run('spacetime',['sql','--server',uri,'--no-config','--format','json',database,'SELECT COUNT(*) AS remaining FROM cast_pull']))[0].rows[0][0],0);
  await c.reducers.selectJournal(defaultQuery); assert.equal(journal().totalEntries,112n); assert.deepEqual(journal().entries,saved);
  console.log('PASS real scheduled seven-day pruning removes retry receipts without deleting permanent journal.');
  c.disconnect(); const resumed=await connect(queries,browser.token);
  assert.equal([...resumed.connection.db.myJournal.iter()][0].totalEntries,112n);
  await writeFile('.local/browser-proof.json',JSON.stringify({uri,database,token:browser.token}));
  await resumed.connection.reducers.unlinkBrowser({}); assert.equal(resumed.connection.db.myJournal.count(),0n);
  console.log('PASS reconnect reconstructs journal; unlink revokes journal access.');
  // Keep a linked browser for actual-app UI verification, separate from logout.
  const showcase=await connect(queries); await showcase.connection.reducers.beginLinkChallenge({});
  const ch=[...showcase.connection.db.myLinkChallenge.iter()][0];
  await linker.reducers.completeAccountLink({challengeId:ch.challengeId,proof:ch.proof,verifiedDiscordUserId:9301n});
  await writeFile('.local/browser-proof.json',JSON.stringify({uri,database,token:showcase.token}));
  console.log(`PASS journal integration complete: ${database}`);
} finally { for(const c of connections) c.disconnect(); }
