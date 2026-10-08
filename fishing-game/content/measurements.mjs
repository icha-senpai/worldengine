import { readFile } from 'node:fs/promises';
import assert from 'node:assert/strict';

export async function readMeasurements() {
  const csv = await readFile(new URL('./species-measurements.csv', import.meta.url), 'utf8');
  const [header, ...lines] = csv.trim().split(/\r?\n/);
  assert.equal(header, 'key,typicalLengthMm,typicalWeightG,profile');
  const profiles = new Set(['marine', 'freshwater', 'aquarium', 'invertebrate', 'largemouth', 'carp', 'fathead', 'stickleback', 'bluefin', 'walleye', 'fantasy', 'artifact']);
  const measurements = new Map();
  for (const line of lines) {
    const fields = line.split(',');
    assert.equal(fields.length, 4, `Invalid measurement row: ${line}`);
    const [key, length, weight, profile] = fields;
    assert(/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(key), `Invalid species key: ${key}`);
    assert(!measurements.has(key), `Duplicate measurements: ${key}`);
    const typicalLengthMm = Number(length);
    const typicalWeightG = Number(weight);
    assert(Number.isSafeInteger(typicalLengthMm) && typicalLengthMm >= 10 && typicalLengthMm <= 10_000, `Invalid length: ${key}`);
    assert(Number.isSafeInteger(typicalWeightG) && typicalWeightG > 0 && typicalWeightG <= 2_000_000, `Invalid weight: ${key}`);
    assert(profiles.has(profile), `Unknown measurement profile: ${key}`);
    measurements.set(key, { typicalLengthMm, typicalWeightG, profile });
  }
  return measurements;
}
