# Seven-biome prototype balance

All 251 supplied sprite names have active, positive encounter weights. Each species has one home biome. Stats are gameplay prototypes, not biological measurements. All 249 ordinary species can earn every rank from F through UUR through species-relative length and weight. Fihs is UUR-only; the Sock is F-only. Rank is saved on each individual catch, receipt, journal entry, and record. Species discovery still counts once; rank counts track each variant separately.

| Biome | Species | Level | Rod power | Fish / junk / treasure |
|---|---:|---:|---:|---|
| Meadow Pond | 28 | 1 | 0 | 86 / 12 / 2 |
| Whispering River | 31 | 5 | 10 | 83 / 13 / 4 |
| Hollow Marsh | 22 | 10 | 20 | 77 / 18 / 5 |
| Moonlit Lake | 22 | 18 | 35 | 84 / 11 / 5 |
| Sunken Coast | 52 | 28 | 50 | 80 / 13 / 7 |
| Glacial Reach | 45 | 40 | 65 | 83 / 12 / 5 |
| Abyssal Shelf | 51 | 55 | 85 | 78 / 14 / 8 |

Level cap: 60. Rods are earned free at the corresponding biome unlock level and claimed once on equip. Rod power controls access only; no luck, bait, or size modifiers apply yet. Level gates and power are checked on travel, equip, and every cast. The global 60-second cooldown survives all loadout changes.

## Exceptional odds

Each biome has 1,000,000 conditional fish tickets covering its species/rank combinations. There are 2,492 valid encounters: 249 ordinary species times ten ranks, plus the two restricted exceptions. First sample the biome category, species, and weighted physical size band. Draw integer length and correlated weight within that band, then classify the highest rank whose species-relative length and weight minimums both hold. Rank-specific XP and base value determine saved rewards, with the existing size bonuses also applied. Physical band weights preserve the approved probabilities without independently rolling the final rank. Every ordinary species has strictly decreasing encounter tickets from F to UUR. Each ordinary UUR entry in Abyssal Shelf has 256 tickets; Fihs has 128 and the Sock 64. Other biomes have adjusted minimum tickets so no ordinary species/rank catch is rarer than the Abyssal UUR reference. The reference tier table is historical, not an extra multiplier.

| Species | Per accepted cast | Expected casts | Median casts | 95% catch by | 99% catch by | Mean days at 100 casts/day |
|---|---:|---:|---:|---:|---:|---:|
| Void Fish (UUR) | 0.019968% | 5,008 | 3,471 | 15,002 | 23,061 | 50.1 |
| Fihs (UUR) | 0.009984% | 10,016 | 6,943 | 30,004 | 46,124 | 100.2 |
| Nidalees Lost Sock (F) | 0.004992% | 20,032 | 13,885 | 60,010 | 92,249 | 200.3 |

## Species-relative size ranks

Version 4 classifies final integer measurements. Both minimums must hold. F means below D in length or weight. A trophy minnow can outrank a small shark; absolute kilograms do not determine rank. Fihs draws only UUR-sized specimens, and the Sock only F-sized specimens. The six Tiny-to-Colossal size grades remain length descriptors; ten-rank rarity uses both measurements. Existing saved catches retain their versioned rank and reward snapshots.

| Rank | Minimum relative length | Minimum relative weight |
|---|---:|---:|
| F | Below D in either measurement | Below D in either measurement |
| D | 0.75x | 0.421875x |
| C | 0.9x | 0.729x |
| B | 1x | 1x |
| A | 1.15x | 1.520875x |
| S | 1.3x | 2.197x |
| SS | 1.45x | 3.048625x |
| SSS | 1.6x | 4.096x |
| UR | 1.75x | 5.359375x |
| UUR | 1.85x | 6.331625x |

## Waiting-time interpretation

With every ordinary species now supporting UUR, the total chance of any ordinary Abyssal UUR is 49 times its individual chance: 0.978432% per accepted cast, about one in 102 casts. A specific species at UUR still averages one in 5,008 casts. The exceptional fish odds are unchanged.

These are geometric-distribution calculations for eligible Abyssal casts, including junk and treasure. Rejected casts do not count. There is no pity timer or deadline guarantee. At 100 eligible casts/day, Fihs averages about 3.3 months and the Sock about 6.6 months; the Sock 99th percentile is about 2.5 years. Time spent leveling/unlocking Abyssal Shelf is additional. Fewer casts/day lengthen calendar waits proportionally.

The validator compares integer per-cast probability numerators across every biome: every ordinary species/rank combination is more common than Fihs, Fihs is exactly half the rarest ordinary UUR entry (all ordinary Abyssal UUR entries tie), and the Sock is exactly half Fihs. There are 249 ordinary collection entries and two bonus entries. Neither exceptional catch unlocks a biome, rod, or XP level.

## Content maintenance

Edit the reviewed biome/encounter assignments in `scripts/build-world-content.mjs`, rebuild with `node scripts/build-world-content.mjs`, then run `npm run content:validate` and `node scripts/world-balance.mjs`. Species IDs, display names, sprite keys, and existing exceptional lore survive regeneration. Rebuild bindings and publish only to a fresh local proof database to verify schema changes; do not reset a player database.
