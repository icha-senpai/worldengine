// Independent, read-only transport checks. No authentication or table subscriptions.
import { createRequire } from 'node:module';
import { randomBytes } from 'node:crypto';
import { createSocket } from 'node:dgram';
import { readFile, mkdir, appendFile, stat, rename, lstat } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { get } from 'node:https';
import { execFile } from 'node:child_process';

const modules = [
  ['bitcraft', 'bitcraft.ichaa.dev', 'space-bitcraft-tools', 3100],
  ['evergather', 'bitcraft.ichaa.dev', 'space-evergather', 3100],
  ['fishbound', 'fishbound.ichaa.dev', 'fishbound-dev-local', 3127],
];
const hex = value => typeof value === 'string' && /^[a-f0-9]{32}$/.test(value) ? value : undefined;
const ray = value => typeof value === 'string' && /^[a-f0-9]{8,32}-[A-Z]{3}$/.test(value) ? value : undefined;
const number = (value, max = 1e15) => (typeof value === 'number' || (typeof value === 'string' && /^\d+(?:\.\d+)?$/.test(value))) && Number.isFinite(Number(value)) && Number(value) >= 0 && Number(value) <= max ? Number(value) : undefined;

// Incoming UDP is untrusted, including loopback. Copy only these fields.
export function cleanProxyRecord(input) {
  if (!input || !['nginx-websocket', 'tcp-control', 'nginx-connection-check'].includes(input.type)) return null;
  if (input.type === 'tcp-control') {
    if (![443, 3100, 3127].includes(input.serverPort) || !['inbound', 'outbound', 'to-database', 'from-database'].includes(input.direction) || !['fin', 'reset'].includes(input.flag)) return null;
    return { type: input.type, at: input.at && Number.isFinite(Date.parse(input.at)) ? new Date(input.at).toISOString() : undefined,
      serverPort: input.serverPort, peerPort: number(input.peerPort, 65535), direction: input.direction, flag: input.flag, family: [4, 6].includes(input.family) ? input.family : undefined };
  }
  if (!['bitcraft', 'evergather', 'fishbound', 'space-auth', 'fishbound-auth', 'space-check', 'fishbound-check'].includes(input.channel)) return null;
  const output = { type: input.type, channel: input.channel, connectionId: hex(input.connectionId), requestId: hex(input.requestId), cfRay: ray(input.cfRay) };
  for (const key of ['durationSeconds', 'status', 'upstreamStatus', 'upstreamConnectSeconds', 'upstreamHeaderSeconds', 'upstreamSeconds', 'bytesToClient', 'bytesFromUpstream', 'bytesToUpstream', 'nginxConnection', 'peerPort']) {
    const max = key === 'peerPort' ? 65535 : key.toLowerCase().includes('seconds') ? 7 * 86400 : key.toLowerCase().includes('status') ? 599 : 1e15;
    const parsed = number(input[key], max); if (parsed !== undefined) output[key] = parsed;
  }
  const endedAtSeconds = number(input.endedAtSeconds, 4102444800);
  if (endedAtSeconds === undefined || output.durationSeconds === undefined) return null;
  output.endedAtMs = endedAtSeconds * 1000;
  output.startedAtMs = output.endedAtMs - output.durationSeconds * 1000;
  output.at = new Date(output.endedAtMs).toISOString();
  output.completed = input.completed === 'OK';
  return output;
}

export function targets(pc = false) {
  return modules.flatMap(([channel, host, database, port]) => (pc ? ['public-v4', 'public-v6', 'origin'] : ['public-v4', 'public-v6', 'origin', 'database']).map(route => ({
    channel, route, host, port, database,
    address: route === 'origin' ? (pc ? '5.189.187.85' : '127.0.0.1') : undefined,
  })));
}

function httpsCheck(host, address) {
  return new Promise(resolve => {
    const started = Date.now();
    const options = { hostname: host, servername: host, path: '/_connection-health', headers: { 'User-Agent': 'Dataverse connection diagnostics' }, timeout: 5000 };
    if (address) options.lookup = (_host, opts, done) => opts.all ? done(null, [{ address, family: 4 }]) : done(null, address, 4);
    const request = get(options, response => { response.resume(); resolve({ status: response.statusCode, durationMs: Date.now() - started, cfRay: ray(response.headers['cf-ray']), requestId: hex(response.headers['x-dataverse-request']) }); });
    request.on('timeout', () => request.destroy(Object.assign(new Error(), { code: 'ETIMEDOUT' })));
    request.on('error', error => resolve({ error: safeError(error), durationMs: Date.now() - started }));
  });
}
function safeError(error) {
  return ['ECONNRESET', 'ETIMEDOUT', 'ECONNREFUSED', 'ENOTFOUND', 'EAI_AGAIN', 'ENETUNREACH', 'EHOSTUNREACH', 'EPIPE', 'ERR_TLS_CERT_ALTNAME_INVALID', 'CERT_HAS_EXPIRED'].includes(error?.code) ? error.code : 'transport_error';
}

async function hostHealth() {
  const result = {};
  if (process.platform !== 'linux') return result;
  try {
    const mem = await readFile('/proc/meminfo', 'utf8');
    result.availableMemoryBytes = Number(mem.match(/^MemAvailable:\s+(\d+)/m)?.[1]) * 1024;
    result.load = (await readFile('/proc/loadavg', 'utf8')).split(' ').slice(0, 3).map(Number);
    const lines = (await readFile('/proc/net/snmp', 'utf8')).split('\n').filter(x => x.startsWith('Tcp:'));
    const names = lines[0].trim().split(/\s+/), values = lines[1].trim().split(/\s+/);
    result.tcp = Object.fromEntries(['RetransSegs', 'EstabResets', 'OutRsts', 'InErrs'].map(key => [key, Number(values[names.indexOf(key)])]));
    result.services = await new Promise(resolve => execFile('systemctl', ['--user', 'show', 'dataverse-space-db', 'dataverse-fish-db', 'dataverse-fish-web', 'dataverse-space-collector', '-p', 'Id', '-p', 'MainPID', '-p', 'NRestarts', '-p', 'ActiveState'], { timeout: 3000 }, (_error, stdout) => resolve(stdout ? stdout.trim().split('\n\n').map(block => Object.fromEntries(block.split('\n').map(line => line.split('=')))) : undefined)));
    const latest = JSON.parse(await readFile('/home/icha/dataverse/space/.runtime/diagnostics/space-bitcraft-tools/latest.json', 'utf8'));
    result.collector = { at: latest.at, phase: latest.phase, storagePhase: latest.storagePhase, pid: latest.pid };
  } catch { result.partial = true; }
  return result;
}

export async function monitor({ root, until, pc = false, output, udpPort = 5514 }) {
  if (!Number.isFinite(until) || until > Date.now() + 7 * 86400000) throw new Error('A capture deadline within seven days is required.');
  if (pc && until <= Date.now()) return;
  const require = createRequire(resolve(root, 'space/package.json'));
  const WebSocket = require('ws');
  let stopping = false, lastIncident = 0, logWork = Promise.resolve(), suppressed = 0, logMinute = 0, logCount = 0;
  async function log(row) {
    if (Math.floor(Date.now() / 60000) !== logMinute) { logMinute = Math.floor(Date.now() / 60000); logCount = 0; }
    if (++logCount > 1200) { suppressed++; return; }
    const line = JSON.stringify({ at: new Date().toISOString(), observer: pc ? 'pc' : 'server', ...row }) + '\n';
    if (!output) { process.stdout.write(line); return; }
    logWork = logWork.catch(() => {}).then(async () => {
      await mkdir(resolve(output, '..'), { recursive: true });
      for (const name of [output, output + '.1', output + '.2']) {
        try { if ((await lstat(name)).isSymbolicLink()) throw new Error('Linked diagnostic output refused'); } catch (error) { if (error.code !== 'ENOENT') throw error; }
      }
      try { if ((await stat(output)).size >= 2_000_000) { try { await rename(output + '.1', output + '.2'); } catch (error) { if (error.code !== 'ENOENT') throw error; } await rename(output, output + '.1'); } } catch (error) { if (error.code !== 'ENOENT') throw error; }
      await appendFile(output, line, { mode: 0o600 });
    });
    await logWork;
  }
  const states = targets(pc).map(target => ({ ...target, socket: null, retry: null, heartbeat: null, opened: 0, lastPing: 0, lastPong: 0, pings: 0, pongs: 0, reconnects: 0, state: 'starting' }));
  async function incident() {
    if (Date.now() - lastIncident < 30000 || stopping) return;
    lastIncident = Date.now();
    const http = await Promise.all(['bitcraft.ichaa.dev', 'fishbound.ichaa.dev'].flatMap(host => [undefined, pc ? '5.189.187.85' : '127.0.0.1'].map(async address => ({ host, route: address ? 'origin' : 'public', ...await httpsCheck(host, address) }))));
    await log({ type: 'connection-incident-health', http, host: await hostHealth() });
  }
  function open(s) {
    if (stopping || Date.now() >= until) return;
    const connectionId = randomBytes(16).toString('hex');
    const started = Date.now();
    const url = s.route === 'database' ? `ws://127.0.0.1:${s.port}` : `wss://${s.host}`;
    const options = { handshakeTimeout: 15000, perMessageDeflate: false, maxPayload: 1000000 };
    if (s.route.startsWith('public-')) options.family = s.route.endsWith('v6') ? 6 : 4;
    if (s.address) options.lookup = (_host, opts, done) => opts.all ? done(null, [{ address: s.address, family: 4 }]) : done(null, s.address, 4);
    const ws = s.socket = new WebSocket(`${url}/v1/database/${s.database}/subscribe?compression=None&connection_id=${connectionId}`, 'v2.bsatn.spacetimedb', options);
    s.state = 'connecting'; s.connectionId = connectionId;
    const event = (type, extra = {}) => void log({ type, channel: s.channel, route: s.route, connectionId, ageMs: Date.now() - started, ...extra }).catch(() => {});
    ws.on('upgrade', response => { s.cfRay = ray(response.headers['cf-ray']); s.requestId = hex(response.headers['x-dataverse-request']); });
    ws.on('unexpected-response', (_req, response) => { event('connection-probe-http-error', { status: response.statusCode }); response.resume(); ws.terminate(); });
    ws.on('open', () => {
      s.state = 'open'; s.opened = Date.now(); s.lastPong = Date.now(); s.pings = 0; s.pongs = 0;
      event('connection-probe-open', { handshakeMs: Date.now() - started, cfRay: s.cfRay, requestId: s.requestId, family: ws._socket?.remoteFamily });
      s.heartbeat = setInterval(() => {
        if (Date.now() - s.lastPong > 65000) { event('connection-probe-pong-timeout'); ws.terminate(); return; }
        if (ws.readyState === WebSocket.OPEN) { s.lastPing = Date.now(); ws.ping(); }
      }, 30000);
    });
    ws.on('ping', () => s.pings++);
    ws.on('pong', () => { s.lastPong = Date.now(); s.pongs++; s.rttMs = s.lastPong - s.lastPing; });
    ws.on('message', () => { s.lastFrame = Date.now(); }); // Never decode identity tokens or payloads.
    ws.on('error', error => {
      s.unavailable = !s.opened && error?.code === 'ENETUNREACH';
      event(s.unavailable ? 'connection-probe-unavailable' : 'connection-probe-error', { error: safeError(error) });
      if (!s.unavailable) void incident().catch(() => {});
    });
    ws.on('close', code => {
      clearInterval(s.heartbeat); s.state = s.unavailable ? 'unavailable' : 'closed';
      event('connection-probe-close', { code, expected: stopping || s.unavailable, cfRay: s.cfRay, requestId: s.requestId, receivedPings: s.pings, receivedPongs: s.pongs, lastPongAgeMs: s.lastPong ? Date.now() - s.lastPong : undefined });
      if (!stopping && !s.unavailable) { s.reconnects++; void incident().catch(() => {}); s.retry = setTimeout(() => open(s), 5000); }
    });
  }
  let udp;
  if (!pc) {
    udp = createSocket('udp4');
    let packets = 0, minute = 0;
    udp.on('message', (message, remote) => {
      if (stopping || remote.address !== '127.0.0.1' || message.length > 2048) return;
      if (minute !== Math.floor(Date.now() / 60000)) { minute = Math.floor(Date.now() / 60000); packets = 0; }
      if (++packets > 1200) return;
      try {
        const raw = message.toString(); const record = cleanProxyRecord(JSON.parse(raw.slice(raw.indexOf('{'))));
        if (record) void log(record).catch(() => {});
      } catch { /* Untrusted or malformed datagram. */ }
    });
    await new Promise((accept, reject) => { udp.once('error', reject); udp.bind(udpPort, '127.0.0.1', accept); });
  }
  await log({ type: 'connection-diagnostics-start', until: new Date(until).toISOString(), routes: states.length, udpPort: pc ? undefined : udpPort });
  for (const s of states) open(s);
  let sampling = false;
  const sample = setInterval(async () => {
    if (sampling) return; sampling = true;
    try { await log({ type: 'connection-diagnostics-sample', suppressed, routes: states.map(s => ({ channel: s.channel, route: s.route, state: s.state, connectionId: s.connectionId, openAgeMs: s.opened ? Date.now() - s.opened : undefined, receivedPings: s.pings, receivedPongs: s.pongs, rttMs: s.rttMs, reconnects: s.reconnects })), host: await hostHealth() }); } catch { process.stderr.write('Diagnostic sample could not be written\n'); } finally { sampling = false; }
  }, 60000);
  async function stop() {
    if (stopping) return; stopping = true; clearInterval(sample); clearTimeout(expiry);
    udp?.close();
    for (const s of states) { clearTimeout(s.retry); clearInterval(s.heartbeat); s.socket?.close(1000); }
    await log({ type: 'connection-diagnostics-stop' });
    setTimeout(() => { for (const s of states) s.socket?.terminate(); }, 2000).unref();
  }
  const expiry = setTimeout(() => void stop(), Math.max(0, until - Date.now()));
  const shutdown = () => { void stop().catch(() => {}); };
  process.once('SIGTERM', shutdown); process.once('SIGINT', shutdown);
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const args = process.argv.slice(2), option = key => args[args.indexOf(key) + 1];
  await monitor({ root: option('--root'), until: Date.parse(option('--until')), pc: args.includes('--pc'), output: args.includes('--output') ? resolve(option('--output')) : undefined });
}
