import { spawnSync } from 'node:child_process';
const database = process.argv[2];
if (!(database === 'fishbound-dev-local' || /^fishbound-proof-/.test(database ?? ''))) throw Error('Specify a local Fishbound database.');
const base = ['--server', 'http://127.0.0.1:3127', '--no-config'];
function run(args) {
  const result = spawnSync('spacetime', args, { encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw Error(result.stderr || result.stdout);
  return result.stdout;
}
run(['call', ...base, '--yes', database, 'activate_achievements']);
const players = JSON.parse(run(['sql', ...base, '--format', 'json', database, 'SELECT player_id FROM player'])).flatMap(block => block.rows.map(row => row[0]));
for (const id of players) run(['call', ...base, '--yes', database, 'backfill_player_achievements', String(id)]);
console.log(`Collection achievement backfill complete for ${players.length} players. Repeating it preserves existing award dates.`);
