import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { levelForXp } from '../apps/web/src/lib/game/progression.ts';
import world from '../content/world.json' with { type: 'json' };
import rules from '../content/game-rules.json' with { type: 'json' };

const [mode, file, database = 'fishbound-dev-local', server = 'http://127.0.0.1:3127'] = process.argv.slice(2);
assert(['before', 'retained', 'activated'].includes(mode));
assert(file && (database === 'fishbound-dev-local' || /^fishbound-proof-/.test(database)));
assert(/^http:\/\/127\.0\.0\.1:\d+$/.test(server));
const tables = [...readFileSync('spacetimedb/src/tables.rs', 'utf8').matchAll(/accessor = (\w+)/g)].map(match => match[1]).filter(table => table !== 'maintenance_job');
const snapshot = Object.fromEntries(tables.map(table => {
  const result = spawnSync('spacetime', ['sql', '--server', server, '--no-config', '--format', 'json', database, `SELECT * FROM ${table}`], { encoding: 'utf8', windowsHide: true });
  assert.equal(result.status, 0, `Read failed: ${table}`);
  const blocks = JSON.parse(result.stdout, (_key, value, context) => typeof value === 'number' && !Number.isSafeInteger(value) ? context.source : value);
  return [table, blocks.flatMap(block => block.rows.map(row => Object.fromEntries(row.map((value, index) => [block.schema.elements[index].name.some, value]))))];
}));
const canonical = rows => rows.map(row => JSON.stringify(row)).sort();
if (mode === 'before') writeFileSync(file, JSON.stringify(snapshot));
else {
  const expected = JSON.parse(readFileSync(file, 'utf8'));
  if (mode === 'activated') {
    assert.equal(world.levelCap, 500);
    const prior = expected.game_config.reduce((highest, row) => row.version > highest.version ? row : highest);
    assert(prior.version < rules.version);
    expected.game_config.push({ ...prior, version: rules.version, level_cap: world.levelCap });
    expected.public_profile = expected.public_profile.map(profile => ({
      ...profile,
      level: levelForXp(BigInt(expected.player.find(player => player.player_id === profile.player_id).total_xp), world.levelCap, rules.version)
    }));
  }
  for (const table of tables) assert.deepEqual(canonical(snapshot[table]), canonical(expected[table]), `${table}: every row/field must match the display-cap-only change`);
}
console.log(`PASS ${mode}: ${tables.length} tables, ${Object.values(snapshot).reduce((total, rows) => total + rows.length, 0)} rows; lifetime XP, wallets, gear and saved rewards retained.`);
