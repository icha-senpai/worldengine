import { requireRustWorkflow, rustTargetPath } from './rust-build.mjs';
import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile, rm } from 'node:fs/promises';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';

requireRustWorkflow();

const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-level-cap-${Date.now()}`;
const fixtureRoot = resolve('.local/level-cap-boundary-module');
const targetRoot = rustTargetPath();
const rustPath = (path: string) => JSON.stringify(resolve(path).replaceAll('\\', '/'));
function run(command: string, args: string[]) {
  const result = spawnSync(command, args, { encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw new Error(result.stderr || result.stdout);
  return result.stdout;
}
await mkdir(fixtureRoot, { recursive: true });
const manifest = (await readFile('spacetimedb/Cargo.toml', 'utf8'))
  .replace('name = "fishing-game-module"', 'name = "fishing-level-cap-proof"')
  .replace('path = "../crates/game-rules"', `path = ${rustPath('crates/game-rules')}`)
  .replace('crate-type = ["cdylib"]', 'crate-type = ["cdylib"]\npath = "lib.rs"');
await writeFile(fixtureRoot + '/Cargo.toml', manifest + '\n[workspace]\n');
await writeFile(fixtureRoot + '/Cargo.lock', (await readFile('spacetimedb/Cargo.lock', 'utf8')).replace('name = "fishing-game-module"', 'name = "fishing-level-cap-proof"'));
const source = (await readFile('spacetimedb/src/lib.rs', 'utf8')).replace(/^mod (\w+);$/gm, (_, name) => `#[path = ${rustPath(`spacetimedb/src/${name}.rs`)}]\nmod ${name};`);
await writeFile(fixtureRoot + '/lib.rs', source + `\ninclude!(${rustPath('scripts/fixtures/crafting-boundary.rs')});\n`);
await writeFile(fixtureRoot + '/lib.rs', (await readFile(fixtureRoot + '/lib.rs', 'utf8')) + `\ninclude!(${rustPath('scripts/fixtures/level-cap.rs')});\n`);
run('cargo', ['build', '--manifest-path', fixtureRoot + '/Cargo.toml', '--target-dir', targetRoot, '--target', 'wasm32-unknown-unknown', '--release', '--locked']);
const wasm = targetRoot + '/wasm32-unknown-unknown/release/fishing_level_cap_proof.wasm';
run('spacetime', ['publish', '--server', uri, '--no-config', '--delete-data=never', '--yes=skip-login', '--bin-path', wasm, database]);

const connections: DbConnection[] = [];
const call = (name: string, args: string[] = []) => run('spacetime', ['call', '--server', uri, '--no-config', '--yes', database, name, ...args]);
const snapshotFile = resolve(`.local/${database}-before.json`);
const verify = (mode: string) => console.log(run(process.execPath, ['scripts/level-cap-preservation.mjs', mode, snapshotFile, database]).trim());
async function connect(queries: string[]) {
  const connection = await new Promise<DbConnection>((resolve, reject) => {
    DbConnection.builder().withUri(uri).withDatabaseName(database).onConnect(connection => resolve(connection)).onConnectError((_, error) => reject(error)).build();
  });
  connections.push(connection);
  await new Promise<void>((resolve, reject) => connection.subscriptionBuilder().onApplied(() => resolve()).onError(ctx => reject(ctx.event)).subscribe(queries.map(table => `SELECT * FROM ${table}`)));
  return connection;
}
let sequence = 1n;
const snowflake = () => ((BigInt(Date.now()) - 1420070400000n) << 22n) | sequence++;
try {
  const seeds = [0n, 4603144n, 4603145n, 1700000000n, 1754621895n, 1754621895n + 10000n];
  for (const [index, xp] of seeds.entries()) call('prepare_trader_player', [String(5301 + index), String(xp), '1000000', '1']);
  call('proof_materials', ['5305', '100', '100']);
  call('proof_previous_display_cap');
  const bot = await connect(['my_service', 'adapter_player', 'adapter_rods', 'adapter_items', 'adapter_receipt', 'adapter_upgrade_quote', 'public_profile', 'game_config']);
  call('configure_service', [JSON.stringify(bot.identity!.toHexString()), JSON.stringify({ discordAdapter: {} }), 'true']);
  const args = { discordUserId: 5305n, interactionId: snowflake() };
  await bot.reducers.selectDiscordPlayer({ ...args, displayName: 'Lifetime XP proof' });
  await bot.reducers.prepareUpgradeFromDiscord({ ...args, rodId: 1 });
  const upgrade = [...bot.db.adapterUpgradeQuote.iter()][0];
  await bot.reducers.commitUpgradeFromDiscord({ ...args, nonce: upgrade.nonce });
  await bot.reducers.fishFromDiscord({ ...args, guildId: 5n, channelId: 42n });
  const player = () => [...bot.db.adapterPlayer.iter()][0];
  const profile = () => [...bot.db.publicProfile.iter()].find(row => row.playerId === player().playerId)!;
  assert.equal(profile().level, 10000);
  assert(player().totalXp > 1754621895n);
  const oldReceipt = [...bot.db.adapterReceipt.iter()][0];
  assert.equal(oldReceipt.rulesVersion, 15);
  bot.disconnect();
  verify('before');
  run('spacetime', ['publish', '--server', uri, '--no-config', '--delete-data=never', '--yes=skip-login', '--bin-path', wasm, database]);
  verify('retained');
  call('activate_xp_progression');
  verify('activated');
  call('activate_xp_progression');
  verify('activated');
  const browser = await connect(['public_profile', 'game_config', 'my_player', 'my_link_challenge']);
  await assert.rejects(browser.reducers.activateXpProgression({}), /OWNER_REQUIRED/);
  const freshBot = await connect(['my_service', 'adapter_player', 'adapter_rods', 'adapter_receipt', 'public_profile', 'game_config']);
  call('configure_service', [JSON.stringify(freshBot.identity!.toHexString()), JSON.stringify({ discordAdapter: {} }), 'true']);
  const freshArgs = { discordUserId: 5305n, interactionId: snowflake() };
  await freshBot.reducers.selectDiscordPlayer({ ...freshArgs, displayName: 'Lifetime XP proof' });
  const selected = () => [...freshBot.db.adapterPlayer.iter()][0];
  const selectedProfile = () => [...freshBot.db.publicProfile.iter()].find(row => row.playerId === selected().playerId)!;
  assert.equal(selectedProfile().level, 500);
  const beforeCast = selected().totalXp;
  call('proof_cast_setup', ['5305', '1', '0']);
  await freshBot.reducers.fishFromDiscord({ ...freshArgs, guildId: 5n, channelId: 42n });
  const receipt = [...freshBot.db.adapterReceipt.iter()][0];
  assert.equal(receipt.rulesVersion, 16);
  assert(receipt.xpGranted > 0n);
  assert.equal(selected().totalXp, beforeCast + receipt.xpGranted);
  assert(selected().totalXp > 1754621895n);
  assert.equal(selectedProfile().level, 500);
  await freshBot.reducers.fishFromDiscord({ ...freshArgs, guildId: 5n, channelId: 42n });
  assert.equal(selected().totalXp, beforeCast + receipt.xpGranted, 'Replay grants XP once after level 500');
  for (const [index, xp] of seeds.entries()) {
    if (index === 4) continue;
    const expectedLevel = xp < 4603144n ? 1 : xp < 4603145n ? 499 : 500;
    assert.equal([...freshBot.db.publicProfile.iter()].find(row => row.playerId === BigInt(index + 1))!.level, expectedLevel);
  }
  console.log('PASS cap 500 boundaries, preserved 1.7 billion lifetime XP, post-cap fishing XP, replay, owner-only activation and unchanged gear/rewards.');
} finally {
  for (const connection of connections) connection.disconnect();
  run('spacetime', ['delete', '--server', uri, '--no-config', '--yes', database]);
  assert(fixtureRoot === resolve('.local/level-cap-boundary-module'));
  await rm(fixtureRoot, { recursive: true, force: true });
  await rm(snapshotFile, { force: true });
}
