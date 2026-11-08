import assert from 'node:assert/strict';
import { test } from 'node:test';
import { guideResourceLabel, guideResourceOwned, guideShortages, tideforgeCostGuide } from '../apps/web/src/lib/game/tideforge-cost-guide.ts';

const roll = { id: 'fortune', tier: 2, slot: 0, finishBp: 5000 };
const gear = { instance_id: '100' };
const convert = { action: 'convert_normal', material_id: 0, batches: 3 };
const refine = { action: 'refine_material', material_id: 0, from_grade: 0, to_grade: 2, batches: 2 };
const resourceAmounts = rows => rows.map(row => [row.kind === 'material' ? row.item : row.affixId, row.amount]);

test('Basic conversion uses a separate balance, an F clear and the configured whole-batch recipe', () => {
  const { plan, problem } = tideforgeCostGuide(convert);
  assert.equal(problem, '');
  assert.deepEqual(resourceAmounts(plan.inputs), [['normal_fillets', 30n]]);
  assert.deepEqual(resourceAmounts(plan.outputs), [['raid_fillets_f', 3n]]);
  assert.deepEqual(plan.requiredGrades, [0]);
  assert.equal(plan.coins, 0n);
  assert.equal(plan.inputs[0].slot, 800);
  assert.equal(plan.outputs[0].slot, 1000);
  const scales = tideforgeCostGuide({ ...convert, material_id: 1 }).plan;
  assert.deepEqual(resourceAmounts(scales.inputs), [['normal_scales', 30n]]);
  assert.equal(scales.inputs[0].slot, 801);
  assert.equal(scales.outputs[0].slot, 1100);
});

test('multi-grade refining retains intermediate remainders and charges each actual batch', () => {
  const { plan, problem } = tideforgeCostGuide(refine);
  assert.equal(problem, '');
  assert.deepEqual(resourceAmounts(plan.inputs), [['raid_fillets_f', 40n]]);
  assert.deepEqual(resourceAmounts(plan.outputs), [['raid_fillets_d', 16n], ['raid_fillets_c', 18n]]);
  assert.deepEqual(plan.requiredGrades, [1, 2]);
  assert.equal(plan.coins, 3n);
  const longer = tideforgeCostGuide({ ...refine, batches: 3, to_grade: 3 }).plan;
  assert.deepEqual(resourceAmounts(longer.outputs), [['raid_fillets_d', 14n], ['raid_fillets_c', 16n], ['raid_fillets_b', 18n]]);
  assert.equal(longer.coins, 6n);
  const single = tideforgeCostGuide({ ...refine, to_grade: 1, batches: 1 }).plan;
  assert.deepEqual(resourceAmounts(single.outputs), [['raid_fillets_d', 18n]]);
  assert.equal(single.coins, 1n);
});

test('unreachable multi-grade targets do not borrow existing higher-grade inventory', () => {
  const result = tideforgeCostGuide({ ...refine, batches: 1 });
  assert.equal(result.plan, null);
  assert.match(result.problem, /cannot reach.*Increase batches/);
  for (const invalid of [
    { ...refine, batches: 0 }, { ...refine, batches: 1001 }, { ...refine, batches: 1.5 },
    { ...refine, material_id: 2 }, { ...refine, to_grade: 0 }, { ...refine, to_grade: 10 },
  ]) assert.equal(tideforgeCostGuide(invalid).plan, null);
});

test('Forge add, change and multi-tier upgrades identify exact grade materials and shards', () => {
  const add = tideforgeCostGuide({ ...gear, action: 'add_affix', affix_id: 'fortune', secondary_biome_id: null }).plan;
  assert.deepEqual(resourceAmounts(add.inputs), [['raid_fillets_f', 15n], ['raid_scales_f', 10n], ['fortune', 1n]]);
  assert.deepEqual(add.requiredGrades, [0]);
  const change = tideforgeCostGuide({ ...gear, action: 'change_affix', slot: 0, affix_id: 'fortune', secondary_biome_id: null }).plan;
  assert.deepEqual(resourceAmounts(change.inputs), [['fortune', 1n]]);
  assert.equal(change.coins, 0n);
  assert.deepEqual(change.requiredGrades, []);
  const upgrade = tideforgeCostGuide({ ...gear, action: 'upgrade_affix', slot: 0, target_tier: 4 }, roll).plan;
  assert.deepEqual(resourceAmounts(upgrade.inputs), [
    ['raid_fillets_c', 36n], ['raid_scales_c', 24n], ['raid_fillets_b', 44n], ['raid_scales_b', 30n],
  ]);
  assert.deepEqual(upgrade.requiredGrades, [2, 3]);
  assert.equal(tideforgeCostGuide({ ...gear, action: 'upgrade_affix', slot: 1, target_tier: 4 }, roll).plan, null);
});

test('strength guidance uses the next effective step and cumulative ceiling coin difference', () => {
  const intent = { ...gear, action: 'refine_affix', slot: 0 };
  const next = tideforgeCostGuide(intent, roll).plan;
  assert.equal(next.nextFinishBp, 6000);
  assert.equal(next.coins, 125n);
  const fractional = tideforgeCostGuide(intent, { ...roll, finishBp: 5001 }).plan;
  assert.equal(fractional.coins, 124n);
  const small = { ...roll, id: 'haul_appraiser', tier: 1, finishBp: 0 };
  const skipped = tideforgeCostGuide(intent, small).plan;
  assert.equal(skipped.nextFinishBp, 6000);
  assert.equal(skipped.coins, 750n);
  assert.equal(tideforgeCostGuide(intent, { ...roll, finishBp: 10000 }).plan, null);
  assert.equal(tideforgeCostGuide({ ...intent, slot: 1 }, roll).plan, null);
  assert.equal(tideforgeCostGuide(intent, { ...roll, id: 'unknown-affix' }).plan, null);
});

test('shortages name the grade, material, shard and actual deficit without numeric precision loss', () => {
  const plan = tideforgeCostGuide({ ...gear, action: 'add_affix', affix_id: 'fortune', secondary_biome_id: null }).plan;
  const balances = {
    coins: 0n, items: [{ key: (9n << 32n) + 1000n, quantity: 10n }],
    shards: [], clear: () => false,
  };
  assert.deepEqual(guideShortages(plan, balances), [
    'A regular-fish raid clear at F grade is required.',
    'Need 5 more F-grade fillets (10 / 15).',
    'Need 10 more F-grade scales (0 / 10).',
    'Need 1 more Fortune shards (0 / 1).',
  ]);
  const large = 900719925474099312345n;
  balances.items = [{ key: (9n << 32n) + 1000n, quantity: large }];
  assert.equal(guideResourceOwned(plan.inputs[0], balances), large);
  assert.equal(guideResourceLabel(plan.inputs[2]), 'Fortune shards');
  assert.equal(guideResourceLabel({ kind: 'shard', affixId: '<unknown>', amount: 1n }), 'Unknown affix shards');
  assert.equal(guideResourceLabel({ kind: 'material', item: '<unknown>', slot: 99, amount: 1n }), 'Unknown material');
});
