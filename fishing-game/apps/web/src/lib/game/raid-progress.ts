export const RAID_GRADE_COUNT = 10;

export type RaidProgressRow = {
  playerId: bigint;
  speciesId: number;
  clearedGrades: number;
};

export function clearedGradeCount(value: number | null | undefined) {
  return Math.max(0, Math.min(RAID_GRADE_COUNT, value ?? 0));
}

export function gradeCleared(clearedGrades: number | null | undefined, grade: number) {
  return Number.isInteger(grade) && grade >= 0 && grade < clearedGradeCount(clearedGrades);
}

export function nextSelectableGrade(clearedGrades: number | null | undefined) {
  return Math.min(clearedGradeCount(clearedGrades), RAID_GRADE_COUNT - 1);
}

export function gradeSelectable(clearedGrades: number | null | undefined, grade: number) {
  return Number.isInteger(grade) && grade >= 0 && grade <= nextSelectableGrade(clearedGrades);
}

export function hasOrdinaryRaidClearForGrade(
  badges: readonly RaidProgressRow[],
  ordinarySpeciesIds: ReadonlySet<number>,
  playerId: bigint | null | undefined,
  grade: number
) {
  return playerId !== null && playerId !== undefined
    && badges.some(row => row.playerId === playerId && ordinarySpeciesIds.has(row.speciesId)
      && clearedGradeCount(row.clearedGrades) > grade);
}

export function totalOrdinaryRaidSeals(
  badges: readonly RaidProgressRow[],
  ordinarySpeciesIds: ReadonlySet<number>,
  playerId: bigint | null | undefined
) {
  if (playerId === null || playerId === undefined) return 0;
  return badges.filter(row => row.playerId === playerId && ordinarySpeciesIds.has(row.speciesId))
    .reduce((total, row) => total + clearedGradeCount(row.clearedGrades), 0);
}
