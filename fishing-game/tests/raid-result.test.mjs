import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { formatRaidDuration, raidResultSummary } from '../apps/web/src/lib/game/raid-result.ts';

const at = n => Object.freeze({ microsSinceUnixEpoch: BigInt(n) * 1000000n });
const fish = Object.freeze({ speciesId: 1, name: 'Minnow', spriteAsset: '/fish/koi.png' });
const fihs = Object.freeze({ speciesId: 74, name: 'Fihs', spriteAsset: '/fish/fihs.png' });
const sock = Object.freeze({ speciesId: 165, name: 'Nidalees Lost Sock', spriteAsset: '/fish/nidalees-lost-sock.png' });
const event = Object.freeze({ eventId: 4n, name: 'Big Expedition' });
const specimen = Object.freeze({ catchId: 123n, playerId: 7n, speciesId: 74, rarity: 'UUR', lengthMm: 500, weightG: 1200n });
const attempt = (changes = {}) => Object.freeze({
  id: 1n, playerId: 7n, kind: 'personal', status: 'completed', speciesId: 1, grade: 0,
  boatName: 'Old boat', durationSeconds: 5400, supplyUnits: 3, preparedAt: at(1),
  startsAt: at(2), endsAt: at(3), deployedAt: at(2), settledAt: at(4),
  baseFillets: 30n, baseScales: 40n, fullFillets: 100n, fullScales: 120n,
  awardedFillets: 17n, awardedScales: 23n, ...changes
});
const summarize = (row, inventory = []) => raidResultSummary(row, [fish, fihs, sock], [event], inventory);

test('personal results use the saved grade, exact awards and deployment marker', () => {
  const f = summarize(attempt());
  assert.equal(f.targetName, 'Minnow');
  assert.equal(f.gradeLabel, 'F');
  assert.equal(f.statusLabel, 'Completed');
  assert.equal(f.fillets, 17n);
  assert.equal(f.scales, 23n);
  assert.equal(f.suppliesConsumed, 3);
  assert.equal(summarize(attempt({ grade: 9 })).gradeLabel, 'UUR');
  assert.equal(summarize(attempt({ grade: undefined })).gradeLabel, 'Unknown grade');
  assert.equal(summarize(attempt({ grade: 10 })).gradeLabel, 'Unknown grade');
  assert.equal(summarize(attempt({ grade: -1 })).gradeLabel, 'Unknown grade');
  assert.equal(summarize(attempt({ deployedAt: undefined })).suppliesConsumed, 0);
  assert.equal('firstClear' in f, false);
  assert.equal('perfect' in f, false);
});

test('booked duration preserves minutes, hours, days and seconds', () => {
  for (const [seconds, expected] of [[1800, '30m'], [5400, '1h 30m'], [54000, '15h'], [108000, '1d 6h'], [3661, '1h 1m 1s'], [0, '0s']]) {
    assert.equal(formatRaidDuration(seconds), expected);
  }
  const row = attempt({ durationSeconds: 1800, startsAt: at(10), endsAt: at(20) });
  assert.equal(formatRaidDuration(row.durationSeconds), '30m');
  assert.equal(row.startsAt.microsSinceUnixEpoch, 10000000n);
  assert.equal(row.endsAt.microsSinceUnixEpoch, 20000000n);
});

test('withdrawal, cancellation and missed deployment have honest outcomes', () => {
  const withdrawn = summarize(attempt({ status: 'cancelled', awardedFillets: 7n, awardedScales: 4n }));
  assert.equal(withdrawn.statusLabel, 'Withdrawn');
  assert.match(withdrawn.explanation, /partial materials/);
  assert.equal(withdrawn.fillets, 7n);
  assert.equal(withdrawn.scales, 4n);
  assert.equal(withdrawn.suppliesConsumed, 3);
  const partialWithoutMarker = summarize(attempt({ status: 'cancelled', deployedAt: undefined, awardedFillets: 7n, awardedScales: 4n }));
  assert.equal(partialWithoutMarker.statusLabel, 'Withdrawn');
  assert.equal(partialWithoutMarker.suppliesConsumed, 0);
  const empty = summarize(attempt({ status: 'cancelled', awardedFillets: 0n, awardedScales: 0n }));
  assert.match(empty.explanation, /without rewards/);
  const cancelled = summarize(attempt({ status: 'cancelled', deployedAt: undefined, awardedFillets: 0n, awardedScales: 0n }));
  assert.equal(cancelled.statusLabel, 'Cancelled');
  assert.equal(cancelled.suppliesConsumed, 0);
  const missed = summarize(attempt({ status: 'missed', deployedAt: undefined, awardedFillets: 0n, awardedScales: 0n }));
  assert.equal(missed.statusLabel, 'Missed');
  assert.match(missed.explanation, /deployment window was missed/i);
});

test('community resolves the event and never produces a grade or catch', () => {
  const complete = summarize(attempt({ kind: 'community', eventId: 4n, speciesId: undefined }));
  assert.equal(complete.targetName, 'Big Expedition');
  assert.equal(complete.gradeLabel, undefined);
  assert.equal(complete.caughtFish, undefined);
  assert.equal(complete.speciesAsset, undefined);
  assert.equal(summarize(attempt({ kind: 'community', eventId: 99n })).targetName, 'Community expedition');
  assert.equal(summarize(attempt({ kind: 'community', status: 'missed' })).statusLabel, 'Missed');
});

test('special snapshots and exact ownership determine available catch details', () => {
  const row = attempt({ kind: 'special', speciesId: 74, catchId: 123n,
    specialSpecies: Object.freeze({ ...fihs, name: 'Saved Fihs', spriteAsset: '/fish/saved.png' }),
    specialRank: Object.freeze({ rarity: 'UUR' }), specialBand: 0 });
  const saved = summarize(row, [specimen]);
  assert.equal(saved.targetName, 'Saved Fihs');
  assert.equal(saved.speciesAsset, '/fish/saved.png');
  assert.equal(saved.gradeLabel, undefined);
  assert.equal(saved.caughtFish.rarity, 'UUR');
  assert.equal(saved.caughtFish.specimen, specimen);
  assert.equal(summarize(row).caughtFish.specimen, undefined);
  for (const wrong of [
    { ...specimen, catchId: 999n }, { ...specimen, playerId: 8n }, { ...specimen, speciesId: 165 }
  ]) assert.equal(summarize(row, [wrong]).caughtFish.specimen, undefined);
  const snapshotOnlySpecies = attempt({ ...row, speciesId: undefined, specialSpecies: sock });
  assert.equal(summarize(snapshotOnlySpecies, [specimen]).caughtFish.specimen, undefined);
  const sockRow = attempt({ kind: 'special', speciesId: 165, catchId: 555n, specialBand: 0 });
  assert.equal(summarize(sockRow).caughtFish.rarity, 'F');
  assert.equal(summarize(attempt({ ...sockRow, specialBand: 15 })).caughtFish.rarity, undefined);
  assert.equal(summarize(attempt({ ...sockRow, specialBand: undefined })).caughtFish.rarity, undefined);
  assert.equal(summarize(attempt({ ...sockRow, catchId: undefined })).caughtFish, undefined);
  assert.equal(summarize(attempt({ ...sockRow, status: 'cancelled' })).caughtFish, undefined);
  assert.equal(summarize(attempt({ kind: 'special', speciesId: 999 })).targetName, 'Unknown raid target');
});

test('history viewing is read-only and active due-claim remains available', () => {
  const source = readFileSync(new URL('../apps/web/src/lib/game/RaidBoard.svelte', import.meta.url), 'utf8');
  const history = source.slice(source.indexOf('<details class="raid-history">'), source.indexOf('</details>', source.indexOf('<details class="raid-history">')));
  assert.match(history, /onclick=\{\(\) => resultAttempt = attempt\}>View result/);
  assert.doesNotMatch(history, /claim\(|claimRaid|runRaidIntent|!game\.ready|game\.busy/);
  assert.match(source, /onclick=\{\(\) => claim\(attempt\)\}>Claim due rewards/);
});
