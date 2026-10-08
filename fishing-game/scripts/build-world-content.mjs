// Rebuild reviewed prototype game stats without changing sprite-derived names or stable IDs.
import { readFile, writeFile } from 'node:fs/promises';
import assert from 'node:assert/strict';
import { readMeasurements } from '../content/measurements.mjs';
const root = new URL('../content/', import.meta.url);
const catalog = JSON.parse(await readFile(new URL('species.json', root), 'utf8'));
const reviewedMeasurements = await readMeasurements();
assert.deepEqual([...reviewedMeasurements.keys()].sort(), catalog.species.map(fish => fish.key).sort(), 'Every species needs explicit reviewed measurements');
const tiers = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];
const biomes = [
  [1, 'meadow-pond', 'Meadow Pond', 1, 0, 86, 12, 2, 'Quiet banks, garden fish, and small freshwater discoveries.'],
  [2, 'whispering-river', 'Whispering River', 5, 10, 83, 13, 4, 'Moving water, trout, powerful predators, and storm-touched fish.'],
  [3, 'hollow-marsh', 'Hollow Marsh', 10, 20, 77, 18, 5, 'Reedy pools, heavy bottom dwellers, and haunting autumn finds.'],
  [4, 'moonlit-lake', 'Moonlit Lake', 18, 35, 84, 11, 5, 'Moonlit water, luminous fish, and otherworldly visitors.'],
  [5, 'sunken-coast', 'Sunken Coast', 28, 50, 80, 13, 7, 'Reefs, tidal shallows, shellfish, and open-water giants.'],
  [6, 'glacial-reach', 'Glacial Reach', 40, 65, 83, 12, 5, 'Frozen channels, crystalline shoals, and ancient icebound fish.'],
  [7, 'abyssal-shelf', 'Abyssal Shelf', 55, 85, 78, 14, 8, 'Deep ocean trenches and volcanic vents shelter the rarest discoveries.'],
].map(([biomeId, key, name, minimumLevel, _oldPower, fishWeight, junkWeight, treasureWeight, description]) => ({biomeId, key, name, minimumLevel, requiredPower: 0, fishWeight, junkWeight, treasureWeight, description}));
const rodNames = ['Twig Rod', 'River Rod', 'Marsh Rod', 'Moonwood Rod', 'Tide Rod', 'Glacial Rod', 'Abyssal Rod'];
const rods = biomes.map((biome, index) => ({rodId: biome.biomeId, name: rodNames[index], power: [1,10,20,35,50,65,85][index], minimumLevel: biome.minimumLevel, luckBp: [0, 500, 1000, 1500, 2000, 2500, 3000][index], xpBonusBp: [0, 500, 800, 1200, 1600, 2000, 2500][index], spriteAsset: `/rods/${rodNames[index].replace(' Rod', '').toLowerCase()}.png`}));
const set = text => new Set(text.split(' '));
const pond = set('angelfish betta-fish bitterling blue-discus bluegill bream carp crappie crucean-carp fathead-minnow golden-shiner goldfish guppy gudgeon honey-carp killifish koi minnow mosquitofish petal-koi pumpkinseed roach rudd shiner stickleback sunfish tench tilapia');
const river = set('ancient-sturgeon bass big-mouth-bass brook-trout brown-trout burbot channel-catfish creek-chub dace dorado drum fallfish flowstone-muskellunge lightning-loach muskellunge perch pink-salmon rainbow-trout rock-bass ruffe shad silver-perch spottail-shiner stone-loach stormscale sturddlefish suckerfish threadfin-shad walleye white-bass yellow-perch');
const marsh = set('batfin bowfin candycorn-minnow catfish cobweb-carp duskbite eel gar ghost-shiner giant-catfish jack-o-fish leaffish lil-green-slimefish mudminnow pumpkin-koi pumpkin-puffer shadow-koi skullscale thornscale witchlight-betta bullhead warmouth');
const lake = set('blue-slimefish blueglass cloudfish crystal-eel crystalstinger fish-toy galaxyscale glimmer-eel lil-blue-slimefish lil-pink-slimefish moon-fish nebula-minnow nyan-fish pearl-wisp pink-slimefish rift-bitterling runefin silverflake silverscale sun-fish sunray-fish tidebloom');
const abyss = set('blobfish coelacanth deep-anglerfish dragonfish goliath-grouper giant-crab great-white-shark nomura-s-jellyfish oarfish phantom-ray void-fish fihs nidalees-lost-sock');
const cold = /(?:ice|iced|icey|icicle|snow|frost|frozen|glaci|winter|polar|chill|cold|hail|slush|whiteout)/;
const heat = /(?:ash-|basalt|blaz|burn|cinder|corecracker|crackle|ember|fire|flame|furnace|heated|hellfin|ignis|lava|magma|molten|pyro|seared|searfin|searing|volcanic)/;
const common = set('anchovy bitterling blenny creek-chub dace fathead-minnow guppy gudgeon herring killifish minnow mosquitofish mudminnow mullet mussels pea-crab roach sardine shiner smelt stickleback threadfin-shad whiting');
const frequent = set('bass big-mouth-bass bluegill bream brook-trout brown-trout bullhead burbot carp channel-catfish chromis cod crappie crucean-carp drum fallfish flounder goldfish grunt haddock hake mackerel perch pink-salmon plaice pollock pumpkinseed rock-bass rudd ruffe shad silver-perch spottail-shiner stone-loach suckerfish sunfish tench tilapia white-bass whitefish yellow-perch');
const uncommon = set('angelfish betta-fish blue-crab blue-discus blue-tang blowfish croaker damselfish dungeness-crab eel gar hermit-crab jellyfish koi lionfish moon-jelly octopus rainbow-trout red-king-crab red-reef-fish seahorse snow-crab squid starfish stingray triggerfish tuna walleye warmouth wrasse');
const epic = set('crystal-eel crystalstinger frostglass-koi flowstone-muskellunge glimmer-eel heated-goliath-grouper icebarb-sturgeon lava-dragonfish lightning-loach moonice-wrasse nomura-s-jellyfish phantom-ray runefin shadow-koi stormscale volcanic-sturgeon wintertide-octopus witchlight-betta');
const legendary = set('ancient-sturgeon aurora-frostfin blazing-coelacanth flamejaw-oarfish glaciel-sturgeon glacier-maw great-hellfin-shark molten-giant-catfish titanic-frost-catfish whiteout-shark');
const ultra = set('ancient-iced-coelacanth galaxyscale void-fish');
const headline = set('blue-marlin coelacanth deep-anglerfish dragonfish giant-catfish giant-crab goliath-grouper great-white-shark manta-ray muskellunge oarfish sandbar-shark sea-turtle shark swordfish');
function biomeFor(key) {
  if (cold.test(key)) return 6;
  if (heat.test(key) || abyss.has(key)) return 7;
  if (pond.has(key)) return 1;
  if (river.has(key)) return 2;
  if (marsh.has(key)) return 3;
  if (lake.has(key)) return 4;
  return 5; // Remaining supplied species are coastal/marine fish and invertebrates.
}
function encounterAffinity(key) {
  if (ultra.has(key) || legendary.has(key) || epic.has(key)) return 1;
  if (common.has(key)) return 12;
  if (frequent.has(key)) return 8;
  if (uncommon.has(key)) return 5;
  if (headline.has(key)) return 2;
  return 3;
}
// Explicit per-species baselines; missing species fail rather than sharing a fallback.
function measurements(key) {
  const reviewed = reviewedMeasurements.get(key);
  assert(reviewed, `Missing reviewed measurements: ${key}`);
  return {typicalLengthMm: reviewed.typicalLengthMm, typicalWeightG: reviewed.typicalWeightG};
}
const xpByRank = [8, 12, 18, 28, 44, 70, 110, 180, 300, 500];
const valueMultipliers = [1, 2, 4, 6, 10, 17, 29, 50, 90, 160];
for (const fish of catalog.species) {
  assert(Number.isSafeInteger(fish.baseValue) && fish.baseValue > 0, `Set an explicit economy value for ${fish.key}`);
  const exceptional = ['fihs', 'nidalees-lost-sock'].includes(fish.key);
  Object.assign(fish, measurements(fish.key), {
    biomeId: biomeFor(fish.key),
    baseValue: fish.baseValue,
    discoveryXp: 20, requiredForProgression: false,
    countsForOrdinaryCollectionCompletion: !exceptional,
    allowedRarities: fish.key === 'fihs' ? ['UUR'] : fish.key === 'nidalees-lost-sock' ? ['F'] : [...tiers],
  });
  delete fish.rarity;
  fish.ranks = fish.allowedRarities.map(rarity => ({rarity, encounterWeight: 0, baseXp: xpByRank[tiers.indexOf(rarity)], baseValue: fish.baseValue * valueMultipliers[tiers.indexOf(rarity)]}));
}
// Choose a species and physical size band; classify the final measurements for rank. Every ordinary fish has all ten positive
// rank intervals. Floors keep even the rarest ordinary rank more common than Fihs.
const lowerRankWeights = [10000, 7000, 4500, 2800, 1800, 1100, 700, 450, 320];
for (const biome of biomes) {
  const pool = catalog.species.filter(fish => fish.biomeId === biome.biomeId);
  const ordinary = pool.filter(fish => fish.countsForOrdinaryCollectionCompletion);
  const uurTickets = Math.ceil(19968 / biome.fishWeight);
  for (const fish of ordinary) for (const rank of fish.ranks) rank.encounterWeight = uurTickets;
  for (const fish of pool.filter(fish => !fish.countsForOrdinaryCollectionCompletion)) fish.ranks[0].encounterWeight = fish.key === 'fihs' ? 128 : 64;
  const candidates = ordinary.flatMap(fish => fish.ranks.filter(rank => rank.rarity !== 'UUR').map(rank => ({fish, rank, weight: encounterAffinity(fish.key) * lowerRankWeights[tiers.indexOf(rank.rarity)]})));
  const remaining = 1_000_000 - pool.flatMap(f => f.ranks).reduce((sum, rank) => sum + rank.encounterWeight, 0);
  assert(remaining > 0);
  const total = candidates.reduce((sum, entry) => sum + entry.weight, 0);
  for (const entry of candidates) entry.rank.encounterWeight += Math.floor(remaining * entry.weight / total);
  let remainder = 1_000_000 - pool.flatMap(f => f.ranks).reduce((sum, rank) => sum + rank.encounterWeight, 0);
  for (const entry of candidates) { if (remainder <= 0) break; entry.rank.encounterWeight++; remainder--; }
  for (const fish of pool) fish.encounterWeight = fish.ranks.reduce((sum, rank) => sum + rank.encounterWeight, 0);
  assert.equal(pool.reduce((sum, fish) => sum + fish.encounterWeight, 0), 1_000_000);
  console.log(`${biome.name}: ${pool.length} species, ${pool.flatMap(f => f.ranks).length} species/rank encounters`);
}
catalog.version = 5;
catalog.status = 'reviewed-species-measurements';
await writeFile(new URL('species.json', root), JSON.stringify(catalog, null, 2) + '\n');
await writeFile(new URL('world.json', root), JSON.stringify({version: 5, levelCap: 60, biomes, rods}, null, 2) + '\n');
await writeFile(new URL('game-rules.json', root), JSON.stringify({version: 6, status: 'bait-rod-quality-and-mixed-pulls', castCooldownSeconds: 60}, null, 2) + '\n');
const lengthMinimums = [0, 750000, 900000, 1000000, 1150000, 1300000, 1450000, 1600000, 1750000, 1850000];
const weightMinimums = [0, 421875, 729000, 1000000, 1520875, 2197000, 3048625, 4096000, 5359375, 6331625];
await writeFile(new URL('size-rules.json', root), JSON.stringify({version: 4, method: 'both-species-relative-length-and-weight', tiers: tiers.map((rarity, i) => ({rarity, minimumLengthMillionths: lengthMinimums[i], minimumWeightMillionths: weightMinimums[i]}))}, null, 2) + '\n');
