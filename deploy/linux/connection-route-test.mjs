// Bounded, unauthenticated transport comparison. No subscriptions or gameplay calls.
import { createRequire } from 'node:module';
import { randomBytes } from 'node:crypto';
import { writeFile, appendFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { Resolver } from 'node:dns/promises';
import { monitorEventLoopDelay } from 'node:perf_hooks';

const args = process.argv.slice(2);
const option = key => { const i = args.indexOf(key); return i >= 0 ? args[i + 1] : undefined; };
const root = resolve(option('--root'));
const output = resolve(option('--output'));
const minutes = Number(option('--minutes') ?? 15);
const server = args.includes('--server');
if (!Number.isInteger(minutes) || minutes < 1 || minutes > 30) throw new Error('Use a capture of 1 to 30 minutes');
const require = createRequire(resolve(root, 'space/package.json'));
const WebSocket = require('ws');
const started = Date.now(), deadline = started + minutes * 60000;
const safeCode = error => /^[A-Z][A-Z0-9_]{1,60}$/.test(error?.code) ? error.code : 'transport_error';
const hex = value => typeof value === 'string' && /^[a-f0-9]{32}$/.test(value) ? value : undefined;
const ray = value => typeof value === 'string' && /^[a-f0-9]{8,32}-[A-Z]{3}$/.test(value) ? value : undefined;
let writing = Promise.resolve();
function log(row) {
  const line = JSON.stringify({ at: new Date().toISOString(), observer: server ? 'server' : 'pc', ...row }) + '\n';
  writing = writing.then(() => appendFile(output, line, { mode: 0o600 }));
  writing.catch(() => { process.exitCode = 1; });
}
// Never overwrite a prior experiment or follow a linked output.
await writeFile(output, '', { flag: 'wx', mode: 0o600 });
const resolver = new Resolver({ timeout: 3000, tries: 1 });
resolver.setServers(['1.1.1.1']);
const v4 = (await resolver.resolve4('bitcraft.ichaa.dev')).sort();
const v6 = server ? [] : (await resolver.resolve6('bitcraft.ichaa.dev')).sort();
const endpoints = [...v4.map((address, i) => ({ address, family: 4, route: `cloudflare-v4-${i + 1}` })),
  ...v6.map((address, i) => ({ address, family: 6, route: `cloudflare-v6-${i + 1}` }))];
const profiles = [
  { name: 'tls13-passive', tls: 'TLSv1.3', pingMs: 0 },
  { name: 'tls13-ping15', tls: 'TLSv1.3', pingMs: 15000 },
  { name: 'tls13-ping30', tls: 'TLSv1.3', pingMs: 30000 },
  { name: 'tls12-ping15', tls: 'TLSv1.2', pingMs: 15000 },
];
const modules = [['bitcraft', 'bitcraft.ichaa.dev', 'space-bitcraft-tools'], ['fishbound', 'fishbound.ichaa.dev', 'fishbound-dev-local']];
const checks = modules.flatMap(([channel, host, database]) => [
  ...endpoints.flatMap(endpoint => profiles.map(profile => ({ channel, host, database, ...endpoint, ...profile }))),
  ...profiles.filter(p => p.pingMs === 15000).map(profile => ({ channel, host, database, address: server ? '127.0.0.1' : '5.189.187.85', family: 4, route: 'origin', ...profile })),
]);
log({ type: 'route-test-start', deadline: new Date(deadline).toISOString(), connections: checks.length,
  endpointLabels: endpoints.map(({ route, family }) => ({ route, family })), profiles });
const loop = monitorEventLoopDelay({ resolution: 100 }); loop.enable();
let stopping = false;
for (const s of checks) {
  Object.assign(s, { connectionId: randomBytes(16).toString('hex'), opened: 0, pings: 0, pongs: 0, lastPing: 0, lastPong: 0, lastServerPing: 0, state: 'connecting' });
  const began = Date.now();
  const emit = (type, extra = {}) => log({ type, channel: s.channel, route: s.route, profile: s.name,
    connectionId: s.connectionId, ageMs: Date.now() - began, cfRay: s.cfRay, requestId: s.requestId, ...extra });
  const ws = s.socket = new WebSocket(`wss://${s.host}/v1/database/${s.database}/subscribe?compression=None&connection_id=${s.connectionId}`,
    'v2.bsatn.spacetimedb', { minVersion: s.tls, maxVersion: s.tls, perMessageDeflate: false, handshakeTimeout: 12000, maxPayload: 1000000,
      lookup: (_host, opts, done) => opts.all ? done(null, [{ address: s.address, family: s.family }]) : done(null, s.address, s.family) });
  ws.on('upgrade', response => { s.cfRay = ray(response.headers['cf-ray']); s.requestId = hex(response.headers['x-dataverse-request']); });
  ws.on('unexpected-response', (_request, response) => { emit('route-test-handshake-failed', { status: response.statusCode }); response.resume(); ws.terminate(); });
  ws.on('open', () => {
    s.opened = Date.now(); s.state = 'open'; s.lastPong = Date.now();
    emit('route-test-open', { handshakeMs: s.opened - began, tls: ws._socket.getProtocol(), family: s.family, localPort: ws._socket.localPort });
    ws._socket.on('end', () => emit('route-test-tls-end', { expected: stopping }));
    ws._socket.on('error', error => emit('route-test-tcp-error', { error: safeCode(error), expected: stopping }));
    ws._socket.on('close', hadError => emit('route-test-tcp-close', { hadError, expected: stopping, bytesRead: ws._socket.bytesRead, bytesWritten: ws._socket.bytesWritten }));
    if (s.pingMs) s.timer = setInterval(() => { if (ws.readyState === WebSocket.OPEN) { s.lastPing = Date.now(); ws.ping(); } }, s.pingMs);
  });
  ws.on('ping', () => { s.pings++; s.lastServerPing = Date.now(); });
  ws.on('pong', () => { s.pongs++; s.lastPong = Date.now(); s.rttMs = s.lastPong - s.lastPing; });
  ws.on('message', () => {}); // Never decode or log identity tokens or data.
  ws.on('error', error => emit('route-test-error', { error: safeCode(error), expected: stopping }));
  ws.on('close', code => {
    clearInterval(s.timer); s.state = 'closed';
    emit('route-test-close', { code, expected: stopping, receivedPings: s.pings, receivedPongs: s.pongs,
      lastServerPingAgoMs: s.lastServerPing ? Date.now() - s.lastServerPing : undefined,
      lastPongAgoMs: s.pingMs && s.lastPong ? Date.now() - s.lastPong : undefined,
      eventLoopMaxDelayMs: Math.round(loop.max / 1e6) });
  });
}
const sample = setInterval(() => {
  log({ type: 'route-test-sample', eventLoopMaxDelayMs: Math.round(loop.max / 1e6),
    connections: checks.map(s => ({ channel: s.channel, route: s.route, profile: s.name, state: s.state,
      receivedPings: s.pings, receivedPongs: s.pongs, rttMs: s.rttMs })) });
  loop.reset();
}, 60000);
async function stop() {
  if (stopping) return; stopping = true; clearInterval(sample); clearTimeout(expiry); loop.disable();
  for (const s of checks) { clearInterval(s.timer); s.socket.close(1000); }
  log({ type: 'route-test-finish', connections: checks.map(s => ({ channel: s.channel, route: s.route, profile: s.name, state: s.state })) });
  const force = setTimeout(() => { for (const s of checks) s.socket.terminate(); }, 2000); force.unref();
  await writing;
}
const expiry = setTimeout(() => { void stop(); }, Math.max(0, deadline - Date.now()));
process.once('SIGINT', () => { void stop(); });
process.once('SIGTERM', () => { void stop(); });
