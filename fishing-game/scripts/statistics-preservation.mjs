import { readFileSync, writeFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import assert from 'node:assert/strict';
const [mode, database, server = 'http://127.0.0.1:3127', file = `.local/${database}-statistics-before.json`] = process.argv.slice(2);
assert(['before', 'after'].includes(mode));
assert(database === 'fishbound-dev-local' || /^fishbound-proof-/.test(database));
assert(/^http:\/\/127\.0\.0\.1:\d+$/.test(server));
const added = ['angler_statistics', 'fishing_day', 'fishing_biome_activity'];
const tables = [...readFileSync('spacetimedb/src/tables.rs', 'utf8').matchAll(/accessor = (\w+)/g)].map(m => m[1]).filter(t => t !== 'maintenance_job' && !added.includes(t));
const snapshot = Object.fromEntries(tables.map(table => {
  const result = spawnSync('spacetime', ['sql', '--server', server, '--no-config', '--format', 'json', database, `SELECT * FROM ${table}`], {encoding:'utf8',windowsHide:true});
  assert.equal(result.status, 0, `Read failed: ${table}`);
  return [table, JSON.parse(result.stdout).flatMap(block => block.rows.map(row => JSON.stringify(row))).sort()];
}));
if (mode === 'before') writeFileSync(file, JSON.stringify(snapshot));
else assert.deepEqual(snapshot, JSON.parse(readFileSync(file, 'utf8')), 'Existing rows must remain identical');
console.log(`${mode === 'before' ? 'Saved' : 'PASS preserved'} ${tables.length} existing tables, ${Object.values(snapshot).reduce((sum, rows) => sum + rows.length, 0)} rows. Only three statistics tables are additive; scheduled maintenance is excluded.`);
