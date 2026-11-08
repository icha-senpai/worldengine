import assert from 'node:assert/strict';
import { test } from 'node:test';
import { formatCampRoute, parseCampHash } from '../apps/web/src/lib/game/camp-navigation.ts';

test('legacy links and canonical sections resolve without changing base views', () => {
  assert.deepEqual(parseCampHash('#tideforge'), { view: 'tideforge', section: 'gear' });
  assert.deepEqual(parseCampHash('#trader'), { view: 'trader', section: 'camp' });
  assert.deepEqual(parseCampHash('#inventory'), { view: 'inventory', section: undefined });
  assert.deepEqual(parseCampHash('#collection'), { view: 'compendium', section: 'collection' });
  assert.deepEqual(parseCampHash('', 'collection'), { view: 'compendium', section: 'collection' });
  assert.equal(formatCampRoute('collection'), '#compendium/collection');
  assert.deepEqual(parseCampHash('#tideforge/vault'), { view: 'tideforge', section: 'materials' });
  assert.deepEqual(parseCampHash('#tideforge/trader'), { view: 'trader', section: 'additional' });
  assert.deepEqual(parseCampHash('#compendium/collection'), { view: 'compendium', section: 'collection' });
  assert.deepEqual(parseCampHash('#compendium/field-guide'), { view: 'compendium', section: 'field-guide' });
  for (const section of ['gear', 'forge', 'processing', 'materials', 'codex', 'reliquary']) {
    assert.deepEqual(parseCampHash(`#tideforge/${section}`), { view: 'tideforge', section });
    assert.equal(formatCampRoute('tideforge', section), `#tideforge/${section}`);
  }
  for (const section of ['camp', 'additional', 'wandering']) {
    assert.deepEqual(parseCampHash(`#trader/${section}`), { view: 'trader', section });
    assert.equal(formatCampRoute('trader', section), `#trader/${section}`);
  }
});

test('unknown and malformed sections safely fall back without corrupting aliases', () => {
  assert.deepEqual(parseCampHash('#tideforge/unknown'), { view: 'tideforge', section: 'gear' });
  assert.deepEqual(parseCampHash('#trader/unknown'), { view: 'trader', section: 'camp' });
  assert.deepEqual(parseCampHash('#tideforge/forge/extra'), { view: 'tideforge', section: 'gear' });
  assert.deepEqual(parseCampHash('#unknown/forge'), { view: 'camp', section: undefined });
  assert.equal(formatCampRoute('tideforge', 'trader'), '#trader/additional');
  assert.equal(formatCampRoute('tideforge', 'vault'), '#tideforge/materials');
});
