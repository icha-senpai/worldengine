// Publish an old production WASM to a new proof DB, then prove additive activation.
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';

const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-measurements-${Date.now()}`;
const oldWasm = process.argv[2];
assert(oldWasm, 'Pass the saved pre-update production WASM path');
const nextWasm = 'spacetimedb/target/wasm32-unknown-unknown/release/fishing_game_module.wasm';
const catalog = JSON.parse(await readFile('content/species.json', 'utf8'));
const thresholds = JSON.parse(await readFile('content/size-rules.json', 'utf8')).tiers;
const tables = [...(await readFile('spacetimedb/src/tables.rs', 'utf8')).matchAll(/accessor = (\w+)/g)]
  .map(match => match[1]).filter(name => !['species_definition', 'maintenance_job'].includes(name));
function run(command: string, args: string[]) {
  const result = spawnSync(command, args, { encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw new Error(result.stderr || result.stdout);
  return result.stdout;
}
const call = (name: string, args: string[] = []) => run('spacetime', ['call', '--server', uri, '--no-config', '--yes', database, name, ...args]);
function sql(table: string): Record<string, any>[] {
  const blocks = JSON.parse(run('spacetime', ['sql', '--server', uri, '--no-config', '--format', 'json', database, `SELECT * FROM ${table}`]));
  return blocks.flatMap((block: any) => block.rows.map((row: any[]) => Object.fromEntries(row.map((value, index) => [block.schema.elements[index].name.some, value]))));
}
const snapshot = () => Object.fromEntries(tables.map(table => [table, sql(table).map(row => JSON.stringify(row)).sort()]));
const publish = (path: string) => run('spacetime', ['publish', '--server', uri, '--no-config', '--yes=skip-login', '--bin-path', path, database]);
const connections: DbConnection[] = [];
async function connect(token?: string) {
  return new Promise<{connection: DbConnection; token: string}>((resolve, reject) => {
    const connection = DbConnection.builder().withUri(uri).withDatabaseName(database).withToken(token)
      .onConnect((connection, _identity, token) => resolve({connection, token}))
      .onConnectError((_ctx, error) => reject(error)).build();
    connections.push(connection);
  });
}
async function subscribe(connection: DbConnection) {
  await new Promise<void>((resolve, reject) => connection.subscriptionBuilder().onApplied(() => resolve()).onError(ctx => reject(ctx.event)).subscribe(['SELECT * FROM adapter_inventory', 'SELECT * FROM adapter_receipt', 'SELECT * FROM species_definition']));
}
let sequence = 1n;
const snowflake = () => ((BigInt(Date.now()) - 1420070400000n) << 22n) | sequence++;
async function castUntilFish(connection: DbConnection, firstUser: bigint) {
  for (let i = 0n; i < 20n; i++) {
    const args = {discordUserId: firstUser + i, displayName: 'Measurement proof', interactionId: snowflake()};
    await connection.reducers.selectDiscordPlayer(args);
    await connection.reducers.fishFromDiscord({...args, guildId: 5n, channelId: 42n});
    const receipt = [...connection.db.adapterReceipt.iter()][0];
    if (receipt.outcome === 'fish') return [...connection.db.adapterInventory.iter()][0];
  }
  throw new Error('Proof could not obtain a fish within twenty isolated casts');
}
try {
  publish(oldWasm);
  assert.equal(sql('species_definition').find(row => row.key === 'stickleback')?.typical_length_mm, 230, 'Old artifact must contain the audited v4 baseline');
  const bot = await connect();
  call('configure_service', [JSON.stringify(bot.connection.identity!.toHexString()), JSON.stringify({discordAdapter: {}}), 'true']);
  await subscribe(bot.connection);
  const legacy = await castUntilFish(bot.connection, 9500n);
  assert.equal(legacy.contentVersion, 4);
  const before = snapshot();
  bot.connection.disconnect();
  publish(nextWasm);
  const guest = await connect();
  await assert.rejects(guest.connection.reducers.activateSpeciesMeasurements({}), /OWNER_REQUIRED/);
  const beforeDefinitions = sql('species_definition');
  call('activate_species_measurements');
  assert.deepEqual(snapshot(), before, 'Activation must preserve every non-measurement table');
  const definitions = sql('species_definition');
  const dimensions = ['typical_length_mm', 'min_length_mm', 'max_length_mm', 'typical_weight_g', 'min_weight_g', 'max_weight_g'];
  for (const row of definitions) {
    const fish = catalog.species.find((fish: any) => fish.speciesId === row.species_id);
    assert.equal(row.typical_length_mm, fish.typicalLengthMm);
    assert.equal(row.typical_weight_g, fish.typicalWeightG);
    const metadata = (row: Record<string, any>) => Object.fromEntries(Object.entries(row).filter(([key]) => !dimensions.includes(key)));
    assert.deepEqual(metadata(row), metadata(beforeDefinitions.find(prior => prior.species_id === row.species_id)!));
  }
  call('activate_species_measurements');
  assert.deepEqual(sql('species_definition'), definitions, 'Activation must be idempotent');
  assert.deepEqual(snapshot(), before);
  const reconnected = await connect(bot.token);
  await subscribe(reconnected.connection);
  const fresh = await castUntilFish(reconnected.connection, 9600n);
  assert.equal(fresh.contentVersion, 5);
  const species = definitions.find(row => row.species_id === fresh.speciesId)!;
  const expected = species.typical_weight_g * (fresh.lengthMm / species.typical_length_mm) ** 3;
  const threshold = thresholds.find((tier: any) => tier.rarity === fresh.rarity);
  const floor = Math.ceil(species.typical_weight_g * threshold.minimumWeightMillionths / 1_000_000);
  assert(Number(fresh.weightG) >= Math.max(species.min_weight_g, Math.round(expected * .88)));
  assert(Number(fresh.weightG) <= Math.min(species.max_weight_g, Math.max(floor, Math.round(expected * 1.12))));
  const oldCatch = sql('owned_specimen').find(row => row.catch_id === Number(legacy.catchId));
  assert.equal(oldCatch!.content_version, 4);
  assert.equal(oldCatch!.weight_g, Number(legacy.weightG));
  assert.equal(oldCatch!.length_mm, legacy.lengthMm);
  await writeFile('.local/measurements-proof-result.json', JSON.stringify({database, catalogVersion: 5, species: definitions.length, preservedTables: tables.length, oldCatchVersion: 4, newCatchVersion: 5, authorizedActivation: true, idempotent: true}, null, 2));
  console.log(`PASS measurement migration: ${definitions.length} baselines updated; ${tables.length} other tables preserved; unauthorized calls rejected; repeat activation unchanged; v4 catch preserved and fresh v5 catch generated.`);
} finally {
  for (const connection of connections) connection.disconnect();
}
