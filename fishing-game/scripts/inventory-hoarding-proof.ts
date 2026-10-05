import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';

const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-inventory-${Date.now()}`;
const fixtureRoot = resolve('.local/inventory-boundary-module');
const targetRoot = resolve('spacetimedb/target');
const rustPath = (path: string) => JSON.stringify(resolve(path).replaceAll('\\', '/'));
function run(command: string, args: string[]) {
  const result = spawnSync(command, args, { encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw new Error(result.stderr || result.stdout);
  return result.stdout;
}
await mkdir(fixtureRoot, { recursive: true });
const manifest = (await readFile('spacetimedb/Cargo.toml', 'utf8'))
  .replace('name = "fishing-game-module"', 'name = "fishing-inventory-proof"')
  .replace('path = "../crates/game-rules"', `path = ${rustPath('crates/game-rules')}`)
  .replace('crate-type = ["cdylib"]', 'crate-type = ["cdylib"]\npath = "lib.rs"');
await writeFile(fixtureRoot + '/Cargo.toml', manifest + '\n[workspace]\n');
await writeFile(fixtureRoot + '/Cargo.lock', (await readFile('spacetimedb/Cargo.lock', 'utf8')).replace('name = "fishing-game-module"', 'name = "fishing-inventory-proof"'));
const source = (await readFile('spacetimedb/src/lib.rs', 'utf8')).replace(/^mod (\w+);$/gm, (_, name) => `#[path = ${rustPath(`spacetimedb/src/${name}.rs`)}]\nmod ${name};`);
await writeFile(fixtureRoot + '/lib.rs', source + `\ninclude!(${rustPath('scripts/fixtures/inventory-boundary.rs')});\n`);
run('cargo', ['build', '--manifest-path', fixtureRoot + '/Cargo.toml', '--target-dir', targetRoot, '--target', 'wasm32-unknown-unknown', '--release', '--locked']);
run('spacetime', ['publish', '--server', uri, '--no-config', '--yes=skip-login', '--bin-path', targetRoot + '/wasm32-unknown-unknown/release/fishing_inventory_proof.wasm', database]);
console.log('Built and published an isolated fixture module. Production WASM was not modified.');
let connection: DbConnection | undefined;
const timeout = setTimeout(() => { connection?.disconnect(); process.exit(1); }, 60000);
let sequence = 1n;
const snowflake = () => ((BigInt(Date.now()) - 1420070400000n) << 22n) | sequence++;
const ownerCall = (name: string, args: string[] = []) => run('spacetime', ['call', '--server', uri, '--no-config', '--yes', database, name, ...args]);
try {
  const identity = await new Promise<string>((resolve, reject) => {
    connection = DbConnection.builder().withUri(uri).withDatabaseName(database)
      .onConnect((_, identity) => resolve(identity.toHexString())).onConnectError((_, error) => reject(error)).build();
  });
  ownerCall('configure_service', [JSON.stringify(identity), JSON.stringify({ discordAdapter: {} }), 'true']);
  await new Promise<void>((resolve, reject) => connection!.subscriptionBuilder().onApplied(() => resolve()).onError(ctx => reject(ctx.event)).subscribe(['SELECT * FROM adapter_player', 'SELECT * FROM adapter_inventory', 'SELECT * FROM adapter_receipt', 'SELECT * FROM game_config']));
  const discordUserId = 999001n;
  ownerCall('prepare_hoarder', [discordUserId.toString()]);
  await connection!.reducers.selectDiscordPlayer({ discordUserId, displayName: 'Hoarder proof', interactionId: snowflake() });
  assert.equal(connection!.db.adapterInventory.count(), 100n);
  assert.equal([...connection!.db.adapterPlayer.iter()][0].keptCount, 100);
  assert.equal([...connection!.db.gameConfig.iter()][0].inventoryCapacity, 0);
  for (let attempt = 0; attempt < 20 && connection!.db.adapterInventory.count() < 103n; attempt++) {
    ownerCall('prepare_hoarder', [discordUserId.toString()]);
    const interactionId = snowflake();
    await connection!.reducers.selectDiscordPlayer({ discordUserId, displayName: 'Hoarder proof', interactionId });
    const args = { discordUserId, interactionId, channelId: 42n, guildId: 5n };
    await connection!.reducers.fishFromDiscord(args);
    const before = [...connection!.db.adapterPlayer.iter()][0];
    assert.equal(before.keptCount, Number(connection!.db.adapterInventory.count()));
    const count = connection!.db.adapterInventory.count();
    await connection!.reducers.fishFromDiscord(args);
    assert.equal(connection!.db.adapterInventory.count(), count, 'Replay cannot add another specimen');
    await assert.rejects(connection!.reducers.fishFromDiscord({ ...args, interactionId: snowflake() }), /COOLDOWN_ACTIVE/);
  }
  assert(connection!.db.adapterInventory.count() >= 103n, 'Fish must accumulate beyond the old cap');
  assert.equal([...connection!.db.adapterPlayer.iter()][0].keptCount, Number(connection!.db.adapterInventory.count()));
  ownerCall('migrate_unlimited_inventory'); ownerCall('migrate_unlimited_inventory');
  assert.equal(connection!.db.adapterInventory.count(), 103n, 'Metadata migration must preserve all catches');
  console.log(`PASS ${database}: 100 fixture fish grew to 103 through real casts; cooldown, replay, exact kept counts, and repeatable migration remain correct.`);
} finally { clearTimeout(timeout); connection?.disconnect(); }
