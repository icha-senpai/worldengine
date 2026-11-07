import type { TideforgeEconomyQuote } from '@fishing-game/generated/types';

export type TideforgeKind = 'rod' | 'boat' | 'net';
export type TideforgeIntent =
  | { action: 'camp_copy' | 'wandering_gamble'; kind: TideforgeKind; definition_id: number; secondary_biome_id: number }
  | { action: 'add_affix'; instance_id: string; affix_id: string; secondary_biome_id: number | null }
  | { action: 'upgrade_affix'; instance_id: string; slot: number; target_tier: number }
  | { action: 'refine_affix'; instance_id: string; slot: number }
  | { action: 'change_affix'; instance_id: string; slot: number; affix_id: string; secondary_biome_id: number | null }
  | { action: 'dismantle' | 'extract_signature'; instance_id: string }
  | { action: 'fillet'; catch_ids: string[]; allow_trophies: boolean }
  | { action: 'convert_normal'; material_id: number; batches: number }
  | { action: 'refine_material'; material_id: number; from_grade: number; to_grade: number; batches: number };

type ParseContext = { source?: string };
type JsonParser = (text: string, reviver: (key: string, value: unknown, context?: ParseContext) => unknown) => unknown;

export type TideforgeAmount = bigint | number;
export type TideforgePreviewChange = { item?: string; affix_id?: string; consumed: TideforgeAmount; produced: TideforgeAmount; before: TideforgeAmount; after: TideforgeAmount };
export type TideforgePreviewRoll = { slot: number; id: string; tier: number; finishBp: number; secondaryBiomeId?: number | null };
export type TideforgePreviewGear = { instance_id: string; affixes: TideforgePreviewRoll[]; native_signature?: string | null };
export type TideforgePreviewFish = { catch_id: string; species_id: number; rarity: string };
export type TideforgePreview = {
  action: TideforgeIntent['action']; coins_cost?: TideforgeAmount; coins_before?: TideforgeAmount; coins_after?: TideforgeAmount;
  kind?: TideforgeKind; definition_id?: number;
  marks_cost?: TideforgeAmount; marks_before?: TideforgeAmount; marks_after?: TideforgeAmount;
  materials?: TideforgePreviewChange[]; shards?: TideforgePreviewChange[];
  gear_before?: TideforgePreviewGear; gear_after?: TideforgePreviewGear | null; gear?: TideforgePreviewGear;
  learned_signature?: string | null; saved_sale_coins_forgone?: TideforgeAmount; fish?: TideforgePreviewFish[];
  secondary_biome_id?: number; affix_count?: number;
};

export function parseTideforgeJson(text: string): unknown {
  return (JSON.parse as JsonParser)(text, (_key, value, context) => {
    if (typeof value === 'number' && (!Number.isFinite(value) || !Number.isSafeInteger(value))) {
      if (!context?.source || !/^-?(?:0|[1-9]\d*)$/.test(context.source)) {
        throw new Error('This browser cannot safely read the preview.');
      }
      return BigInt(context.source);
    }
    return value;
  });
}

function previewAmount(value: unknown): value is TideforgeAmount {
  return typeof value === 'bigint' && value >= 0n || typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
}

function previewGear(value: unknown): value is TideforgePreviewGear {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
  const gear = value as TideforgePreviewGear;
  return canonicalId(gear.instance_id, 128) && Array.isArray(gear.affixes) && gear.affixes.length <= 4
    && gear.affixes.every(roll => !!roll && typeof roll.id === 'string'
      && integer(roll.slot, 0, 3) && integer(roll.tier, 1, 10) && integer(roll.finishBp, 0, 10000)
      && (roll.secondaryBiomeId == null || integer(roll.secondaryBiomeId, 1, 0xffffffff)))
    && (gear.native_signature == null || typeof gear.native_signature === 'string');
}

function previewChanges(value: unknown, label: 'item' | 'affix_id'): value is TideforgePreviewChange[] {
  return Array.isArray(value) && value.every(row => row && typeof row === 'object' && typeof row[label] === 'string'
    && previewAmount(row.before) && previewAmount(row.after) && previewAmount(row.consumed) && previewAmount(row.produced));
}

export function parseTideforgePreview(text: string): TideforgePreview | null {
  try {
    const value = parseTideforgeJson(text);
    if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
    const data = value as Record<string, unknown>;
    const action = data.action;
    if (typeof action !== 'string') return null;
    const coins = () => previewAmount(data.coins_before) && previewAmount(data.coins_cost) && previewAmount(data.coins_after);
    if (action === 'camp_copy' || action === 'wandering_gamble') {
      if (!coins() || !previewAmount(data.marks_before) || !previewAmount(data.marks_cost) || !previewAmount(data.marks_after)
        || !['rod', 'boat', 'net'].includes(String(data.kind)) || !integer(data.definition_id, 1, 0xffffffff)
        || !integer(data.secondary_biome_id, 1, 0xffffffff) || !integer(data.affix_count, 1, 4)) return null;
    } else if (['add_affix', 'change_affix', 'upgrade_affix', 'refine_affix', 'dismantle', 'extract_signature'].includes(action)) {
      if (!coins() || !previewGear(data.gear_before) || !previewChanges(data.materials, 'item')
        || !previewChanges(data.shards, 'affix_id')) return null;
      if (action === 'dismantle' || action === 'extract_signature') {
        if (data.gear_after !== null) return null;
        if (action === 'extract_signature' && (typeof data.learned_signature !== 'string' || !data.learned_signature)) return null;
      } else if (!previewGear(data.gear_after)) return null;
    } else if (action === 'fillet') {
      if (!previewAmount(data.saved_sale_coins_forgone) || typeof data.allow_trophies !== 'boolean'
        || !previewChanges(data.materials, 'item') || !Array.isArray(data.fish) || !data.fish.length
        || data.fish.length > 50 || data.fish.some(fish => !fish || typeof fish !== 'object'
          || !canonicalId(fish.catch_id, 64) || !integer(fish.species_id, 1, 0xffffffff)
          || typeof fish.rarity !== 'string')) return null;
    } else if (action === 'convert_normal' || action === 'refine_material') {
      if (!coins() || !previewChanges(data.materials, 'item')) return null;
    } else return null;
    if (data.gear !== undefined && !previewGear(data.gear)) return null;
    return data as TideforgePreview;
  } catch { return null; }
}

function canonicalId(value: unknown, bits: 64 | 128): value is string {
  return typeof value === 'string' && /^[1-9]\d*$/.test(value)
    && BigInt(value) < (1n << BigInt(bits));
}

function integer(value: unknown, min: number, max: number): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value >= min && value <= max;
}

function sorted(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(sorted);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).sort(([a], [b]) => a < b ? -1 : a > b ? 1 : 0).map(([k, v]) => [k, sorted(v)]));
  }
  return value;
}

export function canonicalTideforgeIntent(input: unknown): TideforgeIntent {
  if (!input || typeof input !== 'object' || Array.isArray(input)) throw new Error('Invalid Tideforge action.');
  const row = { ...(input as Record<string, unknown>) };
  const action = row.action;
  const keys: Record<string, string[]> = {
    camp_copy: ['kind', 'definition_id', 'secondary_biome_id'],
    wandering_gamble: ['kind', 'definition_id', 'secondary_biome_id'],
    add_affix: ['instance_id', 'affix_id', 'secondary_biome_id'],
    upgrade_affix: ['instance_id', 'slot', 'target_tier'],
    refine_affix: ['instance_id', 'slot'],
    change_affix: ['instance_id', 'slot', 'affix_id', 'secondary_biome_id'],
    dismantle: ['instance_id'],
    extract_signature: ['instance_id'],
    fillet: ['catch_ids', 'allow_trophies'],
    convert_normal: ['material_id', 'batches'],
    refine_material: ['material_id', 'from_grade', 'to_grade', 'batches'],
  };
  if (typeof action !== 'string' || !Object.hasOwn(keys, action)) throw new Error('Invalid Tideforge action.');
  if (action === 'add_affix' || action === 'change_affix') row.secondary_biome_id ??= null;
  const expected = ['action', ...keys[action]].sort();
  if (JSON.stringify(Object.keys(row).sort()) !== JSON.stringify(expected)) throw new Error('Invalid Tideforge action fields.');
  if ('instance_id' in row && !canonicalId(row.instance_id, 128)) throw new Error('Invalid gear ID.');
  if ('kind' in row && !['rod', 'boat', 'net'].includes(String(row.kind))) throw new Error('Invalid gear kind.');
  if ('definition_id' in row && !integer(row.definition_id, 1, 0xffffffff)) throw new Error('Invalid gear definition.');
  if ('slot' in row && !integer(row.slot, 0, 3)) throw new Error('Invalid affix slot.');
  if ('target_tier' in row && !integer(row.target_tier, 1, 10)) throw new Error('Invalid affix tier.');
  if ('affix_id' in row && (typeof row.affix_id !== 'string' || !/^[a-z][a-z0-9_]*$/.test(row.affix_id))) {
    throw new Error('Invalid affix.');
  }
  if ('secondary_biome_id' in row && row.secondary_biome_id !== null
    && !integer(row.secondary_biome_id, 1, 0xffffffff)) throw new Error('Choose a water.');
  if ((action === 'camp_copy' || action === 'wandering_gamble') && row.secondary_biome_id === null) {
    throw new Error('Choose a water.');
  }
  if ((action === 'add_affix' || action === 'change_affix')
    && ((row.affix_id === 'secondary_affinity') !== (row.secondary_biome_id !== null))) {
    throw new Error('Choose a secondary water only for Secondary Affinity.');
  }
  if ('material_id' in row && !integer(row.material_id, 0, 1)) throw new Error('Invalid material.');
  if ('batches' in row && !integer(row.batches, 1, 1000)) throw new Error('Choose 1–1000 batches.');
  if ('from_grade' in row && (!integer(row.from_grade, 0, 8) || !integer(row.to_grade, 1, 9)
    || row.from_grade >= row.to_grade)) throw new Error('Choose a higher destination grade.');
  if (action === 'fillet') {
    if (!Array.isArray(row.catch_ids) || !row.catch_ids.length || row.catch_ids.length > 50
      || !row.catch_ids.every(id => canonicalId(id, 64)) || new Set(row.catch_ids).size !== row.catch_ids.length
      || typeof row.allow_trophies !== 'boolean') throw new Error('Choose up to 50 different fish.');
  }
  return row as TideforgeIntent;
}

export function tideforgeFingerprint(intent: unknown): string {
  return JSON.stringify(sorted(canonicalTideforgeIntent(intent)));
}

export type TideforgeQuoteGuard = {
  identity: string;
  playerId: bigint;
  fingerprint: string;
  nonce?: bigint;
  nowMicros: bigint;
};

export function tideforgeStateAvailability(state: { version: number; catalogVersion: number } | null, failed = false) {
  return failed ? 'unavailable' : !state ? 'inactive'
    : state.version === 1 && state.catalogVersion === 1 ? 'active' : 'unavailable';
}

export function sameTideforgeAccount(
  owner: { identity: string; playerId: bigint }, identity: string | undefined, playerId: bigint | undefined,
) {
  return owner.identity === identity && owner.playerId === playerId;
}

export function tideforgeRetryNonce(
  pending: { identity: string; playerId: bigint; quote: { nonce: bigint } },
  identity: string | undefined, playerId: bigint | undefined,
): bigint | null {
  return sameTideforgeAccount(pending, identity, playerId) ? pending.quote.nonce : null;
}

export function tideforgeResultUncertain(error: unknown, connectionChanged: boolean): boolean {
  return connectionChanged || error instanceof Error && error.name === 'UnknownCallResultError';
}

export function currentTideforgeForm(
  snapshot: { revision: number; identity: string | undefined; playerId: bigint | undefined },
  current: { revision: number; identity: string | undefined; playerId: bigint | undefined; active: boolean; ready: boolean },
) {
  return current.active && current.ready && snapshot.revision === current.revision
    && !!snapshot.identity && snapshot.identity === current.identity
    && snapshot.playerId !== undefined && snapshot.playerId === current.playerId;
}

export function matchesTideforgeQuote(quote: TideforgeEconomyQuote, guard: TideforgeQuoteGuard): boolean {
  try {
    const preview = parseTideforgePreview(quote.previewJson);
    const intent = canonicalTideforgeIntent(JSON.parse(quote.intentJson));
    if (!preview || typeof preview !== 'object' || Array.isArray(preview)) return false;
    const data = preview as unknown as Record<string, unknown>;
    if (data.action !== intent.action) return false;
    if ('instance_id' in intent) {
      const before = data.gear_before;
      if (!before || typeof before !== 'object' || (before as Record<string, unknown>).instance_id !== intent.instance_id) return false;
      if (intent.action === 'dismantle' || intent.action === 'extract_signature') {
        if (data.gear_after !== null) return false;
      } else {
        const after = data.gear_after;
        if (!after || typeof after !== 'object' || (after as Record<string, unknown>).instance_id !== intent.instance_id) return false;
      }
      if (intent.action === 'add_affix' || intent.action === 'change_affix' || intent.action === 'upgrade_affix') {
        const after = data.gear_after;
        if (!after || typeof after !== 'object' || (after as Record<string, unknown>).instance_id !== intent.instance_id) return false;
        const affixes = (after as Record<string, unknown>).affixes;
        if (!Array.isArray(affixes)) return false;
        if (intent.action === 'upgrade_affix') {
          if (!affixes.some(row => row?.slot === intent.slot && row?.tier === intent.target_tier)) return false;
        } else if (!affixes.some(row => row?.id === intent.affix_id
          && (intent.action !== 'change_affix' || row?.slot === intent.slot)
          && (row?.secondaryBiomeId ?? null) === intent.secondary_biome_id)) return false;
      }
    }
    if (intent.action === 'camp_copy' || intent.action === 'wandering_gamble') {
      if (data.kind !== intent.kind || data.definition_id !== intent.definition_id
        || data.secondary_biome_id !== intent.secondary_biome_id) return false;
    }
    if (intent.action === 'fillet') {
      if (data.allow_trophies !== intent.allow_trophies || !Array.isArray(data.fish)
        || JSON.stringify(data.fish.map(fish => fish?.catch_id)) !== JSON.stringify(intent.catch_ids)) return false;
    }
    return quote.identity.toHexString() === guard.identity && quote.playerId === guard.playerId
      && (guard.nonce === undefined || quote.nonce === guard.nonce)
      && quote.catalogVersion === 1 && !quote.consumed
      && quote.expiresAt.microsSinceUnixEpoch > guard.nowMicros
      && tideforgeFingerprint(intent) === guard.fingerprint;
  } catch {
    return false;
  }
}
