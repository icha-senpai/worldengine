// Owner-side reads around the additive XP/bait update. No player mutations.
import { readFile, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import assert from 'node:assert/strict';
const database = process.argv[3] ?? 'fishbound-dev-local';
assert(database === 'fishbound-dev-local' || /^fishbound-proof-/.test(database));
const tables = [...(await readFile('spacetimedb/src/tables.rs','utf8')).matchAll(/accessor = (\w+)/g)].map(m=>m[1]).filter(t=>t!=='maintenance_job');
function query(table) {
 const result=spawnSync('spacetime',['sql','--server','http://127.0.0.1:3127','--no-config','--format','json',database,`SELECT * FROM ${table}`],{encoding:'utf8',windowsHide:true});
 if(result.status!==0)throw Error(`Snapshot failed for ${table}`);
 return JSON.parse(result.stdout).flatMap(block=>block.rows.map(row=>Object.fromEntries(row.map((value,i)=>[block.schema.elements[i].name.some,value]))));
}
const snapshot=Object.fromEntries(tables.map(t=>[t,query(t)]));
const pointer=`.local/${database}-xp-before.json`;
const canonical=rows=>rows.map(row=>JSON.stringify(row)).sort();
if(process.argv[2]==='before') {
 await writeFile(pointer,JSON.stringify(snapshot));
 console.log(`Saved ${tables.length} tables before XP activation.`);
} else if(process.argv[2]==='after') {
 const before=JSON.parse(await readFile(pointer,'utf8'));
 const updated=['game_config','bait_definition','shop_listing','public_profile'];
 for(const table of tables.filter(t=>!updated.includes(t)))assert.deepEqual(canonical(snapshot[table]),canonical(before[table]),`${table} must be preserved`);
 const current=snapshot.game_config.find(row=>row.version===7);assert(current);assert.equal(current.level_cap,120);
 assert.deepEqual(canonical(snapshot.game_config.filter(row=>row.version!==7)),canonical(before.game_config.filter(row=>row.version!==7)));
 assert.equal(snapshot.bait_definition.length,8);assert.equal(snapshot.shop_listing.length,20);
 const content=JSON.parse(await readFile('content/crafting.json','utf8'));
 for(const row of snapshot.bait_definition)assert.equal(row.xp_bonus_bp,content.baits.find(b=>b.baitId===row.bait_id).xpBonusBp);
 for(const old of before.bait_definition)assert.deepEqual(snapshot.bait_definition.find(row=>row.bait_id===old.bait_id),{...old,xp_bonus_bp:old.xp_bonus_bp??0});
 for(const old of before.shop_listing)assert.deepEqual(snapshot.shop_listing.find(row=>row.listing_id===old.listing_id),{...old,catalog_version:3});
 for(const old of before.public_profile) {
  const current=snapshot.public_profile.find(row=>row.player_id===old.player_id);
  assert.deepEqual({...current,level:old.level},old);
  let xp=BigInt(snapshot.player.find(row=>row.player_id===old.player_id).total_xp),level=1;
  while(level<120){const needed=BigInt(80+25*level+5*level*level);if(xp<needed)break;xp-=needed;level++;}
  assert.equal(current.level,level);
 }
 console.log(`PASS ${tables.length-updated.length} gameplay tables preserved byte-for-byte; config, bait metadata, shop versions and profile levels match activation.`);
} else throw Error('Use before or after');
