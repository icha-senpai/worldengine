export type LuckTicket = readonly [weight: bigint, score: number];

export const LEGACY_LUCK_BOUNDARY_BP = 26_600n;
export const TAIL_SCALE_BP = 100_000n;
export const TAIL_BLEND_BP = 50_000n;
export const TARGET_UUR_MULTIPLIER = 10n;
export const TARGET_TICK_SCALE = 1_000_000n;
const i128Min = -(1n << 127n);
const i128Max = (1n << 127n) - 1n;
const u128Max = (1n << 128n) - 1n;
const u64Max = (1n << 64n) - 1n;

function signed(value: bigint) {
  if (value < i128Min || value > i128Max) throw new RangeError('WEIGHT_OVERFLOW');
  return value;
}

function unsigned(value: bigint) {
  if (value < 0n || value > u128Max) throw new RangeError('WEIGHT_OVERFLOW');
  return value;
}

const add = (a: bigint, b: bigint) => unsigned(a + b);
const mul = (a: bigint, b: bigint) => unsigned(a * b);
const sum = (values: readonly bigint[]) => values.reduce(add, 0n);

function gcd(a: bigint, b: bigint): bigint {
  while (b !== 0n) [a, b] = [b, a % b];
  return a;
}

function reduce(values: bigint[]): bigint[] {
  const divisor = values.reduce(gcd, 0n) || 1n;
  return values.map(value => value / divisor);
}

function legacy(tickets: readonly LuckTicket[], total: bigint, moment: bigint, base: bigint, luck: bigint): bigint[] {
  return tickets.map(([weight, score]) => {
    if (weight === 0n) return 0n;
    const factor = signed(base + signed(luck * (BigInt(score) * total - moment)));
    if (factor <= 0n) throw new RangeError('INVALID_ROD_POOL');
    return mul(weight, factor);
  });
}

function allocate(basis: readonly bigint[], budget: bigint, minimum: bigint): bigint[] {
  const result = basis.map(() => 0n);
  let free = basis.flatMap((value, index) => value > 0n ? [index] : []);
  if (budget < mul(minimum, BigInt(free.length))) throw new RangeError('INVALID_ROD_POOL');
  while (free.length) {
    const total = sum(free.map(index => basis[index]));
    const threshold = mul(minimum, total);
    const small = free.filter(index => mul(basis[index], budget) < threshold);
    if (small.length) {
      for (const index of small) result[index] = minimum;
      budget -= mul(minimum, BigInt(small.length));
      free = free.filter(index => !small.includes(index));
      continue;
    }
    const remainders = free.map(index => {
      const product = mul(basis[index], budget);
      result[index] = product / total;
      return { index, remainder: product % total };
    });
    const rest = budget - sum(free.map(index => result[index]));
    if (rest < 0n || rest > BigInt(remainders.length)) throw new RangeError('INVALID_ROD_POOL');
    remainders.sort((a, b) => a.remainder === b.remainder ? a.index - b.index : a.remainder > b.remainder ? -1 : 1);
    for (const { index } of remainders.slice(0, Number(rest))) result[index] = add(result[index], 1n);
    return result;
  }
  if (budget !== 0n) throw new RangeError('INVALID_ROD_POOL');
  return result;
}

function target(tickets: readonly LuckTicket[], anchor: readonly bigint[], total: bigint): bigint[] {
  let top = 0n, largestTop = 0n, others = 0n;
  const rankTotals = Array<bigint>(10).fill(0n);
  tickets.forEach(([weight, score], index) => {
    rankTotals[score] = add(rankTotals[score], anchor[index]);
    if (score === 9) {
      top = add(top, weight);
      if (weight > largestTop) largestTop = weight;
    } else if (weight > 0n) others++;
  });
  const boundDenominator = add(mul(2n, top), mul(others, largestTop));
  let numerator = TARGET_UUR_MULTIPLIER, denominator = 1n;
  if (mul(numerator, boundDenominator) > mul(2n, total)) {
    numerator = mul(2n, total);
    const divisor = gcd(numerator, boundDenominator);
    numerator /= divisor;
    denominator = boundDenominator / divisor;
  }
  const ticks = mul(mul(total, denominator), TARGET_TICK_SCALE);
  const topScale = mul(numerator, TARGET_TICK_SCALE);
  const remaining = ticks - mul(top, topScale);
  if (remaining < 0n) throw new RangeError('INVALID_ROD_POOL');
  const middleTotal = sum(rankTotals.slice(1, 9));
  const fBudget = middleTotal === 0n ? remaining : mul(rankTotals[0], ticks) / sum(anchor);
  if (remaining < fBudget) throw new RangeError('INVALID_ROD_POOL');
  const middle = allocate(rankTotals.slice(1, 9), remaining - fBudget, 0n);
  const budgets = [fBudget, ...middle];
  const floor = mul(largestTop, topScale) / 2n;
  const result = tickets.map(() => 0n);
  for (let score = 0; score < 9; score++) {
    const basis = tickets.map(([, rank], index) => rank === score ? anchor[index] : 0n);
    allocate(basis, budgets[score], floor).forEach((amount, index) => result[index] = add(result[index], amount));
  }
  tickets.forEach(([weight, score], index) => {
    if (score === 9) result[index] = mul(weight, topScale);
  });
  if (sum(result) !== ticks) throw new RangeError('INVALID_ROD_POOL');
  return reduce(result);
}

function dominates(tickets: readonly LuckTicket[], anchor: readonly bigint[], destination: readonly bigint[]): boolean {
  const a = sum(anchor), b = sum(destination);
  for (let score = 1; score <= 9; score++) {
    const upperA = sum(anchor.filter((_, index) => tickets[index][1] >= score));
    const upperB = sum(destination.filter((_, index) => tickets[index][1] >= score));
    if (mul(upperB, a) < mul(upperA, b)) return false;
  }
  return true;
}

function positiveTail(tickets: readonly LuckTicket[], total: bigint, moment: bigint, base: bigint, anchor: bigint, luck: bigint): bigint[] {
  const ordinary = legacy(tickets, total, moment, base, luck < anchor ? luck : anchor);
  const excess = luck > anchor ? luck - anchor : 0n;
  const adjusted = ordinary.map((value, index) => excess === 0n ? value : mul(value, add(TAIL_SCALE_BP, mul(excess, BigInt(tickets[index][1]) + 1n))));
  sum(adjusted);
  return adjusted;
}

/** Exact counterpart of game_rules::luck::weights; normalize by the returned sum. */
export function luckWeights(tickets: readonly LuckTicket[], luckBp: number): bigint[] {
  if (!Number.isInteger(luckBp) || luckBp < 0 || luckBp > 0xffff_ffff) {
    throw new RangeError('INVALID_LUCK');
  }
  for (const [weight, score] of tickets) {
    if (weight < 0n || weight > u64Max || !Number.isInteger(score) || score < 0 || score > 9) {
      throw new RangeError('INVALID_ROD_LUCK');
    }
  }
  const total = tickets.reduce((sum, [weight]) => signed(sum + weight), 0n);
  const moment = tickets.reduce((sum, [weight, score]) => signed(sum + weight * BigInt(score)), 0n);
  const distance = signed(signed(9n * total) - moment);
  const scores = tickets.filter(([weight]) => weight > 0n).map(([, score]) => score);
  if (!scores.length || total === 0n || distance <= 0n) throw new RangeError('INVALID_ROD_POOL');
  const minimum = BigInt(Math.min(...scores));
  const base = signed(10_000n * distance);
  const minimumSlope = minimum * total - moment;
  const lastPositive = minimumSlope < 0n ? (base - 1n) / -minimumSlope : LEGACY_LUCK_BOUNDARY_BP;
  let anchorLuck = lastPositive < LEGACY_LUCK_BOUNDARY_BP ? lastPositive : LEGACY_LUCK_BOUNDARY_BP;
  const compatibilityLuck = anchorLuck;
  const luck = BigInt(luckBp);
  if (!tickets.some(([weight, score]) => weight > 0n && score === 9)) {
    return positiveTail(tickets, total, moment, base, anchorLuck, luck);
  }
  let anchor = reduce(legacy(tickets, total, moment, base, anchorLuck));
  let destination: bigint[] | undefined;
  try {
    const values = target(tickets, anchor, total);
    if (dominates(tickets, anchor, values)) destination = values;
  } catch (error) {
    if (!(error instanceof RangeError) || error.message !== 'INVALID_ROD_POOL') throw error;
  }
  if (!destination) {
    anchorLuck = 0n;
    anchor = reduce(legacy(tickets, total, moment, base, 0n));
    try {
      const values = target(tickets, anchor, total);
      if (!dominates(tickets, anchor, values)) return positiveTail(tickets, total, moment, base, compatibilityLuck, luck);
      destination = values;
    } catch (error) {
      if (!(error instanceof RangeError) || error.message !== 'INVALID_ROD_POOL') throw error;
      return positiveTail(tickets, total, moment, base, compatibilityLuck, luck);
    }
  }
  if (luck <= anchorLuck) {
    const adjusted = legacy(tickets, total, moment, base, luck);
    sum(adjusted);
    return adjusted;
  }
  const a = sum(anchor), b = sum(destination), divisor = gcd(a, b);
  const excess = luck - anchorLuck;
  const adjusted = anchor.map((value, index) =>
    add(mul(mul(TAIL_BLEND_BP, value), b / divisor), mul(mul(excess, destination[index]), a / divisor)));
  sum(adjusted);
  return adjusted;
}
