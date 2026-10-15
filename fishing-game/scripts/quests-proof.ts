import { moduleArtifactPath, requireRustWorkflow, rustTargetPath } from './rust-build.mjs';
import assert from 'node:assert/strict';
import { readFile, writeFile, mkdir, rm } from 'node:fs/promises';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';

requireRustWorkflow();

const project = resolve(import.meta.dirname, '..');
process.chdir(project);
const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-quests-${Date.now()}`;
const proofRoot = resolve('.local/quest-proof-module');
assert.equal(resolve(proofRoot, '..'), resolve(project, '.local'));
assert(/^fishbound-proof-quests-\d+$/.test(database));
const connections: DbConnection[] = [];
const catalog = JSON.parse(await readFile('content/quests.json', 'utf8'));
const awardIds: number[] = catalog.map((q: any) => q.achievement_id).filter(Boolean);
const keepPreview = process.argv.includes('--browser');
function run(command: string, args: string[]) {
  const r = spawnSync(command, args, { encoding: 'utf8', windowsHide: true });
  if (r.status !== 0) throw Error(r.stderr || r.stdout || String(r.error));
  return r.stdout;
}
const call = (name: string, args: string[] = []) => run('spacetime', ['call', '--server', uri, '--no-config', '--yes', database, name, ...args]);
const publish = (wasm: string) => run('spacetime', ['publish', '--server', uri, '--no-config', '--yes=skip-login,migrate,break-clients', '--delete-data=never', '--bin-path', wasm, database]);
function decode(type: any, value: any): any {
  if (type.Product) return Object.fromEntries(type.Product.elements.map((e: any, i: number) => [e.name.some, decode(e.algebraic_type, value[i])]));
  if (type.Sum) { const variant = type.Sum.variants[value[0]]; return variant.name.some === 'none' ? null : decode(variant.algebraic_type, value[1]); }
  return value;
}
const query = (table: string) => JSON.parse(run('spacetime', ['sql', '--server', uri, '--no-config', '--format', 'json', database, `SELECT * FROM ${table}`]), (_k: string, v: any, ctx?: { source: string }) => typeof v === 'number' && !Number.isSafeInteger(v) ? ctx!.source : v).flatMap((b: any) => b.rows.map((r: any[]) => Object.fromEntries(r.map((v, i) => [b.schema.elements[i].name.some, decode(b.schema.elements[i].algebraic_type, v)]))));
const canonical = (rows: any[]) => rows.map(r => JSON.stringify(r)).sort();
async function connect(queries: string[]) {
  const connected = await new Promise<{ c: DbConnection; token: string }>((yes, no) => {
    const c = DbConnection.builder().withUri(uri).withDatabaseName(database).onConnect((c, _id, token) => yes({ c, token })).onConnectError((_c, e) => no(e)).build();
    connections.push(c);
  });
  await new Promise<void>((yes, no) => connected.c.subscriptionBuilder().onApplied(() => yes()).onError(ctx => no(ctx.event)).subscribe(queries.map(t => `SELECT * FROM ${t}`)));
  return connected;
}
async function until(check: () => boolean) {
  const end = Date.now() + 12000;
  while (!check()) { assert(Date.now() < end, 'subscription update timed out'); await new Promise(r => setTimeout(r, 20)); }
}
let serial = 1n;
const snowflake = () => ((BigInt(Date.now()) - 1420070400000n) << 22n) | serial++;
let previewReady = false;
try {
  // Use the actual previous deployed artifact; never copy or read the live data directory.
  publish('.runtime/module/fishing-game-module.wasm');
  const old = await connect(['adapter_player']);
  call('configure_service', [JSON.stringify(old.c.identity!.toHexString()), JSON.stringify({ discordAdapter: {} }), 'true']);
  const oldArgs = { discordUserId: 9301n, interactionId: snowflake(), displayName: 'Quest migration proof' };
  await old.c.reducers.selectDiscordPlayer(oldArgs);
  await old.c.reducers.fishFromDiscord({ discordUserId: 9301n, interactionId: oldArgs.interactionId, guildId: undefined, channelId: 42n });
  old.c.disconnect();
  const newTables = ['quest_definition', 'quest_claim', 'weekly_quest_progress', 'quest_calendar', 'repeat_quest_progress'];
  const tables = [...(await readFile('spacetimedb/src/tables.rs', 'utf8')).matchAll(/accessor = (\w+)/g)].map(m => m[1]).filter(t => !newTables.includes(t));
  const before = Object.fromEntries(tables.map(t => [t, canonical(query(t))]));
  publish(moduleArtifactPath());
  for (const table of tables) assert.deepEqual(canonical(query(table)), before[table], `${table} preserved`);
  assert.equal(query('quest_definition').length, 0);
  call('activate_quests');
  const activated = canonical(query('quest_definition'));
  call('activate_quests');
  assert.deepEqual(canonical(query('quest_definition')), activated);
  for (const table of tables.filter(t => t !== 'achievement_definition')) assert.deepEqual(canonical(query(table)), before[table], `${table} preserved by activation`);
  assert.equal(query('achievement_definition').filter((a: any) => awardIds.includes(a.achievement_id)).length, awardIds.length);
  assert.deepEqual(canonical(query('achievement_definition').filter((a: any) => !awardIds.includes(a.achievement_id))), before.achievement_definition, 'All existing achievement definitions preserved');
  console.log(`PASS actual old module -> new module: ${tables.length} retained tables unchanged; additive, idempotent quest activation.`);

  // Compile proof-only setup reducers alongside the real implementation.
  await mkdir(proofRoot, { recursive: true });
  const rustPath = (path: string) => JSON.stringify(resolve(path).replaceAll('\\', '/'));
  await writeFile(`${proofRoot}/Cargo.toml`, (await readFile('spacetimedb/Cargo.toml', 'utf8')).replace('name = "fishing-game-module"', 'name = "fishing-quest-proof"').replace('path = "../crates/game-rules"', `path = ${rustPath('crates/game-rules')}`).replace('crate-type = ["cdylib"]', 'crate-type = ["cdylib"]\npath = "lib.rs"') + '\n[workspace]\n');
  await writeFile(`${proofRoot}/Cargo.lock`, (await readFile('spacetimedb/Cargo.lock', 'utf8')).replace('name = "fishing-game-module"', 'name = "fishing-quest-proof"'));
  await writeFile(`${proofRoot}/lib.rs`, (await readFile('spacetimedb/src/lib.rs', 'utf8')).replace(/^mod (\w+);$/gm, (_m, name) => `#[path = ${rustPath(`spacetimedb/src/${name}.rs`)}]\nmod ${name};`) + `\ninclude!(${rustPath('scripts/fixtures/crafting-boundary.rs')});\ninclude!(${rustPath('scripts/fixtures/quests.rs')});\n`);
  run('cargo', ['build', '--manifest-path', `${proofRoot}/Cargo.toml`, '--target-dir', rustTargetPath(), '--target', 'wasm32-unknown-unknown', '--release', '--locked']);
  publish(rustTargetPath('wasm32-unknown-unknown', 'release', 'fishing_quest_proof.wasm'));
  const { c: bot } = await connect(['adapter_player', 'adapter_quests', 'quest_definition', 'adapter_inventory', 'adapter_baits', 'adapter_items']);
  call('configure_service', [JSON.stringify(bot.identity!.toHexString()), JSON.stringify({ discordAdapter: {} }), 'true']);
  const { c: alice } = await connect(['my_quests', 'my_dockside', 'my_inventory', 'my_player', 'my_baits', 'my_achievement_progress', 'earned_achievement']);
  const { c: bob } = await connect(['my_quests', 'my_inventory']);
  const { c: guest } = await connect(['my_quests', 'adapter_quests']);
  await assert.rejects(guest.reducers.activateQuests({}), /OWNER_REQUIRED/);
  await assert.rejects(guest.reducers.claimQuest({ questId: 101, baitId: 1, catchId: undefined, expectedWeek: 0n, expectedCycle: 0n }), /ACCOUNT_NOT_LINKED/);
  await assert.rejects(new Promise<void>((yes, no) => guest.subscriptionBuilder().onApplied(() => yes()).onError(ctx => no(ctx.event)).subscribe(['SELECT * FROM quest_claim'])));
  assert.equal(guest.db.adapterQuests.count(), 0n);
  for (const discord of [9301, 9302]) call('proof_quest_history', [String(discord)]);
  const players = query('player');
  const aliceId = players.find((p: any) => p.discord_user_id === 9301).player_id;
  const bobId = players.find((p: any) => p.discord_user_id === 9302).player_id;
  call('proof_quest_link', [JSON.stringify(alice.identity!.toHexString()), String(aliceId)]);
  call('proof_quest_link', [JSON.stringify(bob.identity!.toHexString()), String(bobId)]);
  await until(() => alice.db.myQuests.count() === BigInt(catalog.length));
  assert.equal(guest.db.myQuests.count(), 0n);
  const row = (id: number) => [...alice.db.myQuests.iter()].find(q => q.questId === id)!;
  assert.equal(row(101).current, 3n);
  assert.equal(row(104).current, 1n);
  assert.equal(row(201).current, 5n);
  assert.equal(row(204).current, 1n);
  assert.equal(row(105).unlocked, false);
  const claim = (id: number, baitId = 0, catchId?: bigint, expectedWeek = row(id).week, expectedCycle = row(id).cycle) => alice.reducers.claimQuest({ questId: id, baitId, catchId, expectedWeek, expectedCycle });
  await assert.rejects(claim(105, 1), /QUEST_LOCKED/);
  await claim(101, 1);
  const coins = query('player').find((p: any) => p.player_id === aliceId).coins;
  await claim(101, 1);
  assert.equal(query('player').find((p: any) => p.player_id === aliceId).coins, coins);
  await assert.rejects(claim(102, 13), /QUEST_REWARD_CHOICE_REQUIRED/);
  await claim(102, 6); await claim(103); await claim(104);
  await until(() => row(105).unlocked);
  const pond = query('owned_specimen').find((f: any) => f.player_id === aliceId && row(105).deliverySpeciesIds.includes(f.species_id) && ['F', 'D', 'C'].includes(f.rarity));
  const river = query('owned_specimen').find((f: any) => f.player_id === aliceId && f.biome_id === 2);
  const others = query('owned_specimen').find((f: any) => f.player_id === bobId);
  await assert.rejects(claim(105, 1, BigInt(others.catch_id)), /CATCH_NOT_OWNED/);
  await assert.rejects(claim(105, 1, BigInt(river.catch_id)), /QUEST_WRONG_FISH/);
  await alice.reducers.setCatchFavorite({ catchId: BigInt(pond.catch_id), favorite: true });
  await assert.rejects(claim(105, 1, BigInt(pond.catch_id)), /FAVORITE_PROTECTED/);
  await alice.reducers.setCatchFavorite({ catchId: BigInt(pond.catch_id), favorite: false });
  await assert.rejects(claim(105, 13, BigInt(pond.catch_id)), /QUEST_REWARD_CHOICE_REQUIRED/);
  assert(query('owned_specimen').some((f: any) => f.catch_id === pond.catch_id), 'failed reward choice rolls delivery back');
  const progress = canonical(query('player_species_progress'));
  const records = canonical(query('species_record'));
  await claim(105, 1, BigInt(pond.catch_id)); await claim(105, 1, BigInt(pond.catch_id));
  assert(!query('owned_specimen').some((f: any) => f.catch_id === pond.catch_id));
  assert.equal(query('earned_achievement').filter((a: any) => a.player_id === aliceId && a.achievement_id === 9001).length, 1);
  await claim(201); await claim(202, 4); await claim(203, 0, BigInt(river.catch_id)); await claim(204, 5);
  assert.deepEqual(canonical(query('player_species_progress')), progress);
  assert.deepEqual(canonical(query('species_record')), records);
  assert.equal(query('quest_claim').filter((q: any) => q.player_id === aliceId).length, 9);
  assert.equal(query('player').find((p: any) => p.player_id === aliceId).coins, coins + 900, 'Both permanent chains award exactly 1000 gold');
  assert.equal(query('bait_stack').filter((b: any) => b.player_id === aliceId).reduce((n: number,b: any) => n+b.uses_left,0), 70, 'Seven whole ten-use bait packs awarded');
  const materialRewards = query('economy_ledger').filter((r: any) => r.player_id === aliceId && r.operation_id.startsWith('quest:') && ['rusted_tin','scrap'].includes(r.item));
  assert(materialRewards.length > 0 && materialRewards.every((r: any) => r.reason === 'quest_reward'), 'Quest materials are not misreported as catches');
  console.log('PASS nine story steps, historical credit, reward choices, replay safety, atomic deliveries, favorites, ownership and retained collection/records.');

  // New stories branch independently after the intro, preserving all original claims.
  const newStories = catalog.filter((q: any) => q.quest_id >= 400 && q.quest_id < 1100);
  const branchStarts = newStories.filter((q: any) => q.previous_quest_id === 105);
  assert.equal(branchStarts.length, 7);
  await until(() => branchStarts.every((q: any) => row(q.quest_id).unlocked));
  const storyCoins = query('player').find((p: any) => p.player_id === aliceId).coins;
  for (const q of newStories) {
    if (q.goal === 'rank_maxima') {
      assert.equal(row(q.quest_id).current, 0n);
      await assert.rejects(claim(q.quest_id), /QUEST_NOT_READY/);
      call('proof_quest_maximum', [String(aliceId)]);
      await until(() => row(q.quest_id).current === 1n);
    }
    const fish = q.goal === 'delivery' ? query('owned_specimen').find((f: any) => f.player_id === aliceId && f.biome_id === q.biome_id) : undefined;
    await claim(q.quest_id, q.bait_choices[0] ?? 0, fish ? BigInt(fish.catch_id) : undefined);
  }
  assert.equal(query('player').find((p: any) => p.player_id === aliceId).coins, storyCoins + newStories.reduce((n: number,q: any) => n+q.coins,0));
  assert.equal(query('earned_achievement').filter((a: any) => a.player_id === aliceId && awardIds.includes(a.achievement_id)).length, 9);
  const preservedQuestState = Object.fromEntries(['quest_claim','repeat_quest_progress','player','owned_specimen','earned_achievement'].map(t=>[t,canonical(query(t))]));
  call('activate_quests');
  for (const [t, rows] of Object.entries(preservedQuestState)) assert.deepEqual(canonical(query(t)), rows, `${t} preserved on reactivation`);
  console.log('PASS all seven additional stories, independent branches, historical ranks/quality/maxima, exact rewards, nine badges and retained claims on activation.');

  // Every job starts at zero despite historical catches. Progress saturates, never banks runs.
  assert.equal(row(1101).current,0n);
  const ordinary = query('species_definition').filter((s: any)=>s.counts_for_ordinary_collection_completion).slice(0,5);
  const pull = (outcome: string, id: number, times=1) => call('proof_quest_pull',[String(aliceId),JSON.stringify(outcome),String(id),String(times)]);
  pull('fish',ordinary[0].species_id,30);
  await until(()=>row(1101).current===10n && row(1103).current===1n);
  const runCoins=query('player').find((p: any)=>p.player_id===aliceId).coins;
  await assert.rejects(claim(1101,13),/QUEST_REWARD_CHOICE_REQUIRED/);
  assert.equal(row(1101).current,10n);
  await Promise.allSettled([claim(1101,0,undefined,row(1101).week,0n),claim(1101,0,undefined,row(1101).week,0n)]);
  await until(()=>row(1101).cycle===1n && row(1101).current===0n);
  assert.equal(query('player').find((p: any)=>p.player_id===aliceId).coins,runCoins+100,'Concurrent claims pay once');
  await assert.rejects(claim(1101,0,undefined,row(1101).week,0n),/QUEST_RUN_CHANGED/);
  await assert.rejects(claim(1101),/QUEST_NOT_READY/);
  assert.equal(row(1103).current,1n,'Claiming one job preserves another');
  for(let run=1;run<4;run++) {
    pull('fish',ordinary[0].species_id,10);
    await until(()=>row(1101).current===10n);
    await claim(1101);
    await until(()=>row(1101).cycle===BigInt(run+1));
  }
  assert.equal(query('player').find((p: any)=>p.player_id===aliceId).coins,runCoins+400,'Four runs pay independently without a time gate');
  for(const species of ordinary.slice(1)) pull('fish',species.species_id);
  await until(()=>row(1103).current===5n);
  await assert.rejects(claim(1103,13),/QUEST_REWARD_CHOICE_REQUIRED/);
  await claim(1103,6);
  await until(()=>row(1103).current===0n && row(1103).cycle===1n);
  pull('fish',ordinary[0].species_id,2);
  await until(()=>row(1103).current===1n);
  pull('junk',ordinary[0].species_id,5);
  await until(()=>row(1102).current===5n);
  const materialsBefore=canonical(query('item_stack').filter((i: any)=>i.player_id===aliceId));
  await claim(1102);
  await until(()=>row(1102).current===0n && row(1102).cycle===1n);
  assert.notDeepEqual(canonical(query('item_stack').filter((i: any)=>i.player_id===aliceId)),materialsBefore);
  assert.equal(query('repeat_quest_progress').filter((r: any)=>r.player_id===aliceId).length,3,'Run storage stays bounded');
  assert.equal(bob.db.myQuests.count(),BigInt(catalog.length));
  assert([...bob.db.myQuests.iter()].filter(q=>q.questId>=1100).every(q=>q.current===0n && q.cycle===0n),'Other player runs are private and independent');
  await assert.rejects(new Promise<void>((yes,no)=>guest.subscriptionBuilder().onApplied(()=>yes()).onError(ctx=>no(ctx.event)).subscribe(['SELECT * FROM repeat_quest_progress'])));
  console.log('PASS unlimited repeatable runs, fresh progress, capped counters, distinct-species reset, independent jobs, atomic invalid choices, concurrent claims, stale previews and bounded private state.');

  call('proof_quest_week', [String(aliceId), 'false']);
  await until(() => row(301).current === 15n);
  await assert.rejects(claim(301, 0, undefined, row(301).week - 1n), /QUEST_WEEK_CHANGED/);
  await claim(301); const afterWeekly = query('player').find((p: any) => p.player_id === aliceId).coins;
  await claim(301); assert.equal(query('player').find((p: any) => p.player_id === aliceId).coins, afterWeekly);
  await assert.rejects(claim(302), /QUEST_WEEK_ALREADY_CLAIMED/);
  call('proof_quest_week', [String(aliceId), 'true']);
  await until(() => !row(301).claimed && row(301).current === 0n);
  call('proof_cast_setup', ['9301', '1', '0']);
  const args = { discordUserId: 9301n, interactionId: snowflake() };
  await bot.reducers.selectDiscordPlayer({ ...args, displayName: 'Quest proof' });
  await bot.reducers.fishFromDiscord({ ...args, guildId: undefined, channelId: 42n });
  await until(() => row(301).current > 0n);
  const weekly = query('weekly_quest_progress').find((r: any) => r.player_id === aliceId);
  assert.equal(weekly.claimed_quest_id, 0); assert(weekly.fish_count >= 1 && weekly.fish_count <= 2);
  assert.equal(query('weekly_quest_progress').filter((r: any) => r.player_id === aliceId).length, 1);
  assert.equal([...alice.db.myAchievementProgress.iter()].find(a => a.achievementId === 9001)?.current, 1n);
  const dailyBefore = query('player').find((p: any) => p.player_id === aliceId).coins;
  await alice.reducers.claimDocksideDelivery({});
  await bot.reducers.dailyFromDiscord({ ...args, interactionId: snowflake(), guildId: undefined, channelId: 42n });
  assert.equal(query('player').find((p: any) => p.player_id === aliceId).coins, dailyBefore + 100);
  assert.equal(query('daily_delivery').find((p: any) => p.player_id === aliceId).total_claims, 1);
  console.log('PASS automatic weekly catch tracking, previous-week reset, one weekly reward, stale previews, preserved quest awards and shared web/Discord daily eligibility.');

  if (keepPreview) {
    call('proof_quest_history', ['9303']);
    const id = query('player').find((p: any) => p.discord_user_id === 9303).player_id;
    const firstSpecies=query('species_definition').find((s: any)=>s.counts_for_ordinary_collection_completion).species_id;
    call('proof_quest_pull',[String(id),JSON.stringify('fish'),String(firstSpecies),'10']);
    const browser = await connect(['my_quests']);
    call('proof_quest_link', [JSON.stringify(browser.c.identity!.toHexString()), String(id)]);
    await writeFile('.local/quest-preview.json', JSON.stringify({ uri, database, token: browser.token }));
    previewReady = true;
    console.log('PASS isolated browser fixture prepared; live database untouched.');
  }
} finally {
  for (const c of connections) c.disconnect();
  await rm(proofRoot, { recursive: true, force: true });
  if (!previewReady) run('spacetime', ['delete', '--server', uri, '--no-config', '--yes', database]);
}
