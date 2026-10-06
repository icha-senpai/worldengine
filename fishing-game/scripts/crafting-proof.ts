import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { spawnSync, spawn } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';

const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-crafting-${Date.now()}`;
const fixtureRoot = resolve('.local/crafting-boundary-module');
const targetRoot = resolve('spacetimedb/target');
const rustPath = (path: string) => JSON.stringify(resolve(path).replaceAll('\\', '/'));
function run(command: string, args: string[]) {
  const result = spawnSync(command, args, { encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw new Error(result.stderr || result.stdout);
  return result.stdout;
}
await mkdir(fixtureRoot, { recursive: true });
const manifest = (await readFile('spacetimedb/Cargo.toml', 'utf8'))
  .replace('name = "fishing-game-module"', 'name = "fishing-crafting-proof"')
  .replace('path = "../crates/game-rules"', `path = ${rustPath('crates/game-rules')}`)
  .replace('crate-type = ["cdylib"]', 'crate-type = ["cdylib"]\npath = "lib.rs"');
await writeFile(fixtureRoot + '/Cargo.toml', manifest + '\n[workspace]\n');
await writeFile(fixtureRoot + '/Cargo.lock', (await readFile('spacetimedb/Cargo.lock', 'utf8')).replace('name = "fishing-game-module"', 'name = "fishing-crafting-proof"'));
const source = (await readFile('spacetimedb/src/lib.rs', 'utf8')).replace(/^mod (\w+);$/gm, (_, name) => `#[path = ${rustPath(`spacetimedb/src/${name}.rs`)}]\nmod ${name};`);
await writeFile(fixtureRoot + '/lib.rs', source + `\ninclude!(${rustPath('scripts/fixtures/crafting-boundary.rs')});\n`);
run('cargo', ['build', '--manifest-path', fixtureRoot + '/Cargo.toml', '--target-dir', targetRoot, '--target', 'wasm32-unknown-unknown', '--release', '--locked']);
run('spacetime', ['publish', '--server', uri, '--no-config', '--yes=skip-login', '--bin-path', targetRoot + '/wasm32-unknown-unknown/release/fishing_crafting_proof.wasm', database]);
console.log('Built and published an isolated fixture module. Production WASM was not modified.');

const connections: DbConnection[] = [];
const ownerCall = (name: string, args: string[] = []) => run('spacetime', ['call', '--server', uri, '--no-config', '--yes', database, name, ...args]);
let sequence = 1n;
const snowflake = () => ((BigInt(Date.now()) - 1420070400000n) << 22n) | sequence++;
const pause = () => new Promise(resolve => setTimeout(resolve, 1050));
async function connect(queries: string[]) {
  const connection = await new Promise<DbConnection>((resolve, reject) => {
    DbConnection.builder().withUri(uri).withDatabaseName(database).onConnect(connection => resolve(connection)).onConnectError((_, error) => reject(error)).build();
  });
  connections.push(connection);
  await new Promise<void>((resolve, reject) => connection.subscriptionBuilder().onApplied(() => resolve()).onError(ctx => reject(ctx.event)).subscribe(queries));
  return connection;
}
try {
  const bot = await connect(['my_service','adapter_player','adapter_rods','adapter_baits','adapter_bait_loadout','adapter_items','adapter_upgrade_quote','adapter_shop_quote','adapter_receipt','adapter_cast_pulls','adapter_cast_equipment','adapter_inventory','adapter_collection','shop_listing','bait_definition','quality_definition'].map(t=>`SELECT * FROM ${t}`));
  ownerCall('configure_service',[JSON.stringify(bot.identity!.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);
  const select = async (user=9301n) => { const args={discordUserId:user,displayName:'Crafting proof',interactionId:snowflake()}; await bot.reducers.selectDiscordPlayer(args);return args; };
  const player=()=>[...bot.db.adapterPlayer.iter()][0];
  const material=(item:string)=>[...bot.db.adapterItems.iter()].find(r=>r.item===item)?.quantity??0n;
  const quality=(id=1)=>[...bot.db.adapterRods.iter()].find(r=>r.rodId===id)!.upgradeLevel;
  const uses=(id:number)=>[...bot.db.adapterBaits.iter()].find(r=>r.baitId===id)?.usesLeft??0n;
  const purchase=async(listingId:number,user=9301n)=>{
    await pause();const args=await select(user);await bot.reducers.prepareShopFromDiscord({...args,listingId});const q=[...bot.db.adapterShopQuote.iter()][0];
    const coins=player().coins;await bot.reducers.commitShopFromDiscord({...args,nonce:q.nonce});assert.equal(player().coins,coins-q.quotedCoins);
    await bot.reducers.commitShopFromDiscord({...args,nonce:q.nonce});assert.equal(player().coins,coins-q.quotedCoins);
    return args;
  };
  const prepare=async(user=9301n,id=1)=>{await pause();const args=await select(user);await bot.reducers.prepareUpgradeFromDiscord({...args,rodId:id});return {args,q:[...bot.db.adapterUpgradeQuote.iter()][0]};};
  assert.equal(bot.db.baitDefinition.count(),5n);assert.equal(bot.db.qualityDefinition.count(),7n);assert.equal(bot.db.shopListing.count(),17n);
  ownerCall('prepare_trader_player',['9301','10000000','200000','1']);let args=await select();
  await assert.rejects(bot.reducers.prepareUpgradeFromDiscord({...args,rodId:1}),/INSUFFICIENT_MATERIALS/);
  await assert.rejects(bot.reducers.prepareUpgradeFromDiscord({...args,rodId:7}),/ROD_NOT_OWNED/);
  await assert.rejects(bot.reducers.equipBaitFromDiscord({...args,baitId:1}),/BAIT_NOT_OWNED/);
  ownerCall('proof_materials',['9301','2000','1500']);
  let tin=2000n,scrap=1500n;
  for(let level=1;level<=6;level++){
    const {args,q}=await prepare();const next=[...bot.db.qualityDefinition.iter()].find(r=>r.qualityLevel===level)!;
    assert.equal(q.tinCost,next.tinCost);assert.equal(q.scrapCost,next.scrapCost);
    const before=player();await bot.reducers.commitUpgradeFromDiscord({...args,nonce:q.nonce});tin-=q.tinCost;scrap-=q.scrapCost;
    assert.equal(material('rusted_tin'),tin);assert.equal(material('scrap'),scrap);assert.equal(quality(),level);
    assert.equal(player().coins,before.coins);assert.equal(player().totalXp,before.totalXp);assert.equal(player().nextCastAt.microsSinceUnixEpoch,before.nextCastAt.microsSinceUnixEpoch);
    await bot.reducers.commitUpgradeFromDiscord({...args,nonce:q.nonce});assert.equal(material('rusted_tin'),tin);assert.equal(quality(),level);
  }
  args=await select();await assert.rejects(bot.reducers.prepareUpgradeFromDiscord({...args,rodId:1}),/MAX_QUALITY/);
  await purchase(21);assert.equal(quality(2),0,'Each purchased rod starts Common independently');
  await purchase(20);args=await select();await bot.reducers.changeLoadoutFromDiscord({...args,biomeId:2,rodId:1});assert.equal(player().equippedRodId,1,'Twig travels without a power gate');
  await bot.reducers.changeLoadoutFromDiscord({...args,biomeId:1,rodId:1});
  console.log('PASS six exact recipes, permanent independent qualities, guaranteed craft success, atomic spending, replay and licence-only travel.');
  await purchase(101);await purchase(101);assert.equal(uses(1),20n);
  await purchase(102);assert.equal(uses(2),10n);
  for(const listing of [103,104,105])await purchase(listing);
  args=await select();await bot.reducers.equipBaitFromDiscord({...args,baitId:1});
  let mixed=false;
  for(let cast=0;cast<20;cast++){
    // Proof-only base power 100 plus Prismatic 100 forces two pulls.
    ownerCall('proof_cast_setup',['9301','100',String(cast<3?cast:3)]);
    args=await select();const before=player();const beforeTin=material('rusted_tin'),beforeScrap=material('scrap');
    await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});
    const pulls=[...bot.db.adapterCastPulls.iter()];const total=[...bot.db.adapterReceipt.iter()][0];const gear=[...bot.db.adapterCastEquipment.iter()][0];
    assert.equal(pulls.length,2);assert.equal(player().completedCasts,before.completedCasts+1n);
    assert.equal(uses(1),BigInt(19-cast));assert.equal(gear.baitUsesLeft,uses(1));assert.equal(gear.qualityLevel,6);assert.equal(gear.power,200);
    assert.equal(gear.luckBp,4000);assert.equal(gear.xpBonusBp,7500);
    assert.equal(total.xpGranted,pulls.reduce((sum,p)=>sum+p.baseXp,0n)*17500n/10000n);
    assert.equal(total.xpGranted,pulls.reduce((sum,p)=>sum+p.receipt.xpGranted,0n));
    assert.equal(player().totalXp,before.totalXp+total.xpGranted);assert.equal(player().coins,before.coins+pulls.reduce((sum,p)=>sum+p.receipt.coinsGranted,0n));
    const fish=pulls.filter(p=>p.receipt.outcome==='fish'),junk=pulls.filter(p=>p.receipt.outcome==='junk');
    assert.equal(player().fishCount,before.fishCount+BigInt(fish.length));assert.equal(player().junkCount,before.junkCount+BigInt(junk.length));
    assert.equal(player().treasureCount,before.treasureCount+BigInt(pulls.length-fish.length-junk.length));
    assert.equal(material('rusted_tin'),beforeTin+1n+BigInt(junk.length));
    assert.equal(material('scrap'),beforeScrap+pulls.filter(p=>p.receipt.outcome==='treasure').reduce((sum,p)=>sum+p.receipt.itemQuantity,0n));
    assert.equal(player().nextCastAt.microsSinceUnixEpoch-total.caughtAt.microsSinceUnixEpoch,60000000n);
    for(const f of fish){const specimen=[...bot.db.adapterInventory.iter()].find(r=>r.catchId===f.receipt.catchId);assert(specimen);assert.equal(specimen.rarity,f.receipt.rarity);}
    if(fish.length===2)assert.notEqual(fish[0].receipt.catchId,fish[1].receipt.catchId,'Each fish is independently saved');
    mixed ||=new Set(pulls.map(p=>p.receipt.outcome)).size>1;
    const committed=player();await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});assert.deepEqual(player(),committed);assert.equal(uses(1),BigInt(19-cast));
    await assert.rejects(bot.reducers.fishFromDiscord({...args,interactionId:snowflake(),guildId:5n,channelId:42n}),/COOLDOWN_ACTIVE/);assert.equal(uses(1),BigInt(19-cast));
  }
  assert(mixed,'Actual context RNG produced mixed categories');assert.equal([...bot.db.adapterBaitLoadout.iter()][0].baitId,0);
  ownerCall('proof_cast_setup',['9301','1','0']);args=await select();await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});assert.equal([...bot.db.adapterCastEquipment.iter()][0].baitId,0);assert.equal(uses(1),0n);
  args=await select();await bot.reducers.equipBaitFromDiscord({...args,baitId:2});ownerCall('proof_cast_setup',['9301','100','2']);args=await select();const oldScrap=material('scrap');await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});assert.equal(material('scrap'),oldScrap+1n+[...bot.db.adapterCastPulls.iter()].reduce((sum,p)=>sum+p.receipt.itemQuantity,0n));assert.equal(uses(2),9n);
  for(const [id,luck] of [[3,4500],[4,5000],[5,5500]]){args=await select();await bot.reducers.equipBaitFromDiscord({...args,baitId:id});ownerCall('proof_cast_setup',['9301','1','0']);args=await select();await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});assert.equal([...bot.db.adapterCastEquipment.iter()][0].luckBp,luck);assert.equal(uses(id),9n);}
  const savedPulls=[...bot.db.adapterCastPulls.iter()],savedGear=[...bot.db.adapterCastEquipment.iter()][0];
  await bot.reducers.equipBaitFromDiscord({...args,baitId:0});await bot.reducers.changeLoadoutFromDiscord({...args,rodId:2});const replayPlayer=player();await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});assert.deepEqual([...bot.db.adapterCastPulls.iter()],savedPulls);assert.deepEqual([...bot.db.adapterCastEquipment.iter()][0],savedGear);assert.deepEqual(player(),replayPlayer);
  console.log('PASS repeatable packs, 20 two-pull casts, independent results, XP once, resource bait once, cooldown/replay, all bait effects, depletion and immutable cast snapshots.');
  ownerCall('prepare_trader_player',['9302','10000000','1000','1']);ownerCall('proof_materials',['9302','100','100']);
  let test=await prepare(9302n);ownerCall('proof_materials',['9302','0','100']);await assert.rejects(bot.reducers.commitUpgradeFromDiscord({...test.args,nonce:test.q.nonce}),/INSUFFICIENT_MATERIALS/);assert.equal(quality(),0);assert.equal(material('scrap'),100n);
  ownerCall('proof_materials',['9302','100','100']);test=await prepare(9302n);ownerCall('proof_expire_upgrade',[test.q.nonce.toString()]);await assert.rejects(bot.reducers.commitUpgradeFromDiscord({...test.args,nonce:test.q.nonce}),/ACTION_EXPIRED|ACTION_NOT_FOUND/);
  test=await prepare(9302n);ownerCall('proof_change_quality',['1']);await assert.rejects(bot.reducers.commitUpgradeFromDiscord({...test.args,nonce:test.q.nonce}),/QUOTE_CHANGED/);ownerCall('activate_crafting');
  test=await prepare(9302n);args=await select(9301n);await assert.rejects(bot.reducers.commitUpgradeFromDiscord({...args,nonce:test.q.nonce}),/ACTION_CONFLICT|ACTION_NOT_FOUND/);
  const browser=await connect(['my_link_challenge','my_player','my_rods','my_items','my_upgrade_quote','my_baits','my_bait_loadout','my_ledger'].map(t=>`SELECT * FROM ${t}`));
  const linker=await connect(['SELECT * FROM my_service']);ownerCall('configure_service',[JSON.stringify(linker.identity!.toHexString()),JSON.stringify({accountLinker:{}}),'true']);
  await browser.reducers.beginLinkChallenge({});const link=[...browser.db.myLinkChallenge.iter()][0];await linker.reducers.completeAccountLink({challengeId:link.challengeId,proof:link.proof,verifiedDiscordUserId:9302n});
  await browser.reducers.prepareRodUpgrade({rodId:1});const q=[...browser.db.myUpgradeQuote.iter()][0];await assert.rejects(browser.reducers.commitRodUpgrade({nonce:q.nonce^1n}),/ACTION_CONFLICT/);
  test=await prepare(9302n);const race=await Promise.allSettled([browser.reducers.commitRodUpgrade({nonce:q.nonce}),bot.reducers.commitUpgradeFromDiscord({...test.args,nonce:test.q.nonce})]);assert.equal(race.filter(r=>r.status==='fulfilled').length,1);assert.equal(race.filter(r=>r.status==='rejected'&&/QUOTE_CHANGED/.test(String(r.reason))).length,1);
  assert.equal([...browser.db.myRods.iter()][0].upgradeLevel,1);assert.equal([...browser.db.myItems.iter()].find(r=>r.item==='rusted_tin')!.quantity,90n);assert.equal([...browser.db.myItems.iter()].find(r=>r.item==='scrap')!.quantity,95n);
  const beforeActivation=JSON.stringify([...browser.db.myPlayer.iter()],(_,v)=>typeof v==='bigint'?v.toString():v);ownerCall('activate_crafting');ownerCall('activate_crafting');assert.equal(JSON.stringify([...browser.db.myPlayer.iter()],(_,v)=>typeof v==='bigint'?v.toString():v),beforeActivation);
  const stranger=await connect(['my_baits','my_bait_loadout','my_upgrade_quote','adapter_baits','adapter_upgrade_quote','adapter_cast_pulls','adapter_cast_equipment','adapter_items'].map(t=>`SELECT * FROM ${t}`));
  for(const view of [stranger.db.myBaits,stranger.db.myBaitLoadout,stranger.db.myUpgradeQuote,stranger.db.adapterBaits,stranger.db.adapterUpgradeQuote,stranger.db.adapterCastPulls,stranger.db.adapterCastEquipment,stranger.db.adapterItems])assert.equal(view.count(),0n);
  await assert.rejects(stranger.reducers.equipBait({baitId:1}),/ACCOUNT_NOT_LINKED/);await assert.rejects(stranger.reducers.activateCrafting({}),/OWNER_REQUIRED/);
  for(const table of ['bait_stack','bait_loadout','upgrade_quote','cast_pull','cast_equipment_receipt'])await assert.rejects(connect([`SELECT * FROM ${table}`]));
  await browser.reducers.unlinkBrowser({});assert.equal(browser.db.myBaits.count(),0n);assert.equal(browser.db.myUpgradeQuote.count(),0n);assert.equal(browser.db.myRods.count(),0n);
  ownerCall('prepare_trader_player',['9303','10000000','1000','1']);ownerCall('proof_materials',['9303','100','100']);ownerCall('proof_cast_setup',['9303','1','0']);
  const native=await new Promise<{status:number|null,stdout:string,stderr:string}>((resolve,reject)=>{
    const child=spawn('target/debug/adapter-proof.exe',[],{windowsHide:true,env:{...process.env,TEST_SPACETIMEDB_URI:uri,TEST_SPACETIMEDB_DATABASE:database,TEST_BOT_TOKEN:bot.token!,TEST_CRAFTING_PROOF:'true'}});
    let stdout='',stderr='';child.stdout.on('data',data=>stdout+=data);child.stderr.on('data',data=>stderr+=data);child.on('error',reject);child.on('close',status=>resolve({status,stdout,stderr}));
  });assert.equal(native.status,0,native.stderr||native.stdout);console.log('PASS native crafting transport, snapshot and reconnect replay.');
  ownerCall('configure_service',[JSON.stringify(bot.identity!.toHexString()),JSON.stringify({discordAdapter:{}}),'false']);
  const end=Date.now()+12000;while([bot.db.adapterItems,bot.db.adapterCastPulls,bot.db.adapterBaits].some(view=>view.count()!==0n) && Date.now()<end)await new Promise(r=>setTimeout(r,20));
  assert.equal(bot.db.adapterItems.count(),0n);assert.equal(bot.db.adapterCastPulls.count(),0n);assert.equal(bot.db.adapterBaits.count(),0n);
  console.log('PASS material loss/expiry/stale recipe/wrong account, browser-Discord crafting race, metadata-only activation, anonymous privacy, private-table denial, unlink and service revocation.');
} finally {for(const connection of connections)connection.disconnect();}
