import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import world from '../content/world.json';
import crafting from '../content/crafting.json';
import haulCatalog from '../content/haul-equipment.json';
import speciesCatalog from '../content/species.json';
import raidCatalog from '../content/raids.json';
import { luckWeights } from '../apps/web/src/lib/game/luck';
import { baselineLoadout, castChance, perfectCatchOdds, rankSpreads, weightRangeAtLength, type CatalogFish } from '../apps/web/src/lib/game/compendium';

// Proposed analysis only. These parameters are not imported by gameplay.
type Kind = 'rod' | 'boat' | 'net';
type Goal = 'ranks' | 'maxima' | 'perfects';
type Stat = 'luckBp' | 'playerXpBp' | 'masteryXpBp' | 'saleBp' | 'salvageBp'
  | 'treasureBp' | 'filletBp' | 'scaleBp' | 'powerCenti' | 'baitAttemptBp'
  | 'weightFinishBp' | 'lengthFinishBp' | 'endpointPpm' | 'rankFocusBp'
  | 'baitPreserveBp' | 'extraPullBp' | 'fullAttemptCenti' | 'maximaFocusBp'
  | 'perfectFocusBp' | 'durationBp' | 'duplicateRerollBp' | 'haulAttemptCenti';
type Affix = {
  id: string; name: string; kinds: Kind[]; primary?: boolean; secondary?: boolean;
  stats: Partial<Record<Stat, number[]>>;
};
const allKinds: Kind[] = ['rod', 'boat', 'net'];
const steps = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10] as const;
const grid = (step: number) => steps.map(tier => tier * step);
function affix(id: string, name: string, kinds: Kind[], stats: Partial<Record<Stat, number[]>>, condition?: 'primary' | 'secondary'): Affix {
  return { id, name, kinds, stats, ...(condition ? { [condition]: true } : {}) };
}
const affixes: Affix[] = [
  affix('fortune', 'Fortune', allKinds, { luckBp: grid(250) }),
  affix('scholar', 'Scholar', allKinds, { playerXpBp: grid(200) }),
  affix('old_salt', 'Old Salt', allKinds, { masteryXpBp: grid(200) }),
  affix('coinwright', 'Coinwright', allKinds, { saleBp: grid(200) }),
  affix('scavenger', 'Scavenger', allKinds, { salvageBp: grid(250) }),
  affix('treasurekeeper', 'Treasurekeeper', allKinds, { treasureBp: grid(1000) }),
  affix('provisioner', 'Provisioner', allKinds, { filletBp: grid(120) }),
  affix('scaleweaver', 'Scaleweaver', allKinds, { scaleBp: grid(120) }),
  affix('line_tension', 'Line Tension', ['rod'], { powerCenti: grid(400) }),
  affix('chum_catalyst', 'Chum Catalyst', ['rod'], { baitAttemptBp: grid(1000) }),
  affix('weight_weaver', 'Weight Weaver', ['rod'], { weightFinishBp: grid(50), endpointPpm: grid(50) }),
  affix('trophy_lens', 'Trophy Lens', ['rod'], { lengthFinishBp: grid(50), endpointPpm: grid(50) }),
  affix('focus_tuner', 'Focus Tuner', ['rod'], { rankFocusBp: grid(200) }),
  affix('bait_conservation', 'Bait Conservation', ['rod'], { baitPreserveBp: grid(150) }),
  affix('aftercast', 'Aftercast', ['rod'], { extraPullBp: grid(500) }),
  affix('measured_cast', 'Measured Cast', ['rod'], { fullAttemptCenti: grid(100) }),
  affix('maxima_priority', 'Maxima Priority', ['rod'], { maximaFocusBp: grid(600), perfectFocusBp: grid(150) }),
  affix('deep_trawler', 'Deep Trawler', ['boat'], { extraPullBp: grid(1000) }),
  affix('efficient_hull', 'Efficient Hull', ['boat'], { durationBp: grid(100) }),
  affix('schoolfinder', 'Schoolfinder', ['boat'], { duplicateRerollBp: grid(1000) }),
  affix('wreckbreaker', 'Wreckbreaker', ['boat'], { salvageBp: grid(250) }),
  affix('sunken_treasury', 'Sunken Treasury', ['boat'], { treasureBp: grid(1250) }),
  affix('full_hold', 'Full Hold', ['boat'], { filletBp: grid(180) }),
  affix('surveyor', 'Surveyor', ['boat'], { masteryXpBp: grid(200) }),
  affix('haul_appraiser', 'Haul Appraiser', ['boat'], { haulAttemptCenti: grid(10) }),
  affix('fine_mesh', 'Fine Mesh', ['net'], { luckBp: grid(250) }),
  affix('quick_set', 'Quick Set', ['net'], { durationBp: grid(100) }),
  affix('overflow_knot', 'Overflow Knot', ['net'], { extraPullBp: grid(400) }),
  affix('selective_weave', 'Selective Weave', ['net'], { duplicateRerollBp: grid(1000) }),
  affix('silt_sifter', 'Silt Sifter', ['net'], { salvageBp: grid(250) }),
  affix('pearl_catcher', 'Pearl Catcher', ['net'], { treasureBp: grid(1250) }),
  affix('scale_collector', 'Scale Collector', ['net'], { scaleBp: grid(180) }),
  affix('mist_weaver', 'Mist Weaver', ['net'], { haulAttemptCenti: grid(10) }),
  affix('resonance', 'Resonance', allKinds, { luckBp: grid(400) }, 'primary'),
  affix('attuned_harvest', 'Attuned Harvest', allKinds, { filletBp: grid(100), scaleBp: grid(100) }, 'primary'),
  affix('attuned_wisdom', 'Attuned Wisdom', allKinds, { masteryXpBp: grid(300) }, 'primary'),
  affix('deepwater_cache', 'Deepwater Cache', allKinds, { treasureBp: grid(1500) }, 'primary'),
  affix('secondary_affinity', 'Secondary Affinity', allKinds, { luckBp: grid(400) }, 'secondary'),
  affix('attuned_focus', 'Attuned Focus', ['rod'], { rankFocusBp: grid(150) }, 'primary'),
];
type Signature = {
  id: string; name: string; kind: Kind | 'waters'; stats?: Partial<Record<Stat, number>>;
  rule: string;
};
const signatures: Signature[] = [
  { id: 'echoed_line', name: 'Echoed Line', kind: 'rod', stats: { extraPullBp: 5000 }, rule: 'One nonrecursive independent mixed pull at 50% per accepted rod action.' },
  { id: 'trophys_oath', name: "Trophy's Oath", kind: 'rod', rule: 'One separate 3% paired-maximum finish gate per ordinary UUR specimen, only with all ranks collected and Focus eligible/enabled; never per attempt.' },
  { id: 'baitkeepers_promise', name: "Baitkeeper's Promise", kind: 'rod', stats: { baitAttemptBp: 5000, baitPreserveBp: 1000 }, rule: 'Add 50% of stocked measurement-bait attempts; add 10pp per-slot bait preservation. Bait effects never need activation.' },
  { id: 'collectors_whisper', name: "Collector's Whisper", kind: 'rod', stats: { rankFocusBp: 1000 }, rule: 'Add 10pp missing-rank assistance, with existing eligibility; never selects a species.' },
  { id: 'salvage_sermon', name: 'Salvage Sermon', kind: 'rod', rule: 'First junk in an action adds one scrap after percentage modifiers; once per action.' },
  { id: 'tidal_memory', name: 'Tidal Memory', kind: 'rod', rule: 'First ordinary-fish rod action per biome/UTC day adds 50% of that activity base family-mastery credit.' },
  { id: 'ghost_trawler', name: 'Ghost Trawler', kind: 'boat', stats: { extraPullBp: 5000 }, rule: 'One nonrecursive mixed pull at 50% per normal haul.' },
  { id: 'cartographers_wake', name: "Cartographer's Wake", kind: 'boat', rule: 'Reroll one already-discovered ordinary-species catch per haul. If Schoolfinder also activates, use a second distinct eligible catch; never reroll a replacement. Retain only final outcomes.' },
  { id: 'salvage_fleet', name: 'Salvage Fleet', kind: 'boat', stats: { salvageBp: 2000 }, rule: 'Add 20% junk salvage; not treasure scrap or cache materials.' },
  { id: 'tempest_rudder', name: 'Tempest Rudder', kind: 'boat', stats: { durationBp: 500 }, rule: 'Reduce normal-haul time by 5%, never raid time.' },
  { id: 'gilded_bilge', name: 'Gilded Bilge', kind: 'boat', rule: '25% chance to double only the first treasure coin payout, after the additive treasure group; no recursion.' },
  { id: 'fishermans_folly', name: "Fisherman's Folly", kind: 'boat', rule: 'First junk in a normal haul awards six normal fillets and four normal scales; no all-junk requirement, no percentage amplification.' },
  { id: 'nets_of_plenty', name: 'Nets of Plenty', kind: 'net', stats: { extraPullBp: 2000 }, rule: 'One nonrecursive mixed pull at 20% per normal haul.' },
  { id: 'moonthread', name: 'Moonthread', kind: 'net', rule: 'First ordinary fish in a net haul gets one extra full paired attempt; no bait, rank steering, finisher or all-fish amplification.' },
  { id: 'swiftwater_knot', name: 'Swiftwater Knot', kind: 'net', stats: { durationBp: 500 }, rule: 'Reduce normal-net time by 5%, never raids.' },
  { id: 'whispering_mesh', name: 'Whispering Mesh', kind: 'net', rule: 'Same distinct-catch duplicate-species quota as Cartographer; Selective Weave owns its separate one-catch quota. Neither source rerolls the same fish twice.' },
  { id: 'scale_pact', name: 'Scale Pact', kind: 'net', stats: { scaleBp: 2000 }, rule: 'Add 20% ordinary scales, pinned at catch acquisition.' },
  { id: 'pearlbound', name: 'Pearlbound', kind: 'net', rule: '50% chance to double the first treasure coin payout only; no recursion.' },
  { id: 'rusted_crown', name: 'Rusted Crown', kind: 'waters', rule: 'First junk adds one tin after percentage modifiers; once per activity, not per pull.' },
  { id: 'atlas_of_seven_waters', name: 'Atlas of Seven Waters', kind: 'waters', rule: 'First activity containing ordinary fish per biome/UTC day adds 50% of base activity mastery; one shared cross-kind ledger.' },
  { id: 'wayfarers_compass', name: "Wayfarer's Compass", kind: 'waters', rule: 'Add 10 Luck-stat points outside primary biome only; does not activate primary affixes.' },
  { id: 'forgotten_cache', name: 'Forgotten Cache', kind: 'waters', rule: 'First ordinary fish per activity gives six normal fillets and four normal scales once, separate from eventual processing; no yield multiplier on this packet.' },
  { id: 'oceans_memory', name: "Ocean's Memory", kind: 'waters', rule: 'First newly collected ordinary species-rank per biome/UTC day adds 100% of that catch pre-Tideforge player XP, additive with Scholar; not the whole haul.' },
  { id: 'rift_current', name: 'Rift Current', kind: 'waters', rule: 'First activity containing ordinary fish per biome/UTC day adds one ordinary-only legal fish, no special species or recursive bonus generation.' },
];
type Build = {
  id: string; name: string; kind: Kind; family: number; affixes: string[];
  signatures: string[]; goal: Goal; innate: boolean; reinforcement: number; tier: number;
};
function build(id: string, name: string, kind: Kind, ids: string[], powers: string[], goal: Goal = 'ranks', family = 7, innate = true): Build {
  return { id, name, kind, family, affixes: ids, signatures: powers, goal, innate, reinforcement: kind === 'rod' ? 10 : 0, tier: 10 };
}
const builds: Build[] = [
  build('legacy_rod', 'Existing Abyssal rod', 'rod', [], [], 'ranks', 7, false),
  build('legacy_boat', 'Existing Abyssal boat', 'boat', [], [], 'ranks', 7, false),
  build('legacy_net', 'Existing Abyssal net', 'net', [], [], 'ranks', 7, false),
  build('rank_closer', 'Rank Closer', 'rod', ['focus_tuner', 'attuned_focus', 'line_tension', 'aftercast'], ['collectors_whisper', 'echoed_line', 'forgotten_cache']),
  build('maxima_archivist', 'Maxima Archivist', 'rod', ['maxima_priority', 'trophy_lens', 'weight_weaver', 'chum_catalyst'], ['trophys_oath', 'baitkeepers_promise', 'forgotten_cache'], 'maxima'),
  build('perfect_hunter', 'Perfect Hunter', 'rod', ['maxima_priority', 'trophy_lens', 'weight_weaver', 'chum_catalyst'], ['trophys_oath', 'baitkeepers_promise', 'forgotten_cache'], 'perfects'),
  build('balanced_rod', 'Balanced Explorer', 'rod', ['fortune', 'scholar', 'measured_cast', 'aftercast'], ['echoed_line', 'baitkeepers_promise', 'forgotten_cache']),
  build('xp_trawler', 'XP Trawler', 'boat', ['scholar', 'deep_trawler', 'efficient_hull', 'fortune'], ['ghost_trawler', 'tempest_rudder', 'oceans_memory']),
  build('coin_runner', 'Coin Runner', 'boat', ['coinwright', 'treasurekeeper', 'sunken_treasury', 'deep_trawler'], ['ghost_trawler', 'gilded_bilge', 'forgotten_cache']),
  build('treasure_runner', 'Treasure Runner', 'boat', ['treasurekeeper', 'sunken_treasury', 'deepwater_cache', 'deep_trawler'], ['ghost_trawler', 'gilded_bilge', 'forgotten_cache']),
  build('fillet_hauler', 'Fillet Hauler', 'boat', ['full_hold', 'provisioner', 'attuned_harvest', 'deep_trawler'], ['ghost_trawler', 'tempest_rudder', 'forgotten_cache']),
  build('scale_weaver', 'Scale Weaver', 'net', ['scale_collector', 'scaleweaver', 'attuned_harvest', 'overflow_knot'], ['scale_pact', 'nets_of_plenty', 'forgotten_cache']),
  build('salvage_fleet', 'Salvage Fleet', 'boat', ['scavenger', 'wreckbreaker', 'deep_trawler', 'efficient_hull'], ['salvage_fleet', 'tempest_rudder', 'rusted_crown']),
  build('quick_netter', 'Quick-Cycle Netter', 'net', ['quick_set', 'overflow_knot', 'scholar', 'fine_mesh'], ['nets_of_plenty', 'swiftwater_knot', 'forgotten_cache']),
  build('river_maxima', 'Developed River Maxima', 'rod', ['maxima_priority', 'trophy_lens', 'weight_weaver', 'chum_catalyst'], ['trophys_oath', 'baitkeepers_promise', 'forgotten_cache'], 'maxima', 2),
  build('precision_boat', 'Precision-check Boat', 'boat', ['fortune', 'resonance', 'haul_appraiser', 'deep_trawler'], ['ghost_trawler', 'cartographers_wake', 'forgotten_cache']),
  build('precision_net', 'Precision-check Net', 'net', ['fortune', 'fine_mesh', 'resonance', 'mist_weaver'], ['moonthread', 'nets_of_plenty', 'forgotten_cache']),
];

// The calculation and assertions below are authored separately from runtime
// reducers. They model these proposed rules, not already available equipment.
const rankNames = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];
const fullMask = (1 << 10) - 1;
const byAffix = new Map(affixes.map(row => [row.id, row]));
const bySignature = new Map(signatures.map(row => [row.id, row]));
const close = (a: number, b: number, epsilon = 1e-11) => assert(Math.abs(a - b) <= epsilon, `${a} != ${b}`);
type Stats = Partial<Record<Stat, number>>;
type Composed = { stats: Stats; extraPulls: number[]; signatures: Set<string>; build: Build };
function compose(item: Build, primary = true, secondary = false, finishBp = 10_000): Composed {
  assert(item.affixes.length <= 4 && new Set(item.affixes).size === item.affixes.length);
  assert(item.tier >= 1 && item.tier <= 10 && finishBp >= 0 && finishBp <= 10_000);
  const stats: Stats = {};
  const extraPulls: number[] = [];
  const add = (stat: Stat, value: number) => {
    if (stat === 'extraPullBp') extraPulls.push(value / 10_000);
    else stats[stat] = (stats[stat] ?? 0) + value;
  };
  let biomeCount = 0;
  for (const id of item.affixes) {
    const row = byAffix.get(id);
    assert(row && row.kinds.includes(item.kind), `Illegal affix ${id}`);
    if (row.primary || row.secondary) biomeCount++;
    if (row.primary && !primary || row.secondary && !secondary) continue;
    for (const [stat, values] of Object.entries(row.stats)) {
      const max = values![item.tier - 1];
      const min = Math.ceil(max * 0.8);
      add(stat as Stat, min + Math.floor((max - min) * finishBp / 10_000));
    }
  }
  assert(biomeCount <= 2);
  const active = new Set(item.signatures);
  let kindPowers = 0, watersPowers = 0;
  for (const id of active) {
    const row = bySignature.get(id);
    assert(row && (row.kind === item.kind || row.kind === 'waters'), `Illegal signature ${id}`);
    if (row.kind === 'waters') watersPowers++; else kindPowers++;
    for (const [stat, value] of Object.entries(row.stats ?? {})) add(stat as Stat, value!);
    if (id === 'wayfarers_compass' && !primary) add('luckBp', 1000);
  }
  // Native gear may carry a compatible kind OR Waters signature. The two
  // library slots add one kind and one Waters power; either pool can occupy
  // the native position, but the same ID still activates only once.
  assert(kindPowers <= 2 && watersPowers <= 2 && kindPowers + watersPowers <= 3);
  if (item.innate && primary) add('luckBp', 100);
  assert(extraPulls.every(p => p >= 0 && p <= 1));
  return { stats, extraPulls, signatures: active, build: item };
}
type Pulls = { guaranteed: number; chances: number[]; mean: number; maximum: number };
function pullsFor(effects: Composed): Pulls {
  const rod = world.rods.find(row => row.rodId === effects.build.family)!;
  const quality = crafting.qualities.at(-1)!;
  const power = effects.build.kind === 'rod' ? rod.power + quality.powerBonus + (effects.stats.powerCenti ?? 0) / 100 : 0;
  const guaranteed = effects.build.kind === 'rod' ? 1 + Math.floor(power / 200) : effects.build.kind === 'boat' ? 10 : 4;
  const chances = [...effects.extraPulls];
  if (power % 200) chances.push((power % 200) / 200);
  return { guaranteed, chances, mean: guaranteed + chances.reduce((sum, p) => sum + p, 0), maximum: guaranteed + chances.filter(p => p > 0).length };
}
function pgf(pulls: Pulls, z: number) {
  return z ** pulls.guaranteed * pulls.chances.reduce((product, p) => product * (1 - p + p * z), 1);
}
function hitChance(pulls: Pulls, p: number) { return 1 - pgf(pulls, 1 - p); }
type PoolFish = { fish: CatalogFish; ranks: number[]; speciesProbability: number };
const poolCache = new Map<string, PoolFish[]>();
function poolFor(biomeId: number, luckBp: number): PoolFish[] {
  const key = `${biomeId}:${luckBp}`;
  if (poolCache.has(key)) return poolCache.get(key)!;
  const fish = speciesCatalog.species.filter(row => row.biomeId === biomeId);
  const tickets = fish.flatMap(row => row.ranks.map(rank => [BigInt(rank.encounterWeight), row.key === 'nidalees-lost-sock' ? 9 : rankNames.indexOf(rank.rarity)] as const));
  const weights = luckWeights(tickets, luckBp);
  const total = Number(weights.reduce((sum, weight) => sum + weight, 0n));
  let index = 0;
  const result = fish.map(fish => {
    const ranks = Array<number>(10).fill(0);
    for (const rank of fish.ranks) ranks[rankNames.indexOf(rank.rarity)] = Number(weights[index++]) / total;
    return { fish, ranks, speciesProbability: ranks.reduce((sum, p) => sum + p, 0) };
  });
  close(result.reduce((sum, row) => sum + row.speciesProbability, 0), 1);
  poolCache.set(key, result);
  return result;
}
function rankDistribution(original: number[], effects: Composed, knownMask: number, maximaMask: number, discovered = true, focusEnabled = true) {
  if (effects.build.kind !== 'rod' || !discovered || !focusEnabled) return [...original];
  const chance = (effects.build.reinforcement ? raidCatalog.grades[effects.build.reinforcement - 1].focusChanceBp : 2000) / 10_000
    + (effects.stats.rankFocusBp ?? 0) / 10_000;
  assert(chance <= 1);
  const missing = original.map((_, rank) => rank).filter(rank => !(knownMask & (1 << rank)) && original[rank] > 0);
  const unfinished = original.map((_, rank) => rank).filter(rank => !(maximaMask & (1 << rank)) && original[rank] > 0);
  const result = Array<number>(10).fill(0);
  for (let initial = 0; initial < 10; initial++) {
    let remaining = original[initial];
    if (!remaining) continue;
    if (effects.build.goal === 'perfects' && initial === 9) {
      result[initial] += remaining;
      continue;
    }
    const ranksGate = () => {
      if (!(knownMask & (1 << initial)) || !missing.length) return;
      const denominator = missing.reduce((sum, rank) => sum + original[rank], 0);
      for (const rank of missing) result[rank] += remaining * chance * original[rank] / denominator;
      remaining *= 1 - chance;
    };
    const maximaGate = () => {
      const targets = effects.build.goal === 'perfects' ? unfinished.filter(rank => rank === 9) : unfinished;
      if (!targets.length || targets.includes(initial)) return;
      // UUR uses the same reduced strength in either goal: changing a selector
      // must not make the "Perfects" choice worse than last-rank Maxima cleanup.
      let used = 0;
      for (const target of targets) {
        const p = (target === 9 ? effects.stats.perfectFocusBp ?? 0 : effects.stats.maximaFocusBp ?? 0) / 10_000 / targets.length;
        result[target] += remaining * p;
        used += p;
      }
      remaining *= 1 - used;
    };
    if (effects.build.goal === 'ranks') { ranksGate(); maximaGate(); }
    else { maximaGate(); ranksGate(); }
    result[initial] += remaining;
  }
  close(result.reduce((sum, p) => sum + p, 0), original.reduce((sum, p) => sum + p, 0));
  return result;
}
type Band = ReturnType<typeof rankSpreads>[number];
const bandsCache = new Map<number, Band[]>();
function bandsFor(fish: CatalogFish) {
  if (!bandsCache.has(fish.speciesId)) bandsCache.set(fish.speciesId, rankSpreads(fish, baselineLoadout));
  return bandsCache.get(fish.speciesId)!;
}
function sizeChance(fish: CatalogFish, rank: number, effects: Composed, knownMask: number, haulExtra = 0) {
  const band = bandsFor(fish).find(row => row.rarity === rankNames[rank])!;
  const window = weightRangeAtLength(fish, band.rarity, band.maxLengthMm);
  const ordinary = fish.countsForOrdinaryCollectionCompletion;
  const rod = effects.build.kind === 'rod';
  const fullAttempts = rod ? 16 + (ordinary ? 16 * (effects.stats.baitAttemptBp ?? 0) / 10_000 + (effects.stats.fullAttemptCenti ?? 0) / 100 : 0) : 1 + (ordinary ? haulExtra : 0);
  const length = ordinary && rod ? (effects.stats.lengthFinishBp ?? 0) / 10_000 : 0;
  const weight = ordinary && rod ? (effects.stats.weightFinishBp ?? 0) / 10_000 : 0;
  const natural = (length + (1 - length) / (band.maxLengthMm - band.minLengthMm + 1))
    * (weight + (1 - weight) / (window.max - window.min + 1));
  const count = Math.floor(fullAttempts);
  const fraction = fullAttempts - count;
  const survival = (attempts: number) => {
    const endpoint = (rank === 9 || attempts > 1 ? 1 / 5000 : 0)
      + (ordinary && rod ? (effects.stats.endpointPpm ?? 0) / 1_000_000 : 0);
    assert(endpoint <= 1);
    const perAttempt = endpoint + (1 - endpoint) * natural;
    return (1 - perAttempt) ** attempts;
  };
  const reinforced = ordinary && rod && rank === 9 && knownMask === fullMask
    ? raidCatalog.grades[effects.build.reinforcement - 1]?.perfectBonusRolls ?? 0 : 0;
  const oath = ordinary && rod && rank === 9 && knownMask === fullMask && effects.signatures.has('trophys_oath') ? 0.03 : 0;
  return 1 - ((1 - fraction) * survival(count) + fraction * survival(count + 1)) * (1 - 1 / 5000) ** reinforced * (1 - oath);
}
function effectiveLuck(effects: Composed) {
  const quality = crafting.qualities.at(-1)!;
  if (effects.build.kind === 'rod') return world.rods.find(row => row.rodId === effects.build.family)!.luckBp + quality.luckBp + 5000 + (effects.stats.luckBp ?? 0);
  const gear = haulCatalog.equipment.find(row => row.rodId === effects.build.family && row.kind === effects.build.kind)!;
  return gear.luckBp + quality.luckBp * gear.qualityBonusMultiplier + (effects.stats.luckBp ?? 0);
}
function sizeBoostHit(pulls: Pulls, ordinaryP: number, baseP: number, extraP: number) {
  if (extraP === 0) return hitChance(pulls, baseP);
  const denominator = ordinaryP - baseP;
  assert(denominator > 0);
  return hitChance(pulls, baseP) + extraP * (pgf(pulls, 1 - baseP) - pgf(pulls, 1 - ordinaryP)) / denominator;
}
function measure(item: Build, biomeId: number, finishBp = 10_000) {
  const effects = compose(item, true, false, finishBp);
  const stats = effects.stats;
  const pulls = pullsFor(effects);
  const luckBp = effectiveLuck(effects);
  const pool = poolFor(biomeId, luckBp);
  const biome = world.biomes.find(row => row.biomeId === biomeId)!;
  const categoryTotal = biome.fishWeight + biome.junkWeight + biome.treasureWeight;
  const fishP = biome.fishWeight / categoryTotal, junkP = biome.junkWeight / categoryTotal;
  const ordinaryP = pool.filter(row => row.fish.countsForOrdinaryCollectionCompletion).reduce((sum, row) => sum + row.speciesProbability * fishP, 0);
  const haulExtra = (stats.haulAttemptCenti ?? 0) / 100 + (effects.signatures.has('moonthread') ? 1 : 0);
  const progressBySpecies = (target: number, knownMask: number, maximaMask: number, measurements: boolean) => pool.filter(row => row.fish.countsForOrdinaryCollectionCompletion).map(row => {
    const ranks = rankDistribution(row.ranks, effects, knownMask, maximaMask);
    const rankP = ranks[target] * fishP;
    const size = measurements ? sizeChance(row.fish, target, effects, knownMask) : 1;
    const extra = measurements && haulExtra ? sizeChance(row.fish, target, effects, knownMask, haulExtra) - size : 0;
    return { speciesId: row.fish.speciesId, key: row.fish.key, chance: sizeBoostHit(pulls, ordinaryP, rankP * size, rankP * extra),
      perPullChance: rankP * size, firstOrdinaryBoostPerPull: rankP * extra };
  });
  const sum = (rows: { chance: number }[]) => rows.reduce((total, row) => total + row.chance, 0);
  const maximaByRank = steps.map((_, rank) => sum(progressBySpecies(rank, fullMask, fullMask ^ (1 << rank), true)));
  const rankCleanupBySpecies = progressBySpecies(9, fullMask ^ (1 << 9), fullMask ^ (1 << 9), false);
  const perfectBySpecies = progressBySpecies(9, fullMask, 0, true);
  const chanceOfAnyNewPerfect = sizeBoostHit(pulls, ordinaryP,
    perfectBySpecies.reduce((sum, row) => sum + row.perPullChance, 0),
    perfectBySpecies.reduce((sum, row) => sum + row.firstOrdinaryBoostPerPull, 0));
  const missingUur = sum(rankCleanupBySpecies);
  const perfectWithoutLowerMaxima = sum(perfectBySpecies);
  let fillets = 0, scales = 0, rankBaseXp = 0;
  let saleLow = 0, saleHigh = 0, fishXpLow = 0, fishXpHigh = 0;
  const xpGradeBonus = (ratio: number) => ratio >= 1.6 ? 0.25 : ratio >= 1.35 ? 0.2 : ratio >= 1.15 ? 0.1 : 0;
  for (const row of pool) {
    // Reward envelope: all ranks collected; maxima unfinished. Perfect mode
    // can steer UUR without requiring any completed lower-rank maximum.
    const ranks = row.fish.countsForOrdinaryCollectionCompletion ? rankDistribution(row.ranks, effects, fullMask, 0) : row.ranks;
    for (const saved of row.fish.ranks) {
      const rank = rankNames.indexOf(saved.rarity), p = ranks[rank] * fishP * pulls.mean;
      const band = bandsFor(row.fish).find(entry => entry.rarity === saved.rarity)!;
      const minRatio = band.minLengthMm / row.fish.typicalLengthMm, maxRatio = band.maxLengthMm / row.fish.typicalLengthMm;
      const saleAt = (length: number) => {
        const ratio = BigInt(length) * 1_000_000n / BigInt(row.fish.typicalLengthMm);
        const bounded = ratio < 600_000n ? 600_000n : ratio > 2_000_000n ? 2_000_000n : ratio;
        return Number(BigInt(saved.baseValue) * bounded / 1_000_000n);
      };
      saleLow += p * saleAt(band.minLengthMm);
      saleHigh += p * saleAt(band.maxLengthMm);
      fishXpLow += p * Math.floor(saved.baseXp * (1 + xpGradeBonus(minRatio)));
      fishXpHigh += p * Math.floor(saved.baseXp * (1 + xpGradeBonus(maxRatio)));
      if (row.fish.countsForOrdinaryCollectionCompletion) {
        fillets += p * 3 * (rank + 2);
        scales += p * 2 * (rank + 2);
        rankBaseXp += p * saved.baseXp;
      }
    }
  }
  const cache = effects.signatures.has('forgotten_cache') ? hitChance(pulls, ordinaryP) : 0;
  fillets = fillets * (1 + (stats.filletBp ?? 0) / 10_000) + cache * 6;
  scales = scales * (1 + (stats.scaleBp ?? 0) / 10_000) + cache * 4;
  const quality = crafting.qualities.at(-1)!;
  const gear = item.kind === 'rod' ? world.rods.find(row => row.rodId === item.family)!
    : haulCatalog.equipment.find(row => row.rodId === item.family && row.kind === item.kind)!;
  const gearXpBp = gear.xpBonusBp + quality.xpBonusBp * ('qualityBonusMultiplier' in gear ? gear.qualityBonusMultiplier : 1);
  const xpMultiplier = (1 + gearXpBp / 10_000) * (1 + (stats.playerXpBp ?? 0) / 10_000);
  const saleMultiplier = 1 + (stats.saleBp ?? 0) / 10_000;
  const seconds = (item.kind === 'rod' ? 60 : item.kind === 'boat' ? 600 : 240) * (1 - (stats.durationBp ?? 0) / 10_000);
  return {
    id: item.id, name: item.name, kind: item.kind, family: item.family, biomeId, finishBp,
    luckBp, pulls: pulls.mean, structuralMaxPullsWithoutDailyRift: pulls.maximum,
    seconds, fish: pulls.mean * fishP, ordinaryFish: pulls.mean * ordinaryP,
    missingUurRanksPerAction: missingUur, maximaByRank, perfectWithoutLowerMaximaPerAction: perfectWithoutLowerMaxima,
    rankCleanupBySpecies, perfectBySpecies, chanceOfAnyNewPerfect,
    normalFilletsIfProcessed: fillets, normalScalesIfProcessed: scales,
    fishSaleCoinsIfSoldBounds: [saleLow * saleMultiplier - pulls.mean, saleHigh * saleMultiplier],
    repeatFishXpBounds: [Math.max(0, fishXpLow * xpMultiplier - 3), fishXpHigh * xpMultiplier],
    ordinaryRankBaseXpBeforeSizeAndGear: rankBaseXp,
    regularXpMultiplierRelativeToExistingGear: 1 + (stats.playerXpBp ?? 0) / 10_000,
    treasureCoinsMultiplier: 1 + (stats.treasureBp ?? 0) / 10_000,
    treasureBasePayoutUnits: (pulls.mean * biome.treasureWeight / categoryTotal
      + (effects.signatures.has('gilded_bilge') ? 0.25 : effects.signatures.has('pearlbound') ? 0.5 : 0) * hitChance(pulls, biome.treasureWeight / categoryTotal))
      * (1 + (stats.treasureBp ?? 0) / 10_000),
    firstTreasureDoubleChance: effects.signatures.has('gilded_bilge') ? 0.25 : effects.signatures.has('pearlbound') ? 0.5 : 0,
    junkTinUnderProposedSpread: pulls.mean * junkP * 2 * (1 + (stats.salvageBp ?? 0) / 10_000)
      + (effects.signatures.has('rusted_crown') ? hitChance(pulls, junkP) : 0),
    flags: { excludesDailyBonuses: true, rewardsAreControlledStateNotIncomeForecast: true, processingAndSaleAreAlternatives: true },
  };
}
function combinations<T>(rows: T[], maximum: number): T[][] {
  const results: T[][] = [[]];
  const visit = (start: number, chosen: T[]) => {
    if (chosen.length === maximum) return;
    for (let index = start; index < rows.length; index++) {
      const next = [...chosen, rows[index]];
      results.push(next);
      visit(index + 1, next);
    }
  };
  visit(0, []);
  return results;
}
let assertions = 0;
function check(condition: unknown, message: string): asserts condition {
  assert(condition, message);
  assertions++;
}
check(affixes.length === 39 && new Set(affixes.map(row => row.id)).size === 39, 'Exactly 39 distinct candidate affixes');
check(signatures.length === 24 && new Set(signatures.map(row => row.id)).size === 24, 'Exactly 24 distinct signatures');
for (const row of affixes) for (const values of Object.values(row.stats)) {
  check(values!.length === 10, `Ten tiers for ${row.id}`);
  for (let tier = 0; tier < 10; tier++) check(Number.isSafeInteger(values![tier]) && values![tier] > 0
    && (!tier || values![tier] > values![tier - 1]), `Positive strictly increasing authored tiers: ${row.id}`);
}
const rodControl = compose(builds.find(row => row.id === 'legacy_rod')!);
const rodCount = pullsFor(rodControl);
close(rodCount.mean, 1.925);
for (const p of [0, 0.001, 0.2, 1]) close(hitChance(rodCount, p), p + 0.925 * (1 - p) * p);
for (const row of builds) {
  const effects = compose(row), pulls = pullsFor(effects);
  const pmf = new Map<number, number>([[pulls.guaranteed, 1]]);
  for (const chance of pulls.chances) {
    const next = new Map<number, number>();
    for (const [n, p] of pmf) {
      next.set(n, (next.get(n) ?? 0) + p * (1 - chance));
      next.set(n + 1, (next.get(n + 1) ?? 0) + p * chance);
    }
    pmf.clear(); for (const [n, p] of next) pmf.set(n, p);
  }
  for (const z of [0, 0.1, 0.9, 1]) close(pgf(pulls, z), [...pmf].reduce((sum, [n, p]) => sum + p * z ** n, 0));
  check(Math.abs([...pmf].reduce((sum, [n, p]) => sum + n * p, 0) - pulls.mean) < 1e-12, `Exact pull-count expectation: ${row.id}`);
}
const synthetic = Array<number>(10).fill(0.1);
for (const item of builds.filter(row => row.kind === 'rod')) {
  const effects = compose(item);
  for (const known of [0, fullMask ^ 1, fullMask ^ (1 << 9), fullMask]) for (const maxima of [0, fullMask ^ 1, fullMask ^ (1 << 9), fullMask]) {
    if (maxima & ~known) continue;
    const probabilities = rankDistribution(synthetic, effects, known, maxima);
    check(probabilities.every(p => p >= 0), `Nonnegative rank distribution: ${item.id}`);
    close(probabilities.reduce((sum, p) => sum + p, 0), 1);
  }
  assert.deepEqual(rankDistribution(synthetic, effects, fullMask, 0, false), synthetic);
  assert.deepEqual(rankDistribution(synthetic, effects, fullMask, 0, true, false), synthetic);
  assert.deepEqual(rankDistribution(synthetic, effects, fullMask, fullMask), synthetic);
}
const perfectEffects = compose(builds.find(row => row.id === 'perfect_hunter')!);
const maximaEffects = compose(builds.find(row => row.id === 'maxima_archivist')!);
close(rankDistribution(synthetic, perfectEffects, fullMask, 0)[9], 0.1 + 0.9 * 0.15);
close(rankDistribution(synthetic, maximaEffects, fullMask, fullMask ^ (1 << 9))[9], 0.1 + 0.9 * 0.15);
close(rankDistribution(synthetic, maximaEffects, fullMask, fullMask ^ 1)[0], 0.1 + 0.9 * 0.6);
const duplicate = compose({ ...builds.find(row => row.id === 'perfect_hunter')!, signatures: ['trophys_oath', 'trophys_oath'] });
check(duplicate.signatures.size === 1, 'Same-ID native/library signatures deduplicate');
assert.throws(() => compose({ ...builds[0], affixes: ['deep_trawler'] }));
assert.throws(() => compose({ ...builds[0], affixes: ['fortune', 'fortune'] }));
assert.throws(() => compose({ ...builds[0], signatures: ['ghost_trawler'] }));
const allBounds: Record<string, unknown> = {};
for (const kind of allKinds) {
  const candidates = affixes.filter(row => row.kinds.includes(kind));
  const sets = combinations(candidates.map(row => row.id), 4).filter(ids => ids.filter(id => byAffix.get(id)!.primary || byAffix.get(id)!.secondary).length <= 2);
  const maxima: Stats = {};
  let maximumPulls = 0, maximumPower = 0, maximumEffectiveLuck = 0;
  for (const ids of sets) for (const [primary, secondary] of [[true, false], [false, true], [false, false]]) {
    const item = build('bound', 'Boundary check', kind, ids, []);
    const effects = compose(item, primary, secondary);
    for (const [stat, value] of Object.entries(effects.stats)) maxima[stat as Stat] = Math.max(maxima[stat as Stat] ?? 0, value!);
    maximumPulls = Math.max(maximumPulls, pullsFor(effects).maximum);
    maximumPower = Math.max(maximumPower, world.rods.at(-1)!.power + crafting.qualities.at(-1)!.powerBonus + (effects.stats.powerCenti ?? 0) / 100);
    maximumEffectiveLuck = Math.max(maximumEffectiveLuck, effectiveLuck(effects) + (primary ? 0 : 1000));
  }
  const kindSignatures = signatures.filter(row => row.kind === kind).map(row => row.id);
  const watersSignatures = signatures.filter(row => row.kind === 'waters').map(row => row.id);
  const sigSets = [
    ...combinations(kindSignatures, 2).flatMap(ids => combinations(watersSignatures, 1).map(waters => [...ids, ...waters])),
    ...combinations(kindSignatures, 1).flatMap(ids => combinations(watersSignatures, 2).map(waters => [...ids, ...waters])),
  ];
  const sigMax: Stats = {};
  for (const ids of sigSets) for (const primary of [true, false]) {
    const item = build('sig_bound', 'Signature bound', kind, [], ids, 'ranks', 7, false);
    for (const [stat, value] of Object.entries(compose(item, primary).stats)) sigMax[stat as Stat] = Math.max(sigMax[stat as Stat] ?? 0, value!);
  }
  const rankFocus = 5000 + (maxima.rankFocusBp ?? 0) + (sigMax.rankFocusBp ?? 0);
  const attempts = 16 + 16 * ((maxima.baitAttemptBp ?? 0) + (sigMax.baitAttemptBp ?? 0)) / 10_000 + (maxima.fullAttemptCenti ?? 0) / 100;
  check(kind !== 'rod' || rankFocus === 9500 && attempts === 50 && maximumPower === 225, 'Rod paid bonuses fit their actual non-clipping bounds');
  check((maxima.durationBp ?? 0) + (sigMax.durationBp ?? 0) <= 1500, 'Normal duration reductions fit 15%');
  allBounds[kind] = { legalAffixSets: sets.length, maxima, sigMax, maximumEffectiveLuck, maximumPower: kind === 'rod' ? maximumPower : 0,
    maximumOrdinaryPulls: maximumPulls + 1, maximumResultsIncludingDailyRift: maximumPulls + 2,
    ...(kind === 'rod' ? { missingRankFocusBp: rankFocus, fullAttempts: attempts } : {}) };
}
const rows = world.biomes.flatMap(biome => builds.map(item => measure(item, biome.biomeId)));
const minimumRollStress = world.biomes.flatMap(biome => builds.filter(item => item.id === 'perfect_hunter' || item.id === 'rank_closer').map(item => measure(item, biome.biomeId, 0)));
for (const row of rows) {
  const legacy = rows.find(other => other.biomeId === row.biomeId && other.id === `legacy_${row.kind}`)!;
  check(row.fish >= legacy.fish || row.id === 'river_maxima', `No volume loss from proposed positive affixes: ${row.id}`);
  check(row.fishSaleCoinsIfSoldBounds[1] >= row.fishSaleCoinsIfSoldBounds[0], 'Sale envelope is ordered');
  check(row.repeatFishXpBounds[1] >= row.repeatFishXpBounds[0], 'XP envelope is ordered');
}
for (const biome of world.biomes) {
  const rod = rows.find(row => row.id === 'legacy_rod' && row.biomeId === biome.biomeId)!;
  for (const fish of speciesCatalog.species.filter(row => row.biomeId === biome.biomeId && row.countsForOrdinaryCollectionCompletion)) {
    const focus = { biomeIds: [biome.biomeId], rankMasks: { [fish.speciesId]: fullMask } };
    const loadout = { ...baselineLoadout, power: 185, luckBp: 12000, sizeRolls: 16, reinforcementLevel: 10, focus };
    close(rod.perfectBySpecies.find(row => row.speciesId === fish.speciesId)!.chance, perfectCatchOdds(fish, loadout)!.chancePerCast);
    focus.rankMasks[fish.speciesId] = fullMask ^ (1 << 9);
    close(rod.rankCleanupBySpecies.find(row => row.speciesId === fish.speciesId)!.chance, castChance(fish, 'UUR', loadout));
    assertions += 2;
  }
  const perfect = rows.find(row => row.id === 'perfect_hunter' && row.biomeId === biome.biomeId)!;
  const closer = rows.find(row => row.id === 'rank_closer' && row.biomeId === biome.biomeId)!;
  const hauls = rows.filter(row => row.kind !== 'rod' && row.biomeId === biome.biomeId);
  for (const target of perfect.perfectBySpecies) {
    const bestHaul = Math.max(...hauls.map(row => row.perfectBySpecies.find(fish => fish.speciesId === target.speciesId)!.chance));
    check(target.chance > bestHaul, `Perfect rod beats compared hauls for ${target.key}`);
    const rank = closer.rankCleanupBySpecies.find(row => row.speciesId === target.speciesId)!.chance;
    const haulRank = Math.max(...hauls.map(row => row.rankCleanupBySpecies.find(fish => fish.speciesId === target.speciesId)!.chance));
    check(rank > haulRank, `Rank rod beats compared hauls for ${target.key}'s missing UUR`);
  }
  for (const stressed of minimumRollStress.filter(row => row.biomeId === biome.biomeId)) {
    const field = stressed.id === 'perfect_hunter' ? 'perfectBySpecies' : 'rankCleanupBySpecies';
    for (const target of stressed[field]) check(target.chance > Math.max(...hauls.map(row => row[field].find(fish => fish.speciesId === target.speciesId)!.chance)),
      `Minimum-roll T10 ${stressed.id} still beats compared maximum-roll hauls for ${target.key}`);
  }
  const coinBoat = rows.find(row => row.id === 'coin_runner' && row.biomeId === biome.biomeId)!;
  const xpBoat = rows.find(row => row.id === 'xp_trawler' && row.biomeId === biome.biomeId)!;
  check(coinBoat.fishSaleCoinsIfSoldBounds[0] > perfect.fishSaleCoinsIfSoldBounds[1], 'Bulk boat sale lower bound exceeds precision rod upper bound');
  check(xpBoat.repeatFishXpBounds[0] > perfect.repeatFishXpBounds[1], 'Bulk XP boat lower bound exceeds precision rod upper bound');
}
for (const n of [1, 2, 3, 4]) {
  const p = 0.02, extra = 0.01, ordinary = 0.6;
  let beforeOrdinary = 1, afterOrdinary = 0;
  for (let i = 0; i < n; i++) {
    afterOrdinary = afterOrdinary * (1 - p) + beforeOrdinary * (ordinary - p - extra);
    beforeOrdinary *= 1 - ordinary;
  }
  close(sizeBoostHit({ guaranteed: n, chances: [], mean: n, maximum: n }, ordinary, p, extra), 1 - beforeOrdinary - afterOrdinary);
  assertions++;
}
const sample = speciesCatalog.species.find(fish => fish.key === 'carp')!;
const net = compose(builds.find(row => row.id === 'legacy_net')!);
close(sizeChance(sample, 0, net, fullMask, 0.5), (sizeChance(sample, 0, net, fullMask) + sizeChance(sample, 0, net, fullMask, 1)) / 2);
assertions++;
for (const special of speciesCatalog.species.filter(fish => !fish.countsForOrdinaryCollectionCompletion)) {
  const rank = rankNames.indexOf(special.ranks[0].rarity);
  for (const item of builds) {
    const control = compose(builds.find(row => row.id === `legacy_${item.kind}`)!);
    close(sizeChance(special, rank, compose(item), fullMask, 2), sizeChance(special, rank, control, fullMask));
    assertions++;
  }
}
const inputs = [
  'content/world.json', 'content/species.json', 'content/crafting.json',
  'content/haul-equipment.json', 'content/raids.json', 'content/size-rules.json',
  'content/game-rules.json', 'apps/web/src/lib/game/luck.ts',
  'apps/web/src/lib/game/compendium.ts', 'crates/game-rules/src/luck.rs',
  'scripts/tideforge-effect-proof.ts',
];
const hashes = Object.fromEntries(await Promise.all(inputs.map(async path => [path, createHash('sha256').update(await readFile(new URL(`../${path}`, import.meta.url))).digest('hex')])));
const result = {
  status: 'proposed-model', assertions, scope: 'Local analytic model, not gameplay activation or production forecasts',
  inputs: hashes, affixes, signatures, builds, bounds: allBounds, rows, minimumRollStress,
  limitations: [
    'All ordinary species naturally discovered; Focus unlocked/enabled; Prismatic quality; rod R10 and two Trophy Chums.',
    'All shown slots unlocked; primary affinity fitted to each water independently; maxima use T10 maximum rolls except the explicit 80%-minimum stress rows.',
    'Each progress metric is one fresh controlled state. Do not multiply into completion dates; recorded targets disappear.',
    'Rank cleanup: only UUR missing per species. Maxima cleanup: only the named rank maximum missing, all ranks collected.',
    'Perfect chase: no lower maxima completed, all ranks collected. Species selection always remains random.',
    'Reward envelopes hold all ranks collected and no maxima completed. No daily rewards, discovery XP, bait costs, treasury income or actual wallet forecast.',
    'Material expectations use the unapproved processing table and retained fractional credit; sale and processing are alternative uses.',
    'Junk uses the separately proposed uniform one-to-three spread. Compare this with its own control, not legacy fixed-one junk.',
    'Duplicate-species rerolls have the same distribution when every ordinary species is already discovered; first-ordinary measurement extras are accounted exactly.',
  ],
};
await writeFile(new URL('../.local/tideforge-effect-results.json', import.meta.url), `${JSON.stringify(result, null, 2)}\n`);
console.log(`PASS ${assertions} checks; ${affixes.length} ten-tier affix grids; ${signatures.length} signature contracts; ${rows.length} complete-build/water cases.`);
console.table(rows.filter(row => row.biomeId === 7).map(row => ({
  build: row.name, luck: row.luckBp, pulls: row.pulls, fish: row.ordinaryFish.toFixed(3),
  ranks: row.missingUurRanksPerAction.toFixed(5), fMax: row.maximaByRank[0].toFixed(5),
  uurMax: row.maximaByRank[9].toFixed(5), perfect: row.perfectWithoutLowerMaximaPerAction.toFixed(5),
  fillets: row.normalFilletsIfProcessed.toFixed(1), scales: row.normalScalesIfProcessed.toFixed(1),
})));
