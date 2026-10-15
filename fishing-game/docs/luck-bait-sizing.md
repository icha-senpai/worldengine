# Luck bait size attempts — October 12, 2026

Local rules-12 candidate; crafting catalog 6, trader catalog 8. All four luck baits retain the complete normal species/rank pool and their existing luck bonuses. Each size attempt stays within the selected rank; keep the heaviest, breaking weight ties by length. One accepted cast consumes one bait use, including bonus pulls.

| Bait | Luck | Size attempts | Pack price / ten uses |
|---|---:|---:|---:|
| Plump Worms | +5% | 2 | 30 |
| River Grubs | +10% | 3 | 70 |
| Moonlit Fireflies | +15% | 4 | 120 |
| Trophy Chum | +25% | 8 | 500 |

Rules 12 applies the 1-in-5,000 paired-endpoint attempt to every selected rank when using multi-attempt bait. Natural maxima also remain possible. Official Perfect Angler recognition still requires UUR; the Sock remains excluded. Resource baits, XP slime and no bait keep a single attempt and the UUR endpoint rule.

## Official perfect odds example

Prismatic Abyssal in Meadow Pond; at least one official UUR perfect from any eligible species per accepted cast. Includes junk/treasure and the independent bonus pull. Before uses the former single attempt and rules 11; after uses the new attempt count and rules 12.

| Bait | Before | After |
|---|---:|---:|
| Plump Worms | 1 in 1,531 | 1 in 827 |
| River Grubs | 1 in 1,488 | 1 in 577 |
| Moonlit Fireflies | 1 in 1,448 | 1 in 451 |

Category, species and rank selection probabilities are unchanged by these extra size attempts. More rank entries are not guaranteed; paired maxima become more frequent within whichever rank was selected.

## Gold and XP

No perfect-specific coin or XP reward was added. Sale value depends on rank and length; XP depends on rank, size grade and equipped XP bonuses. Retaining a heavier specimen does not universally increase length on every individual catch. Extra attempts improve average size; monetary/XP improvements are modest compared with luck shifting the selected rank.

In 10,000-cast stationary simulations for every bait across seven rods, Common/Mythic/Prismatic qualities and seven waters (2,058 setups including no bait), every setup had positive mean net income after its current bait cost, excluding daily rewards. This does not guarantee liquidity on every cast or include other purchases. Every caught fish was sold. At Prismatic Abyssal, Trophy at 50 coins/use nets approximately +172 to +318 coins/cast across waters.

## Level-100 multi-baiting proposal

Not implemented in this candidate. Recommendation: unlock two slots at level 100 and allow different categories: luck/size, XP, and materials. Consume one use from each equipped, stocked bait per accepted cast, including bonus pulls. Add independent benefits; do not multiply size attempts or stack two baits from the same category. A depleted slot loses its own bonus while the other remains usable.

Trophy plus Pink Slime would cost 90 coins per accepted cast (50 + 40), retain Trophy’s eight attempts/+25% luck and add Pink’s +100% XP. At top gear this means 3x base cast XP (rod/quality +100% plus slime +100%), compared with 2x using Trophy alone. Perfect odds remain those of Trophy alone. Current stationary Trophy income would leave about +132 to +278 coins/cast after both costs.

## Verification

All 32 game-rules tests pass, including forced endpoint outcomes for every valid species/rank at 2/3/4/8 attempts without promotion. The sizing audit compared 14,952 displayed bands with Rust bounds, luck and endpoint probabilities, with 2,990,400 natural generated samples. The isolated crafting proof checks saved 2/3/4 attempt metadata, use consumption, XP rounding, all species pools, exact Trophy pricing and metadata activation.

Reproduce odds with `npm run rust:script -- scripts/luck-bait-sizing-audit.ts`; raw all-biome odds and reference bounds are saved under `.local/luck-bait-sizing-*.json`. The income experiment is offline; its source and detailed results are under `.local/economy-xp-audit/` and `.local/luck-bait-sizing-income.json`.

The audit was read-only. The tuning subsequently shipped on October 12 with
rules 13, alongside Collection Focus and the extended level cap. Existing
wallets, XP, catches, equipment and saved rewards passed preservation checks.
