import assert from 'node:assert/strict';
import { ServerClock, remainingCooldownSeconds } from '../apps/web/src/lib/game/server-clock.ts';

let monotonic = 1000;
const clock = new ServerClock(() => monotonic);
assert.equal(clock.now(), null, 'An unsynchronized clock must not claim a cast is ready');
assert.equal(remainingCooldownSeconds(60_000_000n, clock.now()), null);

// The browser clock may be arbitrarily wrong; only server and monotonic time
// participate. A 200ms round trip estimates 100ms of return transit.
clock.synchronize(1_000_000, 800, 1000);
assert.equal(clock.now(), 1_000_100);
const deadline = 1_060_000_000n;
assert.equal(remainingCooldownSeconds(deadline, clock.now()), 60);
monotonic += 1500;
assert.equal(remainingCooldownSeconds(deadline, clock.now()), 59);
assert.equal(remainingCooldownSeconds(BigInt((clock.now() + 60_000) * 1000), clock.now()), 60,
  'A fresh cast uses the current time between timer ticks');
monotonic += 90_000;
assert.equal(remainingCooldownSeconds(deadline, clock.now()), 0, 'Delayed ticks catch up immediately');

// Refresh the anchor after a suspended tab resumes.
clock.synchronize(2_000_000, monotonic - 100, monotonic);
assert.equal(remainingCooldownSeconds(2_060_000_000n, clock.now()), 60);
assert.equal(remainingCooldownSeconds(null, clock.now()), null);
assert.throws(() => clock.synchronize(NaN, 0, 100));
console.log('PASS server time, latency compensation, delayed ticks, resume and unknown state.');
