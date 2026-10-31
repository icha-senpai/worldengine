import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const order = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];

export async function validateRaids() {
  const config = JSON.parse(await readFile(new URL('./raids.json', import.meta.url), 'utf8'));
  const species = JSON.parse(await readFile(new URL('./species.json', import.meta.url), 'utf8')).species;
  const positive = (value, label) => assert(Number.isSafeInteger(value) && value > 0, label);
  assert.equal(config.version, 2);
  assert.equal(config.rulesVersion, 17);
  assert.equal(config.grades.length, order.length);
  assert.deepEqual(config.grades.map(row => row.name), order);
  assert.equal(config.historyRetentionDays, 30);
  assert.equal(config.requestLifetimeSeconds, 86_400);
  assert.equal(config.deploymentWindowSeconds, 600);
  assert.equal(config.rewardBonusDivisor, 4);
  const slots = new Set([config.supply.slot]);
  assert.equal(config.supply.slot, 900);
  assert.equal(config.supply.item, 'raid_supplies');
  positive(config.supply.priceCoins, 'Supply price');
  positive(config.supply.unitsPerPurchase, 'Supply units');
  assert.equal(config.materials.length, 2);
  assert.deepEqual(config.materials.map(row => row.key), ['fillets', 'scales']);
  assert.equal(config.refining.inputQuantity, 60);
  assert.equal(config.refining.outputQuantity, 50);
  assert.equal(config.refining.coinsPerBatch, 5);
  assert.equal(config.refining.maximumBatches, 100);
  for (const [index, grade] of config.grades.entries()) {
    assert.equal(grade.grade, index);
    for (const field of ['durationSeconds', 'supplyUnits', 'fillets', 'scales', 'reinforceCoins']) {
      positive(grade[field], `${grade.name} ${field}`);
    }
    assert.equal(grade.focusChanceBp, 2000 + (index + 1) * 300);
    assert.equal(grade.perfectBonusRolls, index + 1);
    if (index) assert(grade.durationSeconds >= config.grades[index - 1].durationSeconds);
    for (const material of config.materials) {
      const slot = material.slotBase + index;
      assert(!slots.has(slot), 'Raid item slots must be unique');
      slots.add(slot);
    }
  }
  const minimumSeconds = config.grades.reduce((sum, row) => sum + row.durationSeconds, 0);
  const maximumDeploymentSeconds = config.grades.length * config.deploymentWindowSeconds;
  assert.deepEqual(config.grades.map(row => row.durationSeconds),
    [1800, 3600, 5400, 7200, 10800, 14400, 21600, 32400, 43200, 54000]);
  assert.equal(minimumSeconds, 54 * 3600, 'A personal ladder contains 54 hours of raid time');
  assert.equal(minimumSeconds + maximumDeploymentSeconds, 200_400,
    'Ten maximum deployment waits add 100 minutes, not extra raid time');
  assert.deepEqual(
    config.grades.map(row => [row.supplyUnits, row.fillets, row.scales, row.reinforceCoins]),
    [
      [1, 60, 40, 50], [1, 108, 72, 100], [2, 147, 99, 200],
      [2, 176, 120, 400], [3, 240, 162, 800], [4, 288, 192, 1600],
      [6, 384, 252, 3200], [8, 522, 342, 6400], [12, 624, 408, 12800],
      [15, 720, 480, 25600],
    ],
    'The shorter ladder keeps per-clear supplies, materials and reinforcement costs unchanged'
  );
  const conversion = config.refining.outputQuantity / config.refining.inputQuantity;
  const meanPayout = (grade, item) => grade[item] + Math.floor(grade[item] / config.rewardBonusDivisor) / 2;
  const comparisons = [];
  for (let source = 0; source < 9; source += 1) {
    for (let target = source + 1; target < 10; target += 1) {
      for (const item of ['fillets', 'scales']) {
        const sourceRate = meanPayout(config.grades[source], item) / config.grades[source].durationSeconds;
        const targetRate = meanPayout(config.grades[target], item) / config.grades[target].durationSeconds;
        const relativeEfficiency = sourceRate * conversion ** (target - source) / targetRate;
        assert(relativeEfficiency <= 1, 'Refining must not dominate direct higher-grade raids');
        assert(relativeEfficiency >= 0.4, 'Refining must remain a viable alternative');
        comparisons.push({ source: order[source], target: order[target], item, relativeEfficiency });
      }
    }
  }
  const refiningRequirements = ['fillets', 'scales'].map(item => {
    let required = config.grades[9][item];
    for (let grade = 8; grade >= 0; grade -= 1) {
      required = Math.ceil(required / config.refining.outputQuantity) * config.refining.inputQuantity;
    }
    const lowRaids = Math.ceil(required / config.grades[0][item]);
    return { item, requiredFMaterials: required, guaranteedFRaids: lowRaids };
  });
  assert.deepEqual(config.community.startDaysUtc, [1, 16]);
  assert.equal(config.community.durationSeconds, 4 * 86_400);
  assert.equal(config.community.signupSeconds, 86_400);
  assert.equal(config.community.names.length, config.community.startDaysUtc.length);
  assert.equal(config.special.durationSeconds, 2 * 86_400);
  assert.equal(config.special.maximumFish, 1);
  assert.equal(config.special.maximumRollOneIn, 5000);
  assert.equal(config.special.requiresSpeciesDiscovery, true);
  assert.deepEqual(config.special.speciesKeys, ['fihs', 'nidalees-lost-sock']);
  assert(species.filter(row => !config.special.speciesKeys.includes(row.key))
    .every(row => row.countsForOrdinaryCollectionCompletion));
  return {
    version: config.version,
    ladderHours: minimumSeconds / 3600,
    maximumOnTimeHours: (minimumSeconds + maximumDeploymentSeconds) / 3600,
    suppliesForOneLadder: config.grades.reduce((sum, row) => sum + row.supplyUnits, 0),
    reinforcementCoinsForOneRod: config.grades.reduce((sum, row) => sum + row.reinforceCoins, 0),
    refiningRelativeEfficiency: {
      minimum: Math.min(...comparisons.map(row => row.relativeEfficiency)),
      maximum: Math.max(...comparisons.map(row => row.relativeEfficiency)),
    },
    refiningRequirements,
    comparisons,
  };
}
