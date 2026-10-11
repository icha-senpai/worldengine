# Rod progression at shorter sessions — October 11, 2026

Locally implemented rules 9 / crafting 3 / Trader 5. This report does not claim live activation.

The primary schedules are 25 and 50 accepted casts per day. Ten is a lighter-play sensitivity; 100 is the upper bound, and zero disables daily income while retaining fishing.

The goal is a short starter purchase, then Mythic on each paid family before the next rod becomes affordable, with the Prismatic material chase meeting that purchase. Gold timing and random drops cannot guarantee a particular quality. Keep River as the first meaningful upgrade loop; upgrading Twig is optional.

## Method and limits

- 1,000 independently seeded careers per strategy and schedule: 25,000 final careers. Separate calibration seeds prevent fitting validation careers.
- Fresh Common Twig, zero wallet/materials; sell every fish at its authoritative physical-size value. Advance licences and waters when level and money allow; buy rods sequentially as soon as eligible and affordable.
- Actual mixed pulls, luck, size bonuses, first discoveries, integer additive XP, natural tin/treasure, gold and bait-pack charges are included. Daily pays coins, never XP; one claim per played day, including the first.
- Natural banking stops at Mythic and saves the family-specific Prismatic recipe. Prismatic-spending policies actually spend the materials; resource policies buy ten-use packs and carry remaining charges. Pink buys XP slime whenever affordable, so its gold cost makes rod purchases slower.
- No gifts, achievements income, other purchases, retained fish, or skipped rods. Real players may progress differently. The shop permits skipping families when the previous licence is owned; this remains a limitation of sequential pacing estimates, especially for the close Marsh/Moonwood prices.
- Fishing-day equivalents are casts divided by the stated daily budget. They become elapsed calendar time only with that budget every day. Missing days preserves XP, quality, materials and delivery stamps; these totals do not include the time off.
- Biome licence gates remain 1/5/10/18/28/40/55. Rods require gold and the previous licence, without an angler-level gate. Buying Abyssal does not imply access to Abyssal Shelf or a fully upgraded Abyssal.

## Full ladder: natural drops, bank at Mythic

| Casts/day | Median casts | Median fishing days | 90th-percentile days | Previous candidate median days |
|---:|---:|---:|---:|---:|
| 10 | 1,382 | 138.2 | 149.3 | — |
| 25 | 1,441 | 57.6 | 62.6 | 221.8 |
| 50 | 1,463 | 29.3 | 31.8 | 112.1 |
| 100 | 1,476 | 14.8 | 16.1 | — |

No daily claims: median 1,483 casts, 1.4% above the 50/day baseline. Daily delivery is a modest benefit, rather than a requirement to sustain rod progress. Existing delivery stamps are lifetime claims and survive missed days.

## Individual family intervals

These are intervals after buying the previous rod, not cumulative waits. Percentages use natural drops and banking at Mythic.

| Next rod | Gold | Median days at 25/day | Median days at 50/day | Mythic reached at purchase, 25 / 50 | Prismatic ready, 25 / 50 |
|---|---:|---:|---:|---:|---:|
| River Rod | 500 | 0.4 | 0.2 | 0.0% / 0.0% | 0.0% / 0.0% |
| Marsh Rod | 15,000 | 5.7 | 2.9 | 93.6% / 94.3% | 46.9% / 49.3% |
| Moonwood Rod | 16,000 | 5.5 | 2.8 | 94.9% / 95.8% | 44.6% / 48.6% |
| Tide Rod | 33,000 | 9.6 | 4.9 | 98.7% / 98.6% | 44.9% / 50.9% |
| Glacial Rod | 74,000 | 14.4 | 7.3 | 99.9% / 99.9% | 45.6% / 50.4% |
| Abyssal Rod | 130,000 | 21.9 | 11.1 | 100.0% / 100.0% | 45.7% / 52.3% |

Twig is intentionally replaced early; its zero Mythic percentage is expected. From River onward the target is met by roughly 94–100% of the natural-banking trials at the primary schedules, with roughly half of players at Prismatic readiness. Unlucky players can keep their current rod and finish crafting; there is no forced replacement or quality loss.

## Other choices: full-ladder median days

| Strategy | 25 casts/day | 50 casts/day |
|---|---:|---:|
| natural bank at mythic | 57.6 | 29.3 |
| natural spend prismatic | 57.9 | 29.4 |
| pink bank at mythic | 76.6 | 38.9 |
| resource bait bank at mythic | 57.2 | 29.1 |
| resource bait spend prismatic | 56.2 | 28.5 |

Prismatic spending slightly increases the natural-drop full-ladder wait; it does not reset progress. Material bait gets recipes ready sooner but the gold ladder still applies. Pink XP slime trades gold for XP and is the slowest rod-purchase policy here.

## Recipe and licence budgets

Each cost is tin / scrap. Mythic is cumulative Common→Mythic; Prismatic is the additional final step.

| Family | Cumulative Mythic | Additional Prismatic |
|---|---:|---:|
| Twig Rod | 545 / 360 | 600 / 400 |
| River Rod | 6 / 6 | 9 / 10 |
| Marsh Rod | 18 / 13 | 18 / 16 |
| Moonwood Rod | 34 / 23 | 36 / 24 |
| Tide Rod | 55 / 37 | 60 / 40 |
| Glacial Rod | 88 / 59 | 96 / 64 |
| Abyssal Rod | 132 / 88 | 144 / 96 |

All six paid rods cost 268,500 gold in total. Licences cost 41,150 through Abyssal Shelf, or 16,150 through Glacial Reach (the last licence required to buy Abyssal Rod). Licences are purchased whenever eligible in the simulations, so some careers buy the final licence before the last rod.

Bait unit costs (ten uses per pack):

- Sticky Chum: 2 gold/use.
- Salvage Chum: 5 gold/use.
- Plump Worms: 3 gold/use.
- River Grubs: 7 gold/use.
- Moonlit Fireflies: 12 gold/use.
- Green XP Slime: 5 gold/use.
- Yellow XP Slime: 15 gold/use.
- Pink XP Slime: 40 gold/use.

## Measured rewards per cast

Natural banking, 50/day, aggregated by equipped family while advancing through waters. Gross coins include fish sales and treasure, excluding daily deliveries, licence/rod spending and bait purchases. These are sample means, not fixed earnings for one biome.

| Equipped rod | Gross gold/cast | Tin/cast | Scrap/cast |
|---|---:|---:|---:|
| Twig Rod | 85.52 | 0.122 | 0.033 |
| River Rod | 106.34 | 0.194 | 0.109 |
| Marsh Rod | 120.28 | 0.219 | 0.135 |
| Moonwood Rod | 131.49 | 0.166 | 0.148 |
| Tide Rod | 211.80 | 0.201 | 0.205 |
| Glacial Rod | 248.59 | 0.220 | 0.213 |

Resource bait adds exactly one of the selected material per cast on top of natural pulls, at its charged gold cost. It is optional for the natural-drop pacing above.

## Verification

- Content validation passed, including complete recipes and the River-onward material carry guard.
- All 21 game-rule tests and both simulation tests passed; production WASM built successfully.
- Isolated crafting proof passed exact wallet boundaries, River/Marsh/Moonwood recipes, spending and replay, previous-licence gates, stale quotes, native transport and browser/Discord concurrency.
- Old-WASM migration proof passed owner-only and repeated activation. All 41 gameplay tables were preserved exactly; only intended metadata/profile levels changed.
- This turn changes content, the offline runner, proofs and documentation. It does not change the client schema or UI, and it has not activated the new balance on the live player database.

## Reproduce

```powershell
cargo build -p fishing-simulations --bin rod_progression --release --locked
target/release/rod_progression.exe 1000 output/balance/short-sessions-final-25.json 25 content gold
target/release/rod_progression.exe 1000 output/balance/short-sessions-final-50.json 50 content gold
```

Use 10/100 for sensitivities, or 0 to disable deliveries. Final report files contain exact input catalogs, seeds, strategy assumptions and every transition. Previous candidate snapshots are retained in `output/balance/candidates/gold-rods-family-recipes`; calibration is saved in `output/balance/short-sessions-calibration-50.json` (shop prices rounded to 500 gold before validation).

Input SHA-256 hashes:

- `world.json`: `ca726fa0eae96cb7dcaa4ef43a947d6cbfac3b0f7e512b8a54fbbd539722ccab`
- `species.json`: `158c18482f0068bb5a23174dd52dc764789366c613669034bc1e8abfae5a3051`
- `crafting.json`: `086c695d4f52adbc1747de6438dbf5695f400c2f59eff44636a0413f7fcd1344`
- `trader.json`: `d62a78c321ee6b21af5b46fa8dd653e8bd965de9b1af518c71f83e48e5a82a0d`
