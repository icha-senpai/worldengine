import economy from '../../../../../content/tideforge-economy.json';
import effects from '../../../../../content/tideforge-effects.json';
import raids from '../../../../../content/raids.json';
import type { TideforgeIntent } from './tideforge-intents';
import { rolledAffixValue, type AffixRoll } from './tideforge';
import { affixName } from './tideforge-ui';
import { tideforgeMaterialLabel } from './tideforge-copy';
import { itemSlot } from './raid-items';

export type GuideResource =
  | { kind: 'material'; slot: number; item: string; amount: bigint }
  | { kind: 'shard'; affixId: string; amount: bigint };
export type CostGuide = {
  coins: bigint;
  inputs: GuideResource[];
  outputs: GuideResource[];
  requiredGrades: number[];
  nextFinishBp?: number;
};
export type CostGuideResult = { plan: CostGuide | null; problem: string };
export type GuideBalances = {
  coins: bigint;
  items: readonly { key: bigint; quantity: bigint }[];
  shards: readonly { affixId: string; count: bigint }[];
  clear: (grade: number) => boolean;
};

const grades = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];
const emptyPlan = (): CostGuide => ({ coins: 0n, inputs: [], outputs: [], requiredGrades: [] });
const failure = (problem: string): CostGuideResult => ({ plan: null, problem });
const whole = (value: number, min: number, max: number) => Number.isSafeInteger(value) && value >= min && value <= max;

function material(id: number, grade: number | null, amount: bigint): GuideResource {
  const definition = raids.materials.find(row => row.id === id)!;
  return grade === null
    ? { kind: 'material', slot: id === 0 ? economy.processing.filletsSlot : economy.processing.scalesSlot,
      item: id === 0 ? 'normal_fillets' : 'normal_scales', amount }
    : { kind: 'material', slot: definition.slotBase + grade, item: `raid_${definition.key}_${grades[grade].toLowerCase()}`, amount };
}

/** Display guidance from the checked-in catalog, never a substitute for a server quote. */
export function tideforgeCostGuide(intent: TideforgeIntent, roll?: AffixRoll): CostGuideResult {
  const plan = emptyPlan();
  if (intent.action === 'convert_normal' || intent.action === 'refine_material') {
    if (![0, 1].includes(intent.material_id) || !whole(intent.batches, 1, economy.maximumConversionBatches)) {
      return failure('Choose a material and a valid whole number of batches.');
    }
    const count = BigInt(intent.batches), recipe = economy.processing;
    if (intent.action === 'convert_normal') {
      plan.inputs.push(material(intent.material_id, null, count * BigInt(recipe.normalToFInput)));
      plan.outputs.push(material(intent.material_id, 0, count * BigInt(recipe.normalToFOutput)));
      plan.coins = count * BigInt(recipe.normalToFCoinCost);
      plan.requiredGrades.push(0);
    } else {
      if (!whole(intent.from_grade, 0, 8) || !whole(intent.to_grade, intent.from_grade + 1, 9)) {
        return failure('Choose a higher destination grade.');
      }
      plan.inputs.push(material(intent.material_id, intent.from_grade, count * BigInt(recipe.refiningInput)));
      let batches = count;
      for (let grade = intent.from_grade + 1; grade <= intent.to_grade; grade++) {
        plan.requiredGrades.push(grade);
        plan.coins += batches * BigInt(recipe.refiningCoinCost);
        const produced = batches * BigInt(recipe.refiningOutput);
        if (grade === intent.to_grade) plan.outputs.push(material(intent.material_id, grade, produced));
        else {
          const next = produced / BigInt(recipe.refiningInput);
          if (next === 0n) return failure('These batches cannot reach that grade. Increase batches or choose a nearer grade.');
          const remainder = produced % BigInt(recipe.refiningInput);
          if (remainder) plan.outputs.push(material(intent.material_id, grade, remainder));
          batches = next;
        }
      }
    }
    return { plan, problem: '' };
  }
  if (intent.action === 'add_affix' || intent.action === 'upgrade_affix') {
    if (intent.action === 'upgrade_affix' && (!roll || roll.slot !== intent.slot)) {
      return failure('Choose an occupied unlocked affix slot.');
    }
    const from = intent.action === 'add_affix' ? 0 : roll?.tier;
    const to = intent.action === 'add_affix' ? 1 : intent.target_tier;
    if (from === undefined || !whole(from, 0, 9) || !whole(to, from + 1, 10)) {
      return failure('Choose an occupied affix and a higher tier.');
    }
    for (const tier of economy.forge.tiers.filter(row => row.tier > from && row.tier <= to)) {
      plan.requiredGrades.push(tier.grade);
      plan.inputs.push(material(0, tier.grade, BigInt(tier.fillets)), material(1, tier.grade, BigInt(tier.scales)));
    }
    if (intent.action === 'add_affix') plan.inputs.push({ kind: 'shard', affixId: intent.affix_id, amount: BigInt(economy.forge.shardsPerAddition) });
    return { plan, problem: '' };
  }
  if (intent.action === 'change_affix') {
    plan.inputs.push({ kind: 'shard', affixId: intent.affix_id, amount: BigInt(economy.forge.shardsPerChange) });
    return { plan, problem: '' };
  }
  if (intent.action === 'refine_affix') {
    const affix = effects.affixes.find(row => row.id === roll?.id);
    if (!affix || !roll || roll.slot !== intent.slot || !whole(roll.tier, 1, 10) || !whole(roll.finishBp, 0, 10000)) {
      return failure('Affix details are unavailable. Retry details before improving this affix.');
    }
    const maxima = Object.values(affix.stats).filter((grid): grid is number[] => Array.isArray(grid)).map(grid => grid[roll.tier - 1]);
    const values = (finish: number) => maxima.map(maximum => rolledAffixValue(maximum, finish));
    const before = values(roll.finishBp);
    const differs = (finish: number) => values(finish).some((value, index) => value !== before[index]);
    if (!differs(10000)) return failure('This affix is already at its strongest effective value for this tier.');
    const step = economy.forge.refinementStepBp;
    let next = (Math.floor(roll.finishBp / step) + 1) * step;
    while (next <= 10000 && !differs(next)) next += step;
    if (next > 10000) return failure('This affix cannot be improved further at this tier.');
    const cumulative = (finish: number) => (BigInt(economy.forge.refinementFullCostCoins) * BigInt(finish) + 9999n) / 10000n;
    plan.coins = cumulative(next) - cumulative(roll.finishBp);
    plan.nextFinishBp = next;
    return { plan, problem: '' };
  }
  return { plan: null, problem: '' };
}

export function guideResourceLabel(resource: GuideResource): string {
  return resource.kind === 'material' ? tideforgeMaterialLabel(resource.item) : `${affixName(resource.affixId)} shards`;
}

export function guideResourceOwned(resource: GuideResource, balances: GuideBalances): bigint {
  return resource.kind === 'material'
    ? balances.items.find(row => itemSlot(row.key) === resource.slot)?.quantity ?? 0n
    : balances.shards.find(row => row.affixId === resource.affixId)?.count ?? 0n;
}

export function guideShortages(plan: CostGuide, balances: GuideBalances): string[] {
  const problems = plan.requiredGrades.filter(grade => !balances.clear(grade))
    .map(grade => `A regular-fish raid clear at ${grades[grade]} grade is required.`);
  for (const resource of plan.inputs) {
    const owned = guideResourceOwned(resource, balances);
    if (owned < resource.amount) problems.push(`Need ${(resource.amount - owned).toLocaleString()} more ${guideResourceLabel(resource)} (${owned.toLocaleString()} / ${resource.amount.toLocaleString()}).`);
  }
  if (balances.coins < plan.coins) problems.push(`Need ${(plan.coins - balances.coins).toLocaleString()} more coins (${balances.coins.toLocaleString()} / ${plan.coins.toLocaleString()}).`);
  return problems;
}
