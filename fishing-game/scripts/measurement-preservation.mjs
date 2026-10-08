// Owner-only SQL reads around the local additive measurement deployment.
import { readFile, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import assert from 'node:assert/strict';
const tables = [...(await readFile(new URL('../spacetimedb/src/tables.rs', import.meta.url), 'utf8')).matchAll(/accessor = (\w+)/g)].map(match => match[1]).filter(name => name !== 'maintenance_job');
function query(table) {
  const result = spawnSync('spacetime', ['sql', '--server', 'http://127.0.0.1:3127', '--no-config', '--format', 'json', 'fishbound-dev-local', `SELECT * FROM ${table}`], { encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw new Error(`Preservation snapshot failed: ${table}`);
  return JSON.parse(result.stdout).flatMap(block => block.rows.map(row => Object.fromEntries(row.map((value, index) => [block.schema.elements[index].name.some, value]))));
}
const snapshot = Object.fromEntries(tables.map(table => [table, query(table)]));
const pointer = '.local/measurements-live-snapshot-path.txt';
if (process.argv[2] === 'before') {
  const path = `.local/measurements-live-before-${Date.now()}.json`;
  await writeFile(path, JSON.stringify(snapshot), 'utf8');
  await writeFile(pointer, path, 'utf8');
  console.log(`Saved ${tables.length} table snapshots, including all catch/history/economy/equipment data.`);
} else if (process.argv[2] === 'after') {
  const path = (await readFile(pointer, 'utf8')).trim();
  const before = JSON.parse(await readFile(path, 'utf8'));
  const canonical = rows => rows.map(row => JSON.stringify(row)).sort();
  for (const table of tables.filter(name => name !== 'species_definition')) {
    assert.deepEqual(canonical(snapshot[table]), canonical(before[table]), `Live table changed: ${table}`);
  }
  const catalog = JSON.parse(await readFile('content/species.json', 'utf8')).species;
  const measurements = new Set(['typical_length_mm', 'min_length_mm', 'max_length_mm', 'typical_weight_g', 'min_weight_g', 'max_weight_g']);
  const metadata = row => Object.fromEntries(Object.entries(row).filter(([field]) => !measurements.has(field)));
  assert.equal(snapshot.species_definition.length, 251);
  for (const row of snapshot.species_definition) {
    const fish = catalog.find(fish => fish.speciesId === row.species_id);
    assert.deepEqual(metadata(row), metadata(before.species_definition.find(prior => prior.species_id === row.species_id)));
    assert.equal(row.typical_length_mm, fish.typicalLengthMm);
    assert.equal(row.typical_weight_g, fish.typicalWeightG);
    assert.equal(row.min_length_mm, Math.floor(fish.typicalLengthMm * 55 / 100));
    assert.equal(row.max_length_mm, Math.floor(fish.typicalLengthMm * 19 / 10));
    assert.equal(row.min_weight_g, Math.max(1, Math.floor(fish.typicalWeightG / 8)));
    assert.equal(row.max_weight_g, fish.typicalWeightG * 8);
  }
  await writeFile('.local/measurements-live-preserved.json', JSON.stringify({ species: 251, preservedTables: tables.length - 1, catalogVersion: 5, snapshot: path }, null, 2), 'utf8');
  console.log(`PASS live update: 251 measurement definitions match v5; all ${tables.length - 1} other tables and non-measurement species fields preserved exactly.`);
} else {
  throw new Error('Use before or after');
}
