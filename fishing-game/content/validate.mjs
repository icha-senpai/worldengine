import { readFile } from 'node:fs/promises';
import assert from 'node:assert/strict';
import { readMeasurements } from './measurements.mjs';

const content = JSON.parse(await readFile(new URL('./rarities.json', import.meta.url), 'utf8'));
const manifest = JSON.parse(await readFile(new URL('../assets/manifest.json', import.meta.url), 'utf8'));
const catalog = JSON.parse(await readFile(new URL('./species.json', import.meta.url), 'utf8'));
const rules = JSON.parse(await readFile(new URL('./game-rules.json', import.meta.url), 'utf8'));
const pond = JSON.parse(await readFile(new URL('./meadow-pond.json', import.meta.url), 'utf8'));
const world = JSON.parse(await readFile(new URL('./world.json', import.meta.url), 'utf8'));
assert.equal(rules.castCooldownSeconds, 60, 'The global cast cooldown must be one minute');
console.log(`Game rules valid: ${rules.castCooldownSeconds}-second cast cooldown.`);
const order = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];
assert.deepEqual(content.tiers.map((tier) => tier.key), order);
for (const [ordinal, tier] of content.tiers.entries()) {
  assert.equal(tier.ordinal, ordinal);
  assert(Number.isSafeInteger(tier.referenceWeight) && tier.referenceWeight > 0);
  assert(manifest.rankCards.some((asset) => asset.key === tier.key), `Missing card: ${tier.key}`);
}
assert.equal(content.tiers.reduce((sum, tier) => sum + tier.referenceWeight, 0), 1_000_000);
console.log(`Content valid: ${content.tiers.length} ordered tiers, reference weights total 1,000,000.`);

const ids = new Set();
const keys = new Set();
const names = new Set();
const spriteKeys = new Set();
const assets = new Set(manifest.fish.map((asset) => asset.key));
const exceptionalFish = new Set(['fihs', 'nidalees-lost-sock']);
for (const fish of catalog.species) {
  assert(Number.isSafeInteger(fish.speciesId) && fish.speciesId > 0, 'Invalid species ID');
  assert(!ids.has(fish.speciesId), `Duplicate species ID: ${fish.speciesId}`);
  assert(typeof fish.key === 'string' && /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(fish.key));
  assert(!keys.has(fish.key), `Duplicate species key: ${fish.key}`);
  assert(typeof fish.name === 'string' && fish.name.trim().length > 0, `Missing name: ${fish.key}`);
  assert(!names.has(fish.name.toLowerCase()), `Duplicate fish name: ${fish.name}`);
  assert(assets.has(fish.spriteKey), `Missing sprite: ${fish.spriteKey}`);
  assert(!spriteKeys.has(fish.spriteKey), `Duplicate sprite assignment: ${fish.spriteKey}`);
  assert.equal(fish.isFakeFish, fish.key === 'nidalees-lost-sock', `Unexpected fake fish: ${fish.key}`);
  assert(fish.rarity === undefined || order.includes(fish.rarity), `Invalid rarity: ${fish.key}`);
  assert(fish.encounterRarity === undefined || exceptionalFish.has(fish.key), `Reserved rarest positions cannot be assigned to ${fish.key}`);
  ids.add(fish.speciesId);
  keys.add(fish.key);
  names.add(fish.name.toLowerCase());
  spriteKeys.add(fish.spriteKey);
}
assert.deepEqual(spriteKeys, assets, 'Every supplied sprite must have a catalog entry');
const fihs = catalog.species.find((fish) => fish.key === 'fihs');
assert.deepEqual(fihs?.allowedRarities, ['UUR'], 'Fihs must be catchable only at UUR');
assert.equal(fihs.requiredForProgression, false, 'Fihs cannot gate progression');
assert.equal(fihs.countsForOrdinaryCollectionCompletion, false, 'Fihs cannot gate ordinary collection completion');
assert.deepEqual(fihs.encounterRarity, {
  overallPosition: 2,
  chanceRelativeTo: 'rarest-ordinary-uur',
  chanceMultiplier: { numerator: 1, denominator: 2 },
}, 'Fihs must be second-rarest, with half the rarest ordinary UUR catch chance');
const sock = catalog.species.find((fish) => fish.key === 'nidalees-lost-sock');
assert.deepEqual(sock?.allowedRarities, ['F'], 'Nidalees Lost Sock must remain F rank');
assert.equal(sock.requiredForProgression, false, 'The Sock cannot gate progression');
assert.equal(sock.countsForOrdinaryCollectionCompletion, false, 'The F-rank Sock cannot gate ordinary collection completion');
assert.deepEqual(sock.encounterRarity, {
  overallPosition: 1,
  chanceRelativeTo: 'fihs',
  chanceMultiplier: { numerator: 1, denominator: 2 },
}, 'Nidalees Lost Sock must be rarest, with half the catch chance of Fihs');
console.log(`Fish catalog valid: ${catalog.species.length} named fish; Nidalees Lost Sock is the one fake fish.`);
console.log('Exceptional rarity valid: Fihs is UUR and second-rarest; Nidalees Lost Sock is F and twice as rare as Fihs.');

assert.equal(pond.version, 1);
assert(Number.isSafeInteger(pond.discoveryXp) && pond.discoveryXp >= 0);
assert(pond.species.length >= 6 && pond.species.length <= 10);
const pondKeys = new Set();
for (const fish of pond.species) {
  assert(keys.has(fish.key), `Unknown live species: ${fish.key}`);
  assert(!exceptionalFish.has(fish.key), 'Exceptional fish await the late-game balance pass');
  assert(!pondKeys.has(fish.key), `Repeated pond species: ${fish.key}`);
  assert(order.includes(fish.rarity));
  for (const field of ['typicalLengthMm', 'typicalWeightG', 'baseValue', 'encounterWeight']) {
    assert(Number.isSafeInteger(fish[field]) && fish[field] > 0 && fish[field] < 1_000_000_000, `Invalid ${field}: ${fish.key}`);
  }
  pondKeys.add(fish.key);
}
console.log(`Historical starter reference valid: ${pond.species.length} species; active definitions use species.json and world.json.`);

assert.equal(catalog.species.length, 251);
assert.equal(catalog.version, 5);
const measurements = await readMeasurements();
assert.deepEqual([...measurements.keys()].sort(), [...keys].sort(), 'Every species must have explicit measurements');
for (const fish of catalog.species) {
  const reviewed = measurements.get(fish.key);
  assert.equal(fish.typicalLengthMm, reviewed.typicalLengthMm, `Stale length: ${fish.key}`);
  assert.equal(fish.typicalWeightG, reviewed.typicalWeightG, `Stale weight: ${fish.key}`);
}
console.log(`Measurements valid: ${measurements.size} explicit species baselines, no generic fallback.`);
assert.equal(world.version, catalog.version);
assert.equal(rules.version, 6);
assert.equal(world.levelCap, 60);
assert.deepEqual(world.biomes.map(b => b.name), ['Meadow Pond', 'Whispering River', 'Hollow Marsh', 'Moonlit Lake', 'Sunken Coast', 'Glacial Reach', 'Abyssal Shelf']);
assert.equal(new Set(world.biomes.map(b => b.biomeId)).size, world.biomes.length);
assert.equal(new Set(world.rods.map(r => r.rodId)).size, world.rods.length);
assert.equal(world.rods.length, 7);
for (const [i, rod] of world.rods.entries()) {
  assert.equal(rod.luckBp, [0, 500, 1000, 1500, 2000, 2500, 3000][i]);
  assert.equal(rod.xpBonusBp, [0, 500, 800, 1200, 1600, 2000, 2500][i]);
  assert(manifest.rods.some(asset => asset.url === rod.spriteAsset), `Missing rod sprite: ${rod.name}`);
  if (i) assert(rod.power > world.rods[i - 1].power && rod.minimumLevel > world.rods[i - 1].minimumLevel);
}
const probabilities = new Map();
for (const biome of world.biomes) {
  assert(biome.minimumLevel <= world.levelCap && biome.minimumLevel >= 1);
  assert.equal(biome.fishWeight + biome.junkWeight + biome.treasureWeight, 100);
  for (const field of ['fishWeight', 'junkWeight', 'treasureWeight']) assert(Number.isSafeInteger(biome[field]) && biome[field] > 0);
  assert(world.rods.some(rod => rod.minimumLevel <= biome.minimumLevel && rod.power >= biome.requiredPower), `Unreachable gear gate: ${biome.name}`);
  const pool = catalog.species.filter(fish => fish.biomeId === biome.biomeId);
  assert(pool.length > 0, `Empty encounter pool: ${biome.name}`);
  assert.equal(pool.reduce((sum, fish) => sum + fish.encounterWeight, 0), 1_000_000);
  for (const fish of pool) {
    assert.equal(fish.rarity, undefined, 'Rank belongs to catches, not species');
    assert.deepEqual(fish.allowedRarities, fish.key === 'fihs' ? ['UUR'] : fish.key === 'nidalees-lost-sock' ? ['F'] : order);
    assert.deepEqual(fish.ranks.map(rank => rank.rarity), fish.allowedRarities);
    assert.equal(fish.ranks.reduce((sum, rank) => sum + rank.encounterWeight, 0), fish.encounterWeight);
    for (const rank of fish.ranks) {
      for (const field of ['encounterWeight', 'baseXp', 'baseValue']) assert(Number.isSafeInteger(rank[field]) && rank[field] > 0);
      probabilities.set(`${fish.key}:${rank.rarity}`, BigInt(biome.fishWeight) * BigInt(rank.encounterWeight));
    }
    for (let i = 1; i < fish.ranks.length; i++) assert(fish.ranks[i-1].encounterWeight > fish.ranks[i].encounterWeight, 'Higher ranks must be rarer for ' + fish.key);
    for (const field of ['typicalLengthMm', 'typicalWeightG', 'baseValue', 'encounterWeight', 'discoveryXp']) assert(Number.isSafeInteger(fish[field]) && fish[field] > 0, `Invalid ${field}: ${fish.key}`);
    assert.equal(fish.countsForOrdinaryCollectionCompletion, !exceptionalFish.has(fish.key));
    // Integer numerator over a common denominator of 100,000,000 per pull.

  }
  console.log(`${biome.name}: ${pool.length} catchable species, level ${biome.minimumLevel}, licence access.`);
}
assert.equal(probabilities.size, 2492, '249 ordinary fish times ten ranks plus two exceptions');
const ordinaryUur = catalog.species.filter(fish => !exceptionalFish.has(fish.key)).map(fish => probabilities.get(`${fish.key}:UUR`));
const rarestOrdinary = ordinaryUur.reduce((min, p) => p < min ? p : min);
assert.equal(probabilities.get('fihs:UUR') * 2n, rarestOrdinary);
assert.equal(probabilities.get('nidalees-lost-sock:F') * 2n, probabilities.get('fihs:UUR'));
for (const [key, p] of probabilities) if (!key.startsWith('fihs:') && !key.startsWith('nidalees-lost-sock:')) assert(p > probabilities.get('fihs:UUR'), `${key} would break second-rarest ordering`);
for (const rod of world.rods) assert(Number.isSafeInteger(rod.power) && rod.power > 0 && Number.isSafeInteger(rod.minimumLevel) && rod.minimumLevel <= world.levelCap);
console.log('All 249 ordinary fish have ten catch ranks; 2,492 encounters valid with exact exceptional ordering and ratios.');

const sizes = JSON.parse(await readFile(new URL('./size-rules.json', import.meta.url), 'utf8'));
assert.equal(sizes.version, 4, 'Measurement baselines change independently of rank thresholds');
assert.deepEqual(sizes.tiers.map(row => row.rarity), order);
for (let i = 0; i < sizes.tiers.length; i++) {
  const tier = sizes.tiers[i];
  assert(Number.isSafeInteger(tier.minimumLengthMillionths) && tier.minimumLengthMillionths >= 0);
  assert(Number.isSafeInteger(tier.minimumWeightMillionths) && tier.minimumWeightMillionths >= 0);
  if (i) { assert(tier.minimumLengthMillionths > sizes.tiers[i-1].minimumLengthMillionths); assert(tier.minimumWeightMillionths > sizes.tiers[i-1].minimumWeightMillionths); }
  for (const fish of catalog.species.filter(f => f.allowedRarities.includes(tier.rarity))) {
    const minLength = Math.max(Math.floor(fish.typicalLengthMm * .55), Math.ceil(fish.typicalLengthMm * tier.minimumLengthMillionths / 1e6));
    const maxLength = Math.min(Math.floor(fish.typicalLengthMm * 1.9), i < 9 ? Math.ceil(fish.typicalLengthMm * sizes.tiers[i+1].minimumLengthMillionths / 1e6)-1 : Number.MAX_SAFE_INTEGER);
    const minWeight = Math.max(1, Math.ceil(fish.typicalWeightG * tier.minimumWeightMillionths / 1e6));
    assert(minLength <= maxLength && minWeight <= fish.typicalWeightG * 8, `${fish.key}:${tier.rarity} has no physical size range`);
  }
}
console.log('Species-relative length and weight thresholds valid; all 2,492 size bands are reachable.');

const trader = JSON.parse(await readFile(new URL('./trader.json', import.meta.url), 'utf8'));
assert.equal(trader.listings.length, 17);
assert.equal(new Set(trader.listings.map(row => row.listingId)).size, trader.listings.length);
for (const listing of trader.listings) {
  assert(['rod', 'licence', 'bait'].includes(listing.kind));
  assert(Number.isSafeInteger(listing.priceCoins) && listing.priceCoins > 0);
  const biome = world.biomes.find(row => row.biomeId === listing.biomeId);
  assert(biome && listing.previousBiomeId === Math.max(1,biome.biomeId - 1));
  assert.equal(listing.minimumLevel, biome.minimumLevel);
  if (listing.kind === 'bait') { assert(listing.targetId >= 1 && listing.targetId <= 5); continue; }
  if (listing.kind === 'licence') assert.equal(listing.targetId, biome.biomeId);
  else assert(world.rods.some(row => row.rodId === listing.targetId && row.minimumLevel === listing.minimumLevel && row.power >= biome.requiredPower));
}
console.log('Trader catalog valid: six permanent licences, six purchasable rods and five repeatable bait offers.');

const equipmentArt = JSON.parse(await readFile(new URL('./equipment-art.json', import.meta.url), 'utf8'));
assert.deepEqual(equipmentArt.licences.map(row => row.biomeId), trader.listings.filter(row => row.kind === 'licence').map(row => row.targetId));
assert.equal(equipmentArt.baits.length, 5);
for (const kind of ['licences', 'baits']) {
  assert.equal(new Set(equipmentArt[kind].map(row => row.key)).size, equipmentArt[kind].length);
  for (const row of equipmentArt[kind]) {
    assert(manifest[kind].some(asset => asset.key === row.key && asset.url === row.spriteAsset), `Missing ${kind} artwork: ${row.name}`);
  }
}
console.log('Equipment artwork valid: every paid licence and all five bait types have sprites.');

const crafting = JSON.parse(await readFile(new URL('./crafting.json', import.meta.url), 'utf8'));
assert.equal(crafting.version, 1);
assert.deepEqual(crafting.qualities.map(q => [q.qualityLevel,q.name,q.powerBonus,q.luckBp,q.xpBonusBp,q.tinCost,q.scrapCost]), [
  [0,'Common',0,0,0,0,0],[1,'Uncommon',5,200,500,10,5],[2,'Rare',15,500,1000,25,15],
  [3,'Epic',30,1000,2000,60,40],[4,'Legendary',50,1500,3000,150,100],
  [5,'Mythic',75,2500,5000,300,200],[6,'Prismatic',100,4000,7500,600,400]
]);
assert.equal(crafting.baits.length, 5);
assert.deepEqual(crafting.baits.map(b => b.baitId), [1,2,3,4,5]);
for (const bait of crafting.baits) {
  assert.equal(bait.usesPerPurchase,10);
  const offer = trader.listings.find(row => row.kind === 'bait' && row.targetId === bait.baitId);
  assert(offer && offer.priceCoins === [20,50,30,70,120][bait.baitId-1]);
  assert(manifest.baits.some(asset => asset.url === bait.spriteAsset));
  assert.equal(bait.luckBp,[0,0,500,1000,1500][bait.baitId-1]);
  assert.equal(bait.resourceItem,['rusted_tin','scrap','','',''][bait.baitId-1]);
}
assert(world.biomes.every(b => b.requiredPower === 0));
assert.equal(Math.max(...world.rods.map(r=>r.power))+100,185);
assert.equal(Math.max(...world.rods.map(r=>r.luckBp))+4000+1500,8500);
console.log('Crafting valid: seven permanent qualities, exact recipes, five 10-use baits; maximum 92.5% bonus pull and +85% luck.');
