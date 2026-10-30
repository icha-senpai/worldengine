import type { HaulTrip } from '@fishing-game/generated/types';

export function runningHaulDeadline(trips: readonly Pick<HaulTrip, 'kind' | 'status' | 'startedAt' | 'dueAt'>[], kind: 'boat' | 'net'): bigint | null {
  let deadline: bigint | null = null;
  let started = 0n;
  for (const trip of trips) {
    if (trip.kind !== kind || trip.status !== 'running') continue;
    if (deadline === null || trip.startedAt.microsSinceUnixEpoch > started) {
      started = trip.startedAt.microsSinceUnixEpoch;
      deadline = trip.dueAt.microsSinceUnixEpoch;
    }
  }
  return deadline;
}

export function formatCooldownSeconds(seconds: number, minutes = false): string {
  if (minutes && seconds >= 86_400) {
    const days = Math.floor(seconds / 86_400);
    const hours = Math.floor((seconds % 86_400) / 3_600);
    return `${days}d ${hours}h`;
  }
  if (minutes && seconds >= 3_600) {
    const hours = Math.floor(seconds / 3_600);
    const remainingMinutes = Math.floor((seconds % 3_600) / 60);
    return `${hours}h ${remainingMinutes}m`;
  }
  if (minutes && seconds >= 60) {
    const whole = Math.floor(seconds / 60);
    return `${whole}m ${String(seconds % 60).padStart(2, '0')}s`;
  }
  return `${seconds}s`;
}
