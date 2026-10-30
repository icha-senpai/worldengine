import test from 'node:test';
import assert from 'node:assert/strict';
import {
  clearedGradeCount,
  gradeCleared,
  gradeSelectable,
  hasOrdinaryRaidClearForGrade,
  nextSelectableGrade,
  totalOrdinaryRaidSeals
} from '../apps/web/src/lib/game/raid-progress.ts';

test('raid badges encode a sequential clear count, not a bit mask', () => {
  for (const count of [0, 1, 2, 3, 10]) {
    assert.equal(clearedGradeCount(count), count);
    assert.equal(nextSelectableGrade(count), Math.min(count, 9));
    for (let grade = 0; grade < 10; grade++) {
      assert.equal(gradeCleared(count, grade), grade < count);
      assert.equal(gradeSelectable(count, grade), grade <= Math.min(count, 9));
    }
  }
  assert.equal(nextSelectableGrade(3), 3, 'C clear count 3 unlocks B');
  assert.equal(totalOrdinaryRaidSeals([
    { playerId: 1n, speciesId: 1, clearedGrades: 3 },
    { playerId: 1n, speciesId: 2, clearedGrades: 10 },
    { playerId: 1n, speciesId: 74, clearedGrades: 10 },
    { playerId: 2n, speciesId: 3, clearedGrades: 10 },
  ], new Set([1, 2, 3]), 1n), 13);
});

test('refining and reinforcement require some ordinary clear beyond the target grade', () => {
  const ordinary = new Set([1, 2]);
  const badges = [
    { playerId: 7n, speciesId: 1, clearedGrades: 3 },
    { playerId: 7n, speciesId: 74, clearedGrades: 10 },
  ];
  assert.equal(hasOrdinaryRaidClearForGrade(badges, ordinary, 7n, 2), true);
  assert.equal(hasOrdinaryRaidClearForGrade(badges, ordinary, 7n, 3), false);
  assert.equal(hasOrdinaryRaidClearForGrade(badges, ordinary, 7n, 9), false);
});
