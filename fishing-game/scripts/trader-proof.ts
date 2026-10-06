import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { spawnSync, spawn } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';

const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-trader-${Date.now()}`;
const fixtureRoot = resolve('.local/trader-boundary-module');
const targetRoot = resolve('spacetimedb/target');
const rustPath = (path: string) => JSON.stringify(resolve(path).replaceAll('\\', '/'));
function run(command: string, args: string[]) {
  const result = spawnSync(command, args, { encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw new Error(result.stderr || result.stdout);
  return result.stdout;
}
await mkdir(fixtureRoot, { recursive: true });
const manifest = (await readFile('spacetimedb/Cargo.toml', 'utf8'))
  .replace('name = "fishing-game-module"', 'name = "fishing-trader-proof"')
  .replace('path = "../crates/game-rules"', `path = ${rustPath('crates/game-rules')}`)
  .replace('crate-type = ["cdylib"]', 'crate-type = ["cdylib"]\npath = "lib.rs"');
await writeFile(fixtureRoot + '/Cargo.toml', manifest + '\n[workspace]\n');
await writeFile(fixtureRoot + '/Cargo.lock', (await readFile('spacetimedb/Cargo.lock', 'utf8')).replace('name = "fishing-game-module"', 'name = "fishing-trader-proof"'));
const source = (await readFile('spacetimedb/src/lib.rs', 'utf8')).replace(/^mod (\w+);$/gm, (_, name) => `#[path = ${rustPath(`spacetimedb/src/${name}.rs`)}]\nmod ${name};`);
await writeFile(fixtureRoot + '/lib.rs', source + `\ninclude!(${rustPath('scripts/fixtures/trader-boundary.rs')});\n`);
run('cargo', ['build', '--manifest-path', fixtureRoot + '/Cargo.toml', '--target-dir', targetRoot, '--target', 'wasm32-unknown-unknown', '--release', '--locked']);
run('spacetime', ['publish', '--server', uri, '--no-config', '--yes=skip-login', '--bin-path', targetRoot + '/wasm32-unknown-unknown/release/fishing_trader_proof.wasm', database]);
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
  const bot = await connect(['SELECT * FROM my_service', 'SELECT * FROM adapter_player', 'SELECT * FROM adapter_rods', 'SELECT * FROM adapter_licences', 'SELECT * FROM adapter_shop_quote', 'SELECT * FROM adapter_cast_pulls', 'SELECT * FROM adapter_receipt', 'SELECT * FROM shop_listing', 'SELECT * FROM rod_bonuses', 'SELECT * FROM species_rank_definition', 'SELECT * FROM species_definition']);
  ownerCall('configure_service', [JSON.stringify(bot.identity!.toHexString()), JSON.stringify({ discordAdapter: {} }), 'true']);
  const select = async (user: bigint) => {
    const interactionId = snowflake();
    await bot.reducers.selectDiscordPlayer({ discordUserId: user, displayName: 'Trader proof', interactionId });
    return { discordUserId: user, interactionId };
  };
  const prepare = async (user: bigint, listingId: number) => {
    const args = await select(user);
    await bot.reducers.prepareShopFromDiscord({ ...args, listingId });
    return { args, quote: [...bot.db.adapterShopQuote.iter()][0] };
  };
  const purchase = async (user: bigint, listingId: number) => {
    await pause();
    const { args, quote } = await prepare(user, listingId);
    const before = [...bot.db.adapterPlayer.iter()][0];
    await bot.reducers.commitShopFromDiscord({ ...args, nonce: quote.nonce });
    assert.equal([...bot.db.adapterShopQuote.iter()][0].consumed, true);
    const after = [...bot.db.adapterPlayer.iter()][0];
    assert.equal(after.coins, before.coins - quote.quotedCoins);
    assert.equal(after.totalXp, before.totalXp);
    assert.equal(after.nextCastAt.microsSinceUnixEpoch, before.nextCastAt.microsSinceUnixEpoch);
    await bot.reducers.commitShopFromDiscord({ ...args, nonce: quote.nonce });
    assert.equal([...bot.db.adapterPlayer.iter()][0].coins, after.coins);
    return quote;
  };
  ownerCall('prepare_trader_player', ['9201', '0', '200000', '1']);
  await assert.rejects(prepare(9201n, 20), /SHOP_LEVEL_REQUIRED/);
  ownerCall('prepare_trader_player', ['9202', '10000000', '0', '1']);
  await assert.rejects(prepare(9202n, 20), /INSUFFICIENT_COINS/);
  ownerCall('prepare_trader_player', ['9203', '10000000', '200000', '1']);
  let args = await select(9203n);
  await assert.rejects(bot.reducers.changeLoadoutFromDiscord({ ...args, rodId: 2 }), /ROD_NOT_OWNED/);
  await assert.rejects(prepare(9203n, 70), /PREVIOUS_LICENCE_REQUIRED/);
  await purchase(9203n, 21);
  args = await select(9203n);
  await assert.rejects(bot.reducers.changeLoadoutFromDiscord({ ...args, rodId: 2, biomeId: 2 }), /BIOME_LICENCE_REQUIRED/);
  await purchase(9203n, 20);
  args = await select(9203n);
  await bot.reducers.changeLoadoutFromDiscord({ ...args, rodId: 2, biomeId: 2 });
  assert.equal([...bot.db.adapterPlayer.iter()][0].coins, 199600n);
  await assert.rejects(prepare(9203n, 20), /ALREADY_OWNED/);
  for (let biome = 3; biome <= 7; biome++) {
    await purchase(9203n, biome * 10);
    args = await select(9203n);
    await bot.reducers.changeLoadoutFromDiscord({ ...args, biomeId: biome });
    await purchase(9203n, biome * 10 + 1);
    args = await select(9203n);
    await bot.reducers.changeLoadoutFromDiscord({ ...args, biomeId: biome, rodId: biome });
    assert.equal([...bot.db.adapterPlayer.iter()][0].selectedBiomeId, biome);
  }
  assert.equal(bot.db.adapterLicences.count(), 6n);
  assert.equal(bot.db.adapterRods.count(), 7n);
  await bot.reducers.fishFromDiscord({ ...args, guildId: 5n, channelId: 42n });
  assert.equal([...bot.db.adapterReceipt.iter()][0].biomeId, 7);
  const castReceipt = [...bot.db.adapterReceipt.iter()][0];
  assert.equal(castReceipt.rodId, 7);
  assert.equal(castReceipt.rulesVersion, 6);
  const baseXp = [...bot.db.adapterCastPulls.iter()].reduce((sum,pull)=>sum+pull.baseXp,0n);
  assert.equal(castReceipt.xpGranted,baseXp*12500n/10000n,'Equipped rod XP applies once to all pulls including discovery');
  assert.equal(bot.db.rodBonuses.count(), 7n);
  const castPlayer = [...bot.db.adapterPlayer.iter()][0];
  await bot.reducers.changeLoadoutFromDiscord({ ...args, biomeId: 1, rodId: 1 });
  await bot.reducers.fishFromDiscord({ ...args, guildId: 5n, channelId: 42n });
  assert.equal([...bot.db.adapterReceipt.iter()][0].xpGranted, castReceipt.xpGranted, 'Replay after changing rod retains the original reward');
  assert.equal([...bot.db.adapterPlayer.iter()][0].totalXp, castPlayer.totalXp);
  assert.equal([...bot.db.adapterPlayer.iter()][0].nextCastAt.microsSinceUnixEpoch, castPlayer.nextCastAt.microsSinceUnixEpoch);
  await assert.rejects(bot.reducers.fishFromDiscord({ ...args, interactionId: snowflake(), guildId: 5n, channelId: 42n }), /COOLDOWN_ACTIVE/);
  console.log('PASS equipped-rod XP applies once, discovery is included, replay keeps original reward after a gear change, and cooldown is unchanged.');
  ownerCall('prepare_trader_player', ['9204', '10000000', '200000', '1']);
  const changed = await prepare(9204n, 20);
  ownerCall('change_proof_offer', ['20', '151']);
  await assert.rejects(bot.reducers.commitShopFromDiscord({ ...changed.args, nonce: changed.quote.nonce }), /QUOTE_CHANGED/);
  assert.equal([...bot.db.adapterPlayer.iter()][0].coins, 200000n);
  await pause();
  const expired = await prepare(9204n, 20);
  ownerCall('expire_proof_quote', [expired.quote.nonce.toString()]);
  await assert.rejects(bot.reducers.commitShopFromDiscord({ ...expired.args, nonce: expired.quote.nonce }), /ACTION_EXPIRED|ACTION_NOT_FOUND/);
  await pause();
  const different = await prepare(9204n, 21);
  args = await select(9203n);
  await assert.rejects(bot.reducers.commitShopFromDiscord({ ...args, nonce: different.quote.nonce }), /ACTION_CONFLICT/);
  ownerCall('prepare_trader_player', ['9205', '10000000', '200000', '1']);
  const browser = await connect(['SELECT * FROM my_link_challenge', 'SELECT * FROM my_player', 'SELECT * FROM my_rods', 'SELECT * FROM my_licences', 'SELECT * FROM my_shop_quote', 'SELECT * FROM my_ledger']);
  await browser.reducers.beginLinkChallenge({});
  const challenge = [...browser.db.myLinkChallenge.iter()][0];
  const linker = await connect(['SELECT * FROM my_service']);
  ownerCall('configure_service', [JSON.stringify(linker.identity!.toHexString()), JSON.stringify({ accountLinker: {} }), 'true']);
  await linker.reducers.completeAccountLink({ challengeId: challenge.challengeId, verifiedDiscordUserId: 9205n, proof: challenge.proof });
  await browser.reducers.prepareShopPurchase({ listingId: 20 });
  const browserQuote = [...browser.db.myShopQuote.iter()][0];
  await assert.rejects(browser.reducers.commitShopPurchase({ nonce: browserQuote.nonce ^ 1n }), /ACTION_CONFLICT/);
  const competing = await prepare(9205n, 20);
  const results = await Promise.allSettled([
    browser.reducers.commitShopPurchase({ nonce: browserQuote.nonce }),
    bot.reducers.commitShopFromDiscord({ ...competing.args, nonce: competing.quote.nonce })
  ]);
  assert.equal(results.filter(row => row.status === 'fulfilled').length, 1);
  assert.equal(results.filter(row => row.status === 'rejected' && /ALREADY_OWNED/.test(String(row.reason))).length, 1);
  assert.equal([...browser.db.myPlayer.iter()][0].coins, 199849n);
  assert.equal(browser.db.myLicences.count(), 1n);
  assert.equal([...browser.db.myLedger.iter()].filter(row => row.reason === 'trader_purchase' && row.item === 'coins').length, 1);
  assert.equal([...browser.db.myLedger.iter()].find(row => row.reason === 'trader_purchase' && row.item === 'coins')!.delta, -151n);
  await pause();
  await browser.reducers.prepareShopPurchase({ listingId: 21 });
  const rodQuote = [...browser.db.myShopQuote.iter()][0];
  await browser.reducers.commitShopPurchase({ nonce: rodQuote.nonce });
  await browser.reducers.commitShopPurchase({ nonce: rodQuote.nonce });
  await browser.reducers.changeLoadout({ biomeId: 2, rodId: 2 });
  assert.equal([...browser.db.myPlayer.iter()][0].selectedBiomeId, 2);
  assert.equal(browser.db.myRods.count(), 2n);
  const outsider = await connect(['SELECT * FROM my_shop_quote', 'SELECT * FROM my_rods', 'SELECT * FROM my_licences', 'SELECT * FROM adapter_shop_quote']);
  assert.equal(outsider.db.myShopQuote.count(), 0n);
  assert.equal(outsider.db.myRods.count(), 0n);
  assert.equal(outsider.db.adapterShopQuote.count(), 0n);
  await assert.rejects(outsider.reducers.commitShopPurchase({ nonce: rodQuote.nonce }), /ACCOUNT_NOT_LINKED/);
  ownerCall('prepare_trader_player', ['9206', '10000000', '1234', '4']);
  ownerCall('activate_trader', ['true']);
  await select(9206n);
  assert.equal(bot.db.adapterLicences.count(), 3n);
  assert.equal([...bot.db.adapterPlayer.iter()][0].coins, 1234n);
  ownerCall('prepare_trader_player', ['9207', '10000000', '1234', '6']);
  ownerCall('activate_trader', ['true']);
  await select(9207n);
  assert.equal(bot.db.adapterLicences.count(), 0n, 'Re-running activation cannot grant new free licences');
  ownerCall('prepare_trader_player', ['9208', '10000000', '200000', '1']);
  const native = await new Promise<{ status: number | null; stdout: string; stderr: string }>((resolve, reject) => {
    const child = spawn('target/debug/adapter-proof.exe', [], { windowsHide: true, env: {
      ...process.env, TEST_SPACETIMEDB_URI: uri, TEST_SPACETIMEDB_DATABASE: database, TEST_BOT_TOKEN: bot.token!, TEST_SHOP_PROOF: 'true'
    }});
    let stdout = ''; let stderr = '';
    child.stdout.on('data', data => stdout += data.toString());
    child.stderr.on('data', data => stderr += data.toString());
    child.on('error', reject);
    child.on('close', status => resolve({ status, stdout, stderr }));
  });
  assert.equal(native.status, 0, native.stderr || native.stdout);
  console.log('PASS native trader client: confirmed purchase and same-nonce recovery after reconnect.');
  await browser.reducers.unlinkBrowser({});
  assert.equal(browser.db.myRods.count(), 0n);
  assert.equal(browser.db.myLicences.count(), 0n);
  assert.equal(browser.db.myShopQuote.count(), 0n);
  console.log('PASS trader proof: all seven biomes require sequential licences and levels; optional purchased rods, sequential access, wallet/ledger correctness, replay, browser/Discord race, expiry, stale price, privacy, and one-time legacy migration.');
} finally { for (const connection of connections) connection.disconnect(); }
