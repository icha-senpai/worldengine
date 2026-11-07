import catalog from '../../../../../content/tideforge-effects.json';

export type GearKind = 'rod' | 'boat' | 'net';
export type CollectionGoal = 'ranks' | 'maxima' | 'perfects';
export type AffixRoll = {
  slot: number;
  id: string;
  tier: number;
  finishBp: number;
  secondaryBiomeId?: number | null;
};
export type TideforgeEffects = {
  version: number;
  kind: GearKind;
  goal: CollectionGoal;
  stats: Record<string, number>;
  extraPullBp: number[];
  signatures: string[];
  parameters: Record<string, Record<string, number>>;
  unlockedSlots: number;
  activeAffixes: string[];
  dormantAffixes: string[];
};
export type EffectLoadout = {
  kind: GearKind;
  biomeId: number;
  primaryBiomeId: number;
  masteryXp: bigint;
  affixes: readonly AffixRoll[];
  nativeSignature?: string | null;
  kindSignature?: string | null;
  watersSignature?: string | null;
  goal: CollectionGoal;
};

const u32Max = 0xffff_ffff;
const u64Max = (1n << 64n) - 1n;
const statNames = new Set([
  'luckBp', 'playerXpBp', 'masteryXpBp', 'saleBp', 'salvageBp',
  'treasureBp', 'filletBp', 'scaleBp', 'powerCenti', 'baitAttemptBp',
  'weightFinishBp', 'lengthFinishBp', 'endpointPpm', 'rankFocusBp',
  'baitPreserveBp', 'extraPullBp', 'fullAttemptCenti', 'maximaFocusBp',
  'perfectFocusBp', 'durationBp', 'duplicateRerollBp', 'haulAttemptCenti',
]);

function integer(value: number, minimum: number, maximum: number, error: string) {
  if (!Number.isSafeInteger(value) || value < minimum || value > maximum) throw new Error(error);
}

export function masteryThreshold(level: number): bigint {
  integer(level, 1, 100, 'TIDEFORGE_MASTERY_LEVEL_INVALID');
  const n = BigInt(level - 1);
  return 100n * n + 10n * n * (n - 1n);
}

export function masteryLevel(xp: bigint): number {
  if (xp < 0n || xp > u64Max) throw new Error('TIDEFORGE_MASTERY_XP_INVALID');
  let level = 1;
  while (level < 100 && xp >= masteryThreshold(level + 1)) level++;
  return level;
}

export function unlockedAffixSlots(xp: bigint): number {
  const level = masteryLevel(xp);
  return catalog.mastery.slotLevels.filter(threshold => level >= threshold).length;
}

export function rolledAffixValue(maximum: number, finishBp: number): number {
  integer(maximum, 0, u32Max, 'TIDEFORGE_VALUE_INVALID');
  integer(finishBp, 0, 10_000, 'TIDEFORGE_FINISH_INVALID');
  const minimum = Math.ceil(maximum * 4 / 5);
  return minimum + Math.floor((maximum - minimum) * finishBp / 10_000);
}

/** Exact display/preview arithmetic. Only the server grants the returned credit. */
export function scaledCredit(amount: bigint, rateBp: number, remainderBp: number): [bigint, number] {
  if (amount < 0n || amount > u64Max) throw new Error('TIDEFORGE_AMOUNT_INVALID');
  integer(rateBp, 0, u32Max, 'TIDEFORGE_RATE_INVALID');
  integer(remainderBp, 0, 9999, 'TIDEFORGE_REMAINDER_INVALID');
  const numerator = amount * BigInt(rateBp) + BigInt(remainderBp);
  const whole = numerator / 10_000n;
  if (whole > u64Max) throw new Error('TIDEFORGE_CREDIT_OVERFLOW');
  return [whole, Number(numerator % 10_000n)];
}

export function effectStat(effects: TideforgeEffects, name: string): number {
  return effects.stats[name] ?? 0;
}

export function signatureParameter(effects: TideforgeEffects, signature: string, name: string): number {
  return effects.parameters[signature]?.[name] ?? 0;
}

/** Candidate preview only; equipped effects and booked snapshots come from the server. */
export function composeTideforgeEffects(input: EffectLoadout): TideforgeEffects {
  if (!['rod', 'boat', 'net'].includes(input.kind)
    || !['ranks', 'maxima', 'perfects'].includes(input.goal) || input.affixes.length > 4) {
    throw new Error('TIDEFORGE_LOADOUT_INVALID');
  }
  integer(input.biomeId, 1, u32Max, 'TIDEFORGE_BIOME_INVALID');
  integer(input.primaryBiomeId, 1, u32Max, 'TIDEFORGE_BIOME_INVALID');
  const effects: TideforgeEffects = {
    version: catalog.version, kind: input.kind, goal: input.goal, stats: {},
    extraPullBp: [], signatures: [], parameters: {}, unlockedSlots: unlockedAffixSlots(input.masteryXp),
    activeAffixes: [], dormantAffixes: [],
  };
  const add = (name: string, value: number) => {
    if (!statNames.has(name)) throw new Error('TIDEFORGE_CATALOG_INVALID');
    integer(value, 0, u32Max, 'TIDEFORGE_EFFECT_OVERFLOW');
    if (name === 'extraPullBp') {
      integer(value, 0, 10_000, 'TIDEFORGE_PROBABILITY_INVALID');
      effects.extraPullBp.push(value);
    } else {
      const total = effectStat(effects, name) + value;
      integer(total, 0, u32Max, 'TIDEFORGE_EFFECT_OVERFLOW');
      effects.stats[name] = total;
    }
  };
  const slots = new Set<number>();
  const ids = new Set<string>();
  let biomeAffixes = 0;
  for (const rolled of [...input.affixes].sort((a, b) => a.slot - b.slot)) {
    const row = catalog.affixes.find(affix => affix.id === rolled.id);
    if (!row || !row.kinds.includes(input.kind) || slots.has(rolled.slot) || ids.has(rolled.id)) {
      throw new Error('TIDEFORGE_AFFIX_INVALID');
    }
    integer(rolled.slot, 0, 3, 'TIDEFORGE_AFFIX_INVALID');
    integer(rolled.tier, 1, 10, 'TIDEFORGE_AFFIX_INVALID');
    integer(rolled.finishBp, 0, 10_000, 'TIDEFORGE_FINISH_INVALID');
    slots.add(rolled.slot);
    ids.add(rolled.id);
    if (row.condition) biomeAffixes++;
    const secondary = rolled.secondaryBiomeId ?? null;
    if (row.condition === 'secondary') {
      if (secondary === null) throw new Error('TIDEFORGE_AFFIX_INVALID');
      integer(secondary, 1, u32Max, 'TIDEFORGE_AFFIX_INVALID');
    } else if (secondary !== null) {
      throw new Error('TIDEFORGE_AFFIX_INVALID');
    }
    const matching = row.condition === 'primary' ? input.biomeId === input.primaryBiomeId
      : row.condition === 'secondary' ? secondary !== input.primaryBiomeId && secondary === input.biomeId
      : true;
    if (rolled.slot >= effects.unlockedSlots || !matching) {
      effects.dormantAffixes.push(rolled.id);
      continue;
    }
    effects.activeAffixes.push(rolled.id);
    for (const [name, grid] of Object.entries(row.stats)) {
      if (!grid) throw new Error('TIDEFORGE_CATALOG_INVALID');
      add(name, rolledAffixValue(grid[rolled.tier - 1], rolled.finishBp));
    }
  }
  if (biomeAffixes > 2) throw new Error('TIDEFORGE_BIOME_AFFIX_LIMIT');
  for (const [slot, id] of [
    ['native', input.nativeSignature], ['kind', input.kindSignature], ['waters', input.watersSignature],
  ] as const) {
    if (id == null) continue;
    const row = catalog.signatures.find(signature => signature.id === id);
    if (!row) throw new Error('TIDEFORGE_SIGNATURE_INVALID');
    const compatible = slot === 'native' ? row.kind === input.kind || row.kind === 'waters'
      : slot === 'kind' ? row.kind === input.kind : row.kind === 'waters';
    if (!compatible) throw new Error('TIDEFORGE_SIGNATURE_INCOMPATIBLE');
    if (effects.signatures.includes(id)) continue;
    effects.signatures.push(id);
    const parameters: Record<string, number> = {};
    for (const [name, value] of Object.entries(row.parameters ?? {})) {
      if (typeof value !== 'number') throw new Error('TIDEFORGE_CATALOG_INVALID');
      integer(value, 0, u32Max, 'TIDEFORGE_CATALOG_INVALID');
      parameters[name] = value;
    }
    effects.parameters[id] = parameters;
    for (const [name, value] of Object.entries(row.stats ?? {})) {
      if (typeof value !== 'number') throw new Error('TIDEFORGE_CATALOG_INVALID');
      add(name, value);
    }
    if (id === 'wayfarers_compass' && input.biomeId !== input.primaryBiomeId) {
      add('luckBp', signatureParameter(effects, id, 'outsidePrimaryLuckBp'));
    }
  }
  if (input.biomeId === input.primaryBiomeId) add('luckBp', catalog.innatePrimaryLuckBp);
  return effects;
}
