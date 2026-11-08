import economy from '../../../../../content/tideforge-economy.json';

const hourMs = 3_600_000;
const dayMs = 24 * hourMs;

export type TraderVisit = { opensAt: number; closesAt: number };
export type TraderSchedule = {
  open: boolean;
  current: TraderVisit | null;
  next: TraderVisit;
  visits: TraderVisit[];
};

export function wanderingTraderSchedule(serverMs: number | null): TraderSchedule | null {
  if (serverMs === null || !Number.isFinite(serverMs) || serverMs < 0 || serverMs > 8.64e15 - 2 * dayMs) return null;
  const midnight = Math.floor(serverMs / dayMs) * dayMs;
  const { utcStartHours, durationHours } = economy.acquisition.wandering;
  const candidates = [0, 1].flatMap(day => utcStartHours.map(hour => {
    const opensAt = midnight + day * dayMs + hour * hourMs;
    return { opensAt, closesAt: opensAt + durationHours * hourMs };
  }));
  const current = candidates.find(visit => visit.opensAt <= serverMs && serverMs < visit.closesAt) ?? null;
  const next = candidates.find(visit => visit.opensAt > serverMs)!;
  return { open: current !== null, current, next, visits: candidates.filter(visit => visit.closesAt > serverMs).slice(0, 4) };
}

export function formatTraderTime(timestampMs: number, locale?: string, timeZone?: string): string {
  return new Intl.DateTimeFormat(locale, {
    timeZone, weekday: 'short', month: 'short', day: 'numeric',
    hour: 'numeric', minute: '2-digit', timeZoneName: 'short',
  }).format(timestampMs);
}
