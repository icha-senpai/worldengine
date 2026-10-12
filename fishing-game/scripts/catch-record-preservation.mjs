import { readFileSync, writeFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import assert from 'node:assert/strict';
const [mode, database, server = 'http://127.0.0.1:3127', file = `.local/${database}-catch-records-before.json`] = process.argv.slice(2);
assert(['before', 'after', 'restored'].includes(mode));
assert(database === 'fishbound-dev-local' || /^fishbound-proof-/.test(database ?? ''));
assert(/^http:\/\/127\.0\.0\.1:\d+$/.test(server));
const tables = [...readFileSync('spacetimedb/src/tables.rs', 'utf8').matchAll(/accessor = (\w+)/g)].map(m => m[1]).filter(t => t !== 'maintenance_job');
const snapshot = Object.fromEntries(tables.map(table => {
  const result = spawnSync('spacetime', ['sql', '--server', server, '--no-config', '--format', 'json', database, `SELECT * FROM ${table}`], {encoding:'utf8',windowsHide:true});
  assert.equal(result.status, 0, `Read failed: ${table}`);
  return [table, JSON.parse(result.stdout).flatMap(block => block.rows.map(row => Object.fromEntries(row.map((v,i) => [block.schema.elements[i].name.some,v]))))];
}));
const canonical = rows => rows.map(row => JSON.stringify(row)).sort();
if (mode === 'before') writeFileSync(file, JSON.stringify(snapshot));
else {
  const before = JSON.parse(readFileSync(file, 'utf8'));
  for (const table of tables) {
    if (table === 'angler_statistics' && mode === 'after') {
      const retained = rows => rows.map(({records_set, records_tracked_since, biggest_single_sale, ...rest}) => rest);
      assert.deepEqual(canonical(retained(snapshot[table])), canonical(retained(before[table])), 'Only the three intended statistics fields may change');
      for (const old of before[table]) {
        const current = snapshot[table].find(row => row.player_id === old.player_id);
        assert(current.biggest_single_sale >= old.biggest_single_sale, 'Previously recorded fish value must survive');
      }
    } else assert.deepEqual(canonical(snapshot[table]), canonical(before[table]), `Retained table changed: ${table}`);
  }
}
console.log(`${mode === 'before' ? 'Saved' : 'PASS preserved'} ${tables.length} tables and ${Object.values(snapshot).reduce((n,rows) => n + rows.length,0)} rows; ${mode === 'after' ? 'only three intended statistics fields may differ' : 'all fields compared'}.`);
