import assert from 'node:assert/strict';
import { test } from 'node:test';
import { formatTraderTime, wanderingTraderSchedule } from '../apps/web/src/lib/game/wandering-trader.ts';

const hour = 3_600_000;
const day = 24 * hour;

test('daily UTC windows include their start and exclude their end on every date', () => {
  for (const midnight of [0, Date.UTC(2026, 10, 7), Date.UTC(2026, 11, 31), Date.UTC(2028, 1, 29)]) {
    for (const offset of [0, 6, 12, 18]) {
      const start = midnight + offset * hour, close = start + 2 * hour;
      if (start > 0) assert.equal(wanderingTraderSchedule(start - 1).open, false);
      for (const now of [start, start + hour, close - 1]) {
        const schedule = wanderingTraderSchedule(now);
        assert.equal(schedule.open, true);
        assert.deepEqual(schedule.current, { opensAt: start, closesAt: close });
        assert.deepEqual(schedule.visits[0], schedule.current);
        assert.equal(schedule.next.opensAt, start + 6 * hour);
      }
      assert.equal(wanderingTraderSchedule(close).open, false);
      assert.equal(wanderingTraderSchedule(close + hour).open, false);
    }
    const schedule = wanderingTraderSchedule(midnight + day - 1);
    assert.equal(schedule.open, false);
    assert.equal(schedule.next.opensAt, midnight + day);
    assert.deepEqual(schedule.visits.map(visit => visit.opensAt), [0, 6, 12, 18].map(offset => midnight + day + offset * hour));
  }
});

test('unknown or invalid server time fails closed', () => {
  for (const value of [null, NaN, Infinity, -1, 8.64e15]) assert.equal(wanderingTraderSchedule(value), null);
});

test('display formatting honors local dates, fractional offsets and daylight saving changes', () => {
  const midnight = Date.UTC(2026, 10, 7);
  const local = formatTraderTime(midnight, 'en-US', 'America/Los_Angeles');
  assert.match(local, /Fri, Nov 6/);
  assert.match(local, /4:00 PM PST/);
  assert.match(formatTraderTime(midnight, 'en-US', 'Asia/Kolkata'), /Sat, Nov 7.*5:30 AM GMT\+5:30/);
  const dst = wanderingTraderSchedule(Date.UTC(2026, 2, 8, 6));
  assert.match(formatTraderTime(dst.current.opensAt, 'en-US', 'America/New_York'), /1:00 AM EST/);
  assert.match(formatTraderTime(dst.current.closesAt, 'en-US', 'America/New_York'), /4:00 AM EDT/);
  assert.equal(dst.current.closesAt - dst.current.opensAt, 2 * hour);
});
