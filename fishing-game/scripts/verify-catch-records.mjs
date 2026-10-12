// Independent owner-side verification. No writes or raw private data output.
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
const database = process.argv[2];
assert(database === 'fishbound-dev-local' || /^fishbound-proof-/.test(database ?? ''));
function read(table) {
  const r=spawnSync('spacetime',['sql','--server','http://127.0.0.1:3127','--no-config','--format','json',database,`SELECT * FROM ${table}`],{encoding:'utf8',windowsHide:true});
  assert.equal(r.status,0,`Read failed: ${table}`);
  return JSON.parse(r.stdout).flatMap(b=>b.rows.map(row=>Object.fromEntries(row.map((v,i)=>[b.schema.elements[i].name.some,v]))));
}
const fields=[...readFileSync('spacetimedb/src/tables.rs','utf8').match(/pub struct CommandReceipt \{([\s\S]*?)\n\}/)[1].matchAll(/pub (\w+):/g)].map(m=>m[1]);
const option=v=>v[0]===0?v[1]:null;
const players=read('player'),stats=read('angler_statistics'),records=read('species_record');
const fish=read('journal_entry').filter(e=>e.outcome==='fish').map(e=>({...e,species:option(e.species_id),id:option(e.catch_id),time:e.caught_at[0]}));
assert(fish.every(e=>e.id!==null&&e.species!==null));assert.equal(new Set(fish.map(e=>e.id)).size,fish.length);
fish.sort((a,b)=>a.time-b.time||a.id-b.id);
const best=new Map(),totals=new Map(),values=new Map();
for(const e of fish) {
  let wins=false;
  for(const [metric,size] of [['length',e.length_mm],['weight',e.weight_g]]) {
    const key=e.species+':'+metric,old=best.get(key);
    if(!old||size>old.size){wins=true;best.set(key,{size,id:e.id,player:e.player_id,time:e.time});}
  }
  if(wins)totals.set(e.player_id,(totals.get(e.player_id)||0)+1);
}
assert.equal(best.size,records.length);
for(const r of records)assert.deepEqual(best.get(r.species_id+':'+r.metric),{size:r.measurement,id:r.catch_id,player:r.player_id,time:r.caught_at[0]});
for(const table of ['cast_pull','command_receipt','owned_specimen'])for(const row of read(table)) {
  const r=table==='cast_pull'?Object.fromEntries(fields.map((f,i)=>[f,row.receipt[i]])):row;
  const id=table==='owned_specimen'?r.catch_id:option(r.catch_id);
  if(id!==null&&(table==='owned_specimen'||r.outcome==='fish')) {
    if(values.has(id))assert.equal(values.get(id),r.sale_value_coins);
    values.set(id,r.sale_value_coins);
  }
}
for(const p of players) {
  const catches=fish.filter(e=>e.player_id===p.player_id);
  assert.equal(catches.length,p.fish_count,'Entire fish history must be present');
  assert(catches.every(e=>values.has(e.id)),'Every original fish price must be known');
  const stat=stats.find(s=>s.player_id===p.player_id);assert(stat);
  assert.equal(stat.records_set,totals.get(p.player_id)||0);
  assert.deepEqual(stat.records_tracked_since,p.created_at);
  assert.equal(stat.biggest_single_sale,Math.max(0,...catches.map(e=>values.get(e.id))));
}
const own=stats.find(s=>s.player_id===1);
console.log(`PASS ${fish.length} complete fish histories reproduce all ${records.length} records; record-setting totals and original maximum fish values match for ${players.length} players.`);
if(database==='fishbound-dev-local'&&own)console.log(`icha_: ${own.records_set} record-setting catches; most expensive fish ${own.biggest_single_sale} coins.`);
