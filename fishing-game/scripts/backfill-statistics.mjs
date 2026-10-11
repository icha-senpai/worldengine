import { spawnSync } from 'node:child_process';
import assert from 'node:assert/strict';
const database = process.argv[2];
assert(database === 'fishbound-dev-local' || /^fishbound-proof-/.test(database ?? ''));
const server = 'http://127.0.0.1:3127';
const run = args => {
  const r = spawnSync('spacetime', [...args, '--server', server, '--no-config'], {encoding:'utf8',windowsHide:true});
  assert.equal(r.status, 0, 'Owner statistics backfill failed');
  return r.stdout;
};
const blocks = JSON.parse(run(['sql','--format','json',database,'SELECT player_id FROM player']));
const ids = blocks.flatMap(block => block.rows.map(row => row[0]));
for (const id of ids) run(['call','--yes',database,'backfill_player_statistics',String(id)]);
console.log(`Refreshed statistics for ${ids.length} players without changing game state.`);
