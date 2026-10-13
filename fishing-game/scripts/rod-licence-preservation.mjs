import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

const [mode, file, server = 'http://127.0.0.1:3127'] = process.argv.slice(2);
assert(['before', 'retained', 'activated'].includes(mode));
assert(file && /^http:\/\/127\.0\.0\.1:\d+$/.test(server));
const database = 'fishbound-dev-local';
const tables = [...readFileSync('spacetimedb/src/tables.rs', 'utf8').matchAll(/accessor = (\w+)/g)].map(match => match[1]).filter(table => table !== 'maintenance_job');
const snapshot = Object.fromEntries(tables.map(table => {
  const result = spawnSync('spacetime', ['sql', '--server', server, '--no-config', '--format', 'json', database, `SELECT * FROM ${table}`], { encoding: 'utf8', windowsHide: true });
  assert.equal(result.status, 0, `Read failed: ${table}`);
  const blocks = JSON.parse(result.stdout, (_key, value, context) => typeof value === 'number' && !Number.isSafeInteger(value) ? context.source : value);
  return [table, blocks.flatMap(block => block.rows.map(row => JSON.stringify(row))).sort()];
}));
if (mode === 'before') writeFileSync(file, JSON.stringify(snapshot));
else {
  const expected = JSON.parse(readFileSync(file, 'utf8'));
  if (mode === 'activated') {
    const previous = [5, 6];
    expected.shop_listing = expected.shop_listing.map(serialized => {
      const row = JSON.parse(serialized);
      const index = [61, 71].indexOf(row[0]);
      if (index !== -1) {
        assert.equal(row[6], previous[index]);
        row[6] = 1;
      }
      assert.equal(row[8], 8);
      row[8] = 9;
      return JSON.stringify(row);
    }).sort();
  }
  assert.deepEqual(snapshot, expected, 'Every persisted row must match the intended catalog-only update');
}
console.log(`PASS ${mode}: ${tables.length} tables, ${Object.values(snapshot).reduce((total, rows) => total + rows.length, 0)} rows; scheduled maintenance excluded.`);
