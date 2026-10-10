import { readFileSync, writeFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import assert from 'node:assert/strict';
const [mode, database] = process.argv.slice(2);
if (!['before', 'after'].includes(mode) || !(database === 'fishbound-dev-local' || /^fishbound-proof-/.test(database ?? ''))) throw Error('Specify before/after and a local Fishbound database.');
const tables = [...readFileSync('spacetimedb/src/tables.rs', 'utf8').matchAll(/accessor = (\w+)/g)].map(m => m[1]).filter(t => !['maintenance_job', 'achievement_collection', 'achievement_definition', 'achievement_progress', 'earned_achievement'].includes(t));
function rows(table) {
  const result = spawnSync('spacetime', ['sql', '--server', 'http://127.0.0.1:3127', '--no-config', '--format', 'json', database, `SELECT * FROM ${table}`], { encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw Error(`Snapshot failed: ${table}`);
  return JSON.parse(result.stdout).flatMap(block => block.rows.map(row => JSON.stringify(row))).sort();
}
const legacyDefinitions = rows('achievement_definition').map(row => JSON.parse(row)).filter(row => row[0] <= 18).map(row => {
  // The existing Pond requirement text is clarified; its badge, title and target stay intact.
  if (row[0] === 10) row[2] = '';
  return JSON.stringify(row);
}).sort();
const snapshot = { gameplay: Object.fromEntries(tables.map(table => [table, rows(table)])), awards: rows('earned_achievement'), legacyDefinitions };
const file = `.local/achievements-before-${database}.json`;
if (mode === 'before') {
  writeFileSync(file, JSON.stringify(snapshot));
  console.log(`Saved ${tables.length} gameplay tables and all existing award dates.`);
} else {
  const before = JSON.parse(readFileSync(file, 'utf8'));
  assert.deepEqual(snapshot.gameplay, before.gameplay);
  assert.deepEqual(snapshot.legacyDefinitions, before.legacyDefinitions);
  for (const award of before.awards) assert(snapshot.awards.includes(award), 'An existing award changed');
  console.log(`PASS all ${tables.length} gameplay tables unchanged, all original awards and dates preserved.`);
}
