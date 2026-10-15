import { requireRustWorkflow, rustTargetPath } from './rust-build.mjs';
import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';

requireRustWorkflow();

const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-trader-bulk-${Date.now()}`;
const fixtureRoot = resolve('.local/trader-bulk-module');
const targetRoot = rustTargetPath();
const previousWasm = process.argv[2];
function run(command: string, args: string[]) {
  const result = spawnSync(command, args, { encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw new Error(result.stderr || result.stdout);
  return result.stdout;
}
const rustPath = (path: string) => JSON.stringify(resolve(path).replaceAll('\\', '/'));
await mkdir(fixtureRoot, { recursive: true });
const manifest = (await readFile('spacetimedb/Cargo.toml', 'utf8'))
  .replace('name = "fishing-game-module"', 'name = "fishing-trader-bulk-proof"')
  .replace('path = "../crates/game-rules"', `path = ${rustPath('crates/game-rules')}`)
  .replace('crate-type = ["cdylib"]', 'crate-type = ["cdylib"]\npath = "lib.rs"');
await writeFile(fixtureRoot + '/Cargo.toml', manifest + '\n[workspace]\n');
await writeFile(fixtureRoot + '/Cargo.lock', (await readFile('spacetimedb/Cargo.lock', 'utf8')).replace('name = "fishing-game-module"', 'name = "fishing-trader-bulk-proof"'));
const source = (await readFile('spacetimedb/src/lib.rs', 'utf8')).replace(/^mod (\w+);$/gm, (_, name) => `#[path = ${rustPath(`spacetimedb/src/${name}.rs`)}]\nmod ${name};`);
await writeFile(fixtureRoot + '/lib.rs', source + `\ninclude!(${rustPath('scripts/fixtures/trader-bulk-boundary.rs')});\n`);
run('cargo', ['build', '--manifest-path', fixtureRoot + '/Cargo.toml', '--target-dir', targetRoot, '--target', 'wasm32-unknown-unknown', '--release', '--locked']);
const wasm = targetRoot + '/wasm32-unknown-unknown/release/fishing_trader_bulk_proof.wasm';
function publish(path: string) {
  assert(database.startsWith('fishbound-proof-'));
  run('spacetime', ['publish', '--server', uri, '--no-config', '--yes=skip-login,migrate,break-clients', '--delete-data=never', '--bin-path', path, database]);
}
publish(previousWasm ? resolve(previousWasm) : wasm);
const connections: DbConnection[] = [];
const ownerCall = (name: string, args: string[] = []) => run('spacetime', ['call', '--server', uri, '--no-config', '--yes', database, name, ...args]);
async function connect(queries: string[], token?: string) {
  const connection = await new Promise<DbConnection>((resolve, reject) => DbConnection.builder().withUri(uri).withDatabaseName(database).withToken(token).onConnect(connection => resolve(connection)).onConnectError((_, error) => reject(error)).build());
  connections.push(connection);
  await new Promise<void>((resolve, reject) => connection.subscriptionBuilder().onApplied(() => resolve()).onError(ctx => reject(ctx.event)).subscribe(queries.map(table => `SELECT * FROM ${table}`)));
  return connection;
}
const tables = [...(await readFile('spacetimedb/src/tables.rs', 'utf8')).matchAll(/accessor = (\w+)/g)].map(row => row[1]).filter(table => table !== 'maintenance_job');
function snapshot() {
  return Object.fromEntries(tables.map(table => {
    const raw = run('spacetime', ['sql', '--server', uri, '--no-config', '--format', 'json', database, `SELECT * FROM ${table}`]);
    const blocks = JSON.parse(raw, (...[_key, value, context]: any[]) => typeof value === 'number' && !Number.isSafeInteger(value) ? context.source : value);
    return [table, blocks.flatMap((block: { rows: unknown[] }) => block.rows.map(row => JSON.stringify(row))).sort()];
  }));
}
const pause = () => new Promise(resolve => setTimeout(resolve, 1050));
let sequence = 1n;
const snowflake = () => ((BigInt(Date.now()) - 1420070400000n) << 22n) | sequence++;
try {
  const user = 9410n;
  ownerCall('prepare_trader_player', [String(user), '10000000', '10000000', '1']);
  let browser = await connect(['my_player', 'my_baits', 'my_rods', 'my_licences', 'my_shop_quote', 'my_link_challenge', 'my_ledger', 'shop_listing', 'bait_definition']);
  await browser.reducers.beginLinkChallenge({});
  const challenge = [...browser.db.myLinkChallenge.iter()][0];
  const linker = await connect(['my_service']);
  ownerCall('configure_service', [JSON.stringify(linker.identity!.toHexString()), JSON.stringify({ accountLinker: {} }), 'true']);
  await linker.reducers.completeAccountLink({ challengeId: challenge.challengeId, verifiedDiscordUserId: user, proof: challenge.proof });
  await browser.reducers.prepareShopPurchase({ listingId: 101 });
  const legacy = [...browser.db.myShopQuote.iter()][0];
  const token = browser.token!;
  if (previousWasm) {
    const before = snapshot();
    for (const connection of connections) connection.disconnect();
    publish(wasm);
    assert.deepEqual(snapshot(), before, 'Every persisted row/field must survive the additive reducer publication');
    browser = await connect(['my_player', 'my_baits', 'my_rods', 'my_licences', 'my_shop_quote', 'my_ledger', 'shop_listing', 'bait_definition'], token);
    console.log(`PASS: ${tables.length} retained tables unchanged, including outstanding quote, wallet, identities and gear.`);
  }
  const wallet = () => [...browser.db.myPlayer.iter()][0].coins;
  const uses = (baitId: number) => [...browser.db.myBaits.iter()].find(row => row.baitId === baitId)?.usesLeft ?? 0n;
  await browser.reducers.commitShopPurchase({ nonce: legacy.nonce });
  assert.equal(uses(1), 10n);
  assert.equal(wallet(), 10000000n - legacy.quotedCoins);
  const oldWallet = wallet();
  await browser.reducers.commitShopPurchaseQuantity({ nonce: legacy.nonce, quantity: 10 });
  assert.equal(wallet(), oldWallet, 'Legacy consumed quote replay must not spend again');
  assert.equal(uses(1), 10n);
  console.log('PASS: legacy browser purchase and outstanding pre-update quote remain compatible.');
  const prepare = async (listingId: number) => {
    await pause(); await browser.reducers.prepareShopPurchase({ listingId });
    return [...browser.db.myShopQuote.iter()][0];
  };
  for (const offer of [...browser.db.shopListing.iter()].filter(row => row.kind === 'bait')) {
    for (const quantity of [1, 5, 10]) {
      const quote = await prepare(offer.listingId);
      const coins = wallet(), stock = uses(offer.targetId), rows = browser.db.myLedger.count();
      const bait = [...browser.db.baitDefinition.iter()].find(row => row.baitId === offer.targetId)!;
      await browser.reducers.commitShopPurchaseQuantity({ nonce: quote.nonce, quantity });
      assert.equal(wallet(), coins - offer.priceCoins * BigInt(quantity));
      assert.equal(uses(offer.targetId), stock + bait.usesPerPurchase * BigInt(quantity));
      assert.equal(browser.db.myLedger.count(), rows + 2n);
      const ledger = [...browser.db.myLedger.iter()].filter(row => row.operationId === `shop:${quote.nonce}`);
      assert.equal(ledger.find(row => row.item === 'coins')!.delta, -offer.priceCoins * BigInt(quantity));
      assert.equal(ledger.find(row => row.item === `bait_${offer.targetId}`)!.delta, BigInt(quantity));
      await browser.reducers.commitShopPurchaseQuantity({ nonce: quote.nonce, quantity });
      assert.equal(wallet(), coins - offer.priceCoins * BigInt(quantity));
      assert.equal(browser.db.myLedger.count(), rows + 2n);
    }
  }
  console.log('PASS: all 13 baits at 1/5/10 packs, exact costs/uses, one ledger pair, same-nonce replay.');
  const beforeFailure = () => JSON.stringify({ coins: String(wallet()), uses: String(uses(13)), ledger: String(browser.db.myLedger.count()) });
  ownerCall('prepare_trader_player', [String(user), '10000000', '1000', '1']);
  let quote = await prepare(113), state = beforeFailure();
  await assert.rejects(browser.reducers.commitShopPurchaseQuantity({ nonce: quote.nonce, quantity: 5 }), /INSUFFICIENT_COINS/);
  assert.equal(beforeFailure(), state);
  for (const quantity of [0, 2, 11]) await assert.rejects(browser.reducers.commitShopPurchaseQuantity({ nonce: quote.nonce, quantity }), /SHOP_QUANTITY_INVALID/);
  assert.equal(beforeFailure(), state);
  await assert.rejects(browser.reducers.commitShopPurchaseQuantity({ nonce: quote.nonce ^ 1n, quantity: 1 }), /ACTION_CONFLICT/);
  const outsider = await connect(['my_shop_quote', 'my_baits']);
  await assert.rejects(outsider.reducers.commitShopPurchaseQuantity({ nonce: quote.nonce, quantity: 1 }), /ACCOUNT_NOT_LINKED/);
  assert.equal(outsider.db.myShopQuote.count(), 0n); assert.equal(outsider.db.myBaits.count(), 0n);
  ownerCall('proof_bait_uses', [String(user), '13', '18446744073709551615']);
  await pause();
  state = beforeFailure();
  await assert.rejects(browser.reducers.commitShopPurchaseQuantity({ nonce: quote.nonce, quantity: 1 }), /ITEM_OVERFLOW/);
  assert.equal(beforeFailure(), state, 'Overflow must roll back the wallet and ledger too');
  ownerCall('proof_bait_uses', [String(user), '13', '0']);
  ownerCall('prepare_trader_player', [String(user), '10000000', '10000000', '1']);
  quote = await prepare(21); state = beforeFailure();
  await assert.rejects(browser.reducers.commitShopPurchaseQuantity({ nonce: quote.nonce, quantity: 5 }), /SHOP_QUANTITY_INVALID/);
  assert.equal(beforeFailure(), state);
  await browser.reducers.commitShopPurchaseQuantity({ nonce: quote.nonce, quantity: 1 });
  assert([...browser.db.myRods.iter()].some(row => row.rodId === 2));
  quote = await prepare(20);
  await browser.reducers.commitShopPurchaseQuantity({ nonce: quote.nonce, quantity: 1 });
  assert([...browser.db.myLicences.iter()].some(row => row.biomeId === 2));
  quote = await prepare(113); state = beforeFailure();
  ownerCall('expire_proof_quote', [String(quote.nonce)]);
  await assert.rejects(browser.reducers.commitShopPurchaseQuantity({ nonce: quote.nonce, quantity: 1 }), /ACTION_EXPIRED|ACTION_NOT_FOUND/);
  assert.equal(beforeFailure(), state);
  quote = await prepare(113); state = beforeFailure();
  ownerCall('change_proof_offer', ['113', '1001']);
  await assert.rejects(browser.reducers.commitShopPurchaseQuantity({ nonce: quote.nonce, quantity: 5 }), /QUOTE_CHANGED/);
  assert.equal(beforeFailure(), state);
  console.log('PASS: insufficient funds, invalid quantity, nonce/privacy, overflow rollback, rods/licences, stale/expired quotes.');
  ownerCall('change_proof_offer', ['113', '1000']);
  quote = await prepare(113); const coins = wallet();
  await Promise.all([
    browser.reducers.commitShopPurchaseQuantity({ nonce: quote.nonce, quantity: 10 }),
    browser.reducers.commitShopPurchaseQuantity({ nonce: quote.nonce, quantity: 10 })
  ]);
  assert.equal(wallet(), coins - 10000n); assert.equal(uses(13), 100n);
  browser.disconnect();
  browser = await connect(['my_player', 'my_baits', 'my_rods', 'my_licences', 'my_shop_quote', 'my_ledger', 'shop_listing', 'bait_definition'], token);
  await browser.reducers.commitShopPurchaseQuantity({ nonce: quote.nonce, quantity: 10 });
  assert.equal(wallet(), coins - 10000n); assert.equal(uses(13), 100n);
  const bot = await connect(['my_service', 'adapter_shop_quote', 'adapter_baits', 'adapter_player']);
  ownerCall('configure_service', [JSON.stringify(bot.identity!.toHexString()), JSON.stringify({ discordAdapter: {} }), 'true']);
  const args = { discordUserId: user, interactionId: snowflake() };
  await bot.reducers.selectDiscordPlayer({ ...args, displayName: 'Bulk trader proof' });
  await bot.reducers.prepareShopFromDiscord({ ...args, listingId: 113 });
  const discordQuote = [...bot.db.adapterShopQuote.iter()][0];
  await bot.reducers.commitShopFromDiscord({ ...args, nonce: discordQuote.nonce });
  assert.equal([...bot.db.adapterPlayer.iter()][0].coins, coins - 11000n);
  assert.equal([...bot.db.adapterBaits.iter()].find(row => row.baitId === 13)!.usesLeft, 110n);
  await pause(); // The other identity's subscribed view refreshes separately.
  assert.equal(wallet(), coins - 11000n); assert.equal(uses(13), 110n);
  console.log(`PASS: double commit/reconnect charge once, existing Discord single-pack command retained. Isolated database: ${database}`);
} finally { for (const connection of connections) connection.disconnect(); }
