import assert from 'node:assert/strict';
import { createDevLogin } from '../apps/web/src/lib/server/dev-login.ts';
import { parseDevPlayerId, runAccountLink } from '../apps/web/src/lib/server/dev-login-runtime.ts';

const IDENTITY = 'c2002230cb292d622fb9716c64c8c73f84b1934e4eb6e6db546fb271ab32e488';
const ORIGIN = 'https://fishbound.test';
const calls = { link: [], identity: 0 };
const deps = (overrides = {}) => ({
  development: true,
  uri: 'wss://fishbound.test',
  database: 'fishbound-dev-local',
  readIdentity: async () => { calls.identity++; return IDENTITY; },
  link: async (challengeId, proof) => { calls.link.push([challengeId, proof]); },
  ...overrides,
});
const post = (url = ORIGIN + '/api/dev-login', body = { challengeId: '7', proof: '42' }, headers = {}) =>
  new Request(url, { method: 'POST', headers: { 'content-type': 'application/json', origin: new URL(url).origin, ...headers }, body: typeof body === 'string' ? body : JSON.stringify(body) });
const streamPost = (url, stream, headers = {}) =>
  new Request(url, { method: 'POST', headers: { 'content-type': 'application/json', origin: new URL(url).origin, ...headers }, body: stream, duplex: 'half' });
const reset = () => { calls.link = []; calls.identity = 0; };

reset();
assert.equal((await createDevLogin(deps({ development: false }))(post())).status, 404, 'production must hide the endpoint');
assert.equal(calls.identity, 0, 'production never touches dependencies');
assert.equal((await createDevLogin(deps({ uri: 'ws://other' }))(post())).status, 404, 'wrong URI hides endpoint');
assert.equal((await createDevLogin(deps({ database: 'other' }))(post())).status, 404, 'wrong database hides endpoint');
assert.equal(calls.identity, 0, 'config gates precede dependencies');
assert.equal((await createDevLogin(deps())(post('https://evil.test/api/dev-login'))).status, 404, 'foreign origins get 404');
assert.equal((await createDevLogin(deps())(post('http://fishbound.test:9999/api/dev-login'))).status, 404, 'arbitrary proxy ports get 404');
assert.equal((await createDevLogin(deps())(post('ftp://fishbound.test/api/dev-login'))).status, 404, 'foreign schemes get 404');
assert.equal((await createDevLogin(deps())(post('http://127.0.0.1:5174/api/dev-login'))).status, 204, 'loopback dev origin allowed');
assert.equal((await createDevLogin(deps())(post('http://localhost:5174/api/dev-login'))).status, 204, 'localhost dev origin allowed');

reset();
const ok = createDevLogin(deps());
assert.equal((await ok(new Request(ORIGIN + '/api/dev-login', { method: 'GET' }))).status, 405, 'non-POST rejected');
assert.equal((await ok(post(ORIGIN + '/api/dev-login', {}, { origin: 'https://evil.test' }))).status, 403, 'mismatched Origin header rejected');
assert.equal((await ok(new Request(ORIGIN + '/api/dev-login', { method: 'POST', headers: { 'content-type': 'application/json' }, body: '{}' }))).status, 403, 'missing Origin rejected');
assert.equal((await ok(post(ORIGIN + '/api/dev-login', {}, { 'sec-fetch-site': 'cross-site' }))).status, 403, 'cross-site fetch rejected');
const proxyPost = post('http://fishbound.test/api/dev-login', { challengeId: '7', proof: '42' }, { origin: 'https://fishbound.test' });
assert.equal((await ok(proxyPost)).status, 204, 'nginx-proxied https page over http upstream allowed');
assert.equal((await ok(post('http://fishbound.test/api/dev-login', { challengeId: '7', proof: '42' }, { origin: 'http://fishbound.test' }))).status, 403, 'plain http origin on proxy host rejected');
assert.equal(calls.link.length, 1, 'only valid requests reach link so far');

const bodies = [
  ['text/plain', 'not json', 'wrong content type'],
  ['application/jsonp', { challengeId: '7', proof: '42' }, 'jsonp content type'],
  ['application/json', '{oops', 'invalid JSON'],
  ['application/json', { challengeId: '7', proof: '42', playerId: '1' }, 'extra keys'],
  ['application/json', { challengeId: '7' }, 'missing proof'],
  ['application/json', { challengeId: '-7', proof: '42' }, 'negative challengeId'],
  ['application/json', { challengeId: '7.5', proof: '42' }, 'fraction challengeId'],
  ['application/json', { challengeId: '7e3', proof: '42' }, 'exponent challengeId'],
  ['application/json', { challengeId: '', proof: '42' }, 'empty challengeId'],
  ['application/json', { challengeId: '007', proof: '42' }, 'leading zeros challengeId'],
  ['application/json', { challengeId: '7', proof: '00' }, 'leading zeros proof'],
  ['application/json', { challengeId: '18446744073709551616', proof: '42' }, 'challengeId over u64'],
  ['application/json', { challengeId: '7', proof: '340282366920938463463374607431768211456' }, 'proof over u128'],
];
for (const [type, body, label] of bodies) {
  assert.equal((await ok(post(ORIGIN + '/api/dev-login', body, { 'content-type': type }))).status, 400, `400 for ${label}`);
}
reset();
const before = calls.identity;
let streamCancelled = false;
let pushed = false;
const oversized = new ReadableStream({
  pull(controller) { if (!pushed) { pushed = true; controller.enqueue(new TextEncoder().encode('{"challengeId":"7","proof":"42"}' + 'x'.repeat(2000))); } },
  cancel() { streamCancelled = true; },
});
const streamResult = await ok(streamPost(ORIGIN + '/api/dev-login', oversized));
await new Promise(resolve => setTimeout(resolve, 10));
assert.equal(streamResult.status, 400, 'chunked oversize body rejected');
assert.equal(streamCancelled, true, 'oversize stream is cancelled');
assert.equal(calls.identity, before, 'oversize body rejected before identity lookup');
assert.equal(calls.link.length, 0, 'oversize body never links');

const padded = JSON.stringify({ challengeId: '7', proof: '42' });
const exactBody = padded + ' '.repeat(1024 - padded.length);
assert.equal(exactBody.length, 1024);
assert.equal((await ok(post(ORIGIN + '/api/dev-login', exactBody, { 'content-length': '1024' }))).status, 204, 'exact-limit body accepted');
assert.equal((await ok(post(ORIGIN + '/api/dev-login', { challengeId: '7', proof: '42' }, { 'content-length': '2048' }))).status, 400, 'declared oversize content-length rejected early');

assert.equal((await ok(post(ORIGIN + '/api/dev-login', { challengeId: '0', proof: '0' }))).status, 204, 'zero bounds accepted');
assert.equal((await ok(post(ORIGIN + '/api/dev-login', { challengeId: '18446744073709551615', proof: '340282366920938463463374607431768211455' }))).status, 204, 'u64/u128 max accepted');

reset();
const mismatch = createDevLogin(deps({ readIdentity: async () => 'other-identity' }));
assert.equal((await mismatch(post())).status, 503, 'identity mismatch returns generic 503');
assert.equal(calls.link.length, 0, 'mismatch never links');
const rejectedIdentity = createDevLogin(deps({ readIdentity: async () => { throw new Error('secret ' + IDENTITY); } }));
const rejectedResponse = await rejectedIdentity(post());
assert.equal(rejectedResponse.status, 503, 'rejected identity returns 503, never throws');
assert.equal(await rejectedResponse.text(), '', 'rejected identity body is empty');
assert.equal(calls.link.length, 0, 'rejected identity never links');
const secretError = new Error('secret detail ' + IDENTITY + ' token=abc');
const failing = createDevLogin(deps({ link: async () => { calls.link.push([1n]); throw secretError; } }));
const failedResponse = await failing(post());
assert.equal(failedResponse.status, 503, 'link failures return generic 503, not framework 500');
assert.equal(await failedResponse.text(), '', 'link failure body is empty');
const headerDump = JSON.stringify([...failedResponse.headers.entries()]);
assert.ok(!headerDump.includes('secret'), 'failures never echo error text');
assert.equal(failedResponse.headers.get('cache-control'), 'no-store', 'failures are uncacheable');

reset();
assert.deepEqual(calls.link.length === 0, true);
await ok(post());
assert.deepEqual(calls.link, [[7n, 42n]], 'exact BigInts reach link');
const response = await ok(post());
assert.equal(await response.text(), '', '204 has no body');
assert.equal(response.headers.get('cache-control'), 'no-store', 'responses are uncacheable');

const wrap = (rows) => `[{"schema":{"elements":[{"name":{"some":"discord_user_id"}}]},"rows":${rows},"total_duration_micros":176,"stats":{}}]`;
assert.equal(parseDevPlayerId(wrap('[[9007199254740993]]')), 9007199254740993n, 'unsafe integer retained exactly as bigint');
assert.equal(parseDevPlayerId(wrap('[[123456789012345678]]')), 123456789012345678n, 'snowflake-shaped integer retained exactly');
assert.equal(parseDevPlayerId(wrap('[["123456789012345678"]]')), 123456789012345678n, 'decimal string accepted');
assert.equal(parseDevPlayerId(wrap('[[42]]')), 42n, 'small number accepted');
assert.throws(() => parseDevPlayerId(wrap('[]')), /unexpected|usable/, 'zero rows fails closed');
assert.throws(() => parseDevPlayerId(wrap('[[1],[2]]')), /unexpected|usable/, 'ambiguous rows fail closed');
assert.throws(() => parseDevPlayerId('[{"schema":{"elements":[{"name":{"some":"other_column"}}]},"rows":[[1]]}]'), /unexpected|usable/, 'missing column fails closed');
assert.throws(() => parseDevPlayerId(wrap('[[0]]')), /usable/, 'empty id fails closed');
assert.throws(() => parseDevPlayerId('not json'), 'invalid stdout fails closed');

const channel = () => ({ disconnects: 0, disconnect() { this.disconnects++; } });
let conn = channel();
let completed = 0;
await runAccountLink((onConnect) => { onConnect(conn); return conn; }, async () => { completed++; }, 5000);
assert.equal(completed, 1, 'normal link completes');
assert.equal(conn.disconnects, 1, 'normal link disconnects after completion');
conn = channel();
const failed = await runAccountLink((onConnect) => { onConnect(conn); return conn; }, async () => { throw new Error('reducer failed'); }, 5000).then(() => null, error => error);
assert.ok(failed instanceof Error, 'reducer failure rejects');
assert.equal(conn.disconnects, 1, 'rejected link disconnects');
conn = channel();
completed = 0;
let lateConnect = null;
const timedOut = await runAccountLink((onConnect) => { lateConnect = onConnect; return conn; }, async () => { completed++; }, 30).then(() => null, error => error);
assert.ok(timedOut instanceof Error && /timed out/.test(timedOut.message), 'deadline rejects with timeout');
assert.equal(conn.disconnects, 1, 'timeout disconnects the waiting connection');
lateConnect(conn);
await new Promise(resolve => setTimeout(resolve, 20));
assert.equal(completed, 0, 'late onConnect never invokes the reducer');
assert.equal(conn.disconnects, 2, 'late onConnect disconnects instead of linking');
conn = channel();
completed = 0;
await runAccountLink((onConnect, onError) => { onError(new Error('connect failed')); return conn; }, async () => { completed++; }, 5000).then(() => { throw new Error('should reject'); }, () => {});
assert.equal(completed, 0, 'connect error never invokes reducer');
assert.equal(conn.disconnects, 1, 'connect error disconnects');

console.log('PASS dev-login gates, CSRF/proxy rules, streaming bounds, lossless SQL parser, account-link lifecycle.');
