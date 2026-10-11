# Short-session XP audit — October 11, 2026

Rules 10 change level requirements, not XP rewards. Lifetime XP, saved receipts, owned equipment, gold, materials and slime charges remain intact. Existing players can gain displayed levels from their already-earned XP.

## Working target and curve

The working target is about 90 fishing days to level 120 at 25 casts/day, with slime optional. This is a tuning assumption rather than an observed play frequency or a guaranteed deadline. The first ten level costs remain unchanged.

```text
L <= 10: 80 + 25*L + 5*L*L
L > 10:  830 + 35*(L - 10)
```

Level 120 requires 304,395 lifetime XP instead of 3,032,120. Costs increase monotonically across the join (830 → 865). Server purchase/travel/cast gates, Discord displays and the browser level bar use the subscribed rule version; the old curve stays active until owner activation inserts rules 10.

## Inputs

- 1,000 validation careers for each of five policies at 10/25/50/100 casts/day and with no daily claims: 25,000 final careers. The old-curve control has another 5,000 careers at 25/day; candidate sweeps are separate.
- Fresh Common Twig, zero coins/materials, all fish sold immediately at their saved physical-size value, actual mixed pulls and random natural drops, first discoveries, integer XP and additive gear/slime bonuses.
- Buy sequential licences and rods as soon as eligible and affordable. Bank Prismatic on each paid family until replacing it, then finish Abyssal upgrades when affordable. Slime packs cost actual gold, retain their charges, and are bought when affordable until level 120. Continue until both the cap and last rod purchase have been measured; do not waste further gold buying slime after the cap.
- Natural means no purchased bait. Resource uses paid tin/scrap bait. Green/Yellow/Pink use their corresponding paid XP slime, with an unboosted cast when unaffordable.
- No gifts, achievement income, favourites, skipped rods or other spending. Licence gates, rod prices, recipes, cooldown and sale values are unchanged. Real players can skip families; these estimates assume sequential purchases.
- Fishing days are casts divided by the daily budget. Missed days add elapsed time without losing XP, quality, materials or lifetime delivery stamps. A 50-cast session spans about 50 minutes at the one-minute cooldown; these estimates do not require multiple hours daily.

## Baseline progression without bait

| Level | Median casts at 25/day | Median days at 25/day | 90th-percentile days | Median days at 50/day |
|---:|---:|---:|---:|---:|
| 5 | 16 | 0.6 | 1.0 | 0.3 |
| 10 | 63 | 2.5 | 3.2 | 1.3 |
| 18 | 206 | 8.2 | 9.9 | 4.1 |
| 28 | 379 | 15.2 | 17.4 | 7.6 |
| 40 | 555 | 22.2 | 24.7 | 11.1 |
| 55 | 813 | 32.5 | 35.2 | 16.3 |
| 60 | 904 | 36.2 | 39.0 | 18.1 |
| 80 | 1,316 | 52.6 | 56.2 | 26.4 |
| 100 | 1,749 | 70.0 | 73.6 | 35.1 |
| 120 | 2,224 | 89.0 | 93.2 | 44.6 |

Old curve: level 120 median **674.8 days** at 25/day. The new cap wait is about 87% shorter, while retaining the starter curve and all earned XP. The final water level-55 threshold is reached in about 33 days at 25/day or 16 days at 50/day; the 25,000-gold licence must still be bought after the previous licences.

## Slime comparison: level 120

| Policy | Cost/use | Median days, 25/day | 90th-percentile days, 25/day | Median days, 50/day |
|---|---:|---:|---:|---:|
| Natural | 0 gold | 89.0 | 93.2 | 44.6 |
| Green | 5 gold | 81.2 | 85.2 | 40.8 |
| Yellow | 15 gold | 75.5 | 79.9 | 37.9 |
| Pink | 40 gold | 69.3 | 74.3 | 34.8 |
| Resource | 2 tin / 5 scrap gold | 87.5 | 91.6 | 43.9 |

Green/Yellow/Pink add 25%/50%/100% of base cast XP, alongside the rod and quality bonuses. Slime is optional for the baseline target. Its gold spending delays rod purchases; that delay and lower gear while saving are included in the cap estimates. Pink can reach level 120 before the final rod purchase, which remains a separate gold/material progression goal.

## Schedule and daily sensitivity

| Casts/day | Natural cap median days | Natural cap 90th-percentile days |
|---:|---:|---:|
| 10 | 220.6 | 230.7 |
| 25 | 89.0 | 93.2 |
| 50 | 44.6 | 46.7 |
| 100 | 22.3 | 23.4 |

No daily claims: median 2,239 casts to the cap, compared with 2,231 at 50/day (0.36% more). There is no XP reward for daily claims. The small difference comes from coins affecting gear/licence timing.

## Rod-loop check after XP tuning

| Previous rod | Mythic at next purchase, 25/day | Prismatic ready, 25/day | Mythic, 50/day | Prismatic ready, 50/day |
|---|---:|---:|---:|---:|
| Twig Rod | 0.0% | 0.0% | 0.0% | 0.0% |
| River Rod | 94.6% | 47.1% | 95.1% | 50.3% |
| Marsh Rod | 95.5% | 47.7% | 96.6% | 51.2% |
| Moonwood Rod | 98.4% | 38.8% | 98.8% | 42.4% |
| Tide Rod | 100.0% | 83.2% | 100.0% | 86.0% |
| Glacial Rod | 100.0% | 65.7% | 100.0% | 68.3% |

Twig is the quick starter exception. Faster licence access changes the mix of waters and material drops, so later Prismatic readiness does not remain a uniform 50%. Mythic still precedes the next purchase in roughly 95–100% of natural careers. The paid-rod gold ladder remains the same; cap-level progression can finish before the final rod when choosing expensive slime.

## Verification and reproduction

- Content validation, 22 authoritative game-rule tests, both simulation tests, Rust workspace check and Svelte check (zero errors/warnings) passed.
- The isolated rules-9 → rules-10 curve proof seeded low/high/capped XP, owned upgraded gear, slime stock, materials, gold and a saved cast. It verified old licence gates until owner activation, exact new levels against the frontend helper, owner authorization, repeat activation and preservation of all 41 gameplay tables.
- The complete crafting proof passed all slime reward categories, single charge across mixed pulls, cooldown/replay, depletion, native transport, privacy and cross-client races.
- Actual AnglerLevel rendering checked at 1440px and 390px: 60,000 XP shows old level 30 and new level 48; 304,395 XP shows capped 120. The mobile fixture has no horizontal overflow. Screenshots are under ignored `output/playwright/xp-curve-fixture-*.png`.

```powershell
cargo build -p fishing-simulations --bin rod_progression --release --locked
target/release/rod_progression.exe 1000 output/balance/xp-tuned-final-25.json 25 content xp
target/release/rod_progression.exe 1000 output/balance/xp-tuned-final-50.json 50 content xp
target/release/rod_progression.exe 1000 output/balance/xp-legacy-baseline-25.json 25 content xp 5
npx tsx scripts/xp-curve-proof.ts <saved-rules-9-crafting-fixture.wasm>
```

Files contain exact cumulative level thresholds, per-milestone cast/day distributions and seeds. Curves tested during calibration included late quadratic coefficients 5/2/1/0 and linear increments 32/35; the selected 35-XP increment matches the working 90-day target more closely.

Input hashes (SHA-256):

- `crates/game-rules/src/progression.rs`: `66b287b115c848a09c5f79a27646892e1a1f23e4335d2936f55fffaa461b5ca6`
- `content/world.json`: `ca726fa0eae96cb7dcaa4ef43a947d6cbfac3b0f7e512b8a54fbbd539722ccab`
- `content/species.json`: `158c18482f0068bb5a23174dd52dc764789366c613669034bc1e8abfae5a3051`
- `content/crafting.json`: `086c695d4f52adbc1747de6438dbf5695f400c2f59eff44636a0413f7fcd1344`
- `content/trader.json`: `d62a78c321ee6b21af5b46fa8dd653e8bd965de9b1af518c71f83e48e5a82a0d`
- `content/game-rules.json`: `5f97e8364d6b744a7f043996e0652abf23cf1c275d43cbbd4bdcd3daac1cb4ee`
