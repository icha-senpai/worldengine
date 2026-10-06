import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';
import type { Identity } from 'spacetimedb';

const uri = process.env.TEST_SPACETIMEDB_URI ?? 'http://127.0.0.1:3127';
assert(new URL(uri).hostname === '127.0.0.1', 'Integration tests require an isolated loopback host');
const database = process.env.TEST_SPACETIMEDB_DATABASE ?? (await readFile('.local/proof-database.txt', 'utf8')).trim();
assert(/^fishbound-proof-[a-z0-9-]+$/.test(database), 'Only a proof database may be tested');
const connections: DbConnection[] = [];
let passed = 0;
let sequence = 1n;
const snowflake = () => ((BigInt(Date.now()) - 1420070400000n) << 22n) | sequence++;
const timeout = <T>(promise: Promise<T>, label: string, ms = 12000) => {
  let timer: ReturnType<typeof setTimeout>;
  return Promise.race([promise, new Promise<T>((_, reject) => {
    timer = setTimeout(() => reject(new Error(`Timeout: ${label}`)), ms);
  })]).finally(() => clearTimeout(timer));
};
async function connect(token?: string) {
  return timeout(new Promise<{ connection: DbConnection; identity: Identity; token: string }>((resolve, reject) => {
    const connection = DbConnection.builder().withUri(uri).withDatabaseName(database).withToken(token)
      .onConnect((connection, identity, token) => resolve({ connection, identity, token }))
      .onConnectError((_, error) => reject(error)).build();
    connections.push(connection);
  }), 'connect');
}
async function subscribe(connection: DbConnection, queries: string[]) {
  return timeout(new Promise<void>((resolve, reject) => {
    connection.subscriptionBuilder().onApplied(() => resolve()).onError((ctx) => reject(ctx.event))
      .subscribe(queries);
  }), 'subscribe');
}
const waitFor = async (predicate: () => boolean, label: string) => {
  const end = Date.now() + 12000;
  while (!predicate()) {
    if (Date.now() > end) throw new Error(`Timeout: ${label}`);
    await new Promise(resolve => setTimeout(resolve, 15));
  }
};
const ownerGrant = (identity: Identity, role: string, active = true) => {
  const result = spawnSync('spacetime', ['call', '--server', uri, '--no-config', '--yes', database,
    'configure_service', JSON.stringify(identity.toHexString()), JSON.stringify({ [role[0].toLowerCase() + role.slice(1)]: {} }), String(active)],
    { encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw new Error(`Owner service grant failed: ${result.stderr || result.stdout}`);
};
const checks = async (label: string, action: () => Promise<void>) => {
  await action(); passed++; console.log(`PASS ${label}`);
};
try {
  const bot = await connect();
  const linker = await connect();
  const alice = await connect();
  const bob = await connect();
  const replacement = await connect();
  const botConn = bot.connection;
  ownerGrant(bot.identity, 'DiscordAdapter');
  ownerGrant(linker.identity, 'AccountLinker');
  const browserQueries = ['SELECT * FROM my_rods', 'SELECT * FROM my_licences', 'SELECT * FROM my_shop_quote', 'SELECT * FROM my_profile', 'SELECT * FROM my_player', 'SELECT * FROM my_inventory',
    'SELECT * FROM my_collection', 'SELECT * FROM my_recent_catches', 'SELECT * FROM my_link_challenge',
    'SELECT * FROM adapter_player', 'SELECT * FROM adapter_receipt', 'SELECT * FROM adapter_daily_receipt', 'SELECT * FROM adapter_rods', 'SELECT * FROM adapter_licences', 'SELECT * FROM adapter_shop_quote', 'SELECT * FROM shop_listing'];
  await Promise.all([subscribe(botConn, ['SELECT * FROM adapter_player', 'SELECT * FROM adapter_receipt', 'SELECT * FROM adapter_daily_receipt', 'SELECT * FROM adapter_rods', 'SELECT * FROM adapter_licences', 'SELECT * FROM adapter_shop_quote', 'SELECT * FROM shop_listing',
    'SELECT * FROM adapter_cast_pulls', 'SELECT * FROM adapter_inventory', 'SELECT * FROM adapter_collection', 'SELECT * FROM species_definition',
    'SELECT * FROM game_config', 'SELECT * FROM biome_definition', 'SELECT * FROM rod_definition', 'SELECT * FROM rod_bonuses', 'SELECT * FROM species_rank_definition', 'SELECT * FROM rarity_definition',
    'SELECT * FROM angler_standing', 'SELECT * FROM legendary_find', 'SELECT * FROM species_record']), subscribe(alice.connection, browserQueries), subscribe(bob.connection, browserQueries),
    subscribe(replacement.connection, browserQueries)]);
  await checks('ordinary identities cannot grant services, cast, select accounts, or complete links', async () => {
    await assert.rejects(bob.connection.reducers.activateTrader({ grandfatherLicences: true }), /OWNER_REQUIRED/);
    await assert.rejects(bob.connection.reducers.prepareShopPurchase({ listingId: 20 }), /ACCOUNT_NOT_LINKED/);
    await assert.rejects(bob.connection.reducers.commitShopPurchase({ nonce: 0n }), /ACCOUNT_NOT_LINKED/);
    await assert.rejects(bob.connection.reducers.prepareShopFromDiscord({ discordUserId: 8001n, interactionId: snowflake(), listingId: 20 }), /SERVICE_UNAUTHORIZED/);
    assert.equal(bob.connection.db.myRods.count(), 0n);
    assert.equal(bob.connection.db.adapterRods.count(), 0n);
    await assert.rejects(bob.connection.reducers.configureService({ identity: bob.identity, role: { tag: 'DiscordAdapter' }, active: true }), /OWNER_REQUIRED/);
    await assert.rejects(bob.connection.reducers.rebuildPlayerRecords({ playerId: 1n }), /OWNER_REQUIRED/);
    await assert.rejects(bob.connection.reducers.migrateUnlimitedInventory({}), /OWNER_REQUIRED/);
    await assert.rejects(bob.connection.reducers.selectDiscordPlayer({ discordUserId: 8001n, displayName: 'Mallory', interactionId: snowflake() }), /SERVICE_UNAUTHORIZED/);
    await assert.rejects(bob.connection.reducers.fishFromDiscord({ discordUserId: 8001n, interactionId: snowflake(), channelId: 42n, guildId: 5n }), /SERVICE_UNAUTHORIZED/);
    await assert.rejects(bob.connection.reducers.completeAccountLink({ challengeId: 1n, verifiedDiscordUserId: 8001n, proof: 0n }), /SERVICE_UNAUTHORIZED/);
    assert.equal(bob.connection.db.adapterPlayer.count(), 0n);
    assert.equal(bob.connection.db.adapterReceipt.count(), 0n);
    assert.equal(bob.connection.db.adapterDailyReceipt.count(), 0n);
    await assert.rejects(bob.connection.reducers.dailyFromDiscord({ discordUserId: 8001n, interactionId: snowflake(), channelId: 42n, guildId: 5n }), /SERVICE_UNAUTHORIZED/);
    await assert.rejects(linker.connection.reducers.dailyFromDiscord({ discordUserId: 8001n, interactionId: snowflake(), channelId: 42n, guildId: 5n }), /SERVICE_UNAUTHORIZED/);
    await assert.rejects(bob.connection.reducers.changeLoadout({ biomeId: 1, rodId: 1 }), /ACCOUNT_NOT_LINKED/);
    await assert.rejects(bob.connection.reducers.changeLoadoutFromDiscord({ discordUserId: 8001n, interactionId: snowflake(), biomeId: 1, rodId: 1 }), /SERVICE_UNAUTHORIZED/);
  });
  await checks('all 251 species, seven reachable biome gates, and seven rod templates plus trader offers are seeded from current content', async () => {
    const catalog = JSON.parse(await readFile('content/species.json', 'utf8'));
    const world = JSON.parse(await readFile('content/world.json', 'utf8'));
    const species = [...botConn.db.speciesDefinition.iter()];
    const biomes = [...botConn.db.biomeDefinition.iter()];
    const rods = [...botConn.db.rodDefinition.iter()];
    const ranks = [...botConn.db.speciesRankDefinition.iter()];
    const sizeRules = JSON.parse(await readFile('content/size-rules.json', 'utf8'));
    for (const rule of sizeRules.tiers) {
      const actual = [...botConn.db.rarityDefinition.iter()].find(row => row.tier === rule.rarity)!;
      assert.equal(actual.minimumLengthMillionths, BigInt(rule.minimumLengthMillionths));
      assert.equal(actual.minimumWeightMillionths, BigInt(rule.minimumWeightMillionths));
    }
    assert.equal(ranks.length, 2492);
    assert.equal(species.length, 251);
    assert.equal(biomes.length, 7);
    assert.equal(rods.length, 7);
    assert.equal(botConn.db.rodBonuses.count(), 7n);
    for (const rod of world.rods) {
      const actual = [...botConn.db.rodBonuses.iter()].find(row => row.rodId === rod.rodId)!;
      assert.equal(actual.luckBp, rod.luckBp);
      assert.equal(actual.xpBonusBp, rod.xpBonusBp);
      assert.equal(actual.spriteAsset, rod.spriteAsset);
    }
    await assert.rejects(bob.connection.reducers.activateRodBonuses({}), /NOT_AUTHORIZED/);
    assert.equal(botConn.db.shopListing.count(), 17n);
    assert.equal([...botConn.db.gameConfig.iter()][0].levelCap, 60);
    assert.equal([...botConn.db.gameConfig.iter()][0].inventoryCapacity, 0, 'Fish storage is unlimited');
    for (const definition of catalog.species) {
      const actual = species.find(row => row.speciesId === definition.speciesId)!;
      assert.equal(actual.name, definition.name);
      assert.deepEqual(actual.allowedRarities, definition.allowedRarities);
      for (const rank of definition.ranks) {
        const stored = ranks.find(row => row.speciesId === definition.speciesId && row.rarity === rank.rarity)!;
        assert(stored, 'Every eligible rank must be seeded');
        assert.equal(stored.encounterWeight, BigInt(rank.encounterWeight));
        assert.equal(stored.baseXp, BigInt(rank.baseXp));
        assert.equal(stored.baseValue, BigInt(rank.baseValue));
      }
      assert.equal(ranks.filter(row => row.speciesId === definition.speciesId).length, definition.allowedRarities.length);
      assert.equal(actual.biomeId, definition.biomeId);
      assert.equal(actual.encounterWeight, BigInt(definition.encounterWeight));
      assert.equal(actual.countsForOrdinaryCollectionCompletion, definition.countsForOrdinaryCollectionCompletion);
      assert.equal(actual.spriteAsset, `/fish/${definition.spriteKey}.png`);
    }
    for (const biome of world.biomes) {
      const actual = biomes.find(row => row.biomeId === biome.biomeId)!;
      assert.equal(actual.minimumLevel, biome.minimumLevel);
      assert.equal(actual.requiredPower, biome.requiredPower);
      assert.equal(species.filter(row => row.biomeId === biome.biomeId).reduce((sum, row) => sum + row.encounterWeight, 0n), 1_000_000n);
      assert(rods.some(rod => rod.minimumLevel <= actual.minimumLevel && rod.power >= actual.requiredPower));
    }
  });
  await checks('raw private tables reject unrelated subscriber queries', async () => {
    for (const table of ['player', 'player_identity', 'owned_specimen', 'command_receipt', 'daily_delivery', 'daily_receipt', 'owned_rod', 'owned_biome_licence', 'shop_quote', 'trader_migration', 'service_principal', 'link_challenge']) {
      await assert.rejects(subscribe(bob.connection, [`SELECT * FROM ${table}`]));
    }
  });
  const discordUserId = 8001n;
  const interactionId = snowflake();
  const fishArgs = { discordUserId, interactionId, channelId: 42n, guildId: 5n };
  await botConn.reducers.selectDiscordPlayer({ discordUserId, displayName: 'Alice', interactionId });
  await waitFor(() => botConn.db.adapterPlayer.count() === 1n, 'selected player');
  await checks('starter creation is idempotent and does not grant repeat rewards', async () => {
    const original = [...botConn.db.adapterPlayer.iter()][0];
    await botConn.reducers.selectDiscordPlayer({ discordUserId, displayName: 'Changed', interactionId });
    const after = [...botConn.db.adapterPlayer.iter()][0];
    assert.equal(after.playerId, original.playerId);
    assert.equal(after.coins, 0n);
    assert.equal(after.completedCasts, 0n);
  });
  await checks('one cast commits a durable result, XP, and the sixty-second cooldown', async () => {
    await botConn.reducers.fishFromDiscord(fishArgs);
    await waitFor(() => botConn.db.adapterReceipt.count() === 1n, 'committed receipt');
    const receipt = [...botConn.db.adapterReceipt.iter()][0];
    const player = [...botConn.db.adapterPlayer.iter()][0];
    assert.equal(player.completedCasts, 1n);
    assert.equal(player.totalXp, receipt.xpGranted);
    assert.equal(player.coins, receipt.coinsGranted);
    assert.equal(player.nextCastAt.microsSinceUnixEpoch - receipt.caughtAt.microsSinceUnixEpoch, 60000000n);
    assert(['fish', 'junk', 'treasure'].includes(receipt.outcome));
    const pulls=[...botConn.db.adapterCastPulls.iter()];
    assert(pulls.length >= 1 && pulls.length <= 2);
    assert.equal(receipt.xpGranted,pulls.reduce((sum,p)=>sum+p.receipt.xpGranted,0n));
    assert.equal(player.keptCount,pulls.filter(p=>p.receipt.outcome==='fish').length);
    assert.equal(botConn.db.adapterInventory.count(),BigInt(player.keptCount));
    for (const pull of pulls) {
      const result=pull.receipt;
      if(result.outcome !== 'fish') continue;
      const species=[...botConn.db.speciesDefinition.iter()].find(s=>s.speciesId===result.speciesId)!;
      assert(result.lengthMm>=species.minLengthMm && result.lengthMm<=species.maxLengthMm);
      assert(result.weightG>=species.minWeightG && result.weightG<=species.maxWeightG);
      const specimen=[...botConn.db.adapterInventory.iter()].find(f=>f.catchId===result.catchId)!;
      assert.equal(specimen.rarity,result.rarity);
      const expected=[...botConn.db.rarityDefinition.iter()].sort((a,b)=>b.ordinal-a.ordinal).find(rule=>BigInt(result.lengthMm)*1_000_000n>=BigInt(species.typicalLengthMm)*rule.minimumLengthMillionths && result.weightG*1_000_000n>=species.typicalWeightG*rule.minimumWeightMillionths)!;
      assert.equal(result.rarity,expected.tier);
      const rank=[...botConn.db.speciesRankDefinition.iter()].find(r=>r.speciesId===result.speciesId && r.rarity===result.rarity)!;
      const progress=[...botConn.db.adapterCollection.iter()].find(r=>r.speciesId===result.speciesId)!;
      assert.equal(progress.rankCounts.length,10);
      assert.equal(progress.rankCounts.reduce((sum,c)=>sum+c,0n),progress.count);
      const first=pulls.find(p=>p.receipt.speciesId===result.speciesId)===pull;
      assert.equal(pull.baseXp,rank.baseXp*[10000n,10000n,10000n,11000n,12000n,12500n][result.sizeGrade]/10000n+(first?species.discoveryXp:0n));
      let ratio=BigInt(result.lengthMm)*1_000_000n/BigInt(species.typicalLengthMm);
      ratio=ratio<600_000n?600_000n:ratio>2_000_000n?2_000_000n:ratio;
      assert.equal(result.saleValueCoins,rank.baseValue*ratio/1_000_000n);
    }
  });
  await checks('replay recovers the same result and conflicts cannot change it', async () => {
    const receipt = [...botConn.db.adapterReceipt.iter()][0];
    const standing = [...botConn.db.anglerStanding.iter()].find(row => row.playerId === receipt.playerId);
    await botConn.reducers.fishFromDiscord(fishArgs);
    assert.deepEqual([...botConn.db.adapterReceipt.iter()][0], receipt);
    assert.equal([...botConn.db.adapterPlayer.iter()][0].completedCasts, 1n);
    assert.deepEqual([...botConn.db.anglerStanding.iter()].find(row => row.playerId === receipt.playerId), standing);
    await assert.rejects(botConn.reducers.fishFromDiscord({ ...fishArgs, channelId: 43n }), /REQUEST_CONFLICT/);
    assert.equal([...botConn.db.adapterPlayer.iter()][0].completedCasts, 1n);
  });
  await checks('concurrent new casts and expired requests leave existing rewards untouched', async () => {
    const result = await Promise.allSettled([1, 2].map(() => botConn.reducers.fishFromDiscord({ ...fishArgs, interactionId: snowflake() })));
    assert(result.every(entry => entry.status === 'rejected' && /COOLDOWN_ACTIVE/.test(String(entry.reason))));
    const old = ((BigInt(Date.now() - 301000) - 1420070400000n) << 22n) | sequence++;
    await assert.rejects(botConn.reducers.fishFromDiscord({ ...fishArgs, interactionId: old }), /INTERACTION_EXPIRED/);
    assert.equal([...botConn.db.adapterPlayer.iter()][0].completedCasts, 1n);
  });
  await checks('two concurrent first casts commit exactly one result', async () => {
    const racingPlayer = 8002n;
    await botConn.reducers.selectDiscordPlayer({ discordUserId: racingPlayer, displayName: 'Racer', interactionId: snowflake() });
    const results = await Promise.allSettled([1, 2].map(() => botConn.reducers.fishFromDiscord({ ...fishArgs, discordUserId: racingPlayer, interactionId: snowflake() })));
    assert.equal(results.filter(result => result.status === 'fulfilled').length, 1);
    assert.equal(results.filter(result => result.status === 'rejected' && /COOLDOWN_ACTIVE/.test(String(result.reason))).length, 1);
    await waitFor(() => [...botConn.db.adapterPlayer.iter()][0]?.discordUserId === racingPlayer, 'racing player state');
    assert.equal([...botConn.db.adapterPlayer.iter()][0].completedCasts, 1n);
    await botConn.reducers.selectDiscordPlayer({ discordUserId, displayName: 'Alice', interactionId });
    await waitFor(() => [...botConn.db.adapterPlayer.iter()][0]?.discordUserId === discordUserId, 'restore Alice selection');
  });
  await checks('daily delivery grants once across servers, replays safely, and leaves fishing progression unchanged', async () => {
    const discordUserId = 8099n;
    const args = { discordUserId, interactionId: snowflake(), channelId: 42n, guildId: 5n };
    await botConn.reducers.selectDiscordPlayer({ discordUserId, displayName: 'Delivery proof', interactionId: args.interactionId });
    const before = [...botConn.db.adapterPlayer.iter()][0];
    const standing = [...botConn.db.anglerStanding.iter()].find(row => row.playerId === before.playerId);
    const results = await Promise.allSettled([
      botConn.reducers.dailyFromDiscord(args),
      botConn.reducers.dailyFromDiscord({ ...args, interactionId: snowflake(), guildId: 6n })
    ]);
    assert(results.every(result => result.status === 'fulfilled'));
    await waitFor(() => botConn.db.adapterDailyReceipt.count() === 1n, 'daily receipt');
    const receipt = [...botConn.db.adapterDailyReceipt.iter()][0];
    const after = [...botConn.db.adapterPlayer.iter()][0];
    assert.equal(after.coins, 100n);
    assert.deepEqual({ ...after, coins: before.coins }, before);
    assert.deepEqual([...botConn.db.anglerStanding.iter()].find(row => row.playerId === before.playerId), standing);
    assert.equal(receipt.totalClaims, 1n);
    assert.equal(receipt.stamps, 1);
    assert.equal(receipt.nextDeliveryAt.microsSinceUnixEpoch % 86400000000n, 0n);
    assert(receipt.nextDeliveryAt.microsSinceUnixEpoch > receipt.createdAt.microsSinceUnixEpoch);
    await botConn.reducers.dailyFromDiscord(args);
    assert.deepEqual([...botConn.db.adapterDailyReceipt.iter()][0], receipt);
    assert.equal([...botConn.db.adapterPlayer.iter()][0].coins, 100n);
    await assert.rejects(botConn.reducers.dailyFromDiscord({ ...args, channelId: 43n }), /REQUEST_CONFLICT/);
    await assert.rejects(botConn.reducers.fishFromDiscord(args), /REQUEST_CONFLICT/);
    const retry = { ...args, interactionId: snowflake(), guildId: 88n };
    await botConn.reducers.selectDiscordPlayer({ discordUserId, displayName: 'Delivery proof', interactionId: retry.interactionId });
    await botConn.reducers.dailyFromDiscord(retry);
    const repeated = [...botConn.db.adapterDailyReceipt.iter()][0];
    assert.equal(repeated.claimed, false);
    assert.equal(repeated.coinsGranted, 0n);
    assert.equal(repeated.totalClaims, 1n);
    const old = ((BigInt(Date.now() - 301000) - 1420070400000n) << 22n) | sequence++;
    await assert.rejects(botConn.reducers.dailyFromDiscord({ ...args, interactionId: old }), /INTERACTION_EXPIRED/);
    await botConn.reducers.selectDiscordPlayer({ discordUserId: 8001n, displayName: 'Alice', interactionId });
    await assert.rejects(botConn.reducers.dailyFromDiscord(fishArgs), /REQUEST_CONFLICT/);
  });
  await alice.connection.reducers.beginLinkChallenge({});
  await waitFor(() => alice.connection.db.myLinkChallenge.count() === 1n, 'Alice challenge');
  const challenge = [...alice.connection.db.myLinkChallenge.iter()][0];
  await checks('linker requires the browser proof; matching replay is harmless and conflicting reuse rejects', async () => {
    await assert.rejects(botConn.reducers.completeAccountLink({ challengeId: challenge.challengeId, verifiedDiscordUserId: discordUserId, proof: challenge.proof }), /SERVICE_UNAUTHORIZED/);
    await assert.rejects(linker.connection.reducers.completeAccountLink({ challengeId: challenge.challengeId, verifiedDiscordUserId: discordUserId, proof: challenge.proof ^ 1n }), /LINK_PROOF_INVALID/);
    await linker.connection.reducers.completeAccountLink({ challengeId: challenge.challengeId, verifiedDiscordUserId: discordUserId, proof: challenge.proof });
    await waitFor(() => alice.connection.db.myPlayer.count() === 1n, 'linked player');
    await linker.connection.reducers.completeAccountLink({ challengeId: challenge.challengeId, verifiedDiscordUserId: discordUserId, proof: challenge.proof });
    await assert.rejects(linker.connection.reducers.completeAccountLink({ challengeId: challenge.challengeId, verifiedDiscordUserId: 8999n, proof: challenge.proof }), /LINK_USED/);
    await assert.rejects(alice.connection.reducers.beginLinkChallenge({}), /LINK_RATE_LIMITED/);
  });
  await checks('caller-scoped state is visible only to the linked browser', async () => {
    assert.equal(alice.connection.db.myPlayer.count(), 1n);
    assert.equal(alice.connection.db.myRecentCatches.count(), 1n);
    assert.equal(bob.connection.db.myPlayer.count(), 0n);
    assert.equal(bob.connection.db.myRecentCatches.count(), 0n);
    assert.equal(bob.connection.db.myInventory.count(), 0n);
    assert.equal(bob.connection.db.myLinkChallenge.count(), 0n);
  });
  await checks('linked loadout selection is authoritative, rejects locked content, and preserves global cooldown', async () => {
    const before = [...alice.connection.db.myPlayer.iter()][0];
    await alice.connection.reducers.changeLoadout({ biomeId: 1, rodId: 1 });
    const after = [...alice.connection.db.myPlayer.iter()][0];
    assert.equal(after.nextCastAt.microsSinceUnixEpoch, before.nextCastAt.microsSinceUnixEpoch);
    assert.equal(after.totalXp, before.totalXp);
    assert.equal(after.coins, before.coins);
    for (const biome of [2,3,4,5,6,7]) await assert.rejects(alice.connection.reducers.changeLoadout({ biomeId: biome }), /BIOME_LEVEL_REQUIRED/);
    for (const rod of [2,3,4,5,6,7]) await assert.rejects(alice.connection.reducers.changeLoadout({ rodId: rod }), /ROD_LOCKED/);
    await assert.rejects(alice.connection.reducers.changeLoadout({ biomeId: 99 }), /BIOME_UNAVAILABLE/);
    await assert.rejects(alice.connection.reducers.changeLoadout({ rodId: 99 }), /ROD_UNAVAILABLE/);
    assert.equal([...alice.connection.db.myPlayer.iter()][0].selectedBiomeId, 1);
    await botConn.reducers.changeLoadoutFromDiscord({ discordUserId, interactionId, biomeId: 1, rodId: 1 });
    assert.equal([...alice.connection.db.myPlayer.iter()][0].nextCastAt.microsSinceUnixEpoch, before.nextCastAt.microsSinceUnixEpoch);
    await assert.rejects(botConn.reducers.changeLoadoutFromDiscord({ discordUserId: 8999n, interactionId, biomeId: 1 }), /REQUEST_CONFLICT/);
    await assert.rejects(linker.connection.reducers.changeLoadoutFromDiscord({ discordUserId, interactionId, biomeId: 1 }), /SERVICE_UNAUTHORIZED/);
    await assert.rejects(botConn.reducers.fishFromDiscord({ ...fishArgs, interactionId: snowflake() }), /COOLDOWN_ACTIVE/);
  });
  await checks('verified replacement revokes old browser views', async () => {
    await replacement.connection.reducers.beginLinkChallenge({});
    await waitFor(() => replacement.connection.db.myLinkChallenge.count() === 1n, 'replacement challenge');
    const challenge = [...replacement.connection.db.myLinkChallenge.iter()][0];
    await linker.connection.reducers.completeAccountLink({ challengeId: challenge.challengeId, verifiedDiscordUserId: discordUserId, proof: challenge.proof });
    await waitFor(() => replacement.connection.db.myPlayer.count() === 1n && alice.connection.db.myPlayer.count() === 0n, 'identity replacement');
    assert.equal(alice.connection.db.myRecentCatches.count(), 0n);
    assert.equal(alice.connection.db.myInventory.count(), 0n);
  });
  await checks('reconnect reconstructs committed state with the same authenticated identity', async () => {
    replacement.connection.disconnect();
    const resumed = await connect(replacement.token);
    await subscribe(resumed.connection, browserQueries);
    assert.equal(resumed.identity.toHexString(), replacement.identity.toHexString());
    assert.equal(resumed.connection.db.myPlayer.count(), 1n);
    assert.equal([...resumed.connection.db.myPlayer.iter()][0].completedCasts, 1n);
    assert.equal(resumed.connection.db.myRecentCatches.count(), 1n);
  });
  await checks('native Rust adapter commits, replays, serializes selected accounts, and reconnects', async () => {
    const result = spawnSync('target/debug/adapter-proof.exe', [], { encoding: 'utf8', windowsHide: true,
      env: { ...process.env, TEST_SPACETIMEDB_URI: uri, TEST_SPACETIMEDB_DATABASE: database, TEST_BOT_TOKEN: bot.token } });
    assert.equal(result.status, 0, result.stderr || result.stdout);
  });
  await checks('favorites block sales; confirmed sales replay safely and preserve discoveries and records', async () => {
    const manager = await connect();
    await subscribe(manager.connection, [...browserQueries, 'SELECT * FROM my_action', 'SELECT * FROM my_items', 'SELECT * FROM my_ledger', 'SELECT * FROM species_record', 'SELECT * FROM angler_standing']);
    let ownerId = 0n;
    // Exercise actual context RNG without adding a privileged test-only cast reducer.
    for (let attempt = 0; attempt < 16; attempt++) {
      const id = 9100n + BigInt(attempt);
      const requestId = snowflake();
      await botConn.reducers.selectDiscordPlayer({ discordUserId: id, displayName: 'Inventory proof', interactionId: requestId });
      await botConn.reducers.fishFromDiscord({ discordUserId: id, interactionId: requestId, guildId: 5n, channelId: 42n });
      const inventory = [...botConn.db.adapterInventory.iter()];
      if (inventory.length === 1) { ownerId = id; break; }
    }
    assert(ownerId > 0n, 'Context RNG produced no fish in sixteen attempts');
    await manager.connection.reducers.beginLinkChallenge({});
    const challenge = [...manager.connection.db.myLinkChallenge.iter()][0];
    await linker.connection.reducers.completeAccountLink({ challengeId: challenge.challengeId, proof: challenge.proof, verifiedDiscordUserId: ownerId });
    await waitFor(() => manager.connection.db.myInventory.count() === 1n, 'management inventory');
    const ownFish = [...manager.connection.db.myInventory.iter()][0];
    const ownSpecies = [...botConn.db.speciesDefinition.iter()].find(row => row.speciesId === ownFish.speciesId)!;
    const measuredRank = [...botConn.db.rarityDefinition.iter()].sort((a,b) => b.ordinal - a.ordinal).find(rule => BigInt(ownFish.lengthMm) * 1_000_000n >= BigInt(ownSpecies.typicalLengthMm) * rule.minimumLengthMillionths && ownFish.weightG * 1_000_000n >= ownSpecies.typicalWeightG * rule.minimumWeightMillionths)!;
    assert.equal(ownFish.rarity, measuredRank.tier);
    const progress = [...manager.connection.db.myCollection.iter()];
    const standingBeforeSale = [...manager.connection.db.anglerStanding.iter()].find(row => row.playerId === [...manager.connection.db.myPlayer.iter()][0].playerId);
    const records = [...manager.connection.db.speciesRecord.iter()].filter(row => row.speciesId === ownFish.speciesId);
    assert.equal(records.length, 2);
    await assert.rejects(bob.connection.reducers.prepareInventoryAction({ kind: 'sell', catchIds: [ownFish.catchId], favorite: false }), /ACCOUNT_NOT_LINKED/);
    await assert.rejects(manager.connection.reducers.prepareInventoryAction({ kind: 'sell', catchIds: [ownFish.catchId, ownFish.catchId], favorite: false }), /DUPLICATE_CATCH/);
    await manager.connection.reducers.prepareInventoryAction({ kind: 'favorite', catchIds: [ownFish.catchId], favorite: true });
    let nonce = [...manager.connection.db.myAction.iter()][0].nonce;
    await manager.connection.reducers.commitInventoryAction({ nonce });
    assert.equal([...manager.connection.db.myInventory.iter()][0].favorite, true);
    await new Promise(resolve => setTimeout(resolve, 1100));
    await assert.rejects(manager.connection.reducers.prepareInventoryAction({ kind: 'sell', catchIds: [ownFish.catchId], favorite: false }), /FAVORITE_PROTECTED/);
    await manager.connection.reducers.prepareInventoryAction({ kind: 'favorite', catchIds: [ownFish.catchId], favorite: false });
    const next = [...manager.connection.db.myAction.iter()][0].nonce;
    await assert.rejects(manager.connection.reducers.commitInventoryAction({ nonce }), /ACTION_CONFLICT/);
    await manager.connection.reducers.commitInventoryAction({ nonce: next });
    await new Promise(resolve => setTimeout(resolve, 1100));
    await manager.connection.reducers.prepareInventoryAction({ kind: 'sell', catchIds: [ownFish.catchId], favorite: false });
    const quote = [...manager.connection.db.myAction.iter()][0];
    const before = [...manager.connection.db.myPlayer.iter()][0].coins;
    assert.equal(quote.quotedCoins, ownFish.saleValueCoins);
    await manager.connection.reducers.commitInventoryAction({ nonce: quote.nonce });
    await manager.connection.reducers.commitInventoryAction({ nonce: quote.nonce });
    assert.equal(manager.connection.db.myInventory.count(), 0n);
    assert.equal([...manager.connection.db.myPlayer.iter()][0].coins, before + quote.quotedCoins);
    assert.equal([...manager.connection.db.myPlayer.iter()][0].keptCount, 0);
    assert.deepEqual([...manager.connection.db.myCollection.iter()], progress);
    assert.deepEqual([...manager.connection.db.anglerStanding.iter()].find(row => row.playerId === standingBeforeSale?.playerId), standingBeforeSale);
    assert.deepEqual([...manager.connection.db.speciesRecord.iter()].filter(row => row.speciesId === ownFish.speciesId), records);
    assert.equal([...manager.connection.db.myLedger.iter()].filter(row => row.reason === 'fish_sale').length, 1);
    await writeFile('.local/browser-proof.json', JSON.stringify({ uri, database, token: manager.token }), { mode: 0o600 });
  });
  await checks('a real owned fish is prepared for browser management verification', async () => {
    const showcase = await connect();
    await subscribe(showcase.connection, browserQueries);
    let ownerId = 0n;
    for (let attempt = 0; attempt < 16; attempt++) {
      const id = 9300n + BigInt(attempt);
      const requestId = snowflake();
      await botConn.reducers.selectDiscordPlayer({ discordUserId: id, displayName: 'Browser proof', interactionId: requestId });
      await botConn.reducers.fishFromDiscord({ discordUserId: id, interactionId: requestId, guildId: 5n, channelId: 42n });
      if (botConn.db.adapterInventory.count() === 1n) { ownerId = id; break; }
    }
    assert(ownerId > 0n, 'No fish available for browser fixture');
    await showcase.connection.reducers.beginLinkChallenge({});
    const challenge = [...showcase.connection.db.myLinkChallenge.iter()][0];
    await linker.connection.reducers.completeAccountLink({ challengeId: challenge.challengeId, proof: challenge.proof, verifiedDiscordUserId: ownerId });
    await waitFor(() => showcase.connection.db.myInventory.count() === 1n, 'browser fixture');
    await writeFile('.local/browser-proof.json', JSON.stringify({ uri, database, token: showcase.token }), { mode: 0o600 });
  });
  await checks('public lifetime standings stay accurate across casts, record transfers, and owner backfill', async () => {
    const guest = await connect();
    await subscribe(guest.connection, ['SELECT * FROM angler_standing', 'SELECT * FROM legendary_find', 'SELECT * FROM species_record']);
    let transfers = 0;
    for (let attempt = 0; attempt < 70; attempt++) {
      const id = 9500n + BigInt(attempt);
      const requestId = snowflake();
      await botConn.reducers.selectDiscordPlayer({ discordUserId: id, displayName: `Record proof ${attempt + 1}`, interactionId: requestId });
      const previous = new Map([...botConn.db.speciesRecord.iter()].map(row => [row.key, row.playerId]));
      await botConn.reducers.fishFromDiscord({ discordUserId: id, interactionId: requestId, guildId: 5n, channelId: 42n });
      const player = [...botConn.db.adapterPlayer.iter()][0];
      const progress = [...botConn.db.adapterCollection.iter()];
      const standing = [...botConn.db.anglerStanding.iter()].find(row => row.playerId === player.playerId)!;
      assert(standing);
      assert.equal(standing.fishCount, player.fishCount);
      assert.equal(standing.discoveries, progress.filter(row => [...botConn.db.speciesDefinition.iter()].find(fish => fish.speciesId === row.speciesId)?.countsForOrdinaryCollectionCompletion).length);
      assert.equal(standing.uurCount, progress.reduce((sum, row) => sum + row.rankCounts[9], 0n));
      const records = [...botConn.db.speciesRecord.iter()];
      transfers += records.filter(row => previous.has(row.key) && previous.get(row.key) !== row.playerId).length;
      for (const holder of botConn.db.anglerStanding.iter()) {
        assert.equal(holder.recordsHeld, records.filter(row => row.playerId === holder.playerId).length);
      }
    }
    assert(transfers > 0, 'The actual cast sample should exercise record transfers');
    await waitFor(() => guest.connection.db.anglerStanding.count() === botConn.db.anglerStanding.count(), 'anonymous standings');
    assert(guest.connection.db.anglerStanding.count() > 70n);
    assert.equal(guest.connection.db.legendaryFind.count(), 0n, 'Meadow Pond casts cannot discover the two Abyss legends');
    const before = [...botConn.db.anglerStanding.iter()].sort((a, b) => Number(a.playerId - b.playerId));
    for (const row of before) {
      const rebuilt = spawnSync('spacetime', ['call', '--server', uri, '--no-config', '--yes', database, 'rebuild_player_records', row.playerId.toString()], { encoding: 'utf8', windowsHide: true });
      assert.equal(rebuilt.status, 0, rebuilt.stderr || rebuilt.stdout);
    }
    // A second owner rebuild must be harmless and must retain totals from sold catches.
    const rebuilt = spawnSync('spacetime', ['call', '--server', uri, '--no-config', '--yes', database, 'rebuild_player_records', before[0].playerId.toString()], { encoding: 'utf8', windowsHide: true });
    assert.equal(rebuilt.status, 0, rebuilt.stderr || rebuilt.stdout);
    assert.deepEqual([...botConn.db.anglerStanding.iter()].sort((a, b) => Number(a.playerId - b.playerId)), before);
    console.log(`Verified ${transfers} real record transfers and ${before.length} lifetime standings.`);
  });
  await checks('unlinking removes private browser access', async () => {
    const logout = await connect(replacement.token);
    await subscribe(logout.connection, browserQueries);
    await logout.connection.reducers.unlinkBrowser({});
    await waitFor(() => logout.connection.db.myPlayer.count() === 0n, 'unlinked state');
    assert.equal(logout.connection.db.myRecentCatches.count(), 0n);
    await assert.rejects(logout.connection.reducers.prepareInventoryAction({ kind: 'sell', catchIds: [1n], favorite: false }), /ACCOUNT_NOT_LINKED/);
  });
  const revokedDailyInteraction = snowflake();
  await botConn.reducers.selectDiscordPlayer({ discordUserId: 8099n, displayName: 'Delivery proof', interactionId: revokedDailyInteraction });
  await botConn.reducers.dailyFromDiscord({ discordUserId: 8099n, interactionId: revokedDailyInteraction, guildId: 5n, channelId: 42n });
  assert.equal(botConn.db.adapterDailyReceipt.count(), 1n);
  await checks('revoked service role loses access and cannot create casts', async () => {
    ownerGrant(bot.identity, 'DiscordAdapter', false);
    await assert.rejects(botConn.reducers.dailyFromDiscord({ discordUserId: 8099n, interactionId: snowflake(), guildId: 5n, channelId: 42n }), /SERVICE_UNAUTHORIZED/);
    await waitFor(() => botConn.db.adapterPlayer.count() === 0n && botConn.db.adapterReceipt.count() === 0n && botConn.db.adapterDailyReceipt.count() === 0n, 'revoked service views');
    await assert.rejects(botConn.reducers.fishFromDiscord({ ...fishArgs, interactionId: snowflake() }), /SERVICE_UNAUTHORIZED/);
  });
  console.log(`Integration proof complete: ${passed} checks passed against ${database}.`);
} finally {
  for (const connection of connections) connection.disconnect();
}
