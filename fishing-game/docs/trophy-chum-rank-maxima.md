# Trophy Chum: maxima at every rank

Calculated 2026-10-12 from species/world catalog 5, crafting 6, and rules 12. This is a local candidate; rules 11 remains deployed until publication. Reproduce with `npm run rust:script -- scripts/rank-maxima-audit.ts`.

Rules 12 extends the existing 1-in-5,000 paired-endpoint roll to F through UUR for multi-attempt bait (currently Trophy Chum). Fish and rank are selected first; all eight attempts stay within that rank and retain the heaviest specimen, with length breaking ties. Existing UUR perfect odds, eligibility and rewards are preserved. The bait now costs 500 coins per 10 uses and adds 25% luck. XP and sale-value formulas remain unchanged; the extra maximum-size outcomes can slightly raise their averages.

For each attempt, `s = endpoint + (1 - endpoint) / (lengthChoices × weightChoicesAtMaximumLength)`. Eight attempts give `1 - (1 - s)^8`. Multiply by the species/rank pull probability and apply `(1 + q)p - qp²` for at least one success per cast. With Trophy Chum, after selecting the fish and rank, the paired maximum chance is at least 1 in 625.44; natural endpoints improve it further. This conditional chance is not the chance per cast.

Both columns below use Prismatic Abyssal Rod + Trophy Chum (power 185, 95% luck, eight attempts). Before uses rules 11; after uses rules 12. Each target requires both exact maxima on the same catch. Per-cast odds include biome fish/junk/treasure category weights, graded luck tickets and the 92.5% independent bonus pull. Assume accepted eligible casts in the named fish's biome and continuous Trophy Chum use.

## Carp in Meadow Pond

| Rank | Before per cast | After per cast | Improvement |
|---|---:|---:|---:|
| F | 1 in 131,473 | 1 in 33,170 | 4.0× |
| D | 1 in 199,719 | 1 in 42,179 | 4.7× |
| C | 1 in 244,703 | 1 in 55,179 | 4.4× |
| B | 1 in 782,917 | 1 in 87,615 | 8.9× |
| A | 1 in 1,489,778 | 1 in 121,642 | 12.2× |
| S | 1 in 2,926,720 | 1 in 173,319 | 16.9× |
| SS | 1 in 5,085,274 | 1 in 231,336 | 22.0× |
| SSS | 1 in 8,589,117 | 1 in 296,919 | 28.9× |
| UR | 1 in 7,745,813 | 1 in 339,196 | 22.8× |
| UUR | 1 in 755,569 | 1 in 755,569 | 1.0× |

## Species contrast

| Species | Rank | Before per cast | After per cast |
|---|---|---:|---:|
| Minnow | F | 1 in 201 | 1 in 200 |
| Minnow | UR | 1 in 2,872 | 1 in 2,847 |
| Pea Crab | F | 1 in 79 | 1 in 79 |
| Pea Crab | UR | 1 in 582 | 1 in 582 |
| Carp | F | 1 in 131,473 | 1 in 33,170 |
| Carp | UR | 1 in 7,745,813 | 1 in 339,196 |
| Blue Marlin | F | 1 in 274,665,844 | 1 in 257,233 |
| Blue Marlin | UR | 1 in 5,749,394,322 | 1 in 717,468 |
| Great Hellfin Shark | F | 1 in 4,999,370,146 | 1 in 204,317 |
| Great Hellfin Shark | UR | 1 in 124,771,516,083 | 1 in 679,256 |

## Verification and limits

The audit cross-checks all 9968 displayed bands against Rust's actual luck adjustment, endpoint gate, inclusive measurement ranges and bonus-pull formula, with 1993600 generated natural samples. Forced endpoint tests cover all 2492 valid species/rank pairs without promotion. Seeded lower-rank frequency tests cover F and UR with 200,000 eight-attempt catches each; legacy RNG streams and UUR behavior remain stable. No live player data or deployment changes are made by this audit.

These are independent-trial odds, not pity thresholds. At 50 eligible casts/day, the mean wait for the exact F Carp target is 663 days; at 25/day it doubles. Rank-by-rank collections are still a long-term species-specific chase. The current Perfect Angler badge, species marks and announcement remain reserved for official UUR perfect catches.
