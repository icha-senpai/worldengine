import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { baselineLoadout, castChance, perfectCatchOdds, rankMaximumOdds } from '../apps/web/src/lib/game/compendium.ts';

const catalog = JSON.parse(await readFile(new URL('../content/species.json', import.meta.url))).species;
const raids = JSON.parse(await readFile(new URL('../content/raids.json', import.meta.url)));
const ranks = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];
const close = (actual, expected) => assert(
  Math.abs(actual - expected) <= Math.max(1e-18, Math.abs(expected) * 1e-10),
  `${actual} != ${expected}`,
);

// Enumerate each selected-rank branch and each possible missing-rank reroll.
// This deliberately does not use the production pullBands implementation.
function branches(fish, base, mask, proc) {
  const selected = fish.ranks.map(row => ({
    rank: row.rarity,
    p: castChance(fish, row.rarity, { ...base, power: 0, focus: undefined, reinforcementLevel: 0 }),
  }));
  const missing = selected.filter(row => !(mask & (1 << ranks.indexOf(row.rank))));
  const missingMass = missing.reduce((sum, row) => sum + row.p, 0);
  const outcomes = [];
  for (const row of selected) {
    if (!missingMass || !(mask & (1 << ranks.indexOf(row.rank)))) outcomes.push(row);
    else {
      outcomes.push({ ...row, p: row.p * (1 - proc) });
      for (const replacement of missing) {
        outcomes.push({ rank: replacement.rank, p: row.p * proc * replacement.p / missingMass });
      }
    }
  }
  return outcomes;
}

function enumerate(fish, loadout, mask, target, maximum) {
  const grade = loadout.reinforcementLevel ? raids.grades[loadout.reinforcementLevel - 1] : undefined;
  const proc = (grade?.focusChanceBp ?? 2000) / 10_000;
  const baseSize = maximum
    ? rankMaximumOdds(fish, target, { ...loadout, focus: undefined, reinforcementLevel: 0 }).sizeChance
    : 1;
  const sizeAt = known => {
    if (!maximum || target !== 'UUR' || known !== 1023 || !grade) return baseSize;
    return 1 - (1 - baseSize) * (1 - 1 / 5000) ** grade.perfectBonusRolls;
  };
  const first = branches(fish, loadout, mask, proc);
  const mass = first.reduce((sum, row) => sum + row.p, 0);
  let probability = 0;
  for (const outcome of [{ rank: 'other', p: 1 - mass }, ...first]) {
    const firstHit = outcome.rank === target ? sizeAt(mask) : 0;
    const nextMask = outcome.rank === 'other' ? mask : mask | (1 << ranks.indexOf(outcome.rank));
    const secondHit = branches(fish, loadout, nextMask, proc)
      .filter(row => row.rank === target)
      .reduce((sum, row) => sum + row.p * sizeAt(nextMask), 0);
    probability += outcome.p * (firstHit + (1 - firstHit) * loadout.power / 200 * secondHit);
  }
  return probability;
}

test('reinforced rank and perfect odds match independent branch enumeration', () => {
  let checked = 0;
  for (const fish of catalog.filter(row => row.countsForOrdinaryCollectionCompletion)) {
    for (const reinforcementLevel of [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]) {
      for (const mask of [0, 85, 511, 1023]) {
        for (const [power, sizeRolls] of [[0, 1], [200, 16]]) {
          const loadout = {
            ...baselineLoadout,
            rulesVersion: 17,
            power,
            sizeRolls,
            reinforcementLevel,
            focus: { biomeIds: [fish.biomeId], rankMasks: { [fish.speciesId]: mask } },
          };
          for (const target of ['F', 'UUR']) {
            close(castChance(fish, target, loadout), enumerate(fish, loadout, mask, target, false));
            close(rankMaximumOdds(fish, target, loadout).chancePerCast, enumerate(fish, loadout, mask, target, true));
            checked += 2;
          }
          const single = { ...loadout, power: 0 };
          close(
            fish.ranks.reduce((sum, row) => sum + castChance(fish, row.rarity, single), 0),
            castChance(fish, undefined, single),
          );
        }
      }
    }
  }
  console.log(`Verified ${checked} reinforced rank/maximum probabilities across all 249 ordinary species.`);
});

test('reinforcement is ignored before rules 17, outside Focus, and for exceptional fish', () => {
  for (const fish of catalog) {
    const base = { ...baselineLoadout, rulesVersion: 16, power: 163, sizeRolls: 16,
      focus: { biomeIds: [fish.biomeId], rankMasks: { [fish.speciesId]: 1023 } } };
    for (const rarity of fish.allowedRarities) {
      const old = rankMaximumOdds(fish, rarity, base);
      assert.deepEqual(rankMaximumOdds(fish, rarity, { ...base, reinforcementLevel: 10 }), old);
      const off = { ...base, rulesVersion: 17, focus: undefined };
      assert.deepEqual(
        rankMaximumOdds(fish, rarity, { ...off, reinforcementLevel: 10 }),
        rankMaximumOdds(fish, rarity, off),
      );
      if (!fish.countsForOrdinaryCollectionCompletion) {
        assert.deepEqual(
          rankMaximumOdds(fish, rarity, { ...base, rulesVersion: 17, reinforcementLevel: 10 }),
          rankMaximumOdds(fish, rarity, { ...base, rulesVersion: 17, reinforcementLevel: 0 }),
        );
      }
    }
  }
  const sock = catalog.find(row => row.key === 'nidalees-lost-sock');
  assert.equal(perfectCatchOdds(sock), null);
});

test('the second pull can gain mastery after the first fills the last missing rank', () => {
  const fish = catalog.find(row => row.countsForOrdinaryCollectionCompletion);
  const loadout = { ...baselineLoadout, rulesVersion: 17, power: 200, sizeRolls: 1,
    reinforcementLevel: 10, focus: { biomeIds: [fish.biomeId], rankMasks: { [fish.speciesId]: 511 } } };
  const reinforced = perfectCatchOdds(fish, loadout);
  const unreinforced = perfectCatchOdds(fish, { ...loadout, reinforcementLevel: 0 });
  close(reinforced.sizeChance, unreinforced.sizeChance);
  assert(reinforced.chancePerCast > unreinforced.chancePerCast);
  close(reinforced.chancePerCast, enumerate(fish, loadout, 511, 'UUR', true));
});
