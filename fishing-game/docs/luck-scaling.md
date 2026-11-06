# Uncapped Luck Scaling

Status: local implementation; production deployment requires separate approval.
This replaces the old input ceilings, not existing equipment or earned results.

## Player Contract

- Rods, boats and nets no longer reject Luck above their old 12,000 / 26,600
  basis-point limits. The existing `u32` representation remains a numeric
  safety boundary, not a balance cap.
- Every currently obtainable loadout keeps its exact encounter weights and
  the same random-number stream. No existing equipment stat is reduced.
- Beyond current gear, Luck has diminishing returns toward a positive
  distribution. More Luck improves cumulative higher-rank odds without
  making ordinary lower ranks disappear.
- Fihs remains half the rarest ordinary Abyssal UUR encounter per pull, and
  the Sock half Fihs. Before Collection Focus, every ordinary species/rank
  remains more common than Fihs at equal Luck across the current seven waters.
  Focus can intentionally suppress already-collected ranks as before.
- Species remain randomly selected. A minimum protects rare ordinary
  measurements/ranks; it does not let players select a species.
- Fish/junk/treasure category chances, Power, extra pulls, XP, bait consumption,
  measurement generation and eligible perfect trials are unchanged.

Luck can provide more UUR encounters and indirectly more perfect opportunities.
It does not multiply the conditional perfect-measurement probability or grant
an endpoint directly. Current-loadout perfect odds remain unchanged.

## Why The Old Formula Needed Replacing

For base ticket weight `w`, rank score `r` from 0 to 9, `T = sum(w)`,
`M = sum(w*r)` and `D = 9*T-M`, the original adjusted weight was:

```text
w' = w * (10000*D + L*(r*T-M))
```

Scores below the weighted mean lose weight as Luck `L` grows. Eventually some
become negative. Raising the validation ceiling cannot repair that.

A simple positive rank bias is also insufficient: some ordinary F encounters
can become rarer than Fihs long before numerical limits are reached. The new
curve protects encounter ordering as well as positivity.

## Compatibility And Extension

All current ticket pools retain the original formula through `A = 26600`,
the highest previously obtainable haul Luck. Current rod maxima are below it.
This is a compatibility transition, not a stat cap.

Let `a` be the normalized original distribution at `A`, `b` a positive target
distribution, and `E = L-A`. Above the transition:

```text
P(L) = (50000*a + E*b) / (50000+E)
```

This is a convex mixture of two valid distributions, not a clipped weight.
It is continuous in probability at the transition and approaches its target
without reaching it at any finite Luck. Every further increment continues
moving toward that target.

For each upper-rank set, the target mass is checked to be at least the anchor
mass. Therefore cumulative higher-rank chances cannot decrease along the tail.
The scale `50000` controls diminishing returns; it does not reject a Luck value.

### Target Distribution

The current catalog's target uses ten times the original score-9 probability,
not a tenfold multiplier on final perfect odds:

1. Reserve exact base-weight-proportional tickets for score 9. The Sock uses
   score 9 for Luck despite retaining its actual F rank.
2. Preserve the total ordinary score-0 probability at the compatibility anchor,
   rounded down by at most one target tick.
3. Fund the additional score-9 probability from D through UR in proportion to
   their anchor rank masses.
4. Within each ordinary rank, retain proportional anchor weights where possible.
   A minimum of half the largest score-9 target ticket prevents rare ordinary
   ranks from falling behind the bonus encounters.

This deliberately allows some rare ordinary rank combinations to gain more
weight than others. Preserving all within-rank ratios at arbitrarily high Luck
would conflict with the protected exceptional ordering.

Tenfold is a chosen terminal rarity budget, not a law of the game or a cap on
the displayed stat. It can be reviewed explicitly with future content. Ordinary
UUR encounters never become guaranteed, and low ranks retain positive shares.
Earlier Tideforge affix-number proposals need retesting against this curve;
they are not approved by the formula change.

### Exact Integer Implementation

The target has `T * 1,000,000` ticks in current pools: one trillion ticks when
the original pool has one million tickets. Score-9 weights remain exact common
multiples of their base weights, retaining the exceptional 2:1 relationships.

Rank budgets and ordinary within-rank allocations use stable largest-remainder
rounding, with original ticket index as the tie-break. Minimums are applied
before rounding. The target is constructed once per calculation, not rerolled.

GCD reduction preserves probabilities before mixing. If reduced anchor weights
sum to `X`, target weights to `Y`, and `g = gcd(X,Y)`, the tail weights are:

```text
W_i = 50000*a_i*(Y/g) + E*b_i*(X/g)
```

Normalize by the actual sum of `W`, not the old fixed denominator. Rust uses
checked `u128` arithmetic; the website uses matching checked BigInt arithmetic.
No floating-point weights, negative clamping or per-catch probability rounding
enter the authoritative sampler.

The wide sampler still draws a `u64` random number whenever its total fits in
`u64`. This preserves the old RNG stream for all existing loadouts, including
Collection Focus. Larger pools use an unbiased `u128` draw.

### Unusual Future Pools

A pool's compatibility point cannot exceed its last positive legacy point.
If a proposed target cannot improve its compatibility distribution, the helper
tries the unmodified zero-Luck baseline instead. If the pool cannot support
those ordinary rarity minimums even at baseline, it uses the generic positive
rank-plus-one tail from its last positive legacy point. This supports synthetic
forced-rank pools without rejecting valid casts or altering the game catalog.
The exceptional-order contract is proved for the actual catalog, not arbitrary
synthetic inputs that already violate that ordering at zero Luck. Malformed
scores and arithmetic overflow still fail explicitly; Luck is not clipped.

The tenfold target is reduced rationally if a hypothetical pool cannot afford
its positive ordinary minimums. Pools without score-9 tickets use a positive
rank-plus-one continuation instead. All seven current pools use the full
26,600-point compatibility range and the tenfold target.

New catalogs must rerun the compatibility, ordering and upper-rank proofs.
Do not assume new ticket distributions automatically inherit today's balance.

## Example Scale

These are calculated shares of ordinary UUR among fish results in Abyssal Shelf,
before Collection Focus. They are not per-cast perfect odds or observed player
frequencies.

| Raw Luck | Ordinary UUR share |
|---|---:|
| +266% | 4.5911% |
| +332% | 5.5185% |
| +350% | 5.7350% |
| +1,000% | 9.3216% |

The +332% case is about 20% more ordinary UUR results than +266%, relatively,
not 20 extra percentage points of catch chance.

## Verification

Run the bounded workflow with the current task's temporary baseline WASM:

```text
npm run rust:script -- scripts/luck-verification.ts
npm run check
npm run build
```

The preservation runner refuses a second retained fixture when
`.local/luck-preservation-runtime.json` already exists; it does not silently
create another database. The independent math proof is rerunnable without
database mutations:

```text
npm run rust:script -- scripts/uncapped-luck-proof.ts
```

The workflow exercises current-loadout equality, wide sampler intervals and
legacy RNG parity, Rust/TypeScript exact-vector parity, cumulative-rank
monotonicity, exceptional ordering across all seven waters, high-Luck displayed
odds and Focus/measurement regressions. It rebuilds WASM and bindings and uses
one uniquely named isolated proof database to check retained rows, active
trips, saved-cast replay and rejected early settlement.

The database proof uses representative starter records, not synthesized
high-grade raid history, and does not wait for both trips to settle naturally.
No schema migration, content activation or production publish is part of this
change. Saved catches, receipts and existing haul snapshots are not rewritten.

Verified locally for this change: 29 module tests, 82 exact cross-language
vectors, 14,952 displayed rank ranges, 39,840 Focus probability checks and
82 retained tables in the isolated preservation fixture. Web check/build pass;
the build retains its nonfatal large-chunk warning. Detailed task evidence is
in `.local/luck-build-evidence.txt` and `.local/luck-preservation-evidence.json`.
