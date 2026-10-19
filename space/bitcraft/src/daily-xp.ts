// Calendar records share the retained collection sample store, but not its
// six-hour rate window. All calendar arithmetic uses explicit Central offsets.
export const DAILY_XP_PREFIX = "dailyXp|";
export const DAILY_XP_RETENTION_MS = 35 * 86400000;
const BOUNDARY_GRACE_MS = 120000;
type Xp = Record<string, number>;
export interface DailyXp {
  day: string;
  firstAt: number;
  lastAt: number;
  opening: Xp;
  closing: Xp;
  startCovered: boolean;
  endCovered: boolean;
}
function centralOffset(at: number) {
  const year = new Date(at).getUTCFullYear();
  const sunday = (month: number, nth: number) =>
    1 +
    ((7 - new Date(Date.UTC(year, month, 1)).getUTCDay()) % 7) +
    (nth - 1) * 7;
  const start = Date.UTC(year, 2, sunday(2, 2), 8);
  const end = Date.UTC(year, 10, sunday(10, 1), 7);
  return at >= start && at < end ? -5 : -6;
}
export function centralDay(at: number) {
  return new Date(at + centralOffset(at) * 3600000).toISOString().slice(0, 10);
}
function shiftDay(day: string, amount: number) {
  return new Date(Date.parse(day + "T12:00:00Z") + amount * 86400000)
    .toISOString()
    .slice(0, 10);
}
function dayStart(day: string) {
  const midnight = Date.parse(day + "T00:00:00Z");
  // Central midnight precedes the 02:00 DST change on transition days.
  return midnight - centralOffset(midnight + 6 * 3600000) * 3600000;
}
export function advanceDailyXp(
  previous: DailyXp | undefined,
  at: number,
  xp: Xp,
) {
  if (
    !Object.keys(xp).length ||
    Object.entries(xp).some(
      ([id, value]) =>
        !/^\d+$/.test(id) || !Number.isSafeInteger(value) || value < 0,
    )
  )
    return null;
  if (
    previous &&
    (at <= previous.lastAt ||
      Object.entries(previous.closing).some(
        ([id, value]) => xp[id] == null || xp[id] < value,
      ))
  )
    return null;
  const day = centralDay(at);
  if (previous?.day === day)
    return {
      current: { ...previous, lastAt: at, closing: { ...xp } },
      finished: undefined,
    };
  const covered = Boolean(
    previous &&
    previous.day === shiftDay(day, -1) &&
    at - previous.lastAt <= BOUNDARY_GRACE_MS &&
    at - dayStart(day) <= BOUNDARY_GRACE_MS,
  );
  return {
    // A boundary sampled within two minutes is approximate, never exact.
    finished:
      previous && covered
        ? {
            ...previous,
            closing: { ...xp },
            lastAt: at,
            endCovered: true,
          }
        : undefined,
    current: {
      day,
      firstAt: at,
      lastAt: at,
      opening: { ...xp },
      closing: { ...xp },
      startCovered: covered,
      endCovered: false,
    } satisfies DailyXp,
  };
}
export function dailyXpSummary(days: DailyXp[], now: number) {
  const today = centralDay(now),
    yesterday = shiftDay(today, -1);
  const weekday = new Date(today + "T12:00:00Z").getUTCDay();
  const weekStart = shiftDay(today, -((weekday + 6) % 7));
  function period(start: string, end: string, label: string) {
    const rows = days
      .filter((row) => row.day >= start && row.day <= end)
      .sort((a, b) => a.day.localeCompare(b.day));
    const xp: Xp = {};
    for (const row of rows)
      for (const [id, opening] of Object.entries(row.opening))
        xp[id] =
          (xp[id] ?? 0) + Math.max(0, (row.closing[id] ?? opening) - opening);
    const expectedDays =
      Math.round((Date.parse(end) - Date.parse(start)) / 86400000) + 1;
    const partial =
      rows.length !== expectedDays ||
      rows.some(
        (row) => !row.startCovered || (row.day !== today && !row.endCovered),
      );
    return {
      label,
      start,
      end,
      xp,
      partial,
      available: rows.length > 0,
      firstAt: rows[0]?.firstAt ?? null,
      lastAt: rows[rows.length - 1]?.lastAt ?? null,
    };
  }
  return {
    timeZone: "America/Chicago",
    playerDay: today,
    periods: [
      period(today, today, "Today"),
      period(yesterday, yesterday, "Yesterday"),
      period(weekStart, today, "This Week"),
    ],
  };
}
export function recordDailyXp(ctx: any, scope: string, at: bigint, xp: Xp) {
  const dailyScope = DAILY_XP_PREFIX + scope;
  const rows = [...ctx.db.collectionSample.scope.filter(dailyScope)];
  // The closing row and next day's opening can share an observation timestamp.
  // ISO day keys choose the new day's baseline on that tie.
  const latest = rows.sort((a, b) => b.key.localeCompare(a.key))[0];
  if (latest && at / 60000000n === latest.observedAt / 60000000n) return;
  const next = advanceDailyXp(
    latest ? JSON.parse(latest.payload) : undefined,
    Number(at / 1000n),
    xp,
  );
  if (!next) return;
  for (const value of [next.finished, next.current]) {
    if (!value) continue;
    const key = `${dailyScope}|${value.day}`;
    const row = {
      key,
      scope: dailyScope,
      source: "relay",
      epoch: "daily-v1",
      observedAt: BigInt(value.lastAt) * 1000n,
      payload: JSON.stringify(value),
    };
    if (ctx.db.collectionSample.key.find(key))
      ctx.db.collectionSample.key.update(row);
    else ctx.db.collectionSample.insert(row);
  }
}
