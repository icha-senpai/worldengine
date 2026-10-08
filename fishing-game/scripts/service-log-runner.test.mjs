import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdir, mkdtemp, readFile, readdir, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { Readable } from 'node:stream';
import { pipeline } from 'node:stream/promises';
import test from 'node:test';
import { ArchiveQueue, LOG_LIMIT_BYTES, RotatingLog, runService } from './service-log-runner.mjs';

const root = process.cwd();
const python = process.env.FISHBOUND_TEST_PYTHON ?? 'C:\\ServBay\\packages\\python\\current\\python.exe';
const compressor = path.join(root, 'scripts/compress-log.py');
await mkdir(path.join(root, '.local'), { recursive: true });

async function fixture() {
  const directory = await mkdtemp(path.join(root, '.local/log-rotation-test-'));
  const archive = path.join(directory, 'log-archive');
  const queue = new ArchiveQueue(archive, python, compressor);
  return { directory, archive, queue };
}

function decompress(files) {
  return execFileSync(python, ['-c', 'import lzma,sys; [sys.stdout.buffer.write(lzma.open(p,"rb").read()) for p in sys.argv[1:]]', ...files]);
}

test('exact byte cap splits large UTF-8 chunks; XZ round trip preserves every byte', async () => {
  assert.equal(LOG_LIMIT_BYTES, 1_000_000_000);
  const { directory, queue } = await fixture();
  const file = path.join(directory, 'bot.stdout.log');
  const writer = new RotatingLog(file, queue, 1024);
  const closed = [];
  writer.on('rotated', file => closed.push(file));
  const data = Buffer.from('A fish 🐟 and its XP\n'.repeat(180));
  await pipeline(Readable.from([data]), writer);
  assert.ok((await stat(file)).size < 1024);
  await queue.pending;
  assert.equal(queue.failures, 0);
  const restored = Buffer.concat([decompress(closed.map(file => file + '.xz')), await readFile(file)]);
  assert.deepEqual(restored, data);
  for (const file of closed) {
    assert.equal(decompress([file + '.xz']).length, 1024);
    await assert.rejects(stat(file), { code: 'ENOENT' });
    assert.ok((await stat(file + '.xz')).size < 1024);
  }
});

test('restart appends existing logs, exact limit opens a fresh file, failures preserve originals and recover', async () => {
  const { directory, archive } = await fixture();
  const file = path.join(directory, 'bot.stdout.log');
  await writeFile(file, 'a'.repeat(700));
  const failed = new ArchiveQueue(archive, path.join(directory, 'missing-python.exe'), compressor);
  const writer = new RotatingLog(file, failed, 1024);
  const closed = [];
  writer.on('rotated', file => closed.push(file));
  await pipeline(Readable.from([Buffer.from('b'.repeat(324))]), writer);
  await failed.pending;
  assert.equal((await stat(file)).size, 0);
  assert.equal(failed.failures, 1);
  assert.deepEqual(await readFile(closed[0]), Buffer.from('a'.repeat(700) + 'b'.repeat(324)));
  const recovered = new ArchiveQueue(archive, python, compressor);
  await recovered.recover('bot');
  await recovered.pending;
  assert.equal(recovered.failures, 0);
  assert.deepEqual(decompress([closed[0] + '.xz']), Buffer.from('a'.repeat(700) + 'b'.repeat(324)));
});

test('service runner drains stdout/stderr, tracks the actual child, and retains capped logs', async () => {
  const { directory, archive } = await fixture();
  const runnerState = path.join(directory, 'runner.json');
  const config = {
    name: 'bot', executable: process.execPath,
    args: ['-e', 'process.stdout.write("A".repeat(3000)); process.stderr.write("B".repeat(2100));'],
    cwd: root, stateRoot: directory, runnerState, python, compressor, limit: 1024,
  };
  assert.equal(await runService(config), 0);
  const state = JSON.parse(await readFile(runnerState, 'utf8'));
  assert.equal(state.phase, 'drained');
  assert.ok(Number.isInteger(state.childPid) && state.childPid !== process.pid);
  for (const [stream, count, byte] of [['stdout', 3000, 'A'], ['stderr', 2100, 'B']]) {
    const files = (await readdir(archive)).filter(file => file.startsWith(`bot.${stream}.`) && file.endsWith('.xz')).map(file => path.join(archive, file));
    const active = await readFile(path.join(directory, `bot.${stream}.log`));
    assert.ok(active.length < 1024);
    assert.deepEqual(Buffer.concat([decompress(files), active]), Buffer.from(byte.repeat(count)));
  }
});
