import assert from 'node:assert/strict';
import test from 'node:test';
import {
  firstEnabledIndex,
  lastEnabledIndex,
  nextEnabledIndex,
  parseNumberValue,
  positionFloatingMenu,
  stepNumberValue,
  typeaheadIndex
} from '../apps/web/src/lib/game/custom-control-logic.ts';
import { registerOpenPopover } from '../apps/web/src/lib/game/custom-control-popovers.ts';

const options = [
  { value: 'dockside', label: 'Dockside' },
  { value: 'disabled', label: 'Disabled option', disabled: true },
  { value: 'deep', label: 'Deep Ocean' },
  { value: 'rose', label: 'Rose' }
];

test('custom option navigation skips disabled entries and wraps', () => {
  assert.equal(firstEnabledIndex(options), 0);
  assert.equal(lastEnabledIndex(options), 3);
  assert.equal(nextEnabledIndex(options, 0, 1), 2);
  assert.equal(nextEnabledIndex(options, 2, -1), 0);
  assert.equal(nextEnabledIndex([{ value: false, label: 'No', disabled: true }], 0, 1), -1);
});

test('typeahead matches labels, skips disabled options, and cycles repeated letters', () => {
  assert.equal(typeaheadIndex(options, -1, 'd'), 0);
  assert.equal(typeaheadIndex(options, 0, 'd'), 2);
  assert.equal(typeaheadIndex(options, 2, 'r'), 3);
  assert.equal(typeaheadIndex(options, 3, 'r'), 3);
});

test('number parsing preserves invalid intermediate text and step buttons clamp', () => {
  assert.equal(Number.isNaN(parseNumberValue('')), true);
  assert.equal(Number.isNaN(parseNumberValue('-')), true);
  assert.equal(parseNumberValue('3'), 3);
  assert.equal(stepNumberValue(9, 1, 10, 1, 1), 10);
  assert.equal(stepNumberValue(1, 1, 10, 1, -1), 1);
  assert.equal(stepNumberValue(1.5, 1, 10, 1, 1), 2);
  assert.equal(stepNumberValue(1.5, 1, 10, 1, -1), 1);
  assert.equal(stepNumberValue(0.3, 0, 1, 0.1, 1), 0.4);
  assert.equal(stepNumberValue(Number.NaN, 1, 10, 1, 1), 1);
  assert.equal(stepNumberValue(Number.NaN, 1, 10, 1, -1), 10);
});

test('custom menus clamp to the viewport and flip when space below is limited', () => {
  const above = positionFloatingMenu(
    { left: 300, top: 680, right: 360, bottom: 720, width: 60 },
    390, 760, 300
  );
  assert.equal(above.placement, 'above');
  assert(above.left >= 12 && above.left + above.width <= 378);
  assert(above.top >= 12 && above.top + above.height <= 748);
  assert(above.maxHeight <= 320);
});

test('only one custom popover remains open', () => {
  const closed = [];
  const releaseFirst = registerOpenPopover(() => closed.push('first'));
  const releaseSecond = registerOpenPopover(() => closed.push('second'));
  assert.deepEqual(closed, ['first']);
  releaseSecond();
  releaseFirst();
});
