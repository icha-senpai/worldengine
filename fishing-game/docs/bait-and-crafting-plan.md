# Bait and rod quality: implemented rules

Implemented in gameplay rules version 7, including [level 120 and XP slime](xp-progression.md). SpaceTimeDB owns purchases, charges,
quality crafting, bonus pulls and reward snapshots. This document records the
agreed starting balance; content/crafting.json supplies the live catalog.

## Progression

Keep early upgrades cheap, with Mythic and Prismatic as long-term goals.
Quality belongs to an owned rod, independently of its family: Common, Uncommon,
Rare, Epic, Legendary, Mythic, Prismatic. A River Rod can therefore be a Rare
River Rod. Quality improves bonuses; biome access requires level and the
purchased licence, without a rod-power requirement. Any owned rod can fish in
an unlocked biome. Newer rod families retain better base bonuses.
Upgrade success is guaranteed, and the upgraded quality is permanent.

The agreed starting material costs below are for each individual upgrade step,
not cumulative totals. Tune them against real-player progression after implementation.

| Upgrade to | Rusted tin | Scrap |
|---|---:|---:|
| Uncommon | 10 | 5 |
| Rare | 25 | 15 |
| Epic | 60 | 40 |
| Legendary | 150 | 100 |
| Mythic | 300 | 200 |
| Prismatic | 600 | 400 |

### Recipe pacing check

Ran 10,000 seeded resource-farming trials for each of two example loadouts:
Twig Rod in Meadow Pond and Abyssal Rod in Abyssal Shelf. Start with zero
materials, use resource bait every cast, buy 10-use packs as needed, switch
bait toward the larger outstanding material deficit, and upgrade immediately
after each step. Carry leftover materials and bait charges between upgrades.
Include ordinary junk/treasure drops and the quality-dependent mixed bonus
pull. Assume purchase coins are available; do not include level, travel or
rod/licence acquisition time. These are design simulations, not live gameplay.

| Reach quality | Twig/Pond mean casts | Abyssal/Shelf mean casts |
|---|---:|---:|
| Uncommon | 13.1 | 11.1 |
| Rare | 47.5 | 39.0 |
| Epic | 132.7 | 108.0 |
| Legendary | 343.7 | 277.8 |
| Mythic | 759.9 | 610.5 |
| Prismatic | 1579.2 | 1259.8 |

These are cumulative from Common. At one cast per minute, Mythic averages
12.7/10.2 active fishing hours and Prismatic 26.3/21.0 hours in these examples.
Total material costs from Common are 545 tin + 360 scrap for Mythic, and
1145 tin + 760 scrap for Prismatic. Average total bait spending is about
2523/1862 coins to Mythic and 5225/3800 coins to Prismatic. Starter Uncommon
uses about 70/66 coins of bait; the 100-coin daily can fund both resource
starter packs (20 + 50 coins). Natural drops and stocked materials change
individual progression times. These recipes and bait prices were agreed as
the starting balance after reviewing this pacing check.

Any material reclaim feature remains undecided. The current recommendation is
to defer reclaim and keep upgrades permanently attached to individual rods,
making a fully upgraded rod collection a long-term goal.

## Mixed bonus pulls

Power becomes a chance of one additional, independently rolled result from the
current biome: fish, junk or treasure. There are at most two results per cast;
the bonus pull cannot trigger another pull. Each fish has independent species,
measurements and rank. The cooldown remains one minute and the entire cast
consumes only one bait charge. Guaranteed resource-bait materials apply once
per cast, alongside normal materials from either result.

The accepted starting conversion is one power equals 0.5 percentage points of
bonus-pull chance: 20 power gives 10%, 100 power gives 50%. This is an agreed
balance baseline for the future implementation, not current live behavior.

## Agreed quality bonuses

These numbers are agreed as the starting balance. Each row is the total
quality bonus added to the rod-family base, not an amount stacked on all
previous quality rows. This maximum gives a Prismatic Abyssal Rod 185 power,
or a 92.5% bonus-pull chance; no quality reaches a guaranteed second result.

| Quality | Added power | Added luck | Added fishing XP |
|---|---:|---:|---:|
| Common | 0 | 0% | 0% |
| Uncommon | 5 | 2% | 5% |
| Rare | 15 | 5% | 10% |
| Epic | 30 | 10% | 20% |
| Legendary | 50 | 15% | 30% |
| Mythic | 75 | 25% | 50% |
| Prismatic | 100 | 40% | 75% |

Luck percentages describe equipment bonuses, not percentage-point additions to
a rank's probability. The combined luck calculation must be redesigned before
these bonuses can go live. Additional pulls change per-cast rare-fish chances;
their effect must be included in balance tuning while retaining the exceptional
rarity contract.

## Agreed combined luck calculation

This calculation is agreed as the starting balance. Add rod-family, quality and equipped
bait luck, rather than multiplying these three bonuses. The maximum proposed
loadout is Prismatic Abyssal with Moonlit Fireflies: 30% + 40% + 15% = 85%.
Apply the same combined luck to each pull. Power controls whether a second pull
occurs; it does not multiply the luck value.

Shift probability from lower size/rank bands toward higher bands. Give UUR,
Fihs and the Sock the full relative increase: +85% luck multiplies their
baseline per-pull probabilities by 1.85. Intermediate ranks receive a graded
adjustment instead of uniformly increasing every D-through-UUR encounter.
The Sock uses the exceptional UUR luck score while retaining its actual F rank.

For each biome, let `w_i` be a base encounter weight, `T = sum(w_i)`,
`r_i` be the F-through-UUR ordinal 0..9 (9 for the exceptional Sock),
`M = sum(w_i * r_i)`, and `D = 9*T - M`. With total luck `L` in basis points,
use the integer weight:

`w'_i = w_i * (10000*D + L*(r_i*T - M))`

The adjusted total is always `T*10000*D`. UUR and both exceptional weights
become `w_i*D*(10000+L)`, giving the exact relative boost and preserving their
ratios after normalization. Checked all seven current pools at baseline,
maximum and intermediate luck values: weights stay positive, normalization
stays fixed, and Fihs/Sock retain their global encounter ordering.

The agreed rarity contract for multiple results is exact **per pull** and
for **expected catch counts**: Fihs is half the rarest ordinary UUR encounter,
and the Sock is half Fihs. For a per-pull probability `p` and second-pull chance
`q`, the chance of at least one occurrence per cast is `(1+q)*p - q*p*p`.
Consequently the at-least-one-per-cast ratios are slightly different from 2:1;
this per-pull interpretation was explicitly agreed during the design discussion.

At maximum proposed gear, `q = 0.925`. With the current Abyssal fish-category
rate of 78% and Fihs/Sock weights 128/64 per million fish tickets, expected
waiting times are about 2,813 casts for Fihs and 5,625 for the Sock (46.9/93.8
hours casting every minute). These are geometric averages, not guarantees.

## Bait

Each purchased bait has **10 uses**, with one type equipped at a time. Use one
charge per accepted cast, regardless of fish, junk or treasure; reject cooldown
attempts and return replayed receipts without consuming an additional charge.
Bait is purchased from the Camp Trader. Resource bait provides materials in
addition to the normal catch, so players can target tin or scrap for crafting.

These eight bait types, effects and prices are the current starting balance:

| Bait | Effect | Price for 10 uses |
|---|---|---:|
| Sticky Chum | +1 rusted tin per cast | 20 coins |
| Salvage Chum | +1 scrap per cast | 50 coins |
| Plump Worms | +5% luck for larger, heavier catches | 30 coins |
| River Grubs | +10% luck for larger, heavier catches | 70 coins |
| Moonlit Fireflies | +15% luck for larger, heavier catches | 120 coins |
| Green XP Slime | +25% fishing XP | 50 coins |
| Yellow XP Slime | +50% fishing XP | 150 coins |
| Pink XP Slime | +100% fishing XP | 400 coins |

## Artwork

Six purchasable biome licences and eight bait icons are mapped in
`content/equipment-art.json`. All eight bait types are purchasable from the Trader;
the Tackle box shows only the actually equipped bait and its remaining uses.

The original transparent PNGs are in `assets/licences/` and `assets/baits/`.
Exact prompts are stored in `docs/licence-and-bait-art-prompts.json`, generated
through the built-in image tool and copied without altering pixels or alpha.
