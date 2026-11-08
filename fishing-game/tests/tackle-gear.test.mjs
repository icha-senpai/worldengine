import assert from 'node:assert/strict';
import { test } from 'node:test';
import { equipTackleGear, tackleGearOptions, tackleGearValue } from '../apps/web/src/lib/game/tackle-gear.ts';

function fixture() {
  const calls = [];
  const game = {
    ready: true, busy: false, equipmentInformationUnavailable: false, error: '',
    player: { playerId: 9n, equippedRodId: 2 }, profile: { level: 10 },
    rods: [{ rodId: 2, name: 'River Rod', minimumLevel: 1 }, { rodId: 3, name: 'Abyss Rod', minimumLevel: 50 }],
    haulEquipment: [{ equipmentId: 102, rodId: 2, kind: 'boat', name: 'River Boat' },
      { equipmentId: 201, rodId: 2, kind: 'net', name: 'River Net' },
      { equipmentId: 103, rodId: 3, kind: 'boat', name: 'Abyss Boat' }],
    qualities: [{ qualityLevel: 0, name: 'Common' }, { qualityLevel: 2, name: 'Rare' }],
    gearLoadout: { playerId: 9n, rodInstanceId: 100n, boatInstanceId: 102n, netInstanceId: 103n },
    gearInstances: [
      { playerId: 9n, kind: 'rod', instanceId: 100n, familyId: 2, definitionId: 2, qualityLevel: 0, protectedOriginal: true },
      { playerId: 9n, kind: 'rod', instanceId: 9007199254740993n, familyId: 2, definitionId: 2, qualityLevel: 2, protectedOriginal: false },
      { playerId: 9n, kind: 'rod', instanceId: 104n, familyId: 3, definitionId: 3, qualityLevel: 0 },
      { playerId: 9n, kind: 'rod', instanceId: 105n, familyId: 999, definitionId: 999, qualityLevel: 0 },
      { playerId: 9n, kind: 'rod', instanceId: 108n, familyId: 2, definitionId: 999, qualityLevel: 0 },
      { playerId: 8n, kind: 'rod', instanceId: 106n, familyId: 3, definitionId: 3, qualityLevel: 0 },
      { playerId: 9n, kind: 'boat', instanceId: 102n, familyId: 2, definitionId: 102, qualityLevel: 0, protectedOriginal: true },
      { playerId: 9n, kind: 'boat', instanceId: 202n, familyId: 2, definitionId: 102, qualityLevel: 2, protectedOriginal: false, favorite: true },
      { playerId: 9n, kind: 'net', instanceId: 103n, familyId: 2, definitionId: 201, qualityLevel: 0, protectedOriginal: true },
      { playerId: 9n, kind: 'net', instanceId: 203n, familyId: 2, definitionId: 201, qualityLevel: 2, protectedOriginal: false },
    ],
    gearInstanceNames: [{ playerId: 9n, instanceId: 9007199254740993n, name: 'River Friend' }],
    ownedRods: [{ rodId: 2, upgradeLevel: 2 }, { rodId: 3, upgradeLevel: 0 }],
    ownedHaulEquipment: [{ equipmentId: 102, upgradeLevel: 2 }, { equipmentId: 201, upgradeLevel: 0 }, { equipmentId: 103, upgradeLevel: 0 }],
    haulLoadout: { playerId: 9n, boatId: 102, netId: 201 },
    async equipGearInstance(id) { calls.push(['instance', id]); return true; },
    async changeLoadout(biome, id) { calls.push(['rod', biome, id]); },
    async equipHaulEquipment(id) { calls.push(['haul', id]); },
  };
  return { game, calls };
}

test('instance options retain every owned copy, own quality, nickname and bigint identity', () => {
  const { game } = fixture();
  const rods = tackleGearOptions(game, 'rod');
  assert.deepEqual(rods.map(row => row.value), ['instance:100', 'instance:104', 'instance:105', 'instance:108', 'instance:9007199254740993']);
  assert.equal(rods[0].label, 'Common River Rod · Item 1');
  assert.equal(rods[4].label, 'Rare River Friend · River Rod · Item 2');
  assert.equal(rods[4].disabled, false);
  assert.match(rods[1].label, /Level 50 required/);
  assert.equal(rods[1].disabled, true);
  assert.equal(rods[2].disabled, true);
  assert.equal(rods[3].disabled, true);
  assert.deepEqual(tackleGearOptions(game, 'boat').map(row => row.value), ['instance:102', 'instance:202']);
  assert.deepEqual(tackleGearOptions(game, 'net').map(row => row.value), ['instance:103', 'instance:203']);
  assert.match(tackleGearOptions(game, 'boat')[1].label, /^Rare River Boat/);
  assert.equal(tackleGearOptions(game, 'boat')[1].disabled, false);
  assert.equal(tackleGearValue(game, 'rod'), 'instance:100');
  assert.equal(tackleGearValue(game, 'boat'), 'instance:102');
  assert.equal(tackleGearValue(game, 'net'), 'instance:103');
});

test('instance equip rejects stale, disabled and wrong-kind values before dispatch', async () => {
  const { game, calls } = fixture();
  assert.equal(await equipTackleGear(game, 'rod', 'instance:9007199254740993'), true);
  assert.deepEqual(calls, [['instance', 9007199254740993n]]);
  assert.equal(await equipTackleGear(game, 'boat', 'instance:203'), false);
  assert.equal(await equipTackleGear(game, 'rod', 'instance:104'), false);
  assert.equal(await equipTackleGear(game, 'rod', 'instance:105'), false);
  assert.equal(await equipTackleGear(game, 'rod', 'instance:108'), false);
  assert.equal(await equipTackleGear(game, 'rod', 'instance:not-a-number'), false);
  assert.equal(await equipTackleGear(game, 'rod', 'instance:100'), true);
  assert.equal(calls.length, 1);
  assert.equal(await equipTackleGear(game, 'net', 'instance:203'), true);
  assert.deepEqual(calls.at(-1), ['instance', 203n]);
  assert.equal(await equipTackleGear(game, 'boat', 'instance:202'), true);
  assert.deepEqual(calls.at(-1), ['instance', 202n]);
  game.busy = true;
  assert.equal(await equipTackleGear(game, 'boat', 'instance:202'), false);
  game.busy = false; game.ready = false;
  assert.equal(await equipTackleGear(game, 'boat', 'instance:202'), false);
});

test('a once-valid option cannot equip an instance removed before dispatch', async () => {
  const { game, calls } = fixture();
  const option = tackleGearOptions(game, 'rod').find(row => row.value === 'instance:9007199254740993');
  assert(option && !option.disabled);
  game.gearInstances = game.gearInstances.filter(row => row.instanceId !== 9007199254740993n);
  assert.equal(await equipTackleGear(game, 'rod', option.value), false);
  assert.deepEqual(calls, []);
});

test('unavailable, absent and wrong-owner state never falls back to legacy gear', async () => {
  const { game, calls } = fixture();
  game.equipmentInformationUnavailable = true;
  assert.deepEqual(tackleGearOptions(game, 'rod'), []);
  assert.equal(tackleGearValue(game, 'rod'), '');
  assert.equal(await equipTackleGear(game, 'rod', 'instance:100'), false);
  game.gearLoadout = null;
  assert.deepEqual(tackleGearOptions(game, 'rod'), []);
  game.equipmentInformationUnavailable = false;
  game.gearLoadout = { playerId: 8n, rodInstanceId: 100n };
  assert.deepEqual(tackleGearOptions(game, 'rod'), []);
  assert.equal(tackleGearValue(game, 'rod'), '');
  assert.equal(await equipTackleGear(game, 'rod', 'legacy:2'), false);
  game.player = null;
  assert.deepEqual(tackleGearOptions(game, 'boat'), []);
  assert.deepEqual(calls, []);
});

test('legacy models retain their level gates, values and existing dispatch methods', async () => {
  const { game, calls } = fixture();
  game.gearLoadout = null;
  game.player.equippedRodId = 3;
  game.haulLoadout.boatId = 103;
  assert.deepEqual(tackleGearOptions(game, 'rod').map(row => row.value), ['legacy:2', 'legacy:3']);
  assert.match(tackleGearOptions(game, 'rod')[1].label, /Level 50 required/);
  assert.equal(tackleGearOptions(game, 'boat')[1].disabled, true);
  assert.equal(tackleGearValue(game, 'rod'), 'legacy:3');
  assert.equal(tackleGearValue(game, 'boat'), 'legacy:103');
  assert.equal(await equipTackleGear(game, 'rod', 'legacy:2'), true);
  assert.equal(await equipTackleGear(game, 'boat', 'legacy:102'), true);
  game.haulLoadout.netId = 0;
  assert.equal(await equipTackleGear(game, 'net', 'legacy:201'), true);
  assert.deepEqual(calls, [['rod', undefined, 2], ['haul', 102], ['haul', 201]]);
  assert.equal(await equipTackleGear(game, 'boat', 'legacy:103'), false);
});
