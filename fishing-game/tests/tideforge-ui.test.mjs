import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import catalog from '../content/species.json' with { type: 'json' };
import { rankSpreads } from '../apps/web/src/lib/game/compendium.ts';
import { specimenProcessingProtection } from '../apps/web/src/lib/game/tideforge-processing.ts';
import { affixDescription, affixSlotState, affixValues, canConsume, forgeAffixChoices, forgeWaterChangeAllowed, gearSprite, orderGearForDisplay, rolls, signatureDescription, toggleProcessingSelection, waterOptions } from '../apps/web/src/lib/game/tideforge-ui.ts';

test('gear display prioritizes equipped, then favorites, stably without changing source', () => {
  const items = [
    { instanceId: 1n, kind: 'net', favorite: false },
    { instanceId: 2n, kind: 'boat', favorite: true },
    { instanceId: 9007199254740993n, kind: 'rod', favorite: true },
    { instanceId: 4n, kind: 'rod', favorite: false },
    { instanceId: 5n, kind: 'net', favorite: true },
    { instanceId: 6n, kind: 'boat', favorite: false },
    { instanceId: 7n, kind: 'net', favorite: false },
  ];
  const original = [...items];
  items.forEach(Object.freeze);
  Object.freeze(items);
  const equipped = new Set([9007199254740993n, 6n]);
  const sorted = orderGearForDisplay({ gearIsEquipped: id => equipped.has(id) }, items);
  assert.notEqual(sorted, items);
  assert.deepEqual(sorted.map(item => item.instanceId), [9007199254740993n, 6n, 2n, 5n, 1n, 4n, 7n]);
  assert.deepEqual(items, original);
  assert(sorted.every(item => original.includes(item)));
});

test('Tackle Box keeps fish and links to the canonical Gear and Materials views', async () => {
  const dashboard = await readFile(new URL('../apps/web/src/lib/game/Dashboard.svelte', import.meta.url), 'utf8');
  const tideforgeGear = await readFile(new URL('../apps/web/src/lib/game/TideforgeGear.svelte', import.meta.url), 'utf8');
  const records = await readFile(new URL('../apps/web/src/lib/game/TideforgeRecords.svelte', import.meta.url), 'utf8');
  assert.doesNotMatch(dashboard, /RaidWorkshop|tackle-(?:tab|panel)-workshop|id:\s*'workshop'|tackleView\s*===\s*'workshop'/);
  assert.match(dashboard, /openRoute\('tideforge', 'gear'\)/);
  assert.match(dashboard, /openRoute\('tideforge', 'materials'\)/);
  assert.match(dashboard, /<PageTurner bind:page=\{inventoryPage\} total=\{game\.inventory\.length\} pageSize=\{inventoryPageSize\}/);
  assert.doesNotMatch(dashboard, /<TackleBoxItems|import\('\.\/Gear\.svelte'\)/);
  assert.match(tideforgeGear, /<details class="loadout"><summary>Equipped gear, upgrades &amp; bait<\/summary>/);
  assert.match(tideforgeGear, /<Gear \{game\} \{active\} \/>/);
  assert.match(records, /<Materials \{game\} \{onNavigate\} \/>/);
});

test('gear art uses the correct catalog and equipment kind with safe fallbacks', () => {
  const game = {
    rods: [{ rodId: 2, spriteAsset: '/wrong.png' }],
    rodBonuses: [{ rodId: 2, spriteAsset: '/rods/river.png' }, { rodId: 3, spriteAsset: '' }],
    haulEquipment: [
      { equipmentId: 102, kind: 'net', spriteAsset: '/wrong-net.png' },
      { equipmentId: 102, kind: 'boat', spriteAsset: '/boats/river.png' },
      { equipmentId: 201, kind: 'net', spriteAsset: '/nets/twig.png' },
      { equipmentId: 103, kind: 'boat' },
    ],
    gearInstanceNames: [{ instanceId: 101n, playerId: 9n, name: 'Old Faithful' }],
  };
  const rod = { kind: 'rod', definitionId: 2, instanceId: 100n };
  assert.equal(gearSprite(game, rod), '/rods/river.png');
  assert.equal(gearSprite(game, { ...rod, instanceId: 101n }), '/rods/river.png');
  assert.equal(gearSprite(game, { kind: 'boat', definitionId: 102 }), '/boats/river.png');
  assert.equal(gearSprite(game, { kind: 'net', definitionId: 201 }), '/nets/twig.png');
  assert.equal(gearSprite(game, { kind: 'boat', definitionId: 201 }), null);
  assert.equal(gearSprite(game, { kind: 'unknown', definitionId: 2 }), null);
  assert.equal(gearSprite(game, { kind: 'rod', definitionId: 999 }), null);
  assert.equal(gearSprite(game, { kind: 'rod', definitionId: 3 }), null);
  assert.equal(gearSprite(game, { kind: 'boat', definitionId: 103 }), null);
  assert.equal(gearSprite({}, rod), null);
  assert.equal(gearSprite({}, { kind: 'net', definitionId: 201 }), null);
});

test('processing shares 50-fish pages and shows artwork without catch numbering', async () => {
  const processing = await readFile(new URL('../apps/web/src/lib/game/TideforgeProcessing.svelte', import.meta.url), 'utf8');
  const tideforge = await readFile(new URL('../apps/web/src/lib/game/Tideforge.svelte', import.meta.url), 'utf8');
  assert.match(processing, /const pageSize = 50/);
  assert.match(processing, /const currentPageFish = \$derived\(game\.inventory\.slice\(\(page - 1\) \* pageSize, page \* pageSize\)\)/);
  assert.match(processing, /for \(const fish of currentPageFish\)/);
  assert.match(processing, /#each currentPageFish as fish/);
  assert.match(processing, /<PageTurner[^>]*\{pageSize\}/);
  assert.match(processing, /<img src=\{species\?\.spriteAsset \|\| '\/fish\/minnow\.png'\} alt=""[^>]*loading="lazy"/);
  assert.match(processing, /onerror=\{event =>/);
  assert.doesNotMatch(processing, /catchLabel/);
  assert.doesNotMatch(tideforge, /catchLabel/);
  assert.match(tideforge, /formatLength\(specimen\.lengthMm\).*formatWeight\(specimen\.weightG\)/);
});

test('Tideforge help uses the shared info button', async () => {
  for (const [name, count] of [['Tideforge', 2], ['TideforgeGear', 4], ['TideforgeForge', 3], ['TideforgeProcessing', 1], ['TideforgeTrader', 1]]) {
    const component = await readFile(new URL(`../apps/web/src/lib/game/${name}.svelte`, import.meta.url), 'utf8');
    assert.doesNotMatch(component, /ⓘ/);
    assert.match(component, /import InfoButton from '\.\/InfoButton\.svelte'/);
    assert.equal([...component.matchAll(/<InfoButton\b/g)].length, count);
  }
});

test('gear cards do not link to tackle box upgrades', async () => {
  const component = await readFile(new URL('../apps/web/src/lib/game/TideforgeGear.svelte', import.meta.url), 'utf8');
  assert.doesNotMatch(component, /<a\b[^>]*href\s*=\s*["']#inventory["']/i);
  assert.doesNotMatch(component, /Upgrade protected originals in the tackle box/i);
});

test('whole-item recovery protects originals, favorites, equipped and booked gear', () => {
  const item = { instanceId: 4n, protectedOriginal: false, favorite: false };
  const game = { gearIsEquipped: () => false, gearIsBooked: () => false };
  assert(canConsume(game, item));
  assert(!canConsume(game, { ...item, protectedOriginal: true }));
  assert(!canConsume(game, { ...item, favorite: true }));
  assert(!canConsume({ ...game, gearIsEquipped: () => true }, item));
  assert(!canConsume({ ...game, gearIsBooked: () => true }, item));
});

test('dormant rolls stay visible and waters do not depend on licences', () => {
  const item = { instanceId: 9n };
  const game = {
    tideforgeGear: [{ instanceId: 9n, affixesJson: '[{"slot":3,"id":"fortune","tier":2,"finishBp":5000,"secondaryBiomeId":null}]' }],
    biomes: [{ biomeId: 1, name: 'Pond' }, { biomeId: 2, name: 'Sea' }, { biomeId: 3, name: 'Marsh' }],
    licences: [{ biomeId: 1 }],
  };
  assert.equal(rolls(game, item)[0].slot, 3);
  assert.equal(affixSlotState(3, 1, true), 'dormant');
  assert.equal(affixSlotState(2, 1, false), 'locked');
  assert.deepEqual(waterOptions(game, 2).map(row => row.value), [1, 3]);
});

test('fish protections and bounded selection are conservative', () => {
  const model = catalog.species.find(row => row.countsForOrdinaryCollectionCompletion);
  const species = {
    ...model, typicalWeightG: BigInt(model.typicalWeightG),
    minLengthMm: Math.floor(model.typicalLengthMm * 55 / 100),
    maxLengthMm: Math.floor(model.typicalLengthMm * 19 / 10),
    minWeightG: BigInt(model.typicalWeightG) / 8n || 1n,
    maxWeightG: BigInt(model.typicalWeightG) * 8n,
  };
  const rank = rankSpreads(model)[0];
  const fish = { speciesId: model.speciesId, rarity: rank.rarity, lengthMm: rank.maxLengthMm, weightG: BigInt(rank.maxWeightG), favorite: false };
  assert.equal(specimenProcessingProtection(fish, species), 'trophy');
  assert.equal(specimenProcessingProtection({ ...fish, favorite: true }, species), 'favorite');
  assert.equal(specimenProcessingProtection(fish, { ...species, countsForOrdinaryCollectionCompletion: false }), 'bonus');
  assert.equal(specimenProcessingProtection(fish, { ...species, typicalLengthMm: species.typicalLengthMm + 1 }), 'unknown');
  let selected = [];
  for (let id = 1n; id <= 51n; id++) selected = toggleProcessingSelection(selected, id, true);
  assert.equal(selected.length, 50);
  assert(!selected.includes(51n));
  selected = toggleProcessingSelection(selected, 1n, false);
  assert.equal(selected.length, 49);
  assert.equal(toggleProcessingSelection(selected, 51n, false).length, 49);
});

test('Forge counts dormant conditional rolls and permits a real secondary-water retarget', () => {
  const discoveries = ['fortune', 'resonance', 'attuned_focus', 'secondary_affinity'].map(affixId => ({ affixId, naturalCount: 1n }));
  const itemRolls = [
    { slot: 0, id: 'resonance', tier: 2, finishBp: 5000 },
    { slot: 3, id: 'secondary_affinity', tier: 8, finishBp: 7000, secondaryBiomeId: 1 },
  ];
  const add = forgeAffixChoices('rod', discoveries, itemRolls, 'add_affix', 0).map(row => row.id);
  assert(add.includes('fortune'));
  assert(!add.includes('attuned_focus'));
  const change = forgeAffixChoices('rod', discoveries, itemRolls, 'change_affix', 3).map(row => row.id);
  assert(change.includes('secondary_affinity'));
  assert(forgeWaterChangeAllowed('change_affix', itemRolls[1], 'secondary_affinity', 7));
  assert(!forgeWaterChangeAllowed('change_affix', itemRolls[1], 'secondary_affinity', 1));
  assert(!forgeWaterChangeAllowed('change_affix', itemRolls[0], 'resonance', 0));
});

test('friendly affix and signature descriptions avoid raw units and false guarantees', () => {
  assert.match(affixValues('fortune', 1, 0), /^Luck \+\d+(\.\d+)?%$/);
  assert.equal(affixValues('weight_weaver', 1, 0), '');
  assert.match(affixDescription('weight_weaver'), /measurements/);
  assert.match(signatureDescription('echoed_line'), /extra catch/);
  assert.match(signatureDescription('trophys_oath'), /both measurements/);
  assert.match(signatureDescription('rusted_crown'), /First junk in an activity adds rusted tin/);
  for (const id of ['salvage_sermon', 'rusted_crown', 'fishermans_folly', 'gilded_bilge', 'pearlbound', 'forgotten_cache']) {
    assert.doesNotMatch(signatureDescription(id), /daily/i, id);
  }
  for (const id of ['atlas_of_seven_waters', 'tidal_memory', 'oceans_memory', 'rift_current']) {
    assert.match(signatureDescription(id), /daily/i, id);
  }
});
