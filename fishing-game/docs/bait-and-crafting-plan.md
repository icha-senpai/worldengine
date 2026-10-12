# Bait and rod quality: implemented rules

Implemented in gameplay rules version 11, including [level 120 and XP slime](xp-progression.md). SpaceTimeDB owns purchases, charges,
quality crafting, bonus pulls and reward snapshots. This document records the
agreed starting balance; content/crafting.json supplies the catalog to activate at deployment.

## Progression

Keep early upgrades cheap, with Mythic and Prismatic as long-term goals.
Quality belongs to an owned rod, independently of its family: Common, Uncommon,
Rare, Epic, Legendary, Mythic, Prismatic. A River Rod can therefore be a Rare
River Rod. Quality improves bonuses; biome access requires level and the
purchased licence, without a rod-power requirement. Any owned rod can fish in
an unlocked biome. Newer rod families retain better base bonuses.
Upgrade success is guaranteed, and the upgraded quality is permanent.

Material costs vary by rod. Each cell below is **tin / scrap for that step**,
not a cumulative cost. `rod_upgrade_recipe` is the authoritative public metadata;
the server quotes and rechecks its version and costs before spending.

| Rod | Uncommon | Rare | Epic | Legendary | Mythic | Prismatic |
|---|---:|---:|---:|---:|---:|---:|
| Twig Rod | 10 / 5 | 25 / 15 | 60 / 40 | 150 / 100 | 300 / 200 | 600 / 400 |
| River Rod | 1 / 1 | 1 / 1 | 1 / 1 | 1 / 1 | 2 / 2 | 9 / 10 |
| Marsh Rod | 1 / 1 | 1 / 1 | 2 / 2 | 5 / 3 | 9 / 6 | 18 / 16 |
| Moonwood Rod | 1 / 1 | 2 / 1 | 4 / 3 | 9 / 6 | 18 / 12 | 36 / 24 |
| Tide Rod | 1 / 1 | 3 / 2 | 6 / 4 | 15 / 10 | 30 / 20 | 60 / 40 |
| Glacial Rod | 2 / 1 | 4 / 3 | 10 / 7 | 24 / 16 | 48 / 32 | 96 / 64 |
| Abyssal Rod | 3 / 2 | 6 / 4 | 15 / 10 | 36 / 24 | 72 / 48 | 144 / 96 |

River and Marsh are included in the shorter-session tuning. Twig remains an
optional starter investment; buying River does not require upgrading Twig.
River onward uses smaller family recipes, with a separate Prismatic chase.
Banking a Prismatic recipe cannot fully fund the next family's Mythic upgrades
in either material. Quality bonuses and bait prices are unchanged. See
[rod progression](rod-progression.md) and the
[short-session audit](rod-short-session-audit.md) for prices and pacing.

### Historical recipe pacing check (rules 7)

These results predate the rules-8 family/quality rebalance and are retained
as historical recipe evidence, not current pacing estimates.

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
consumes one charge per active bait slot (one slot in rules 13; up to two in the rules-14 candidate). Guaranteed resource-bait materials apply once
per cast, alongside normal materials from either result.

The accepted starting conversion is one power equals 0.5 percentage points of
bonus-pull chance: 20 power gives 10%, 100 power gives 50%. This is an agreed
balance baseline implemented by the authoritative fishing rules.

## Agreed quality bonuses

Rules version 8 moves strength into rod families so each new Common rod beats
the previous Prismatic rod. Rules 9 retain those bonuses while adding family
recipes and gold-gated purchases. Each row is the total
quality bonus added to the rod-family base, not an amount stacked on all
previous quality rows. This maximum gives a Prismatic Abyssal Rod 185 power,
or a 92.5% bonus-pull chance; no quality reaches a guaranteed second result.

| Quality | Added power | Added luck | Added fishing XP |
|---|---:|---:|---:|
| Common | 0 | 0% | 0% |
| Uncommon | 2 | 0.5% | 1% |
| Rare | 4 | 1% | 2.5% |
| Epic | 7 | 2% | 4.5% |
| Legendary | 11 | 3.5% | 7% |
| Mythic | 16 | 5% | 9.5% |
| Prismatic | 22 | 7% | 13% |

Luck percentages describe equipment bonuses, not percentage-point additions to
a rank's probability. The authoritative graded-luck calculation combines family, quality, and bait
bonuses while preserving total ticket weight. Additional pulls change per-cast rare-fish chances;
their effect must be included in balance tuning while retaining the exceptional
rarity contract.

## Agreed combined luck calculation

Add rod-family, quality and equipped bait luck rather than multiplying these
three bonuses. Prismatic Abyssal with Moonlit Fireflies gives 63% + 7% + 15% =
85%. Trophy Chum gives 63% + 7% + 25% = 95% in the same complete encounter
pool. Fihs and the Sock retain their exceptional encounter weights.
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

The catalog contains thirteen bait types. Each resource reward applies once per
accepted cast, including double pulls, and consumes one use.

| Bait | Effect | Price for 10 uses |
|---|---|---:|
| Sticky Chum | +1 rusted tin per cast | 20 coins |
| Salvage Chum | +1 scrap per cast | 50 coins |
| Plump Worms | All fish; +5% luck; best of 2 size rolls | 30 coins |
| River Grubs | All fish; +10% luck; best of 3 size rolls | 70 coins |
| Moonlit Fireflies | All fish; +15% luck; best of 4 size rolls | 120 coins |
| Green XP Slime | +25% fishing XP | 50 coins |
| Yellow XP Slime | +50% fishing XP | 150 coins |
| Pink XP Slime | +100% fishing XP | 400 coins |
| Enriched Tin Chum | +2 rusted tin per cast | 40 coins |
| Concentrated Tin Chum | +3 rusted tin per cast | 60 coins |
| Enriched Salvage Chum | +2 scrap per cast | 100 coins |
| Concentrated Salvage Chum | +3 scrap per cast | 150 coins |
| Trophy Chum | All fish; best of 8 size rolls; +25% luck | 500 coins |

### Trophy Chum

The local rules-12 candidate and Trader catalog 8 price Trophy Chum at 500 coins
per ten uses (50 coins per accepted cast). It uses the entire normal encounter
pool, including small fish and Fihs. The Sock remains catchable but never earns
perfect recognition. All category, species and rank selection rules remain the
same; +25% bait luck shifts ranks normally. Eight independent size attempts
follow within the selected species and band, keeping the heaviest; length
breaks equal-weight ties. Each bonus pull gets eight attempts, while a cast
consumes one use. No extra material or XP bonus applies.

Gameplay rules 11 add an independent 1-in-5,000 endpoint chance to each UUR
size attempt. A hit produces both exact maximum catchable measurements for that
species; all other attempts sample dimensions normally. Natural perfects still
count. Other bait and no bait receive one size attempt and the same endpoint
chance. Fihs is eligible. The Sock's F-only band never receives the UUR roll.
Ranks below UUR never receive it. Rules 10 retain their legacy natural RNG
until owner activation; XP thresholds and every existing reward stay unchanged.

Rules 12 is a local tuning candidate: Trophy Chum's endpoint roll now applies
to every selected rank, F through UUR. Its eight attempts each keep the existing
1-in-5,000 chance; successful measurements are the maxima of that rank and never
promote the fish. Plump Worms, River Grubs and Moonlit Fireflies now use 2/3/4 attempts with
the same within-rank endpoint roll. Resource bait, XP slime and no bait retain
one attempt and UUR-only endpoint rolls. Official
Perfect Angler credit, collection marks and announcements still require UUR
maxima; the Sock remains excluded. The current candidate also halves the Trophy pack price to 500 coins. Trophy luck, its eight attempts and reward formulas are unchanged. The odds comparison and reproducible proof are in
[Trophy Chum rank maxima](trophy-chum-rank-maxima.md). This candidate is not deployed.

At Prismatic Abyssal power/luck, the endpoint chance across eight attempts is
about 1 in 625 UUR fish, before natural perfects. Actual per-accepted-cast
perfect odds include species/rank selection, junk/treasure and the bonus pull:
Carp about 1 in 755,569, Great Hellfin Shark 1 in 834,409 and Fihs 1 in 1,635,508.
Any perfect Pond fish is about 1 in 272 casts; any perfect Abyssal fish about
1 in 1,117. With Common Twig, a Pond perfect averages 812 accepted casts.
At 25–50 casts/day, that is 16–32 days with Common Twig or 5–11 days at maximum
gear, excluding unlocking time. There is no pity or guaranteed deadline.

The bait is a premium perfect-catch option. The 50,000-fish seeded sell-all
audit estimates net Pond coins/cast around 180 versus 243 with Fireflies at
maximum gear, and around 6 versus 82 with Common Twig. Travel, daily rewards,
other spending and unlocking time are excluded. Keeping fish lowers realized
income further. The price is optional and never gates rods or licences.
Reproduce both odds and estimated sale value with
`node scripts/trophy-chum-audit.mjs output/balance/trophy-chum-audit.json`;
add `--rod 1 --quality 0` for the starter-loadout report.

The stronger chum tiers cost the same per guaranteed material as the originals:
2 coins per tin and 5 coins per scrap. They accelerate targeted collection while
spending more gold per cast and occupying the bait slot that could hold XP slime
or luck bait. No level or additional licence gate applies. Crafting catalog 4 and
Trader catalog 6 add these tiers; gameplay rules are now 11; the XP curve remains the rules-10 curve.

## Artwork

Six purchasable biome licences and thirteen bait icons are mapped in
`content/equipment-art.json`. All thirteen bait types are purchasable from the Trader;
the Tackle box shows only the actually equipped bait and its remaining uses.

The original transparent PNGs are in `assets/licences/` and `assets/baits/`.
Exact prompts are stored in `docs/licence-and-bait-art-prompts.json`,
`docs/xp-slime-art-prompts.json`, and `docs/chum-tier-art-prompts.json`, generated
through the built-in image tool and copied without altering pixels or alpha.
Trophy Chum's matching transparent sprite and built-in generation prompt are
recorded in `docs/trophy-chum-art-prompts.json`.


Rules 14 adds unrestricted [two-slot multi-baiting](multi-bait.md) from level 1,
including same-category and identical bait. All effects add; resource bonuses
apply once per slot per cast. Existing prices, qualities and rod recipes remain.
