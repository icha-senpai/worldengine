import type { CommunityRaidEvent, OwnedSpecimen, RaidAttempt, SpeciesDefinition } from '@fishing-game/generated/types';

const ranks = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];

export function formatRaidDuration(seconds: number): string {
  let remaining = Number.isFinite(seconds) ? Math.max(0, Math.trunc(seconds)) : 0;
  const parts: string[] = [];
  for (const [unit, suffix] of [[86400, 'd'], [3600, 'h'], [60, 'm'], [1, 's']] as const) {
    const amount = Math.floor(remaining / unit);
    if (amount) parts.push(`${amount}${suffix}`);
    remaining %= unit;
  }
  return parts.join(' ') || '0s';
}

export function raidResultSummary(
  attempt: RaidAttempt,
  species: readonly SpeciesDefinition[],
  events: readonly CommunityRaidEvent[],
  inventory: readonly OwnedSpecimen[]
) {
  const fish = (attempt.kind === 'special' ? attempt.specialSpecies : undefined)
    ?? species.find(row => row.speciesId === attempt.speciesId);
  const targetName = attempt.kind === 'community'
    ? events.find(row => row.eventId === attempt.eventId)?.name || 'Community expedition'
    : fish?.name || 'Unknown raid target';
  const kindLabel = attempt.kind === 'personal' ? 'Personal raid'
    : attempt.kind === 'special' ? 'Special encounter'
      : attempt.kind === 'community' ? 'Community expedition' : 'Raid';
  const gradeLabel = attempt.kind === 'personal'
    ? (attempt.grade !== undefined && Number.isInteger(attempt.grade) ? ranks[attempt.grade] : undefined) ?? 'Unknown grade'
    : undefined;
  const partial = attempt.kind === 'personal' && attempt.status === 'cancelled'
    && (attempt.awardedFillets > 0n || attempt.awardedScales > 0n);
  const statusLabel = attempt.status === 'completed' ? 'Completed'
    : attempt.status === 'missed' ? 'Missed'
      : attempt.status === 'cancelled' ? (attempt.deployedAt || partial ? 'Withdrawn' : 'Cancelled')
        : 'Unknown outcome';
  const explanation = attempt.status === 'completed'
    ? 'This expedition completed. The saved result is shown below.'
    : attempt.status === 'missed'
      ? 'The deployment window was missed. No completion rewards were awarded.'
      : attempt.status === 'cancelled'
        ? partial ? 'The withdrawal settled the partial materials shown below.'
          : attempt.deployedAt ? 'The expedition was withdrawn without rewards.' : 'Preparation was cancelled without rewards.'
        : 'The outcome is unavailable.';
  const rank = attempt.specialRank?.rarity;
  const savedRank = rank && ranks.includes(rank) ? rank
    : attempt.specialBand !== undefined && Number.isInteger(attempt.specialBand) ? ranks[attempt.specialBand] : undefined;
  const caughtSpeciesId = attempt.speciesId ?? attempt.specialSpecies?.speciesId;
  const caughtFish = attempt.kind === 'special' && attempt.status === 'completed' && attempt.catchId !== undefined
    ? {
        catchId: attempt.catchId,
        rarity: savedRank,
        specimen: inventory.find(row => row.catchId === attempt.catchId && row.playerId === attempt.playerId
          && (caughtSpeciesId === undefined || row.speciesId === caughtSpeciesId))
      }
    : undefined;
  return {
    targetName,
    speciesAsset: attempt.kind === 'community' ? undefined : fish?.spriteAsset || undefined,
    kindLabel,
    gradeLabel,
    statusLabel,
    explanation,
    fillets: attempt.awardedFillets,
    scales: attempt.awardedScales,
    suppliesConsumed: attempt.deployedAt ? attempt.supplyUnits : 0,
    caughtFish
  };
}
