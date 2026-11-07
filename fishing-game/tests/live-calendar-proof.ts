import { requireRustWorkflow, rustTargetPath } from '../scripts/rust-build.mjs';
import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve, join } from 'node:path';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';

requireRustWorkflow();
const uri = 'http://127.0.0.1:3127';
const database = `fishbound-proof-live-calendar-${randomUUID()}`;
const fixtureRoot = resolve('.local/live-state-hardening/proof-build');
const targetRoot = rustTargetPath();
const cli = process.env.FISHBOUND_TYPESCRIPT_CLI || join(process.env.LOCALAPPDATA ?? '', 'SpacetimeDB', 'bin', '2.12.0', 'spacetimedb-cli.exe');
const rustPath = (path: string) => JSON.stringify(resolve(path).replaceAll('\\', '/'));
function run(command: string, args: string[]) {
  const result = spawnSync(command, args, { encoding: 'utf8', windowsHide: true });
  if (result.error || result.status !== 0) throw new Error(`${command} failed (${result.status}): ${result.stderr || result.stdout || result.error?.message}`);
  return result.stdout;
}
await mkdir(fixtureRoot, { recursive: true });
const manifest = (await readFile('spacetimedb/Cargo.toml', 'utf8'))
  .replace('name = "fishing-game-module"', 'name = "fishing-live-calendar-proof"')
  .replace('path = "../crates/game-rules"', `path = ${rustPath('crates/game-rules')}`)
  .replace('crate-type = ["cdylib"]', 'crate-type = ["cdylib"]\npath = "lib.rs"');
await writeFile(join(fixtureRoot, 'Cargo.toml'), `${manifest}\n[workspace]\n`);
await writeFile(join(fixtureRoot, 'Cargo.lock'), (await readFile('spacetimedb/Cargo.lock', 'utf8')).replace('name = "fishing-game-module"', 'name = "fishing-live-calendar-proof"'));
const source = (await readFile('spacetimedb/src/lib.rs', 'utf8')).replace(
  /^mod (\w+);$/gm, (_, name) => `#[path = ${rustPath(`spacetimedb/src/${name}.rs`)}]\nmod ${name};`
);
await writeFile(join(fixtureRoot, 'lib.rs'), `${source}\ninclude!(${rustPath('tests/fixtures/live-calendar.rs')});\n`);
run('cargo', ['build', '--manifest-path', join(fixtureRoot, 'Cargo.toml'), '--target-dir', targetRoot,
  '--target', 'wasm32-unknown-unknown', '--release', '--locked']);
const wasm = join(targetRoot, 'wasm32-unknown-unknown', 'release', 'fishing_live_calendar_proof.wasm');
console.log('PROOF_MODULE_BUILD=PASS');
console.log(`PROOF_DATABASE=${database}`);
console.log(run(cli, ['publish', '--server', uri, '--no-config', '--yes=skip-login,migrate,break-clients',
  '--delete-data=never', '--bin-path', wasm, database]));
let connection: DbConnection | undefined;
try {
  await new Promise<void>((resolve, reject) => {
    connection = DbConnection.builder().withUri(uri).withDatabaseName(database)
      .onConnect(() => resolve()).onConnectError((_, error) => reject(error)).build();
  });
  const observed: bigint[] = [];
  connection!.db.questCalendar.onUpdate((_ctx, _old, row) => { observed.push(row.day); });
  await new Promise<void>((resolve, reject) => connection!.subscriptionBuilder()
    .onApplied(() => resolve()).onError(ctx => reject(ctx.event)).subscribe(['SELECT * FROM quest_calendar']));
  const initial = [...connection!.db.questCalendar.iter()][0];
  assert(initial);
  console.log(`INITIAL_DAY=${initial.day}`);
  const readJobs = () => {
    const payload = JSON.parse(run(cli, ['sql', '--server', uri, '--no-config', '--format', 'json', database, 'SELECT * FROM calendar_job']));
    if (Array.isArray(payload) && payload.length === 1 && Array.isArray(payload[0]?.rows)) return payload[0].rows;
    if (Array.isArray(payload?.rows)) return payload.rows;
    if (Array.isArray(payload) && payload.every(row => row && typeof row === 'object' && 'scheduled_id' in row)) return payload;
    throw new Error(`Unexpected calendar_job SQL JSON shape: ${JSON.stringify(payload)}`);
  };
  const beforeJobs = readJobs();
  assert.equal(beforeJobs.length, 1);
  run(cli, ['call', '--server', uri, '--no-config', '--yes', database, 'seed_live_calendar_proof']);
  const deadline = performance.now() + 15_000;
  while (!(observed.includes(initial.day - 1n) && observed.includes(initial.day))) {
    if (performance.now() > deadline) throw new Error(`Scheduler/subscription proof timed out: ${observed.join(',')}`);
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  console.log(`OBSERVED_DAYS=${observed.join(',')}`);
  let jobs = readJobs();
  const cleanupDeadline = performance.now() + 5000;
  while (jobs.length !== 1 && performance.now() < cleanupDeadline) {
    await new Promise(resolve => setTimeout(resolve, 100));
    jobs = readJobs();
  }
  assert.equal(jobs.length, 1, 'fired one-shot row must be deleted');
  assert.deepEqual(jobs[0], beforeJobs[0], 'the original future midnight job must remain');
  console.log(`CALENDAR_JOBS_BEFORE_AFTER=${JSON.stringify(jobs)}`);
  console.log('PASS isolated scheduler updated quest_calendar in an existing subscription; no real midnight was awaited.');
} finally { connection?.disconnect(); }
