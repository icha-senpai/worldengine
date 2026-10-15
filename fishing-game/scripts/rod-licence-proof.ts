import { requireRustWorkflow, rustTargetPath } from './rust-build.mjs';
import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile, rm } from 'node:fs/promises';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';

requireRustWorkflow();

const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-rod-licences-${Date.now()}`;
const fixtureRoot = resolve('.local/rod-licence-boundary-module');
const targetRoot = rustTargetPath();
const rustPath = (path: string) => JSON.stringify(resolve(path).replaceAll('\\', '/'));
function run(command: string, args: string[]) {
  const result = spawnSync(command, args, { encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw new Error(result.stderr || result.stdout);
  return result.stdout;
}
await mkdir(fixtureRoot, { recursive: true });
const manifest = (await readFile('spacetimedb/Cargo.toml', 'utf8'))
  .replace('name = "fishing-game-module"', 'name = "fishing-rod-licence-proof"')
  .replace('path = "../crates/game-rules"', `path = ${rustPath('crates/game-rules')}`)
  .replace('crate-type = ["cdylib"]', 'crate-type = ["cdylib"]\npath = "lib.rs"');
await writeFile(fixtureRoot + '/Cargo.toml', manifest + '\n[workspace]\n');
await writeFile(fixtureRoot + '/Cargo.lock', (await readFile('spacetimedb/Cargo.lock', 'utf8')).replace('name = "fishing-game-module"', 'name = "fishing-rod-licence-proof"'));
const source = (await readFile('spacetimedb/src/lib.rs', 'utf8')).replace(/^mod (\w+);$/gm, (_, name) => `#[path = ${rustPath(`spacetimedb/src/${name}.rs`)}]\nmod ${name};`);
await writeFile(fixtureRoot + '/lib.rs', source + `\ninclude!(${rustPath('scripts/fixtures/trader-boundary.rs')});\n`);
await writeFile(fixtureRoot + '/lib.rs', (await readFile(fixtureRoot + '/lib.rs', 'utf8')) + `
#[spacetimedb::reducer]
pub fn proof_previous_rod_licences(ctx: &ReducerContext) -> Result<(), String> {
    if ctx.db.deployment_owner().singleton().find(1).ok_or("OWNER_REQUIRED")?.identity != ctx.sender() { return Err("OWNER_REQUIRED".into()); }
    for mut row in ctx.db.shop_listing().iter() {
        row.catalog_version = 8;
        if row.listing_id == 61 { row.previous_biome_id = 5; }
        if row.listing_id == 71 { row.previous_biome_id = 6; }
        ctx.db.shop_listing().listing_id().update(row);
    }
    Ok(())
}
`);
run('cargo', ['build', '--manifest-path', fixtureRoot + '/Cargo.toml', '--target-dir', targetRoot, '--target', 'wasm32-unknown-unknown', '--release', '--locked']);
run('spacetime', ['publish', '--server', uri, '--no-config', '--yes=skip-login', '--bin-path', targetRoot + '/wasm32-unknown-unknown/release/fishing_rod_licence_proof.wasm', database]);
console.log('Built and published an isolated fixture module. Production WASM was not modified.');

const connections: DbConnection[] = [];
const call = (name: string, args: string[] = []) => run('spacetime', ['call', '--server', uri, '--no-config', '--yes', database, name, ...args]);
const tables = [...(await readFile('spacetimedb/src/tables.rs', 'utf8')).matchAll(/accessor = (\w+)/g)].map(match => match[1]).filter(table => table !== 'maintenance_job');
const snapshot = () => Object.fromEntries(tables.map(table => {
  const blocks = JSON.parse(run('spacetime', ['sql', '--server', uri, '--no-config', '--format', 'json', database, `SELECT * FROM ${table}`]), (_key: string, value: unknown, context?: { source: string }) => typeof value === 'number' && !Number.isSafeInteger(value) ? context!.source : value);
  return [table, blocks.flatMap((block: { rows: unknown[] }) => block.rows.map(row => JSON.stringify(row))).sort()];
}));
async function connect(queries: string[]) {
  const connection = await new Promise<DbConnection>((resolve, reject) => {
    DbConnection.builder().withUri(uri).withDatabaseName(database).onConnect(connection => resolve(connection)).onConnectError((_, error) => reject(error)).build();
  });
  connections.push(connection);
  await new Promise<void>((resolve, reject) => connection.subscriptionBuilder().onApplied(() => resolve()).onError(ctx => reject(ctx.event)).subscribe(queries));
  return connection;
}
let sequence = 1n;
const snowflake = () => ((BigInt(Date.now()) - 1420070400000n) << 22n) | sequence++;
try {
  call('prepare_trader_player', ['9291', '0', '204000', '1']);
  call('activate_trader', ['false']);
  call('proof_previous_rod_licences');
  const before = snapshot();
  run('spacetime', ['publish', '--server', uri, '--no-config', '--delete-data=never', '--yes=skip-login', '--bin-path', targetRoot + '/wasm32-unknown-unknown/release/fishing_rod_licence_proof.wasm', database]);
  assert.deepEqual(snapshot(), before, 'Publication retains every persisted row');
  call('activate_trader', ['false']);
  const activated = snapshot();
  for (const table of tables.filter(table => table !== 'shop_listing')) assert.deepEqual(activated[table], before[table], `${table} retained`);
  call('activate_trader', ['false']);
  assert.deepEqual(snapshot(), activated, 'Catalog activation is idempotent');
  console.log('PASS catalog upgrade preserves gameplay and activation is idempotent.');

  const bot = await connect(['my_service', 'adapter_player', 'adapter_rods', 'adapter_licences', 'adapter_shop_quote', 'shop_listing'].map(table => `SELECT * FROM ${table}`));
  call('configure_service', [JSON.stringify(bot.identity!.toHexString()), JSON.stringify({ discordAdapter: {} }), 'true']);
  const select = async () => {
    const args = { discordUserId: 9291n, interactionId: snowflake() };
    await bot.reducers.selectDiscordPlayer({ ...args, displayName: 'Rod licence proof' });
    return args;
  };
  const player = () => [...bot.db.adapterPlayer.iter()][0];
  for (const [listingId, rodId, price] of [[61, 6, 74000], [71, 7, 130000]]) {
    const args = await select();
    assert.equal(bot.db.adapterLicences.count(), 0n);
    call('prepare_trader_player', ['9291', '0', String(price - 1), '1']);
    await assert.rejects(bot.reducers.prepareShopFromDiscord({ ...args, listingId }), /INSUFFICIENT_COINS/);
    call('prepare_trader_player', ['9291', '0', String(price), '1']);
    await bot.reducers.prepareShopFromDiscord({ ...args, listingId });
    const quote = [...bot.db.adapterShopQuote.iter()][0];
    await bot.reducers.commitShopFromDiscord({ ...args, nonce: quote.nonce });
    assert.equal(player().coins, 0n);
    assert.equal(player().totalXp, 0n);
    assert([...bot.db.adapterRods.iter()].some(rod => rod.rodId === rodId));
    await bot.reducers.commitShopFromDiscord({ ...args, nonce: quote.nonce });
    assert.equal(player().coins, 0n, 'Replay spends once');
    await bot.reducers.changeLoadoutFromDiscord({ ...args, rodId });
    assert.equal(player().equippedRodId, rodId);
    await assert.rejects(bot.reducers.changeLoadoutFromDiscord({ ...args, biomeId: rodId }), /BIOME_LEVEL_REQUIRED/);
    await new Promise(resolve => setTimeout(resolve, 1050));
  }
  call('prepare_trader_player', ['9291', '10000000', '1000000', '1']);
  const args = await select();
  await assert.rejects(bot.reducers.prepareShopFromDiscord({ ...args, listingId: 60 }), /PREVIOUS_LICENCE_REQUIRED/);
  await assert.rejects(bot.reducers.prepareShopFromDiscord({ ...args, listingId: 70 }), /PREVIOUS_LICENCE_REQUIRED/);
  await assert.rejects(bot.reducers.prepareShopFromDiscord({ ...args, listingId: 51 }), /PREVIOUS_LICENCE_REQUIRED/);
  await assert.rejects(bot.reducers.changeLoadoutFromDiscord({ ...args, biomeId: 7 }), /BIOME_LICENCE_REQUIRED/);
  await assert.rejects(bot.reducers.activateTrader({ grandfatherLicences: false }), /OWNER_REQUIRED/);
  console.log('PASS Glacial and Abyssal buy/equip at level 1 with zero licences; exact prices, insufficient funds, replay, biome gates and other rod gates verified.');
} finally {
  for (const connection of connections) connection.disconnect();
  run('spacetime', ['delete', '--server', uri, '--no-config', '--yes', database]);
  assert(fixtureRoot === resolve('.local/rod-licence-boundary-module'));
  await rm(fixtureRoot, { recursive: true, force: true });
}

