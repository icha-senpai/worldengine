import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { writeFile } from 'node:fs/promises';
import catalog from '../content/species.json';
import world from '../content/world.json';
import { luckWeights, type LuckTicket } from '../apps/web/src/lib/game/luck';
import { requireRustWorkflow } from './rust-build.mjs';

requireRustWorkflow();
const ranks = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];
const levels = [0, 12_000, 26_600, 26_601, 33_200, 35_000, 40_000, 100_000, 1_000_000, 0xffff_ffff];
const cases: { name: string; tickets: [string, number][]; luckBp: number; weights: string[] }[] = [];
const summaries: Record<string, unknown>[] = [];
const rarityChecks = new Map<number, {
  ordinary: { numerator: bigint; denominator: bigint }[];
  fihs?: { numerator: bigint; denominator: bigint };
  sock?: { numerator: bigint; denominator: bigint };
}>();
const sum = (values: readonly bigint[]) => values.reduce((total, value) => total + value, 0n);
const add = (name: string, tickets: readonly LuckTicket[], luckBp: number, weights: bigint[]) =>
  cases.push({ name, tickets: tickets.map(([weight, score]) => [String(weight), score]), luckBp, weights: weights.map(String) });

for (const biome of world.biomes) {
  const entries = catalog.species.filter(fish => fish.biomeId === biome.biomeId).flatMap(fish => fish.ranks.map(rank => ({
    key: fish.key, rarity: rank.rarity, weight: BigInt(rank.encounterWeight),
    ordinary: fish.countsForOrdinaryCollectionCompletion,
    score: fish.key === 'nidalees-lost-sock' ? 9 : ranks.indexOf(rank.rarity),
  })));
  const tickets = entries.map(row => [row.weight, row.score] as const);
  const total = sum(entries.map(row => row.weight));
  const moment = sum(entries.map(row => row.weight * BigInt(row.score)));
  const base = (9n * total - moment) * 10_000n;
  assert((base - 1n) / moment >= 26_600n, 'Every existing biome must retain the full legacy range');
  const legacy = (luck: number) => entries.map(row => row.weight * (base + BigInt(luck) * (BigInt(row.score) * total - moment)));
  const anchor = legacy(26_600), anchorTotal = sum(anchor);
  const anchorF = sum(anchor.filter((_, index) => entries[index].score === 0));
  let previous: { upper: bigint[]; total: bigint } | undefined;
  const points: Record<string, number>[] = [];
  for (const luck of levels) {
    const weights = luckWeights(tickets, luck), poolTotal = sum(weights);
    assert(weights.every(weight => weight > 0n));
    assert(poolTotal <= (1n << 128n) - 1n);
    if (luck <= 26_600) assert.deepEqual(weights, legacy(luck), 'Exact legacy vector, not approximate probabilities');
    if (luck === 0xffff_ffff) assert(poolTotal > (1n << 64n) - 1n, 'Actually exercise wide sampling');
    const upper = ranks.map((_, rank) => sum(weights.filter((_, index) => entries[index].score >= rank)));
    if (previous) {
      const last = previous;
      upper.forEach((value, rank) => assert(value * last.total >= last.upper[rank] * poolTotal, 'Exact upper-rank mass must be nondecreasing'));
    }
    previous = { upper, total: poolTotal };
    if (luck > 26_600) {
      for (const score of [9]) {
        const reference = entries.findIndex(row => row.score === score);
        if (reference < 0) continue;
        weights.forEach((weight, index) => {
          if (entries[index].score === score) assert.equal(weight * anchor[reference], anchor[index] * weights[reference]);
        });
      }
      const currentF = sum(weights.filter((_, index) => entries[index].score === 0));
      assert(currentF * 10n * anchorTotal >= anchorF * poolTotal, 'The high-Luck tail cannot extinguish rank F');
    }
    if (biome.biomeId === 7) {
      const fihs = entries.findIndex(row => row.key === 'fihs');
      const sock = entries.findIndex(row => row.key === 'nidalees-lost-sock');
      const rarest = weights.filter((_, index) => entries[index].ordinary && entries[index].rarity === 'UUR')
        .reduce((low, weight) => weight < low ? weight : low);
      assert.equal(weights[fihs] * 2n, rarest);
      assert.equal(weights[sock] * 2n, weights[fihs]);
    }
    const ordinaryUur = sum(weights.filter((_, index) => entries[index].ordinary && entries[index].rarity === 'UUR'));
    const check = rarityChecks.get(luck) ?? { ordinary: [] };
    entries.forEach((entry, index) => {
      const probability = { numerator: weights[index] * BigInt(biome.fishWeight), denominator: poolTotal * 100n };
      if (entry.ordinary) check.ordinary.push(probability);
      if (entry.key === 'fihs') check.fihs = probability;
      if (entry.key === 'nidalees-lost-sock') check.sock = probability;
    });
    rarityChecks.set(luck, check);
    const chance = Number(ordinaryUur) / Number(poolTotal);
    assert(Number.isFinite(chance) && chance > 0 && chance < 1);
    points.push({ luckBp: luck, ordinaryUurShareOfFish: chance });
    add(`${biome.biomeId}:${luck}`, tickets, luck, weights);
  }
  summaries.push({ biomeId: biome.biomeId, name: biome.name, points });
}

for (const check of rarityChecks.values()) {
  assert(check.fihs && check.sock);
  const { fihs, sock } = check;
  assert.equal(fihs.numerator * sock.denominator, 2n * sock.numerator * fihs.denominator);
  for (const ordinary of check.ordinary) {
    assert(ordinary.numerator * fihs.denominator > fihs.numerator * ordinary.denominator,
      'Every ordinary species/rank in every water must remain more common than Fihs at equal Luck');
  }
}
const pole = [[1n, 0], [1n, 9]] as const;
assert.deepEqual(luckWeights(pole, 9_999), [169_998n, 189_996n]);
assert.deepEqual(luckWeights(pole, 10_000), [170_000n, 190_000n]);
for (const luck of [9_999, 10_000, 26_600, 0xffff_ffff]) add(`early-pole:${luck}`, pole, luck, luckWeights(pole, luck));
const same = [[0n, 0], [2n, 4], [1n, 4]] as const;
const forced = [[1_000n, 9], [1n, 0]] as const;
assert.deepEqual(luckWeights(forced, 0), [90_000_000n, 90_000n]);
for (const luck of [0, 9, 10, 26_600, 0xffff_ffff]) {
  const weights = luckWeights(forced, luck);
  assert(weights.every(weight => weight > 0n));
  add(`forced-rank:${luck}`, forced, luck, weights);
}
assert.deepEqual(luckWeights(same, 26_601), [0n, 30_001_500_000n, 15_000_750_000n]);
for (const luck of [0, 26_601, 0xffff_ffff]) {
  const weights = luckWeights(same, luck);
  assert.equal(weights[0], 0n);
  assert.equal(weights[1], 2n * weights[2]);
  add(`same-score:${luck}`, same, luck, weights);
}
for (const invalid of [-1, 0.5, Number.NaN, Number.POSITIVE_INFINITY, 0x1_0000_0000]) {
  assert.throws(() => luckWeights(pole, invalid));
}
for (const invalid of [[], [[0n, 0]], [[1n, 9]], [[1n, 10]], [[(1n << 64n) - 1n, 4]]] as readonly LuckTicket[][]) {
  assert.throws(() => luckWeights(invalid, 0));
}
const fixture = '.local/uncapped-luck-vectors.json';
await writeFile(fixture, JSON.stringify(cases));
const rust = spawnSync('cargo', ['run', '-p', 'game-rules', '--example', 'luck-proof', '--locked', '--', fixture], {
  encoding: 'utf8', windowsHide: true,
});
if (rust.error) throw rust.error;
assert.equal(rust.status, 0, rust.stderr || rust.stdout);
console.log(rust.stdout.trim());
await writeFile('.local/luck-parity-evidence.json', JSON.stringify({ status: 'passed', exactVectorCases: cases.length, summaries }, null, 2));
console.log('PASS positive pools, exact cumulative-rank monotonicity, no extinct F rank, exception hierarchy, legacy compatibility and wide-weight safety through u32::MAX.');
