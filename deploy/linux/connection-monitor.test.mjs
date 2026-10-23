import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cleanProxyRecord, targets } from './connection-monitor.mjs';

test('proxy capture strips credentials and derives a safe actual connection interval', () => {
  const row = cleanProxyRecord({ type: 'nginx-websocket', channel: 'bitcraft', endedAtSeconds: '1792717200.125', durationSeconds: '12.5', status: '101', connectionId: 'a'.repeat(32), cfRay: '123456789abcdef0-ORD', peerPort: '44321', token: 'PRIVATE', uri: '/?token=PRIVATE', remote_addr: 'PRIVATE' });
  assert.equal(row.endedAtMs - row.startedAtMs, 12500);
  assert.equal(row.status, 101);
  assert.equal(row.peerPort, 44321);
  assert.ok(!JSON.stringify(row).includes('PRIVATE'));
  assert.equal(cleanProxyRecord({ ...row, endedAtSeconds: 1e15 }), null);
  assert.equal(cleanProxyRecord({ ...row, endedAtSeconds: null }), null);
  assert.equal(cleanProxyRecord({ ...row, endedAtSeconds: '1792717200', durationSeconds: -1 }), null);
});
test('untrusted UDP records cannot introduce arbitrary log fields or channels', () => {
  assert.equal(cleanProxyRecord({ type: 'nginx-websocket', channel: 'secret' }), null);
  assert.equal(cleanProxyRecord({ type: 'tcp-control', serverPort: 22, flag: 'fin', direction: 'inbound' }), null);
  assert.deepEqual(cleanProxyRecord({ type: 'tcp-control', serverPort: 443, flag: 'reset', direction: 'inbound', peerPort: 33333, token: 'PRIVATE' }), { type: 'tcp-control', at: undefined, serverPort: 443, peerPort: 33333, flag: 'reset', direction: 'inbound', family: undefined });
});
test('independent route matrix separates public address families, origin and database', () => {
  assert.equal(targets().length, 12);
  assert.equal(targets(true).length, 9);
  assert.ok(targets().filter(t => t.route === 'origin').every(t => t.address === '127.0.0.1'));
  assert.ok(targets(true).filter(t => t.route === 'origin').every(t => t.address === '5.189.187.85'));
});
