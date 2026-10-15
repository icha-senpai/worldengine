// Temporary field-for-field fingerprints for the additive subscription/outbox
// update. This stores verification hashes, not player records or a backup.
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFile, writeFile, unlink } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
const mode=process.argv[2], database=process.argv[3];
assert(['before','after','finish'].includes(mode));
assert(database==='fishbound-dev-local' || /^fishbound-proof-/.test(database));
const server='http://127.0.0.1:3127';
const path=`.local/${database}-spacetime-fingerprints.json`;
if(mode==='finish'){await unlink(path);process.exit(0);}
function query(sql) {
 const result=spawnSync('spacetime',['sql',database,sql,'--server',server,'--format','json','--no-config'],{encoding:'utf8',windowsHide:true});
 if(result.status!==0)throw Error('Cannot read preservation state');
 return JSON.parse(result.stdout,(_key,value,context)=>typeof value==='number'&&!Number.isSafeInteger(value)?context.source:value).flatMap(block=>block.rows);
}
function rows(table) { assert(/^\w+$/.test(table)); return query(`SELECT * FROM ${table}`); }
function fingerprint(table) {
 const data=rows(table);return {count:data.length,hash:createHash('sha256').update(JSON.stringify(data.map(row=>JSON.stringify(row)).sort())).digest('hex')};
}
const metadata=await fetch(`${server}/v1/database/${database}`).then(r=>{assert(r.ok);return r.json();});
if(mode==='before'){
 const source=(await readFile('spacetimedb/src/tables.rs','utf8'))+(await readFile('spacetimedb/src/notifications.rs','utf8'));
 const installed=new Set(query('SELECT table_name FROM st_table').map(row=>row[0]));
 const tables=[...source.matchAll(/#\[spacetimedb::table\(accessor\s*=\s*(\w+)/g)].map(m=>m[1]).filter(t=>installed.has(t));
 const state=Object.fromEntries(tables.map(t=>[t,fingerprint(t)]));
 await writeFile(path,JSON.stringify({identity:metadata.database_identity.__identity__,tables:state}),{flag:'wx'});
 console.log(`Recorded complete-row fingerprints for ${tables.length} existing tables.`);
}else{
 const before=JSON.parse(await readFile(path,'utf8'));
 assert.equal(metadata.database_identity.__identity__,before.identity,'database identity is preserved');
 for(const [table,expected] of Object.entries(before.tables))assert.deepEqual(fingerprint(table),expected,`${table}: every retained field must match`);
 const counts=new Map(rows('journal_count').map(([player,total])=>[String(player),BigInt(total)]));
 const actual=new Map();
 for(const row of rows('journal_entry'))actual.set(String(row[1]),(actual.get(String(row[1]))??0n)+1n);
 for(const [player] of rows('player'))assert.equal(counts.get(String(player)),actual.get(String(player))??0n,'journal summary matches saved history');
 if(!before.tables.achievement_delivery)assert.equal(rows('achievement_delivery').length,0,'historical awards are not queued');
 console.log(`PASS ${Object.keys(before.tables).length} existing tables match every retained field; identity preserved, journal counts exact, no historical announcements queued.`);
}
