import { spawnSync } from 'node:child_process';
const database=process.argv[2];
if(!(database==='fishbound-dev-local' || /^fishbound-proof-/.test(database??''))) throw Error('Specify the local Fishbound database or an isolated proof database.');
const base=['--server','http://127.0.0.1:3127','--no-config'];
function run(args) {
  const result=spawnSync('spacetime',args,{encoding:'utf8',windowsHide:true});
  if(result.status!==0) throw Error(result.stderr||result.stdout);
  return result.stdout;
}
const players=JSON.parse(run(['sql',...base,'--format','json',database,'SELECT player_id FROM player'])).flatMap(block=>block.rows.map(row=>row[0]));
for(const id of players) run(['call',...base,'--yes',database,'backfill_journal',String(id)]);
const entries=JSON.parse(run(['sql',...base,'--format','json',database,'SELECT COUNT(*) AS entries FROM journal_entry']))[0].rows[0][0];
console.log(`Journal import complete: ${players.length} players, ${entries} permanent entries. Repeating this command is safe.`);
