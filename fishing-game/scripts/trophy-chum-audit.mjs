import fs from 'node:fs';
import { createHash } from 'node:crypto';
const load = (name) => JSON.parse(fs.readFileSync('content/' + name + '.json'));
const rules = load('game-rules');
const c = load('species'),
  w = load('world'),
  craft = load('crafting'),
  s = load('size-rules');
const option = (flag, fallback) => { const i = process.argv.indexOf(flag); return i < 0 ? fallback : Number(process.argv[i + 1]); };
const rod = w.rods.find((x) => x.rodId === option('--rod', 7)),
  quality = craft.qualities.find((x) => x.qualityLevel === option('--quality', 6)),
  trophy = craft.baits.find((x) => x.baitId === 13),
  baseline = craft.baits.find((x) => x.baitId === 5);
const bonus = (rod.power + quality.powerBonus) / 200,
  luck = (rod.luckBp + quality.luckBp + trophy.luckBp) / 10000,
  baselineLuck = (rod.luckBp + quality.luckBp + baseline.luckBp) / 10000;
const ceil = (t, f) => Number((BigInt(t) * BigInt(f) + 999999n) / 1000000n);
function wt(f, l, condition) {
  const d = BigInt(f.typicalLengthMm) ** 3n * 1000000n;
  return Math.max(
    1,
    Math.floor(f.typicalWeightG / 8),
    Math.min(
      f.typicalWeightG * 8,
      Number((BigInt(f.typicalWeightG) * BigInt(l) ** 3n * BigInt(condition) + d / 2n) / d),
    ),
  );
}
function bounds(f, rank) {
  const i = s.tiers.findIndex((x) => x.rarity === rank.rarity),
    r = s.tiers[i],
    n = s.tiers[i + 1];
  return {
    lo: Math.max(
      Math.floor((f.typicalLengthMm * 55) / 100),
      ceil(f.typicalLengthMm, r.minimumLengthMillionths),
    ),
    hi: Math.min(
      Math.floor((f.typicalLengthMm * 19) / 10),
      n ? ceil(f.typicalLengthMm, n.minimumLengthMillionths) - 1 : Infinity,
    ),
    floor: ceil(f.typicalWeightG, r.minimumWeightMillionths),
  };
}
function perfect(f) {
  const rank = f.ranks.at(-1),
    b = bounds(f, rank);
  const low = Math.max(wt(f, b.hi, 880000), b.floor),
    high = Math.max(low, wt(f, b.hi, 1120000));
  return {
    bandWeight: rank.encounterWeight,
    conditionalOneIn: (b.hi - b.lo + 1) * (high - low + 1),
    lengthMm: b.hi,
    weightGrams: high,
  };
}
const endpointChance = 1 / rules.perfectRollOneIn;
const singlePerfect = m => endpointChance + (1 - endpointChance) / m.conditionalOneIn;
const perfectAfterRolls = (m, rolls) => -Math.expm1(rolls * Math.log1p(-singlePerfect(m)));
let seed = 901733;
const rand = () => {
  seed ^= seed << 13;
  seed ^= seed >>> 17;
  seed ^= seed << 5;
  return (seed >>> 0) / 4294967296;
};
function fishEconomy(pool, rolls, luck) {
  const entries = pool.flatMap((f) =>
    f.ranks.map((rank) => ({
      f,
      rank,
      score:
        f.key === 'nidalees-lost-sock' ? 9 : s.tiers.findIndex((x) => x.rarity === rank.rarity),
    })),
  );
  const total = entries.reduce((sum, e) => sum + e.rank.encounterWeight, 0),
    moment = entries.reduce((sum, e) => sum + e.rank.encounterWeight * e.score, 0),
    distance = 9 * total - moment;
  let weightTotal = 0;
  for (const e of entries) {
    weightTotal += e.rank.encounterWeight * (1 + (luck * (e.score * total - moment)) / distance);
    e.cdf = weightTotal;
  }
  let value = 0;
  for (let draw = 0; draw < 50000; draw++) {
    const ticket = rand() * weightTotal;
    let lo = 0,
      hi = entries.length - 1;
    while (lo < hi) {
      const mid = (lo + hi) >> 1;
      if (entries[mid].cdf > ticket) hi = mid;
      else lo = mid + 1;
    }
    const { f, rank } = entries[lo],
      b = bounds(f, rank);
    let best = { l: 0, w: 0 };
    for (let i = 0; i < rolls; i++) {
      if (rank.rarity === 'UUR' && rand() < endpointChance) {
        const m = perfect(f); best = { l: m.lengthMm, w: m.weightGrams }; continue;
      }
      const l = b.lo + Math.floor(rand() * (b.hi - b.lo + 1));
      const min = Math.max(wt(f, l, 880000), b.floor),
        max = Math.max(min, wt(f, l, 1120000));
      const weight = min + Math.floor(rand() * (max - min + 1));
      if (weight > best.w || (weight === best.w && l > best.l)) best = { l, w: weight };
    }
    value += Math.floor(
      rank.baseValue * (Math.floor((best.l * 1000000) / f.typicalLengthMm) / 1000000),
    );
  }
  return value / 50000;
}
const results = w.biomes.map((b) => {
  const all = c.species.filter((f) => f.biomeId === b.biomeId),
    targets = all.filter(f => f.key !== 'nidalees-lost-sock'),
    total = all.reduce((sum, f) => sum + f.encounterWeight, 0),
    allTotal = all.reduce((sum, f) => sum + f.encounterWeight, 0),
    category = b.fishWeight / (b.fishWeight + b.junkWeight + b.treasureWeight);
  const rows = targets.map((f) => {
    const m = perfect(f),
      conditional = perfectAfterRolls(m, trophy.sizeRolls),
      p = ((category * (1 + luck) * m.bandWeight) / total) * conditional,
      bp = (category * (1 + baselineLuck) * m.bandWeight) / allTotal * perfectAfterRolls(m, 1);
    return {
      key: f.key,
      ...m,
      oneInCasts: 1 / (p * (1 + bonus * (1 - p))),
      baselineOneInCasts: 1 / (bp * (1 + bonus * (1 - bp))),
    };
  });
  const p = targets.reduce((sum, f) => {
    const m = perfect(f);
    return (
      sum +
      ((category * (1 + luck) * m.bandWeight) / total) *
        perfectAfterRolls(m, trophy.sizeRolls)
    );
  }, 0);
  const baselinePerfect = all.filter(f => f.key !== 'nidalees-lost-sock').reduce((sum, f) => {
    const m = perfect(f);
    return sum + category * (1 + baselineLuck) * m.bandWeight / allTotal * perfectAfterRolls(m, 1);
  }, 0);
  const treasureIncome = (((1 + bonus) * b.treasureWeight) / 100) * 42.5;
  const economy = [
    ['Moonlit Fireflies', all, 1, baseline.priceCoins / baseline.usesPerPurchase, baselineLuck],
    ['Trophy Chum', all, trophy.sizeRolls, trophy.priceCoins / trophy.usesPerPurchase, luck],
  ].map(([bait, pool, rolls, cost, baitLuck]) => {
    const gross = (1 + bonus) * category * fishEconomy(pool, rolls, baitLuck) + treasureIncome;
    return {
      bait,
      samples: 50000,
      estimatedGrossCoinsPerCast: gross,
      costPerCast: cost,
      estimatedNetCoinsPerCast: gross - cost,
    };
  });
  return {
    biome: b.name,
    eligibleSpecies: targets.map((f) => f.key),
    baselineAnyPerfectOneInCasts: 1 / (baselinePerfect * (1 + bonus * (1 - baselinePerfect))),
    anyPerfectOneInCasts: 1 / (p * (1 + bonus * (1 - p))),
    targets: rows,
    economy,
  };
});
const report = {
  date: new Date().toISOString(),
  versions: { rules: rules.version, world: w.version, species: c.version, crafting: craft.version },
  loadout: `${quality.name} ${rod.name}; compare Moonlit Fireflies versus Trophy Chum`,
  luckBp: luck * 10000,
  baselineLuckBp: baselineLuck * 10000,
  costPerUse: trophy.priceCoins / trophy.usesPerPurchase,
  rodId: rod.rodId, qualityLevel: quality.qualityLevel,
  bonusPull: bonus,
  rolls: trophy.sizeRolls,
  perfectRollOneIn: rules.perfectRollOneIn,
  assumptions:
    'Fixed loadout, exact integer size bounds, no pity. Exact perfect probabilities; 50,000 seeded conditional fish samples per bait/biome for estimated sale value. Sell every catch; include expected treasure coins; exclude discovery/daily/achievement income, travel, other spending and unlocking time. Both baits use the complete encounter pool. Each UUR size attempt has a 1-in-5,000 chance of both exact maxima; natural perfects also count. Fihs qualifies; fake Sock never does.',
  hashes: Object.fromEntries(
    ['game-rules', 'species', 'world', 'crafting', 'size-rules'].map((name) => [
      name,
      createHash('sha256')
        .update(fs.readFileSync('content/' + name + '.json'))
        .digest('hex'),
    ]),
  ),
  results,
};
const output = process.argv[2] ?? '.local/trophy-chum-final-audit.json';
fs.writeFileSync(output, JSON.stringify(report, null, 2) + '\n');
console.log(
  JSON.stringify(
    results.map((r) => ({
      biome: r.biome,
      species: r.eligibleSpecies.length,
      perfectOneIn: Math.round(r.anyPerfectOneInCasts),
      economy: r.economy.map((x) => ({
        bait: x.bait,
        gross: +x.estimatedGrossCoinsPerCast.toFixed(2),
        net: +x.estimatedNetCoinsPerCast.toFixed(2),
      })),
    })),
    null,
    2,
  ),
);
