import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import catalog from '../content/species.json' with { type: 'json' };
import { caughtAtRank, matchesBookFilters, ownedDiscoveries } from '../apps/web/src/lib/game/fish-book-ui.ts';
import { rankMaximaSummary } from '../apps/web/src/lib/game/rank-maxima.ts';
import { guideResourceLabel } from '../apps/web/src/lib/game/tideforge-cost-guide.ts';

test('shared filters match canonical keys and names for catalog and subscribed fish', () => {
  const fish = catalog.species.find(row => row.key.includes('-') && row.name.includes(' '));
  assert(fish);
  const { speciesId, name, biomeId, allowedRarities } = fish;
  const subscribed = { speciesId, name, biomeId, allowedRarities };
  assert.equal('key' in subscribed, false);
  for (const shape of [fish, subscribed]) {
    assert.equal(matchesBookFilters(shape, `  ${fish.key.toUpperCase()}  `, 0, ''), true);
    assert.equal(matchesBookFilters(shape, fish.key.replaceAll('-', ' '), biomeId, allowedRarities[0]), true);
    assert.equal(matchesBookFilters(shape, `  ${name.slice(0, 4).toUpperCase()}  `, 0, ''), true);
    assert.equal(matchesBookFilters(shape, '   ', 0, ''), true);
    assert.equal(matchesBookFilters(shape, fish.key, biomeId + 1000, ''), false);
    assert.equal(matchesBookFilters(shape, fish.key, 0, 'not-a-rank'), false);
    assert.equal(matchesBookFilters(shape, 'not-a-fish', 0, ''), false);
  }
});

test('collection progress counts positive rows only for the current owner', () => {
  const rows = [
    { playerId: 9n, speciesId: 1, count: 0n, rankCounts: [9n] },
    { playerId: 9n, speciesId: 2, count: 2n, rankCounts: [1n] },
    { playerId: 10n, speciesId: 3, count: 7n, rankCounts: [7n] },
  ];
  assert.deepEqual(ownedDiscoveries(rows, 9n).map(row => row.speciesId), [2]);
  assert.deepEqual(ownedDiscoveries(rows, undefined), []);
  assert.equal(caughtAtRank(rows[1], 'F'), 1n);
  assert.equal(caughtAtRank(rows[1], 'UUR'), 0n);
  assert.equal(caughtAtRank(undefined, 'F'), 0n);
  assert.equal(caughtAtRank(rows[1], 'unknown'), 0n);
});

test('bonus identities and paired rank maxima remain distinct from UUR perfects', () => {
  const fihs = catalog.species.find(row => row.speciesId === 74);
  const sock = catalog.species.find(row => row.speciesId === 165);
  assert.equal(fihs.name, 'Fihs'); assert.equal(sock.name, 'Nidalees Lost Sock');
  assert.equal(fihs.countsForOrdinaryCollectionCompletion, false);
  assert.equal(sock.countsForOrdinaryCollectionCompletion, false);
  const species = [{ speciesId: 1, allowedRarities: ['F', 'UUR'] }];
  const summary = rankMaximaSummary([
    { playerId: 9n, speciesId: 1, rarity: 'F' },
    { playerId: 10n, speciesId: 1, rarity: 'UUR' },
  ], species, 9n);
  assert.equal(summary.count, 1); assert.equal(summary.fullyMaxedSpecies, 0);
  assert(summary.marks.has('1:F')); assert(!summary.marks.has('1:UUR'));
});

test('collection renders subscribed perfects and safe unknown resource names', async () => {
  const source = await readFile(new URL('../apps/web/src/lib/game/CollectionProgress.svelte', import.meta.url), 'utf8');
  assert.match(source, /game\.perfectSpecies\.filter/);
  assert.match(source, /ownedDiscoveries\(game\.collection, game\.player\?\.playerId\)/);
  assert.doesNotMatch(source, /bestLengthMm\s*===|bestWeightG\s*===/);
  assert.match(source, /Fihs and Nidalees Lost Sock/);
  assert.equal(guideResourceLabel({ kind: 'material', item: '<internal>', slot: 42, amount: 1n }), 'Unknown material');
  assert.equal(guideResourceLabel({ kind: 'shard', affixId: '<internal>', amount: 1n }), 'Unknown affix shards');
});
