import assert from 'node:assert/strict';
import { rankSpreads } from '../apps/web/src/lib/game/compendium';
import { xpToNextLevel } from '../apps/web/src/lib/game/progression';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { spawnSync, spawn } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';

const uri = 'http://127.0.0.1:3127';
const craftingContent = JSON.parse(await readFile('content/crafting.json', 'utf8'));
const traderContent = JSON.parse(await readFile('content/trader.json', 'utf8'));
const price = (listingId: number) => traderContent.listings.find((r: {listingId: number}) => r.listingId === listingId).priceCoins;
const recipeFor = (rodId: number, qualityLevel: number) => craftingContent.rodRecipes.find((r: {rodId: number; qualityLevel: number}) => r.rodId === rodId && r.qualityLevel === qualityLevel);
const maxQuality = craftingContent.qualities.at(-1);
const forcedBasePower = String(200 - maxQuality.powerBonus);
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
await writeFile(fixtureRoot + '/lib.rs', source + `\ninclude!(${rustPath('scripts/fixtures/crafting-boundary.rs')});\ninclude!(${rustPath('scripts/fixtures/perfect-catches.rs')});\n`);
run('cargo', ['build', '--manifest-path', fixtureRoot + '/Cargo.toml', '--target-dir', targetRoot, '--target', 'wasm32-unknown-unknown', '--release', '--locked']);
run('cargo', ['build', '--package', 'game-client', '--bin', 'adapter-proof', '--locked']);
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
  const bot = await connect(['my_service','adapter_player','adapter_rods','adapter_baits','adapter_bait_loadout','adapter_items','adapter_upgrade_quote','adapter_shop_quote','adapter_receipt','adapter_cast_pulls','adapter_cast_equipment','adapter_inventory','adapter_collection','shop_listing','bait_definition','quality_definition','rod_upgrade_recipe'].map(t=>`SELECT * FROM ${t}`));
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
  assert.equal(bot.db.baitDefinition.count(),13n);assert.equal(bot.db.qualityDefinition.count(),7n);assert.equal(bot.db.shopListing.count(),25n);
  assert.equal(bot.db.rodUpgradeRecipe.count(),42n);
  for(const expected of craftingContent.rodRecipes) {
    const actual=[...bot.db.rodUpgradeRecipe.iter()].find(r=>r.rodId===expected.rodId&&r.qualityLevel===expected.qualityLevel)!;
    assert.equal(actual.tinCost,BigInt(expected.tinCost));assert.equal(actual.scrapCost,BigInt(expected.scrapCost));
  }
  ownerCall('prepare_trader_player',['9305','0',String(price(21)-1),'1']);
  await assert.rejects(purchase(21,9305n),/INSUFFICIENT_COINS/);
  ownerCall('prepare_trader_player',['9305','0',String(price(21)),'1']);
  {
    const args=await purchase(21,9305n);
    assert.equal(player().totalXp,0n);assert.equal(player().coins,0n);
    await bot.reducers.changeLoadoutFromDiscord({...args,rodId:2});assert.equal(player().equippedRodId,2);
    await assert.rejects(purchase(20,9305n),/SHOP_LEVEL_REQUIRED/);
    await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});
  }
  const xpAtLevel=(level:number)=>Array.from({length:level-1},(_,i)=>Number(xpToNextLevel(i+1,10))).reduce((sum,xp)=>sum+xp,0);
  ownerCall('prepare_trader_player',['9306',String(xpAtLevel(5)),'1000000','1']);
  await purchase(20,9306n);let goldArgs=await purchase(31,9306n);
  await bot.reducers.changeLoadoutFromDiscord({...goldArgs,rodId:3});assert.equal(player().equippedRodId,3);
  await assert.rejects(purchase(30,9306n),/SHOP_LEVEL_REQUIRED/);
  await assert.rejects(purchase(41,9306n),/PREVIOUS_LICENCE_REQUIRED/);
  ownerCall('prepare_trader_player',['9306',String(xpAtLevel(10)),String(price(30)+price(41)),'1']);
  await purchase(30,9306n);goldArgs=await purchase(41,9306n);
  assert.equal(player().coins,0n);
  await bot.reducers.changeLoadoutFromDiscord({...goldArgs,rodId:4});assert.equal(player().equippedRodId,4);
  const moonwoodRecipe=recipeFor(4,1);
  ownerCall('proof_materials',['9306',String(moonwoodRecipe.tinCost),String(moonwoodRecipe.scrapCost)]);
  const goldUpgrade=await prepare(9306n,4);assert.equal(goldUpgrade.q.tinCost,BigInt(moonwoodRecipe.tinCost));assert.equal(goldUpgrade.q.scrapCost,BigInt(moonwoodRecipe.scrapCost));
  await bot.reducers.commitUpgradeFromDiscord({...goldUpgrade.args,nonce:goldUpgrade.q.nonce});
  assert.equal(material('rusted_tin'),0n);assert.equal(material('scrap'),0n);
  console.log('PASS gold-only rod purchase/equip/cast, exact wallet boundary, retained licence levels and previous-licence gates, and rod-specific recipe below the former level unlock.');
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
  await purchase(31);await purchase(30);await purchase(41);
  for(const id of [2,3,4]) for(let level=1;level<=6;level++) {
    const {args,q}=await prepare(9301n,id);const recipe=craftingContent.rodRecipes.find((r:{rodId:number;qualityLevel:number})=>r.rodId===id&&r.qualityLevel===level);
    assert.equal(q.tinCost,BigInt(recipe.tinCost));assert.equal(q.scrapCost,BigInt(recipe.scrapCost));
    await bot.reducers.commitUpgradeFromDiscord({...args,nonce:q.nonce});tin-=q.tinCost;scrap-=q.scrapCost;
    assert.equal(material('rusted_tin'),tin);assert.equal(material('scrap'),scrap);assert.equal(quality(id),level);
    await bot.reducers.commitUpgradeFromDiscord({...args,nonce:q.nonce});assert.equal(material('rusted_tin'),tin);assert.equal(material('scrap'),scrap);
  }
  console.log('PASS all 42 published recipes and exact River/Marsh/Moonwood costs, spending and replay.');
  console.log('PASS six exact recipes, permanent independent qualities, guaranteed craft success, atomic spending, replay and licence-only travel.');
  await purchase(101);await purchase(101);assert.equal(uses(1),20n);
  await purchase(102);assert.equal(uses(2),10n);
  for(const listing of [103,104,105])await purchase(listing);
  args=await select();await bot.reducers.equipBaitFromDiscord({...args,baitId:1});
  let mixed=false;
  for(let cast=0;cast<20;cast++){
    // Proof-only base plus current Prismatic bonus forces exactly two pulls.
    ownerCall('proof_cast_setup',['9301',forcedBasePower,String(cast<3?cast:3)]);
    args=await select();const before=player();const beforeTin=material('rusted_tin'),beforeScrap=material('scrap');
    await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});
    const pulls=[...bot.db.adapterCastPulls.iter()];const total=[...bot.db.adapterReceipt.iter()][0];const gear=[...bot.db.adapterCastEquipment.iter()][0];
    assert.equal(pulls.length,2);assert.equal(player().completedCasts,before.completedCasts+1n);
    assert.equal(uses(1),BigInt(19-cast));assert.equal(gear.baitUsesLeft,uses(1));assert.equal(gear.qualityLevel,6);assert.equal(gear.power,200);
    assert.equal(gear.luckBp,maxQuality.luckBp);assert.equal(gear.xpBonusBp,maxQuality.xpBonusBp);
    assert.equal(total.xpGranted,pulls.reduce((sum,p)=>sum+p.baseXp,0n)*BigInt(10000+maxQuality.xpBonusBp)/10000n);
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
  for (const id of [9,10,11,12]) {
    const definition=craftingContent.baits.find((b:{baitId:number})=>b.baitId===id);
    const beforeCoins=player().coins;
    await purchase(100+id);assert.equal(beforeCoins-player().coins,BigInt(definition.priceCoins));assert.equal(uses(id),10n);
    args=await select();await bot.reducers.equipBaitFromDiscord({...args,baitId:id});
    for(let cast=0;cast<10;cast++) {
      ownerCall('proof_cast_setup',['9301',forcedBasePower,String(cast%4)]);
      args=await select();const before=material(definition.resourceItem);
      await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});
      const pulls=[...bot.db.adapterCastPulls.iter()];assert.equal(pulls.length,2);
      const natural=pulls.filter(p=>p.receipt.itemGranted===definition.resourceItem).reduce((sum,p)=>sum+p.receipt.itemQuantity,0n);
      assert.equal(material(definition.resourceItem),before+natural+BigInt(definition.resourceQuantity));
      const receipt=[...bot.db.adapterCastEquipment.iter()][0];assert.equal(receipt.baitQuantity,BigInt(definition.resourceQuantity));assert.equal(receipt.baitItem,definition.resourceItem);
      assert.equal(uses(id),BigInt(9-cast));const saved=material(definition.resourceItem);
      await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});assert.equal(material(definition.resourceItem),saved);assert.equal(uses(id),BigInt(9-cast));
      await assert.rejects(bot.reducers.fishFromDiscord({...args,interactionId:snowflake(),guildId:5n,channelId:42n}),/COOLDOWN_ACTIVE/);assert.equal(uses(id),BigInt(9-cast));
    }
    assert.equal([...bot.db.adapterBaitLoadout.iter()][0].baitId,0);
  }
  console.log('PASS all four chum tiers: exact pack prices, once-per-cast +2/+3 grants across categories and double pulls, saved quantity, replay, cooldown and depletion.');
  ownerCall('proof_cast_setup',['9301','1','0']);args=await select();await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});assert.equal([...bot.db.adapterCastEquipment.iter()][0].baitId,0);assert.equal(uses(1),0n);
  args=await select();await bot.reducers.equipBaitFromDiscord({...args,baitId:2});ownerCall('proof_cast_setup',['9301',forcedBasePower,'2']);args=await select();const oldScrap=material('scrap');await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});assert.equal(material('scrap'),oldScrap+1n+[...bot.db.adapterCastPulls.iter()].reduce((sum,p)=>sum+p.receipt.itemQuantity,0n));assert.equal(uses(2),9n);
  for(const [id,luck] of [[3,maxQuality.luckBp+500],[4,maxQuality.luckBp+1000],[5,maxQuality.luckBp+1500]]){args=await select();await bot.reducers.equipBaitFromDiscord({...args,baitId:id});ownerCall('proof_cast_setup',['9301','1','0']);args=await select();await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});assert.equal([...bot.db.adapterCastEquipment.iter()][0].luckBp,luck);assert.equal(uses(id),9n);}
  for (const [id, bonus] of [[6,2500],[7,5000],[8,10000]]) {
    const coins = player().coins;
    await purchase(100+id);
    assert.equal(coins-player().coins,BigInt([50,150,400][id-6]));
    args=await select();await bot.reducers.equipBaitFromDiscord({...args,baitId:id});
    for (let cast=0;cast<10;cast++) {
      ownerCall('proof_cast_setup',['9301',forcedBasePower,String(cast%3)]);
      args=await select();const before=player();
      await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});
      const gear=[...bot.db.adapterCastEquipment.iter()][0];
      const pulls=[...bot.db.adapterCastPulls.iter()];
      const total=[...bot.db.adapterReceipt.iter()][0];
      assert.equal(pulls.length,2);assert.equal(gear.xpBonusBp,maxQuality.xpBonusBp+bonus);
      assert.equal(gear.luckBp,maxQuality.luckBp);assert.equal(gear.baitItem,'');
      assert.equal(total.xpGranted,pulls.reduce((sum,p)=>sum+p.baseXp,0n)*BigInt(10000+maxQuality.xpBonusBp+bonus)/10000n);
      assert.equal(total.xpGranted,pulls.reduce((sum,p)=>sum+p.receipt.xpGranted,0n));
      assert.equal(player().totalXp,before.totalXp+total.xpGranted);assert.equal(uses(id),BigInt(9-cast));
      const committed=player();await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});
      assert.deepEqual(player(),committed);assert.equal(uses(id),BigInt(9-cast));
      await assert.rejects(bot.reducers.fishFromDiscord({...args,interactionId:snowflake(),guildId:5n,channelId:42n}),/COOLDOWN_ACTIVE/);
      assert.equal(uses(id),BigInt(9-cast));
    }
    assert.equal([...bot.db.adapterBaitLoadout.iter()][0].baitId,0);
  }
  console.log('PASS all three XP slime purchases, additive bonuses on fish/junk/treasure and two pulls, one-use consumption, replay, cooldown rejection and ten-use depletion.');
  const savedPulls=[...bot.db.adapterCastPulls.iter()],savedGear=[...bot.db.adapterCastEquipment.iter()][0];
  await bot.reducers.equipBaitFromDiscord({...args,baitId:0});await bot.reducers.changeLoadoutFromDiscord({...args,rodId:2});const replayPlayer=player();await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});assert.deepEqual([...bot.db.adapterCastPulls.iter()],savedPulls);assert.deepEqual([...bot.db.adapterCastEquipment.iter()][0],savedGear);assert.deepEqual(player(),replayPlayer);
  console.log('PASS repeatable packs, 20 two-pull casts, independent results, XP once, resource bait once, cooldown/replay, all bait effects, depletion and immutable cast snapshots.');
  // A separate angler exercises Trophy Chum in every biome, with real licences.
  const trophyUser=9513n;
  ownerCall('prepare_trader_player',[trophyUser.toString(),'10000000','1000000','1']);
  for(const listingId of [20,30,40,50,60,70])await purchase(listingId,trophyUser);
  let catchesOutsideOldPool=0;
  const speciesContent=JSON.parse(await readFile('content/species.json','utf8'));
  for(let biomeId=1;biomeId<=7;biomeId++) {
    const ordinary=speciesContent.species.filter((f:any)=>f.biomeId===biomeId&&f.countsForOrdinaryCollectionCompletion);
    const ordered=[...ordinary].sort((a:any,b:any)=>b.typicalWeightG-a.typicalWeightG);
    const cutoff=ordered[Math.ceil(ordered.length/3)-1].typicalWeightG;
    args=await select(trophyUser);await bot.reducers.changeLoadoutFromDiscord({...args,biomeId});
    const coins=player().coins;await purchase(113,trophyUser);assert.equal(coins-player().coins,1000n);
    args=await select(trophyUser);await bot.reducers.equipBaitFromDiscord({...args,baitId:13});
    ownerCall('proof_biome_category',[String(biomeId),'0']);
    for(let cast=0;cast<10;cast++) {
      ownerCall('proof_cast_setup',[trophyUser.toString(),'200','0']);
      args=await select(trophyUser);const before=player();await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});
      const pulls=[...bot.db.adapterCastPulls.iter()];assert.equal(pulls.length,2);
      assert(pulls.every(p=>p.receipt.outcome==='fish'));
      for(const p of pulls.filter(p=>p.receipt.outcome==='fish')) {
        const f=speciesContent.species.find((f:any)=>f.speciesId===p.receipt.speciesId);assert(f&&f.biomeId===biomeId,'Trophy Chum retains the full biome pool');assert(f.allowedRarities.includes(p.receipt.rarity));if(f.countsForOrdinaryCollectionCompletion&&f.typicalWeightG<cutoff)catchesOutsideOldPool++;
      }
      const gear=[...bot.db.adapterCastEquipment.iter()][0];assert.equal(gear.baitSizeRolls,8);assert.equal(gear.luckBp,2500);
      assert.equal(uses(13),BigInt(9-cast));const saved=player();await bot.reducers.fishFromDiscord({...args,guildId:5n,channelId:42n});assert.deepEqual(player(),saved);assert.equal(uses(13),BigInt(9-cast));
      await assert.rejects(bot.reducers.fishFromDiscord({...args,interactionId:snowflake(),guildId:5n,channelId:42n}),/COOLDOWN_ACTIVE/);assert.equal(uses(13),BigInt(9-cast));assert.equal(saved.completedCasts,before.completedCasts+1n);
    }
    assert.equal([...bot.db.adapterBaitLoadout.iter()][0].baitId,0);
  }
  assert(catchesOutsideOldPool>0,'Smaller species remain catchable with Trophy Chum');
  console.log('PASS Trophy Chum exact purchase cost, full species pool in all seven biomes, double pulls with one charge, saved effect metadata, replay/cooldown and depletion.');
  args=await select();
  ownerCall('prepare_trader_player',['9302','10000000','1000','1']);ownerCall('proof_materials',['9302','100','100']);
  let test=await prepare(9302n);ownerCall('proof_materials',['9302','0','100']);await assert.rejects(bot.reducers.commitUpgradeFromDiscord({...test.args,nonce:test.q.nonce}),/INSUFFICIENT_MATERIALS/);assert.equal(quality(),0);assert.equal(material('scrap'),100n);
  ownerCall('proof_materials',['9302','100','100']);test=await prepare(9302n);ownerCall('proof_expire_upgrade',[test.q.nonce.toString()]);await assert.rejects(bot.reducers.commitUpgradeFromDiscord({...test.args,nonce:test.q.nonce}),/ACTION_EXPIRED|ACTION_NOT_FOUND/);
  test=await prepare(9302n);ownerCall('proof_change_quality',['1']);await assert.rejects(bot.reducers.commitUpgradeFromDiscord({...test.args,nonce:test.q.nonce}),/QUOTE_CHANGED/);ownerCall('activate_crafting');
  test=await prepare(9302n);ownerCall('proof_change_recipe',['1','1']);await assert.rejects(bot.reducers.commitUpgradeFromDiscord({...test.args,nonce:test.q.nonce}),/QUOTE_CHANGED/);ownerCall('activate_crafting');
  test=await prepare(9302n);args=await select(9301n);await assert.rejects(bot.reducers.commitUpgradeFromDiscord({...args,nonce:test.q.nonce}),/ACTION_CONFLICT|ACTION_NOT_FOUND/);
  const browser=await connect(['my_link_challenge','my_player','my_rods','my_items','my_upgrade_quote','my_baits','my_bait_loadout','my_ledger','my_inventory','perfect_species','earned_achievement','angler_title'].map(t=>`SELECT * FROM ${t}`));
  const linker=await connect(['SELECT * FROM my_service']);ownerCall('configure_service',[JSON.stringify(linker.identity!.toHexString()),JSON.stringify({accountLinker:{}}),'true']);
  await browser.reducers.beginLinkChallenge({});const link=[...browser.db.myLinkChallenge.iter()][0];await linker.reducers.completeAccountLink({challengeId:link.challengeId,proof:link.proof,verifiedDiscordUserId:9302n});
  await browser.reducers.prepareRodUpgrade({rodId:1});const q=[...browser.db.myUpgradeQuote.iter()][0];await assert.rejects(browser.reducers.commitRodUpgrade({nonce:q.nonce^1n}),/ACTION_CONFLICT/);
  test=await prepare(9302n);const race=await Promise.allSettled([browser.reducers.commitRodUpgrade({nonce:q.nonce}),bot.reducers.commitUpgradeFromDiscord({...test.args,nonce:test.q.nonce})]);assert.equal(race.filter(r=>r.status==='fulfilled').length,1);assert.equal(race.filter(r=>r.status==='rejected'&&/QUOTE_CHANGED/.test(String(r.reason))).length,1);
  assert.equal([...browser.db.myRods.iter()][0].upgradeLevel,1);assert.equal([...browser.db.myItems.iter()].find(r=>r.item==='rusted_tin')!.quantity,90n);assert.equal([...browser.db.myItems.iter()].find(r=>r.item==='scrap')!.quantity,95n);
  // Recognition is cosmetic, unique per species and remains after a real sale.
  const perfectPlayer=[...browser.db.myPlayer.iter()][0].playerId;
  const perfectRows=()=>[...browser.db.perfectSpecies.iter()].filter(r=>r.playerId===perfectPlayer);
  const awardRows=()=>[...browser.db.earnedAchievement.iter()].filter(r=>r.playerId===perfectPlayer&&r.achievementId===19);
  const drain=async()=>{await select(9302n);await new Promise(r=>setTimeout(r,100));};
  const carp=speciesContent.species.find((f:any)=>f.key==='carp');
  const minnow=speciesContent.species.find((f:any)=>f.key==='minnow');
  const sock=speciesContent.species.find((f:any)=>f.key==='nidalees-lost-sock');
  const perfectSeed=(fish:any, dl=0, dw=0)=>{const max=rankSpreads(fish).at(-1)!;ownerCall('proof_perfect_specimen',['9302',String(fish.speciesId),String(max.maxLengthMm-dl),String(max.maxWeightG-dw)]);};
  const cosmeticBefore=[...browser.db.myPlayer.iter()][0];
  perfectSeed(carp,1,0);perfectSeed(carp,0,1);perfectSeed(sock);await drain();
  assert.equal(perfectRows().length,0);assert.equal(awardRows().length,0);
  await assert.rejects(browser.reducers.equipTitle({achievementId:19}),/TITLE_NOT_EARNED/);
  perfectSeed(carp);await drain();assert.equal(perfectRows().length,1);assert.equal(awardRows().length,1);
  const firstStamp=perfectRows()[0];perfectSeed(carp);perfectSeed(minnow);await drain();
  assert.equal(perfectRows().length,2);assert.equal(awardRows().length,1);assert.deepEqual(perfectRows().find(r=>r.speciesId===carp.speciesId),firstStamp);
  assert.equal([...browser.db.myPlayer.iter()][0].totalXp,cosmeticBefore.totalXp);assert.equal([...browser.db.myPlayer.iter()][0].coins,cosmeticBefore.coins);
  await browser.reducers.equipTitle({achievementId:19});assert.equal([...browser.db.anglerTitle.iter()].find(r=>r.playerId===perfectPlayer)!.achievementId,19);
  await pause();await browser.reducers.prepareInventoryAction({kind:'sell',catchIds:[firstStamp.firstCatchId],favorite:false});
  const inventoryAction=await connect(['SELECT * FROM my_action']);
  // A quote belongs to its original browser; read it on that same connection.
  await new Promise<void>((resolve,reject)=>browser.subscriptionBuilder().onApplied(()=>resolve()).onError(c=>reject(c.event)).subscribe(['SELECT * FROM my_action']));
  await browser.reducers.commitInventoryAction({nonce:[...browser.db.myAction.iter()][0].nonce});await drain();
  assert(![...browser.db.myInventory.iter()].some(r=>r.catchId===firstStamp.firstCatchId));assert.equal(perfectRows().length,2);assert.equal(awardRows().length,1);
  await assert.rejects(inventoryAction.reducers.activatePerfectCatches({}),/OWNER_REQUIRED/);
  console.log('PASS exact paired maxima, 1mm/1g near misses, fake-fish exclusion, unique badge/title, species deduplication, distinct species, no XP/coin bonus, and permanent marks after sale.');
  const beforeActivation=JSON.stringify([...browser.db.myPlayer.iter()],(_,v)=>typeof v==='bigint'?v.toString():v);
  console.log(run(process.execPath,['scripts/xp-preservation.mjs','before',database]).trim());
  ownerCall('activate_xp_progression');ownerCall('activate_xp_progression');
  console.log(run(process.execPath,['scripts/xp-preservation.mjs','after',database]).trim());
  assert.equal(JSON.stringify([...browser.db.myPlayer.iter()],(_,v)=>typeof v==='bigint'?v.toString():v),beforeActivation);
  const stranger=await connect(['my_baits','my_bait_loadout','my_upgrade_quote','adapter_baits','adapter_upgrade_quote','adapter_cast_pulls','adapter_cast_equipment','adapter_items'].map(t=>`SELECT * FROM ${t}`));
  for(const view of [stranger.db.myBaits,stranger.db.myBaitLoadout,stranger.db.myUpgradeQuote,stranger.db.adapterBaits,stranger.db.adapterUpgradeQuote,stranger.db.adapterCastPulls,stranger.db.adapterCastEquipment,stranger.db.adapterItems])assert.equal(view.count(),0n);
  await assert.rejects(stranger.reducers.equipBait({baitId:1}),/ACCOUNT_NOT_LINKED/);await assert.rejects(stranger.reducers.activateCrafting({}),/OWNER_REQUIRED/);
  await assert.rejects(stranger.reducers.activateXpProgression({}),/OWNER_REQUIRED/);
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
