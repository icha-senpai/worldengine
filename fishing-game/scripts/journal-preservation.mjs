import { readFileSync, writeFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import assert from 'node:assert/strict';
const [mode,database]=process.argv.slice(2);
if(!['before','after'].includes(mode) || !(database==='fishbound-dev-local' || /^fishbound-proof-/.test(database))) throw Error('Specify before/after and the local Fishbound database.');
const tables=[...readFileSync('spacetimedb/src/tables.rs','utf8').matchAll(/accessor = (\w+)/g)].map(m=>m[1]).filter(t=>!['maintenance_job','journal_entry','journal_selection','journal_import'].includes(t));
const snapshot=Object.fromEntries(tables.map(table=>{
  const result=spawnSync('spacetime',['sql','--server','http://127.0.0.1:3127','--no-config','--format','json',database,`SELECT * FROM ${table}`],{encoding:'utf8',windowsHide:true});
  if(result.status!==0) throw Error(`Snapshot failed: ${table}`);
  return [table,JSON.parse(result.stdout).flatMap(block=>block.rows.map(row=>JSON.stringify(row))).sort()];
}));
const file=`.local/journal-before-${database}.json`;
if(mode==='before') { writeFileSync(file,JSON.stringify(snapshot)); console.log(`Saved ${tables.length} existing tables for ${database}.`); }
else { assert.deepEqual(snapshot,JSON.parse(readFileSync(file,'utf8'))); console.log(`PASS all ${tables.length} existing tables unchanged after journal migration and import.`); }
