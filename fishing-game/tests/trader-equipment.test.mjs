import assert from 'node:assert/strict';
import { equipmentComparison } from '../apps/web/src/lib/game/trader-equipment.ts';

const haulEquipment = [
  { equipmentId: 10, kind: 'boat', name: 'Leaky Rowboat' },
  { equipmentId: 11, kind: 'boat', name: 'Reed Skiff' },
  { equipmentId: 20, kind: 'net', name: 'Frayed Net' },
  { equipmentId: 21, kind: 'net', name: 'Silk Net' },
];
const haulStatTable = {
  10: { luckBp: 500, xpBonusBp: 500 },
  11: { luckBp: 900, xpBonusBp: 200 },
  20: { luckBp: 2000, xpBonusBp: 3000 },
  21: { luckBp: 2500, xpBonusBp: 1000 },
};
const rodStatTable = {
  1: { power: 5, luckBp: 200, xpBonusBp: 300 },
  2: { power: 9, luckBp: 500, xpBonusBp: 100 },
};
const haulCalls = [];
const rodCalls = [];
const game = (overrides = {}) => ({
  player: { playerId: 1n, equippedRodId: 1 },
  rods: [{ rodId: 1 }, { rodId: 2 }],
  haulEquipment,
  haulLoadout: { playerId: 1n, boatId: 10, netId: 20 },
  haulStats: id => { haulCalls.push(id); return haulStatTable[id]; },
  rodStats: id => { rodCalls.push(id); return rodStatTable[id]; },
  ...overrides,
});
const listing = (kind, targetId) => ({ kind, targetId });

// Boat comparisons use the equipped boatId even when the equipped net has
// stronger stats; the net must never become a boat baseline.
haulCalls.length = 0;
assert.equal(equipmentComparison(game(), listing('boat', 11)), '+4% luck · -3% XP vs equipped');
assert.deepEqual(haulCalls, [11, 10], 'Boat rows compare against the equipped boat');

// Net rows use netId, not the boat slot.
haulCalls.length = 0;
assert.equal(equipmentComparison(game(), listing('net', 21)), '+5% luck · -20% XP vs equipped');
assert.deepEqual(haulCalls, [21, 20], 'Net rows compare against the equipped net');

// Positive and negative deltas on one card; zero keeps its plus sign.
const upgraded = game({
  haulStats: id => ({ 10: { luckBp: 1200, xpBonusBp: 800 }, 11: { luckBp: 400, xpBonusBp: 800 } }[id]),
});
assert.equal(equipmentComparison(upgraded, listing('boat', 11)), '-8% luck · +0% XP vs equipped',
  'Both sides use per-item upgraded stats from haulStats');

// Comparing the equipped piece against itself still reports +0 deltas.
assert.equal(equipmentComparison(game(), listing('boat', 10)), '+0% luck · +0% XP vs equipped');
assert.equal(equipmentComparison(game(), listing('net', 20)), '+0% luck · +0% XP vs equipped');

// Haul comparisons need a player, a loadout, and definitions of the right kind.
assert.equal(equipmentComparison(game({ player: null }), listing('boat', 11)), '');
assert.equal(equipmentComparison(game({ haulLoadout: null }), listing('boat', 11)), '');
assert.equal(equipmentComparison(game({ haulLoadout: { playerId: 1n, boatId: 99, netId: 20 } }), listing('boat', 11)), '',
  'A missing baseline definition yields no comparison');
assert.equal(equipmentComparison(game({ haulEquipment: haulEquipment.filter(gear => gear.equipmentId !== 11) }), listing('boat', 11)), '',
  'A missing listed definition yields no comparison');
const wrongBaselineKind = game({ haulEquipment: [
  { equipmentId: 10, kind: 'net', name: 'Frayed Net' },
  { equipmentId: 11, kind: 'boat', name: 'Reed Skiff' },
  { equipmentId: 20, kind: 'net', name: 'Frayed Net' },
] });
assert.equal(equipmentComparison(wrongBaselineKind, listing('boat', 11)), '',
  'An equipped id resolving to a different kind yields no comparison');
const wrongTargetKind = game({ haulEquipment: [
  { equipmentId: 10, kind: 'boat', name: 'Leaky Rowboat' },
  { equipmentId: 11, kind: 'net', name: 'Silk Net' },
  { equipmentId: 20, kind: 'net', name: 'Frayed Net' },
] });
assert.equal(equipmentComparison(wrongTargetKind, listing('boat', 11)), '',
  'A listed definition of the wrong kind yields no comparison even with a valid baseline');

// Fractional basis-point deltas keep their decimal percentages.
const fractional = game({
  haulStats: id => ({ 10: { luckBp: 500, xpBonusBp: 500 }, 11: { luckBp: 650, xpBonusBp: 425 } }[id]),
});
assert.equal(equipmentComparison(fractional, listing('boat', 11)), '+1.5% luck · -0.75% XP vs equipped');

// Rods keep power, luck and XP deltas against the equipped rod.
assert.equal(equipmentComparison(game(), listing('rod', 2)), '+4 power · +3% luck · -2% XP vs equipped');
assert.equal(equipmentComparison(game({ rods: [] }), listing('rod', 2)), '', 'No equipped rod means no comparison');
assert.equal(equipmentComparison(game({ player: null }), listing('rod', 2)), '');

// Bait, licences and unknown kinds never produce a comparison.
assert.equal(equipmentComparison(game(), listing('bait', 5)), '');
assert.equal(equipmentComparison(game(), listing('licence', 3)), '');

console.log('PASS trader equipment comparisons for rods, boats, nets and unsupported kinds.');
