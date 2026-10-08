import { spawn } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { mkdir, open, readdir, rename, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { Writable } from 'node:stream';
import { pipeline } from 'node:stream/promises';
import { pathToFileURL } from 'node:url';

export const LOG_LIMIT_BYTES = 1_000_000_000;

function execute(executable, args, options = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(executable, args, { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'], ...options });
    let error = '';
    child.stderr.on('data', chunk => { error = (error + chunk.toString()).slice(-4096); });
    child.once('error', reject);
    child.once('close', code => code === 0 ? resolve() : reject(new Error(error || `Compressor exited with ${code}`)));
  });
}

export class ArchiveQueue {
  constructor(directory, python, compressor, statusFile) {
    this.directory = directory;
    this.python = python;
    this.compressor = compressor;
    this.statusFile = statusFile;
    this.pending = Promise.resolve();
    this.failures = 0;
  }

  enqueue(file) {
    this.pending = this.pending.then(async () => {
      let status = 'compressed';
      let error;
      try {
        await execute(this.python, [this.compressor, file, this.directory]);
      } catch (failure) {
        this.failures++;
        status = 'failed-original-retained';
        error = failure.message.slice(-4096);
      }
      // A fixed-size status file records failures without another growing log.
      if (this.statusFile) {
        await writeFile(this.statusFile, JSON.stringify({ file, status, error, at: new Date().toISOString() })).catch(() => {});
      }
    });
  }

  async recover(service) {
    await mkdir(this.directory, { recursive: true });
    for (const name of (await readdir(this.directory)).sort()) {
      if ((name.startsWith(`${service}.stdout.`) || name.startsWith(`${service}.stderr.`)) && name.endsWith('.log')) {
        this.enqueue(path.join(this.directory, name));
      }
    }
  }
}

export class RotatingLog extends Writable {
  constructor(file, archives, limit = LOG_LIMIT_BYTES) {
    super({ highWaterMark: 64 * 1024 });
    if (!Number.isSafeInteger(limit) || limit < 1) throw new Error('Invalid log byte limit');
    this.file = file;
    this.archives = archives;
    this.limit = limit;
    this.handle = null;
    this.size = 0;
  }

  async initialize() {
    await mkdir(path.dirname(this.file), { recursive: true });
    await mkdir(this.archives.directory, { recursive: true });
    this.size = await stat(this.file).then(file => file.size).catch(error => {
      if (error.code !== 'ENOENT') throw error;
      return 0;
    });
    this.handle = await open(this.file, 'a');
    if (this.size >= this.limit) await this.rotate();
  }

  async rotate() {
    await this.handle.close();
    this.handle = null;
    const stamp = new Date().toISOString().replace(/[:.]/g, '-');
    const name = `${path.basename(this.file, '.log')}.${stamp}.${randomUUID()}.log`;
    const closed = path.join(this.archives.directory, name);
    await rename(this.file, closed);
    this.size = 0;
    this.handle = await open(this.file, 'a');
    this.emit('rotated', closed);
    this.archives.enqueue(closed);
  }

  async append(chunk) {
    if (!this.handle) await this.initialize();
    let offset = 0;
    while (offset < chunk.length) {
      const length = Math.min(chunk.length - offset, this.limit - this.size);
      await this.handle.writeFile(chunk.subarray(offset, offset + length));
      this.size += length;
      offset += length;
      if (this.size === this.limit) await this.rotate();
    }
  }

  _write(chunk, encoding, callback) {
    this.append(chunk).then(() => callback(), callback);
  }

  _final(callback) {
    (this.handle?.close() ?? Promise.resolve()).then(() => { this.handle = null; callback(); }, callback);
  }

  _destroy(error, callback) {
    (this.handle?.close() ?? Promise.resolve()).then(() => { this.handle = null; callback(error); }, () => callback(error));
  }
}

export async function runService(config) {
  if (!/^[a-z][a-z0-9-]*$/.test(config.name)) throw new Error('Invalid service name');
  const directory = path.resolve(config.stateRoot);
  const archives = new ArchiveQueue(path.join(directory, 'log-archive'), config.python, config.compressor, path.join(directory, `${config.name}.archive-status.json`));
  const stdout = new RotatingLog(path.join(directory, `${config.name}.stdout.log`), archives, config.limit ?? LOG_LIMIT_BYTES);
  const stderr = new RotatingLog(path.join(directory, `${config.name}.stderr.log`), archives, config.limit ?? LOG_LIMIT_BYTES);
  await Promise.all([stdout.initialize(), stderr.initialize()]);
  await archives.recover(config.name);
  const child = spawn(config.executable, config.args ?? [], { cwd: config.cwd, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
  const exited = new Promise((resolve, reject) => {
    child.once('error', reject);
    child.once('close', (code, signal) => resolve({ code, signal }));
  });
  const output = Promise.all([pipeline(child.stdout, stdout), pipeline(child.stderr, stderr)]);
  // Attach handlers immediately so a launch/pipe failure cannot be unhandled.
  exited.catch(() => {});
  output.catch(() => { child.kill(); });
  const state = { childPid: child.pid, phase: 'running' };
  try {
    if (!child.pid) await exited;
    await writeFile(config.runnerState, JSON.stringify(state));
    const result = await exited;
    await output;
    await writeFile(config.runnerState, JSON.stringify({ ...state, ...result, phase: 'drained' }));
    // Closed logs compress independently of gameplay, even after service exit.
    await archives.pending;
    return result.code ?? 0;
  } catch (error) {
    child.kill();
    await output.catch(() => {});
    await writeFile(config.runnerState, JSON.stringify({ ...state, phase: 'failed', error: error.message.slice(-4096) }));
    throw error;
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  const { readFile } = await import('node:fs/promises');
  const config = JSON.parse((await readFile(process.argv[2], 'utf8')).replace(/^\uFEFF/, ''));
  try { process.exitCode = await runService(config); }
  catch (error) { process.stderr.write(`${error.message.slice(-4096)}\n`); process.exitCode = 1; }
}
