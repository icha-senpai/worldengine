import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';

const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-daily-${Date.now()}`;
const fixtureRoot = resolve('.local/daily-boundary-module');
const targetRoot = resolve('spacetimedb/target');
const rustPath = (path: string) => JSON.stringify(resolve(path).replaceAll('\\', '/'));
function run(command: string, args: string[]) {
  const result = spawnSync(command, args, { encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw new Error(result.stderr || result.stdout);
  return result.stdout;
}
await mkdir(fixtureRoot, { recursive: true });
const manifest = (await readFile('spacetimedb/Cargo.toml', 'utf8'))
  .replace('name = "fishing-game-module"', 'name = "fishing-daily-proof"')
  .replace('path = "../crates/game-rules"', `path = ${rustPath('crates/game-rules')}`)
  .replace('crate-type = ["cdylib"]', 'crate-type = ["cdylib"]\npath = "lib.rs"');
await writeFile(fixtureRoot + '/Cargo.toml', manifest + '\n[workspace]\n');
await writeFile(fixtureRoot + '/Cargo.lock', (await readFile('spacetimedb/Cargo.lock', 'utf8')).replace('name = "fishing-game-module"', 'name = "fishing-daily-proof"'));
const source = (await readFile('spacetimedb/src/lib.rs', 'utf8')).replace(/^mod (\w+);$/gm, (_, name) => `#[path = ${rustPath(`spacetimedb/src/${name}.rs`)}]\nmod ${name};`);
await writeFile(fixtureRoot + '/lib.rs', source + `\ninclude!(${rustPath('scripts/fixtures/daily-boundary.rs')});\n`);
run('cargo', ['build', '--manifest-path', fixtureRoot + '/Cargo.toml', '--target-dir', targetRoot, '--target', 'wasm32-unknown-unknown', '--release', '--locked']);
run('spacetime', ['publish', '--server', uri, '--no-config', '--yes=skip-login', '--bin-path', targetRoot + '/wasm32-unknown-unknown/release/fishing_daily_proof.wasm', database]);
console.log('Built and published an isolated fixture module. Production WASM was not modified.');
let connection: DbConnection | undefined;
let sequence = 1n;
const snowflake = () => ((BigInt(Date.now()) - 1420070400000n) << 22n) | sequence++;
const ownerCall = (name: string, args: string[] = []) => run('spacetime', ['call', '--server', uri, '--no-config', '--yes', database, name, ...args]);
try {
  const identity = await new Promise<string>((resolve, reject) => {
    connection = DbConnection.builder().withUri(uri).withDatabaseName(database)
      .onConnect((_, identity) => resolve(identity.toHexString())).onConnectError((_, error) => reject(error)).build();
  });
  ownerCall('configure_service', [JSON.stringify(identity), JSON.stringify({ discordAdapter: {} }), 'true']);
  await new Promise<void>((resolve, reject) => connection!.subscriptionBuilder().onApplied(() => resolve()).onError(ctx => reject(ctx.event)).subscribe(['SELECT * FROM adapter_player', 'SELECT * FROM adapter_daily_receipt']));
  const select = async (discordUserId: bigint) => {
    const args = { discordUserId, interactionId: snowflake(), guildId: 5n, channelId: 42n };
    await connection!.reducers.selectDiscordPlayer({ discordUserId, displayName: 'Delivery proof', interactionId: args.interactionId });
    return args;
  };
  const ledger = () => {
    const output = run('spacetime', ['sql', '--server', uri, '--no-config', database, 'SELECT * FROM economy_ledger']);
    return (output.match(/dockside_delivery/g) ?? []).length;
  };
  ownerCall('prepare_delivery', ['9101', '6', '15', '600']);
  const seventh = await select(9101n);
  await connection!.reducers.dailyFromDiscord(seventh);
  let receipt = [...connection!.db.adapterDailyReceipt.iter()][0];
  assert.equal(receipt.coinsGranted, 350n);
  assert.equal(receipt.totalClaims, 7n);
  assert.equal(receipt.stamps, 7);
  assert.equal([...connection!.db.adapterPlayer.iter()][0].coins, 950n);
  assert.equal(ledger(), 1);
  await connection!.reducers.dailyFromDiscord(seventh);
  assert.equal(ledger(), 1);
  const again = await select(9101n);
  await connection!.reducers.dailyFromDiscord(again);
  assert.equal([...connection!.db.adapterDailyReceipt.iter()][0].claimed, false);
  assert.equal(ledger(), 1);
  ownerCall('prepare_delivery', ['9101', '7', '1', '950']);
  const eighth = await select(9101n);
  await connection!.reducers.dailyFromDiscord(eighth);
  receipt = [...connection!.db.adapterDailyReceipt.iter()][0];
  assert.equal(receipt.totalClaims, 8n);
  assert.equal(receipt.stamps, 1);
  assert.equal(receipt.coinsGranted, 100n);
  assert.equal([...connection!.db.adapterPlayer.iter()][0].coins, 1050n);
  assert.equal(ledger(), 2);
  ownerCall('prepare_delivery', ['9102', '13', '3', '1550']);
  const fourteenth = await select(9102n);
  await connection!.reducers.dailyFromDiscord(fourteenth);
  assert.equal([...connection!.db.adapterDailyReceipt.iter()][0].coinsGranted, 350n);
  assert.equal([...connection!.db.adapterPlayer.iter()][0].coins, 1900n);
  ownerCall('prepare_delivery', ['9103', '6', '2', '18446744073709551615']);
  const overflow = await select(9103n);
  await assert.rejects(connection!.reducers.dailyFromDiscord(overflow), /COIN_OVERFLOW/);
  assert.equal(connection!.db.adapterDailyReceipt.count(), 0n);
  assert.equal([...connection!.db.adapterPlayer.iter()][0].coins, 18446744073709551615n);
  ownerCall('prepare_delivery', ['9103', '6', '2', '600']);
  await connection!.reducers.dailyFromDiscord(overflow);
  assert.equal([...connection!.db.adapterDailyReceipt.iter()][0].stamps, 7, 'Failed wallet grant cannot advance stamps');
  assert.equal([...connection!.db.adapterPlayer.iter()][0].coins, 950n);
  console.log('PASS isolated daily boundaries: missed-day stamp retention, seventh/fourteenth bonus, next-day eligibility, new card, one ledger row per grant, and atomic overflow rollback.');
} finally { connection?.disconnect(); }
