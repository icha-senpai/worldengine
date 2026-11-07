import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import catalog from '../content/tideforge-effects.json';
import {
  composeTideforgeEffects, masteryLevel, rolledAffixValue, scaledCredit, unlockedAffixSlots,
  type AffixRoll, type CollectionGoal, type EffectLoadout, type GearKind,
} from '../apps/web/src/lib/game/tideforge';
import { requireRustWorkflow } from './rust-build.mjs';

requireRustWorkflow();

type ComposeCase = Omit<EffectLoadout, 'masteryXp'> & { operation: 'compose'; masteryXp: string };
type Case = ComposeCase
  | { operation: 'mastery'; xp: string }
  | { operation: 'credit'; amount: string; rateBp: number; remainderBp: number }
  | { operation: 'roll'; maximum: number; finishBp: number };
type Result = { ok: unknown } | { error: string };
const maximumXp = ((1n << 64n) - 1n).toString();
const cases: Case[] = [];

function compose(overrides: Partial<ComposeCase> = {}): ComposeCase {
  return {
    operation: 'compose', kind: 'rod', biomeId: 2, primaryBiomeId: 1, masteryXp: maximumXp,
    affixes: [], goal: 'ranks', ...overrides,
  };
}

function roll(id: string, slot = 0): AffixRoll {
  return { id, slot, tier: 10, finishBp: 10_000 };
}

for (const affix of catalog.affixes) {
  for (const kind of affix.kinds) {
    for (const tier of [1, 10]) {
      for (const finishBp of [0, 5000, 10_000]) {
        for (const biomeId of [1, 2]) {
          cases.push(compose({
            kind: kind as GearKind, biomeId,
            affixes: [{
              id: affix.id, slot: 0, tier, finishBp,
              secondaryBiomeId: affix.condition === 'secondary' ? 2 : null,
            }],
          }));
        }
      }
    }
  }
}
const affixCases = cases.length;
assert.equal(affixCases, 780);
for (const signature of catalog.signatures) {
  const kinds = signature.kind === 'waters' ? ['rod', 'boat', 'net'] : [signature.kind];
  for (const kind of kinds) {
    const library = signature.kind === 'waters'
      ? { watersSignature: signature.id } : { kindSignature: signature.id };
    for (const selections of [
      { nativeSignature: signature.id }, library, { nativeSignature: signature.id, ...library },
    ]) {
      cases.push(compose({ kind: kind as GearKind, ...selections }));
    }
  }
}
const signatureCases = cases.length - affixCases;
assert.equal(signatureCases, 108);

for (const xp of ['0', '99', '100', '1619', '1620', '7919', '7920', '28419', '28420',
  '106919', '106920', maximumXp, '-1', '18446744073709551616']) {
  cases.push({ operation: 'mastery', xp });
  cases.push(compose({
    masteryXp: xp, affixes: [roll('fortune', 0), roll('scholar', 1), roll('old_salt', 2), roll('coinwright', 3)],
  }));
}
for (const amount of ['0', '1', '100', maximumXp]) {
  for (const rateBp of [0, 2500, 10_000, 12_500]) {
    for (const remainderBp of [0, 9999]) cases.push({ operation: 'credit', amount, rateBp, remainderBp });
  }
}
cases.push({ operation: 'credit', amount: '1', rateBp: 10_000, remainderBp: 10_000 });
cases.push({ operation: 'credit', amount: '-1', rateBp: 10_000, remainderBp: 0 });
for (const maximum of [1, 2500, 0xffff_ffff]) {
  for (const finishBp of [0, 5000, 10_000, 10_001]) cases.push({ operation: 'roll', maximum, finishBp });
}
cases.push(
  compose({ affixes: [{ ...roll('fortune'), tier: 11 }] }),
  compose({ affixes: [{ ...roll('fortune'), finishBp: 10_001 }] }),
  compose({ affixes: [{ ...roll('fortune'), slot: 4 }] }),
  compose({ affixes: [roll('fortune'), roll('fortune', 1)] }),
  compose({ affixes: [roll('fortune'), roll('scholar')] }),
  compose({ kind: 'boat', affixes: [roll('line_tension')] }),
  compose({ affixes: [roll('not-a-real-affix')] }),
  compose({ nativeSignature: 'nets_of_plenty' }),
  compose({ kindSignature: 'forgotten_cache' }),
  compose({ watersSignature: 'echoed_line' }),
  compose({ affixes: [roll('fortune'), roll('scholar', 1), roll('old_salt', 2), roll('coinwright', 3), roll('scavenger', 4)] }),
  compose({ affixes: [roll('resonance'), roll('attuned_harvest', 1), roll('attuned_wisdom', 2)] }),
  compose({ affixes: [roll('secondary_affinity')] }),
  compose({ affixes: [{ ...roll('fortune'), secondaryBiomeId: 2 }] }),
  compose({ biomeId: 0 }),
  compose({ goal: 'not-a-real-goal' as CollectionGoal }),
  compose({ biomeId: 1, nativeSignature: 'collectors_whisper', kindSignature: 'collectors_whisper',
    watersSignature: 'atlas_of_seven_waters',
    affixes: [roll('focus_tuner'), roll('attuned_focus', 1), roll('maxima_priority', 2), roll('measured_cast', 3)] }),
  compose({ biomeId: 1, nativeSignature: 'atlas_of_seven_waters', kindSignature: 'tidal_memory',
    watersSignature: 'atlas_of_seven_waters',
    affixes: [roll('old_salt'), roll('attuned_wisdom', 1), roll('fortune', 2), roll('scholar', 3)] }),
  compose({ kind: 'boat', biomeId: 1, nativeSignature: 'ghost_trawler', kindSignature: 'tempest_rudder',
    watersSignature: 'rift_current',
    affixes: [roll('full_hold'), roll('efficient_hull', 1), roll('deep_trawler', 2), roll('attuned_harvest', 3)] }),
  compose({ kind: 'net', biomeId: 1, nativeSignature: 'nets_of_plenty', kindSignature: 'scale_pact',
    watersSignature: 'rift_current',
    affixes: [roll('scaleweaver'), roll('scale_collector', 1), roll('attuned_harvest', 2), roll('quick_set', 3)] }),
);

function predict(row: Case): unknown {
  switch (row.operation) {
    case 'compose': return composeTideforgeEffects({ ...row, masteryXp: BigInt(row.masteryXp) });
    case 'mastery': return { level: masteryLevel(BigInt(row.xp)), slots: unlockedAffixSlots(BigInt(row.xp)) };
    case 'credit': {
      const [whole, remainder] = scaledCredit(BigInt(row.amount), row.rateBp, row.remainderBp);
      return { whole: whole.toString(), remainder };
    }
    case 'roll': return rolledAffixValue(row.maximum, row.finishBp);
  }
}

const execution = spawnSync('cargo', ['run', '--quiet', '--locked', '-p', 'game-rules', '--example', 'tideforge-parity'], {
  input: JSON.stringify(cases), encoding: 'utf8', env: process.env, maxBuffer: 16 * 1024 * 1024,
});
assert.equal(execution.status, 0, execution.error?.message ?? execution.stderr);
const authoritative = JSON.parse(execution.stdout) as Result[];
assert.equal(authoritative.length, cases.length);
let rejected = 0;
for (const [index, row] of cases.entries()) {
  let prediction: unknown;
  let failure: unknown;
  try { prediction = predict(row); } catch (error) { failure = error; }
  const actual = authoritative[index];
  if ('error' in actual) {
    assert(failure instanceof Error, `Client accepted Rust-rejected case ${index}: ${JSON.stringify(row)}`);
    rejected++;
  } else {
    assert.equal(failure, undefined, `Client rejected Rust-accepted case ${index}: ${String(failure)}`);
    assert.deepEqual(prediction, actual.ok, `Case ${index}: ${JSON.stringify(row)}`);
  }
}
console.log(JSON.stringify({
  status: 'passed', cases: cases.length, affixCases, signatureCases, rejected,
  scope: 'Exact effect composition, mastery, refinement and fixed-point credit; no gameplay or per-action odds claim.',
}));
