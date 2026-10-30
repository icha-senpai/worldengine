import assert from 'node:assert/strict';
import { coreQueries, viewQueries } from '../apps/web/src/lib/game/subscriptions.ts';
assert(!coreQueries.some(query => /my_inventory|my_journal|earned_achievement|public_profile|angler_statistics/.test(query)));
assert.deepEqual(viewQueries('journal', 12n), ['SELECT * FROM my_journal']);
assert(viewQueries('quests', 12n).includes('SELECT * FROM my_inventory'));
assert(viewQueries('achievements', 12n).includes('SELECT * FROM earned_achievement WHERE player_id = 12'));
assert(!viewQueries('achievements').some(query => /WHERE/.test(query)));
assert(viewQueries('anglers').includes('SELECT * FROM earned_achievement'));
assert(viewQueries('anglers').includes('SELECT * FROM angler_standing'),
  'Angler profiles need lifetime UUR catches and records held without visiting Records first');
assert(!viewQueries('inventory', 12n, 8).some(query => query.includes('rod_upgrade_recipe')));
assert(viewQueries('inventory', 12n, 9).some(query => query.includes('rod_upgrade_recipe')));
assert(!coreQueries.some(query => /raid_|community_raid|rod_reinforcement/.test(query)));
assert(!viewQueries('quests', 12n, 16).some(query => /raid_|community_raid|rod_reinforcement/.test(query)),
  'Version 16 must not receive unsupported raid queries');
const raids = viewQueries('quests', 12n, 17);
for (const table of ['raid_grade_definition', 'community_raid_event', 'my_raid_attempts', 'my_raid_actions', 'my_rod_reinforcements'])
  assert(raids.includes(`SELECT * FROM ${table}`));
assert(raids.includes('SELECT * FROM raid_badge WHERE player_id = 12'));
assert(raids.includes('SELECT * FROM raid_statistics WHERE player_id = 12'));
assert(!viewQueries('camp', undefined, 17).some(query => query.includes('raid_badge')));
assert(viewQueries('anglers', undefined, 17).includes('SELECT * FROM raid_statistics'));
assert(!viewQueries('camp', undefined, 17).includes('SELECT * FROM raid_statistics'));
console.log('PASS tab subscriptions, player scopes and legacy recipe compatibility.');
