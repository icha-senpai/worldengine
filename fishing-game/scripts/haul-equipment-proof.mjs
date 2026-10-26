import { moduleArtifactPath, requireRustWorkflow, rustTargetPath } from './rust-build.mjs';
import assert from 'node:assert/strict';
import { readFile, writeFile, mkdir, rm } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index.ts';

requireRustWorkflow();
const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-haul-${Date.now()}`;
const proofRoot = resolve('.local', database);
assert.equal(resolve(proofRoot, '..'), resolve('.local'));
assert(/^fishbound-proof-haul-\d+$/.test(database));
const cli = join(process.env.LOCALAPPDATA, 'SpacetimeDB/bin/2.12.0/spacetimedb-cli.exe');
const connections = [];
const keepBrowser = process.argv.includes('--browser');
let retained = false;
function run(command, args) {
  const result = spawnSync(command, args, { encoding: 'utf8', windowsHide: true });
  if (result.error || result.status !== 0) throw Error(result.stderr || result.stdout || String(result.error));
  return result.stdout;
}
const call = (name, args = []) => run(cli, ['call', '--server', uri, '--no-config', '--yes', database, name, ...args]);
const publish = wasm => run(cli, ['publish', '--server', uri, '--no-config', '--yes=skip-login,migrate,break-clients', '--delete-data=never', '--bin-path', wasm, database]);
const canonical = rows => rows.map(row => JSON.stringify(row)).sort();
function decode(type, value) {
  if (type.Product) return Object.fromEntries(type.Product.elements.map((e,i) => [e.name.some, decode(e.algebraic_type, value[i])]));
  if (type.Sum) { const v = type.Sum.variants[value[0]]; return v.name.some === 'none' ? null : decode(v.algebraic_type, value[1]); }
  return value;
}
const query = table => JSON.parse(run(cli, ['sql', '--server', uri, '--no-config', '--format', 'json', database, `SELECT * FROM ${table}`]),
  (_key, value, context) => typeof value === 'number' && !Number.isSafeInteger(value) ? context.source : value)
  .flatMap(block => block.rows.map(row => Object.fromEntries(row.map((v,i) => [block.schema.elements[i].name.some, decode(block.schema.elements[i].algebraic_type, v)]))));
const tables = () => JSON.parse(run(cli, ['describe', '--server', uri, '--no-config', '--json', database, 'tables'])).map(t => t.source_name).filter(t => t !== 'maintenance_job');
const snapshot = names => Object.fromEntries(names.map(name => [name, canonical(query(name))]));
async function connect(names, token) {
  const connected = await new Promise((yes,no) => {
    const c = DbConnection.builder().withUri(uri).withDatabaseName(database).withToken(token)
      .onConnect((c,_identity,token) => yes({ c, token })).onConnectError((_c,e) => no(e)).build();
    connections.push(c);
  });
  await new Promise((yes,no) => connected.c.subscriptionBuilder().onApplied(yes).onError(ctx => no(ctx.event)).subscribe(names.map(name => `SELECT * FROM ${name}`)));
  return connected;
}
async function until(check) {
  const deadline = Date.now()+10000;
  while (!check()) { assert(Date.now()<deadline,'Subscription update timed out'); await new Promise(r => setTimeout(r,20)); }
}
const pause = () => new Promise(r => setTimeout(r,1050));
let serial = 1n;
const snowflake = () => ((BigInt(Date.now())-1420070400000n)<<22n)|serial++;
try {
  // Optional prior candidate proves appended bonus defaults on existing boat/net
  // definitions as well as the original pre-equipment upgrade path.
  publish(process.env.FISHBOUND_HAUL_PREVIOUS_WASM || '.runtime/module/fishing-game-module.wasm');
  const { c: old } = await connect(['adapter_player']);
  call('configure_service',[JSON.stringify(old.identity.toHexString()),JSON.stringify({discordAdapter:{}}),'true']);
  for (const discordUserId of [9451n,9452n]) {
    const interactionId = snowflake();
    await old.reducers.selectDiscordPlayer({ discordUserId, interactionId, displayName:'Retained angler' });
    await old.reducers.fishFromDiscord({ discordUserId, interactionId, guildId:undefined, channelId:42n });
    await old.reducers.dailyFromDiscord({ discordUserId, interactionId:snowflake(), guildId:undefined, channelId:42n });
  }
  old.disconnect();
  const oldTables = tables();
  const before = snapshot(oldTables);
  const hadEquipment = oldTables.includes('haul_equipment_definition');
  publish(moduleArtifactPath());
  const published = snapshot(oldTables);
  for (const table of oldTables) {
    if (table === 'haul_equipment_definition') {
      assert.deepEqual(published[table], canonical(before[table].map(row => {
        const previous = JSON.parse(row);
        return {...previous,luck_bp:previous.luck_bp ?? 0,xp_bonus_bp:previous.xp_bonus_bp ?? 0,rod_id:previous.rod_id ?? 0,quality_bonus_multiplier:previous.quality_bonus_multiplier ?? 1};
      })), 'Existing gear retains metadata with defaults for appended columns');
    } else if (table === 'owned_haul_equipment') {
      assert.deepEqual(published[table], canonical(before[table].map(row => {
        const previous = JSON.parse(row);
        return {...previous,upgrade_level:previous.upgrade_level ?? 0};
      })), 'Existing owned boats/nets retain ownership and start at Common');
    } else assert.deepEqual(published[table],before[table],`${table} retained by publication`);
  }
  call('activate_haul_equipment');
  const activated = snapshot(oldTables);
  for (const table of oldTables.filter(t => !['shop_listing','haul_equipment_definition'].includes(t))) assert.deepEqual(activated[table],published[table],`${table} preserved by activation`);
  const oldOffers = before.shop_listing.map(row => JSON.parse(row));
  const newOffers = query('shop_listing');
  assert.equal(newOffers.length,oldOffers.length+(hadEquipment ? 0 : 12));
  for (const offer of oldOffers) {
    const current = newOffers.find(row => row.listing_id === offer.listing_id);
    assert.equal(current.catalog_version,10);
    assert.deepEqual({...current,catalog_version:offer.catalog_version},offer,'Old offer changes only catalog version');
  }
  const definitions = query('haul_equipment_definition');
  assert.equal(definitions.length,14);
  const catalog = JSON.parse(await readFile('content/haul-equipment.json','utf8'));
  const world = JSON.parse(await readFile('content/world.json','utf8'));
  for (const row of definitions) {
    const equipment = catalog.equipment.find(gear => gear.equipmentId === row.equipment_id);
    const rod = world.rods.find(rod => rod.rodId === equipment.rodId);
    const baseMultiplier = row.kind === 'boat' ? 4 : 2;
    assert.equal(row.luck_bp,rod.luckBp * baseMultiplier,'Authoritative boat/net base luck multiplier');
    assert.equal(row.xp_bonus_bp,rod.xpBonusBp * baseMultiplier,'Authoritative boat/net base XP multiplier');
    assert.equal(row.quality_bonus_multiplier,2,'Authoritative boat/net quality multiplier');
    assert.equal(row.rod_id,rod.rodId,'Upgrade recipe family matches the corresponding rod');
    assert(!('power' in row),'No boat/net power bonus');
    if (hadEquipment) {
      const previous = before.haul_equipment_definition.map(row => JSON.parse(row)).find(gear => gear.equipment_id === row.equipment_id);
      assert.deepEqual(row,{...previous,luck_bp:equipment.luckBp,xp_bonus_bp:equipment.xpBonusBp,rod_id:rod.rodId,quality_bonus_multiplier:2},'Only declared base/quality bonus tuning changes equipment metadata');
    }
  }
  assert.equal(query('owned_haul_equipment').length,4);
  assert.equal(query('haul_loadout').length,2);
  const allTables = tables();
  const once = snapshot(allTables);
  call('activate_haul_equipment');
  assert.deepEqual(snapshot(allTables),once,'Activation is idempotent across every table');
  console.log(`PASS previous WASM -> current WASM: ${oldTables.length} retained tables; declared equipment/catalog changes only; all fourteen base/quality multipliers verified; idempotent activation.`);

  await mkdir(proofRoot);
  const rustPath = path => JSON.stringify(resolve(path).replaceAll('\\','/'));
  await writeFile(join(proofRoot,'Cargo.toml'),(await readFile('spacetimedb/Cargo.toml','utf8'))
    .replace('name = "fishing-game-module"','name = "fishing-haul-proof"')
    .replace('path = "../crates/game-rules"',`path = ${rustPath('crates/game-rules')}`)
    .replace('crate-type = ["cdylib"]','crate-type = ["cdylib"]\npath = "lib.rs"')+'\n[workspace]\n');
  await writeFile(join(proofRoot,'Cargo.lock'),(await readFile('spacetimedb/Cargo.lock','utf8')).replace('name = "fishing-game-module"','name = "fishing-haul-proof"'));
  await writeFile(join(proofRoot,'lib.rs'),(await readFile('spacetimedb/src/lib.rs','utf8')).replace(/^mod (\w+);$/gm,(_m,name) => `#[path = ${rustPath(`spacetimedb/src/${name}.rs`)}]\nmod ${name};`)+`\ninclude!(${rustPath('scripts/fixtures/haul-equipment.rs')});\n`);
  run('cargo',['build','--manifest-path',join(proofRoot,'Cargo.toml'),'--target','wasm32-unknown-unknown','--release','--locked']);
  publish(rustTargetPath('wasm32-unknown-unknown','release','fishing_haul_proof.wasm'));
  const names = ['my_player','my_haul_equipment','my_haul_loadout','my_haul_upgrade_quote','my_shop_quote','my_upgrade_quote','my_rods','my_baits','my_bait_loadout','my_items','my_ledger','my_link_challenge','haul_equipment_definition','quality_definition','shop_listing'];
  const { c: alice, token } = await connect(names);
  const { c: bob } = await connect(names);
  const { c: guest } = await connect(['my_haul_equipment','my_haul_loadout','my_haul_upgrade_quote']);
  for (const [discord,c] of [[9451,alice],[9452,bob]]) call('proof_link_haul_player',[String(discord),JSON.stringify(c.identity.toHexString())]);
  await until(() => alice.db.myHaulEquipment.count()===2n && bob.db.myHaulEquipment.count()===2n);
  assert.equal(guest.db.myHaulEquipment.count(),0n);
  assert.equal(guest.db.myHaulLoadout.count(),0n);
  await assert.rejects(guest.reducers.equipHaulEquipment({equipmentId:101}),/ACCOUNT_NOT_LINKED/);
  await assert.rejects(alice.reducers.activateHaulEquipment({}),/OWNER_REQUIRED/);
  await assert.rejects(alice.reducers.equipHaulEquipment({equipmentId:999}),/EQUIPMENT_UNAVAILABLE/);
  await assert.rejects(alice.reducers.equipHaulEquipment({equipmentId:102}),/EQUIPMENT_NOT_OWNED/);
  await assert.rejects(new Promise((yes,no) => guest.subscriptionBuilder().onApplied(yes).onError(ctx => no(ctx.event)).subscribe(['SELECT * FROM owned_haul_equipment'])));
  await assert.rejects(alice.reducers.prepareShopPurchase({listingId:1102}),/INSUFFICIENT_COINS/);
  call('prepare_trader_player',['9451','0','1000000','1']);
  await assert.rejects(alice.reducers.prepareShopPurchase({listingId:1103}),/PREVIOUS_LICENCE_REQUIRED/);
  await assert.rejects(alice.reducers.prepareShopPurchase({listingId:1203}),/PREVIOUS_LICENCE_REQUIRED/);
  const player = () => [...alice.db.myPlayer.iter()][0];
  const loadout = () => [...alice.db.myHaulLoadout.iter()][0];
  for (const [id,price] of [[106,74000n],[207,130000n],[102,500n],[202,500n]]) {
    await pause();
    const beforePlayer = player();
    const previousLoadout = loadout();
    await alice.reducers.prepareShopPurchase({listingId:1000+id});
    const quote = [...alice.db.myShopQuote.iter()][0];
    await assert.rejects(alice.reducers.commitShopPurchaseQuantity({nonce:quote.nonce,quantity:5}),/SHOP_QUANTITY_INVALID/);
    await assert.rejects(bob.reducers.commitShopPurchase({nonce:quote.nonce}),/ACTION_NOT_FOUND|ACTION_CONFLICT/);
    await alice.reducers.commitShopPurchase({nonce:quote.nonce});
    await until(() => alice.db.myHaulEquipment.iter().some(row => row.equipmentId===id));
    assert.deepEqual(player(),{...beforePlayer,coins:beforePlayer.coins-price},'Purchase changes only wallet');
    assert.deepEqual(loadout(),previousLoadout,'Purchase never auto-equips');
    await alice.reducers.commitShopPurchase({nonce:quote.nonce});
    assert.equal(player().coins,beforePlayer.coins-price,'Same-nonce retry charges once');
    await assert.rejects(alice.reducers.prepareShopPurchase({listingId:1000+id}),/ALREADY_OWNED/);
    await alice.reducers.equipHaulEquipment({equipmentId:id});
    await until(() => (id<200?loadout().boatId:loadout().netId)===id);
    assert.equal(id<200?loadout().netId:loadout().boatId,id<200?previousLoadout.netId:previousLoadout.boatId,'Other slot stays equipped');
    assert.deepEqual(player(),{...beforePlayer,coins:beforePlayer.coins-price},'Equipping does not change player, rod, XP or cooldown');
    await assert.rejects(bob.reducers.equipHaulEquipment({equipmentId:id}),/EQUIPMENT_NOT_OWNED/);
  }
  const ownedCount = alice.db.myHaulEquipment.count();
  call('activate_haul_equipment');
  assert.equal(alice.db.myHaulEquipment.count(),ownedCount,'Activation preserves purchases');
  assert.equal(loadout().boatId,102);
  assert.equal(loadout().netId,202);
  const equipmentHistory = snapshot(['bait_stack','bait_loadout','owned_rod','cast_equipment_receipt','command_receipt']);
  await alice.reducers.equipHaulEquipment({equipmentId:101});
  await alice.reducers.equipHaulEquipment({equipmentId:201});
  assert.deepEqual(snapshot(Object.keys(equipmentHistory)),equipmentHistory,'Rod/bait ownership, loadouts and saved cast receipts stay unchanged');

  call('prepare_trader_player',['9453','0','0','1']);
  assert.equal(query('owned_haul_equipment').filter(row => row.player_id===query('player').find(p => p.discord_user_id===9453).player_id).length,2,'New players receive both free starters');
  call('proof_grant_haul_licences',['9451']);
  call('prepare_trader_player',['9451','10000000','1000000','1']);
  await pause();
  await alice.reducers.prepareShopPurchase({listingId:1104});
  const stale = [...alice.db.myShopQuote.iter()][0];
  call('change_proof_offer',['1104','16001']);
  await assert.rejects(alice.reducers.commitShopPurchase({nonce:stale.nonce}),/QUOTE_CHANGED/);
  call('activate_haul_equipment');
  await pause();
  await alice.reducers.prepareShopPurchase({listingId:1204});
  const expired = [...alice.db.myShopQuote.iter()][0];
  call('expire_proof_quote',[expired.nonce.toString()]);
  await assert.rejects(alice.reducers.commitShopPurchase({nonce:expired.nonce}),/ACTION_EXPIRED|ACTION_NOT_FOUND/);
  alice.disconnect();
  const { c: reconnected } = await connect(names,token);
  assert.equal(reconnected.db.myHaulEquipment.count(),ownedCount);
  assert.equal([...reconnected.db.myHaulLoadout.iter()][0].boatId,101);
  assert.equal([...reconnected.db.myHaulLoadout.iter()][0].netId,201);
  const upgradeQuote = () => [...reconnected.db.myHaulUpgradeQuote.iter()][0];
  const ownedGear = id => [...reconnected.db.myHaulEquipment.iter()].find(row => row.equipmentId===id);
  const materials = () => Object.fromEntries([...reconnected.db.myItems.iter()].map(row => [row.item,row.quantity]));
  await assert.rejects(guest.reducers.prepareHaulUpgrade({equipmentId:101}),/ACCOUNT_NOT_LINKED/);
  await assert.rejects(reconnected.reducers.prepareHaulUpgrade({equipmentId:999}),/EQUIPMENT_NOT_OWNED/);
  await assert.rejects(bob.reducers.prepareHaulUpgrade({equipmentId:106}),/EQUIPMENT_NOT_OWNED/);
  call('proof_haul_materials',['9451','0','0']);
  await assert.rejects(reconnected.reducers.prepareHaulUpgrade({equipmentId:101}),/INSUFFICIENT_MATERIALS/);
  call('proof_haul_materials',['9451','100000','100000']);
  for (const change of [0,1,2,3]) {
    await pause();
    await reconnected.reducers.prepareHaulUpgrade({equipmentId:101});
    const quote = upgradeQuote();
    const beforeMaterials = materials();
    call('proof_change_haul_quote',[quote.nonce.toString(),String(change)]);
    await assert.rejects(reconnected.reducers.commitHaulUpgrade({nonce:quote.nonce}),change===0 ? /ACTION_EXPIRED|ACTION_NOT_FOUND/ : /QUOTE_CHANGED/);
    assert.deepEqual(materials(),beforeMaterials,'Expired/changed upgrade previews spend nothing');
    assert.equal(ownedGear(101).upgradeLevel,0);
    call('proof_restore_haul_crafting');
  }
  await pause();
  await reconnected.reducers.prepareHaulUpgrade({equipmentId:101});
  const insufficient = upgradeQuote();
  call('proof_haul_materials',['9451','0','100000']);
  await assert.rejects(reconnected.reducers.commitHaulUpgrade({nonce:insufficient.nonce}),/INSUFFICIENT_MATERIALS/);
  assert.equal(ownedGear(101).upgradeLevel,0);
  assert.equal(upgradeQuote().consumed,false,'Failed confirmation does not consume its quote');
  call('proof_haul_materials',['9451','100000','100000']);

  // Purchase remaining families through the actual shop; every boat and net
  // follows its matching rod's six recipes, including the unusual Twig costs.
  for (const gear of catalog.equipment.filter(row => !ownedGear(row.equipmentId))) {
    await pause();
    await reconnected.reducers.prepareShopPurchase({listingId:1000+gear.equipmentId});
    const quote = [...reconnected.db.myShopQuote.iter()][0];
    await reconnected.reducers.commitShopPurchase({nonce:quote.nonce});
  }
  const untouchedTables = ['player','owned_rod','bait_stack','bait_loadout','haul_loadout','cast_equipment_receipt','command_receipt','player_species_progress'];
  const untouched = snapshot(untouchedTables);
  const crafting = JSON.parse(await readFile('content/crafting.json','utf8'));
  for (const gear of catalog.equipment) {
    for (let quality=1; quality<=6; quality++) {
      await pause();
      const recipe = crafting.rodRecipes.find(row => row.rodId===gear.rodId && row.qualityLevel===quality);
      const beforeMaterials = materials();
      await reconnected.reducers.prepareHaulUpgrade({equipmentId:gear.equipmentId});
      const quote = upgradeQuote();
      assert.equal(quote.fromQuality,quality-1);
      assert.equal(quote.rodId,gear.rodId);
      assert.equal(quote.tinCost,BigInt(recipe.tinCost));
      assert.equal(quote.scrapCost,BigInt(recipe.scrapCost));
      if (quality===1) {
        await reconnected.reducers.prepareHaulUpgrade({equipmentId:gear.equipmentId});
        assert.equal(upgradeQuote().nonce,quote.nonce,'Fast duplicate preview keeps the same quote');
        await assert.rejects(reconnected.reducers.commitHaulUpgrade({nonce:quote.nonce^1n}),/ACTION_CONFLICT/);
        await assert.rejects(bob.reducers.commitHaulUpgrade({nonce:quote.nonce}),/ACTION_NOT_FOUND|ACTION_CONFLICT/);
        assert.equal(guest.db.myHaulUpgradeQuote.count(),0n);
      }
      await Promise.all([
        reconnected.reducers.commitHaulUpgrade({nonce:quote.nonce}),
        reconnected.reducers.commitHaulUpgrade({nonce:quote.nonce}),
      ]);
      assert.equal(ownedGear(gear.equipmentId).upgradeLevel,quality);
      assert.deepEqual(materials(),{
        ...beforeMaterials,
        rusted_tin:beforeMaterials.rusted_tin-BigInt(recipe.tinCost),
        scrap:beforeMaterials.scrap-BigInt(recipe.scrapCost),
      },'Concurrent same-nonce confirmation spends each material exactly once');
      assert.equal(upgradeQuote().consumed,true);
    }
    await assert.rejects(reconnected.reducers.prepareHaulUpgrade({equipmentId:gear.equipmentId}),/MAX_QUALITY/);
  }
  assert.deepEqual(snapshot(untouchedTables),untouched,'All 84 upgrades preserve wallets, XP, normal rods/bait, cooldowns, selected gear, catches and collections');
  const upgradedState = snapshot(['owned_haul_equipment','haul_loadout','item_stack']);
  call('activate_haul_equipment');
  assert.deepEqual(snapshot(Object.keys(upgradedState)),upgradedState,'Activation cannot reset purchased qualities or resources');
  for (const reason of ['boat_upgrade','net_upgrade']) {
    // The player's recent-ledger view intentionally retains only 100 rows.
    const rows = query('economy_ledger').filter(row => row.reason===reason);
    assert.equal(rows.filter(row => row.item==='rusted_tin').length,42);
    assert.equal(rows.filter(row => row.item==='scrap').length,42);
    assert.equal(rows.filter(row => row.item.endsWith('_quality')).length,42);
  }

  // The shared material spender must retain the original rod behavior too.
  const beforeRodMaterials = materials();
  await reconnected.reducers.prepareRodUpgrade({rodId:1});
  const rodQuote = [...reconnected.db.myUpgradeQuote.iter()][0];
  await reconnected.reducers.commitRodUpgrade({nonce:rodQuote.nonce});
  await reconnected.reducers.commitRodUpgrade({nonce:rodQuote.nonce});
  assert.equal([...reconnected.db.myRods.iter()].find(row => row.rodId===1).upgradeLevel,1);
  assert.equal(materials().rusted_tin,beforeRodMaterials.rusted_tin-10n);
  assert.equal(materials().scrap,beforeRodMaterials.scrap-5n);
  assert([...reconnected.db.myLedger.iter()].some(row => row.reason==='rod_upgrade' && row.item==='rusted_tin' && row.delta===-10n));
  console.log('PASS all 84 boat/net upgrades: exact family recipes, all seven qualities, capped Prismatic, concurrent replay, ledger entries, invalid/expired/stale/unaffordable confirmations, privacy and original rod regression.');
  for (const id of [101,201]) call('proof_haul_quality',['9451',String(id),'0']);
  call('proof_haul_materials',['9451','10000','10000']);
  console.log('PASS browser purchase/equip, exact prices, coin/licence gates, level-1 Glacial/Abyssal, no bulk buying, replay, stale/expired quotes, private ownership, free starters, independent slots, reconnect and rod/bait/cooldown preservation.');
  if (keepBrowser) {
    await writeFile('.local/haul-equipment-browser.json',JSON.stringify({uri,database,token,proofRoot},null,2));
    retained = true;
    console.log(`Browser fixture ready: ${database}. Remove it and ${proofRoot} after UI verification.`);
  } else {
    await reconnected.reducers.unlinkBrowser({});
    await until(() => reconnected.db.myHaulEquipment.count()===0n && reconnected.db.myHaulLoadout.count()===0n);
  }
} finally {
  for (const c of connections) c.disconnect();
  if (!retained) {
    run(cli,['delete','--server',uri,'--no-config','--yes',database]);
    await rm(proofRoot,{recursive:true,force:true});
  }
}
