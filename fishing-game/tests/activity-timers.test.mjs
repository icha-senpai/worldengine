import assert from 'node:assert/strict';
import { runningHaulDeadline, formatCooldownSeconds } from '../apps/web/src/lib/game/activity-timers.ts';

const at = micros => ({ microsSinceUnixEpoch: BigInt(micros) });
const trip = (kind, status, startedAt, dueAt, interactionId = 0n) => ({ interactionId, kind, status, startedAt: at(startedAt), dueAt: at(dueAt) });

assert.equal(runningHaulDeadline([], 'boat'), null, 'no trips gives no boat deadline');
assert.equal(runningHaulDeadline([], 'net'), null, 'no trips gives no net deadline');

const independent = [trip('boat', 'running', 100, 160_000), trip('net', 'running', 200, 260_000)];
assert.equal(runningHaulDeadline(independent, 'boat'), 160_000n, 'boat running deadline returned');
assert.equal(runningHaulDeadline(independent, 'net'), 260_000n, 'net running deadline returned independently');

const history = [trip('boat', 'ready', 50, 150_000), trip('boat', 'delivered', 60, 999_000)];
assert.equal(runningHaulDeadline(history, 'boat'), null, 'ready/delivered history ignored');
assert.equal(runningHaulDeadline(history, 'net'), null, 'boat history does not leak into net');

const unsorted = [
  trip('boat', 'running', 300, 400_000, 99n),
  trip('boat', 'running', 900, 800_000, 2n),
  trip('net', 'running', 500, 600_000, 50n),
];
const copy = [...unsorted];
assert.equal(runningHaulDeadline(unsorted, 'boat'), 800_000n, 'latest startedAt wins regardless of row order or id');
assert.deepEqual(unsorted, copy, 'input array is not mutated');
assert.equal(runningHaulDeadline(unsorted, 'net'), 600_000n, 'boat rows ignored for net');
assert.equal(runningHaulDeadline([trip('wagon', 'running', 100, 200_000)], 'boat'), null, 'unknown kinds ignored');

assert.equal(formatCooldownSeconds(600, true), '10m 00s');
assert.equal(formatCooldownSeconds(545, true), '9m 05s');
assert.equal(formatCooldownSeconds(3_660, true), '1h 1m');
assert.equal(formatCooldownSeconds(86_400, true), '1d 0h');
assert.equal(formatCooldownSeconds(172_800, true), '2d 0h');
assert.equal(formatCooldownSeconds(3_599, true), '59m 59s');
assert.equal(formatCooldownSeconds(60, true), '1m 00s');
assert.equal(formatCooldownSeconds(59, true), '59s');
assert.equal(formatCooldownSeconds(1, true), '1s');
assert.equal(formatCooldownSeconds(60), '60s', 'plain format keeps cast style');

console.log('PASS activity timers deadlines and cooldown formatting.');
