import assert from 'node:assert/strict';
import { test } from 'node:test';
import { materialBalances } from '../apps/web/src/lib/game/materials-ui.ts';

const stack = (slot, item, quantity) => ({ key: (123456789n << 32n) + BigInt(slot), item, quantity });

test('Basic and raid grades retain distinct slots, names, art and bigint quantities', () => {
  const huge = 9007199254740999n;
  const rows = materialBalances([
    stack(800, 'normal_fillets', huge), stack(801, 'normal_scales', 2n),
    stack(1000, 'raid_fillets_f', 7n), stack(1100, 'raid_scales_f', 8n),
    stack(1001, 'raid_fillets_d', 3n), stack(900, 'raid_supplies', 4n),
    stack(1, 'rusted_tin', 9n), stack(2, 'scrap', 11n), stack(7777, 'secret_internal_name', 12n),
  ]);
  const byLabel = label => rows.find(row => row.label === label);
  assert.equal(byLabel('Basic fillets').quantity, huge);
  assert.equal(byLabel('F-grade fillets').quantity, 7n);
  assert.equal(byLabel('D-grade fillets').quantity, 3n);
  assert.equal(byLabel('Basic scales').quantity, 2n);
  assert.equal(byLabel('F-grade scales').quantity, 8n);
  assert.equal(byLabel('Expedition Chum').quantity, 4n);
  assert.equal(byLabel('Rusted tin').quantity, 9n);
  assert.equal(byLabel('Scrap').quantity, 11n);
  assert.equal(byLabel('Basic fillets').image, byLabel('F-grade fillets').image);
  assert.equal(byLabel('Unknown item').quantity, 12n);
  assert(!JSON.stringify(rows, (_key, value) => typeof value === 'bigint' ? value.toString() : value).includes('secret_internal_name'));
});

test('owned only by default, optional empty balances include every grade', () => {
  const input = [stack(1000, 'raid_fillets_f', 0n), stack(1234, 'mystery', 3n)];
  assert.deepEqual(materialBalances(input).map(row => row.label), ['Unknown item']);
  const all = materialBalances(input, true);
  assert.equal(all.filter(row => row.group === 'graded').length, 20);
  assert.equal(all.find(row => row.label === 'F-grade fillets').quantity, 0n);
  assert.equal(all.filter(row => row.label === 'Unknown item').length, 1);
});
