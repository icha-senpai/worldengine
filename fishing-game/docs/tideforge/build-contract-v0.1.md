# Tideforge Build Contract

Status: working consolidation of accepted decisions and remaining build gates.
Documentation only; no code implementation, migration, commit or deployment
is authorized by this document.

The [decision index](README.md) governs precedence. Accepted rules below reflect
Boss's November 4, 2026 clarification and earlier decisions. Proposed payouts,
recipes, unlock levels and unresolved implementation details are not silently
approved by being collected here.

## 1. Preservation And Equipment Identity

- SpaceTimeDB remains authoritative for ownership, RNG, payments and rewards.
- Each existing owned equipment definition migrates to one protected original
  instance carrying its actual quality and eligible rod reinforcement.
- An original starts without rolled affixes/signatures; it can be developed.
  Extra instances never copy another item's individual investment.
- Family mastery stays shared per owner, equipment kind and family, including
  originals and additional copies. The current clarification does not replace
  that earlier decision with a new per-copy XP grind.
- One active main loadout remains. Extra boats do not create extra activity
  slots or bypass a raid reservation. Committed raids lock equipment/preset
  changes; rod/net activities remain available with selected gear.
- Booked activities use immutable authoritative gear/effect snapshots.
  Later purchases, forging, loadout changes or catalog tuning cannot alter them.
- Preserve coins, existing items, quality, reinforcement, collections, maxima,
  perfect recognition, records and booked rewards. Use replay-safe mutations
  and isolated preservation proofs, never production resets or test grants.

These are requirements, not a completed schema or migration implementation.

## 2. Gear Levels Unlock Affix Slots

Accepted: levelling the gear unlocks its ordinary affix slots. Apply this through
the existing shared family-mastery direction, rather than restarting slot
progress on every bought or gambled copy.

The working layout is four ordinary slots. Section 9 now specifies the
1 / 10 / 25 / 50 milestones and mastery curve used for local implementation
and testing. These replace the older 10 / 25 / 50 / 75 candidate, not the
requirement for explicit live activation. Additional-copy purchase eligibility
belongs to the trader stage.

Rolled affixes beyond the currently unlocked slots must remain on the item as
visible dormant affixes. Buying high-tier gear does not automatically unlock
its slots or delete effects the family cannot yet use.

## 3. Gear Sources And Affix Shards

Accepted source split:

| Source | Payment / role | Gear result |
|---|---|---|
| Original family acquisition | Existing original-item progression and coin catalog | Protected vanilla original; earlier preservation decision remains. |
| Camp trader additional copies | Coins | Additional gear with low-tier affixes. |
| Wandering trader | Dredge Marks | Eligible additional gear with very high-tier affixes, not an overwrite of owned gear. |

The wandering trader is the high-tier Mark-gambling route. The current
acquisition and availability contract is specified in section 10.

"Low-tier" and "very high-tier" are accepted distinctions, not finalized T1-T3
or T8-T10 ranges. Exact affix counts, tier/value weights and signature chances
remain open. Additional purchases do not grant new equipment activity slots.

Affix shards come from breaking down/extracting affixes from eligible gear.
Keep the accepted two-route recovery rule:

- Dismantle the item to recover matching Vault resources for all its affixes.
- Alternatively, consume it to extract its signature into the Reliquary.

Never grant both outcomes from one item. Protected originals and favorites
retain their safeguards. No direct shard sale or new automatic shard reward
source is authorized here.

The [first Forge cost model](forge-cost-model-v0.1.md) proposes one type-specific
shard per affix and one shard to add/change an effect; those quantities remain
proposals. Shard sourcing is now settled independently of those recovery counts.

## 4. Dredge Mark Pricing And Sources

Accepted:

- Dredge Marks are earned from raids and achievements.
- One Dredge Mark has the equipment-pricing equivalence of 1,000 coins.
- A 130,000-coin Abyssal rod, boat or net costs 130 Dredge Marks each to gamble.
- Raid rewards across two full ordinary F-through-UUR ladders should provide
  those 130 Marks: 65 Marks per ladder from raids alone.

Achievement awards are additional progress, not a subtraction from this raid
budget. They may let a player afford the same gamble earlier.

This is equipment pricing, not approval of a coin-to-Mark purchase, Mark
cash-out, real-money value or a transferable currency. No exchange feature has
been decided.

Current source was checked at `304a1070` on November 4, 2026. The
[trader catalog](../../content/trader.json) lists the Abyssal Rod, listing 71,
at 130,000 coins. Existing rod/boat/net family prices are:

| Family (rod / boat / net) | Current coins per item | Gambling Marks per item |
|---|---:|---:|
| River | 500 | 1 |
| Marsh | 15,000 | 15 |
| Moonwood | 16,000 | 16 |
| Tide | 33,000 | 33 |
| Glacial | 74,000 | 74 |
| Abyssal | 130,000 | 130 |

Prices apply per item to the rod, boat and net of that family. Coin prices stay
unchanged; River is the approved 1-Mark exception to the 1,000-coin equivalence.
All current prices are whole Marks. The equivalence is a pricing rule, not a
currency-exchange feature.

### Proposed Ordinary Raid Payout Split

The total of 65 is accepted. The following distribution across grades is a
new proposal, not an approved reward table:

| Full successful ordinary clear | Proposed Marks | Running ladder total |
|---|---:|---:|
| F | 1 | 1 |
| D | 2 | 3 |
| C | 3 | 6 |
| B | 4 | 10 |
| A | 5 | 15 |
| S | 6 | 21 |
| SS | 8 | 29 |
| SSS | 10 | 39 |
| UR | 12 | 51 |
| UUR | 14 | 65 |

Checked arithmetic: 1 + 2 + 3 + 4 + 5 + 6 + 8 + 10 + 12 + 14 = 65;
two ladders yield 130, matching the Abyssal gamble's pricing equivalence.
No random material bonus, affix modifier or achievement reward is assumed.

Recommended settlement for this candidate table:

- Award each grade's Marks on a full successful ordinary clear, including
  repeats, rather than first-clear-only or holding the whole payout until UUR.
- Pay Marks alongside the committed settlement exactly once. Opening history,
  replaying a request or retrying a Discord notification cannot pay them again.
- Missed, cancelled, failed or withdrawn attempts award no Marks under this
  proposal, even where ordinary materials have separate partial-withdrawal rules.

This is a budget benchmark, not a requirement to complete a whole ladder before
spending any Marks. Repeating individual grades can fund gambling too.
Higher grades pay more per clear; this distribution is not proportional to
duration and does not promise equal Marks per occupied boat hour.

The [raid catalog](../../content/raids.json) remains unchanged. Community and
special-encounter Mark rewards, achievement reward amounts, eligible
achievement keys and historical-award/backfill handling remain open. No
retroactive currency credit or new production payout is authorized.

## 5. Other Contracts Already In Place

- Species selection stays random. Developed rods serve rank/maxima completion
  and eligible perfect hunting; boats/nets serve broad XP, coin and material
  production. The [build interactions](builds-and-interactions-v0.1.md) remain
  candidate effects, not approved numerical budgets.
- The [Forge flow](forge-and-refinement-v0.1.md) has four accepted actions:
  Add Affix, guaranteed Upgrade Tier, guaranteed visible Improve Roll, and
  directly chosen Change Affix retaining tier and relative roll finish.
- The permanent Reliquary library and same-ID deduplication are accepted. The
  Rod / Haul / Waters layout is proposed; slot count, unlocks and compatibility
  remain open.
- Ordinary affix tiers use raid materials. The exact
  [Forge costs](forge-cost-model-v0.1.md) and
  [processing/refining recipes](processing-and-material-economy-v0.1.md)
  remain proposals.
- Higher-grade material crafting/refining retains the shared destination-grade
  ordinary clear unlock. Processing does not award raid seals or completion
  statistics. The current 54-hour raid timetable is not changed here.
- Bait and affixes are designed together. Existing stats/caps are a baseline,
  not final Tideforge limits. No proposed percentages become live defaults
  without explicit authored rules and verification.

## 6. Proposed Effect Contract And Build Comparisons

**Status: authored starting proposal, not approved balance or implemented
gameplay.** This section replaces the initial effect numbers and unresolved
trigger sketches in the imported catalogue for the next comparison. It does
not replace accepted preservation, sourcing, Forge or raid-access decisions.
Mastery curves, trader distributions, prices and activation still need their
own contracts. Desktop originals remain untouched.

The current family equipment Mark prices in section 4 remain accepted; further
price contracts concern the remaining economy, not reopening that schedule.

### 6.1 What These Builds Should Do

Developed rods specialise in closing missing ranks and exact rank maxima,
then hunting ordinary UUR perfects. Species remains randomly encountered;
there is no species picker. Boats and nets specialise in broad catches,
player XP, sale income and processing materials. An early collection can
still benefit greatly from haul volume; a rod is not promised to beat every
haul for every already-common rank.

These are useful, forgiving bonuses, not required build homework. The
proposed measurement affixes each help on their own and combine more strongly.
Changing an affix keeps its tier and paid roll finish. Completed objectives
can make a conditional bonus unnecessary; they do not erase invested progress.
Show that status and allow the accepted Forge change route.

### 6.2 Ten-Tier Data And Retained Finish

All ten tier maxima are authored below, rather than left to client
interpolation. Each component has minimum `ceil(4 * maximum / 5)`.
The slot retains independent `finish_bp` in 0..10000:

```text
value = minimum + floor((maximum - minimum) * finish_bp / 10000)
```

Compound effects share that affix's finish. Preserve finish through tier
upgrades/type changes; fixed-value detours do not award free finish. Bundle
paid steps when needed for a visible legal change, as in the Forge contract.
These are proposed roll ranges, not approved natural-roll distributions.

Technical table units:

- `luckBp`: 100 = one Luck-stat point, not one percentage point of UUR odds.
- XP/sale/material/salvage/treasure `Bp`: 100 = +1% of the named output.
- Focus/finish/extra-pull/preservation `Bp`: 100 = one probability point.
- `durationBp`: 100 = 1% less normal-haul time.
- `powerCenti`: 100 = one Power; attempt `Centi`: 100 = one expected attempt.
- `endpointPpm`: 50 = +0.005 percentage points of paired-endpoint chance
  per full attempt. It is not a chance to receive another one-in-5000 roll.

| Affix | Eligible kinds | Condition | T1–T10 components |
|---|---|---|---|
| Fortune | rod / boat / net | any | `luckBp`: 250, 500, 750, 1000, 1250, 1500, 1750, 2000, 2250, 2500 |
| Scholar | rod / boat / net | any | `playerXpBp`: 200, 400, 600, 800, 1000, 1200, 1400, 1600, 1800, 2000 |
| Old Salt | rod / boat / net | any | `masteryXpBp`: 200, 400, 600, 800, 1000, 1200, 1400, 1600, 1800, 2000 |
| Coinwright | rod / boat / net | any | `saleBp`: 200, 400, 600, 800, 1000, 1200, 1400, 1600, 1800, 2000 |
| Scavenger | rod / boat / net | any | `salvageBp`: 250, 500, 750, 1000, 1250, 1500, 1750, 2000, 2250, 2500 |
| Treasurekeeper | rod / boat / net | any | `treasureBp`: 1000, 2000, 3000, 4000, 5000, 6000, 7000, 8000, 9000, 10000 |
| Provisioner | rod / boat / net | any | `filletBp`: 120, 240, 360, 480, 600, 720, 840, 960, 1080, 1200 |
| Scaleweaver | rod / boat / net | any | `scaleBp`: 120, 240, 360, 480, 600, 720, 840, 960, 1080, 1200 |
| Line Tension | rod | any | `powerCenti`: 400, 800, 1200, 1600, 2000, 2400, 2800, 3200, 3600, 4000 |
| Chum Catalyst | rod | any | `baitAttemptBp`: 1000, 2000, 3000, 4000, 5000, 6000, 7000, 8000, 9000, 10000 |
| Weight Weaver | rod | any | `weightFinishBp`: 50, 100, 150, 200, 250, 300, 350, 400, 450, 500<br>`endpointPpm`: 50, 100, 150, 200, 250, 300, 350, 400, 450, 500 |
| Trophy Lens | rod | any | `lengthFinishBp`: 50, 100, 150, 200, 250, 300, 350, 400, 450, 500<br>`endpointPpm`: 50, 100, 150, 200, 250, 300, 350, 400, 450, 500 |
| Focus Tuner | rod | any | `rankFocusBp`: 200, 400, 600, 800, 1000, 1200, 1400, 1600, 1800, 2000 |
| Bait Conservation | rod | any | `baitPreserveBp`: 150, 300, 450, 600, 750, 900, 1050, 1200, 1350, 1500 |
| Aftercast | rod | any | `extraPullBp`: 500, 1000, 1500, 2000, 2500, 3000, 3500, 4000, 4500, 5000 |
| Measured Cast | rod | any | `fullAttemptCenti`: 100, 200, 300, 400, 500, 600, 700, 800, 900, 1000 |
| Maxima Priority | rod | any | `maximaFocusBp`: 600, 1200, 1800, 2400, 3000, 3600, 4200, 4800, 5400, 6000<br>`perfectFocusBp`: 150, 300, 450, 600, 750, 900, 1050, 1200, 1350, 1500 |
| Deep Trawler | boat | any | `extraPullBp`: 1000, 2000, 3000, 4000, 5000, 6000, 7000, 8000, 9000, 10000 |
| Efficient Hull | boat | any | `durationBp`: 100, 200, 300, 400, 500, 600, 700, 800, 900, 1000 |
| Schoolfinder | boat | any | `duplicateRerollBp`: 1000, 2000, 3000, 4000, 5000, 6000, 7000, 8000, 9000, 10000 |
| Wreckbreaker | boat | any | `salvageBp`: 250, 500, 750, 1000, 1250, 1500, 1750, 2000, 2250, 2500 |
| Sunken Treasury | boat | any | `treasureBp`: 1250, 2500, 3750, 5000, 6250, 7500, 8750, 10000, 11250, 12500 |
| Full Hold | boat | any | `filletBp`: 180, 360, 540, 720, 900, 1080, 1260, 1440, 1620, 1800 |
| Surveyor | boat | any | `masteryXpBp`: 200, 400, 600, 800, 1000, 1200, 1400, 1600, 1800, 2000 |
| Haul Appraiser | boat | any | `haulAttemptCenti`: 10, 20, 30, 40, 50, 60, 70, 80, 90, 100 |
| Fine Mesh | net | any | `luckBp`: 250, 500, 750, 1000, 1250, 1500, 1750, 2000, 2250, 2500 |
| Quick Set | net | any | `durationBp`: 100, 200, 300, 400, 500, 600, 700, 800, 900, 1000 |
| Overflow Knot | net | any | `extraPullBp`: 400, 800, 1200, 1600, 2000, 2400, 2800, 3200, 3600, 4000 |
| Selective Weave | net | any | `duplicateRerollBp`: 1000, 2000, 3000, 4000, 5000, 6000, 7000, 8000, 9000, 10000 |
| Silt Sifter | net | any | `salvageBp`: 250, 500, 750, 1000, 1250, 1500, 1750, 2000, 2250, 2500 |
| Pearl Catcher | net | any | `treasureBp`: 1250, 2500, 3750, 5000, 6250, 7500, 8750, 10000, 11250, 12500 |
| Scale Collector | net | any | `scaleBp`: 180, 360, 540, 720, 900, 1080, 1260, 1440, 1620, 1800 |
| Mist Weaver | net | any | `haulAttemptCenti`: 10, 20, 30, 40, 50, 60, 70, 80, 90, 100 |
| Resonance | rod / boat / net | primary | `luckBp`: 400, 800, 1200, 1600, 2000, 2400, 2800, 3200, 3600, 4000 |
| Attuned Harvest | rod / boat / net | primary | `filletBp`: 100, 200, 300, 400, 500, 600, 700, 800, 900, 1000<br>`scaleBp`: 100, 200, 300, 400, 500, 600, 700, 800, 900, 1000 |
| Attuned Wisdom | rod / boat / net | primary | `masteryXpBp`: 300, 600, 900, 1200, 1500, 1800, 2100, 2400, 2700, 3000 |
| Deepwater Cache | rod / boat / net | primary | `treasureBp`: 1500, 3000, 4500, 6000, 7500, 9000, 10500, 12000, 13500, 15000 |
| Secondary Affinity | rod / boat / net | secondary | `luckBp`: 400, 800, 1200, 1600, 2000, 2400, 2800, 3200, 3600, 4000 |
| Attuned Focus | rod | primary | `rankFocusBp`: 150, 300, 450, 600, 750, 900, 1050, 1200, 1350, 1500 |

At most four unlocked ordinary slots, no duplicate IDs, and at most two biome
affixes. Dormant slots contribute nothing but remain visible and recoverable.
Primary-only effects activate only in the innate biome. Secondary Affinity
works only in its chosen different biome and never activates primary effects.
If reattunement makes both biomes match, preserve but deactivate the secondary
effect until its target changes.

### 6.3 One Rank Replacement, With Goal Steering

Reuse existing Focus unlock/enabled eligibility. Rank assistance requires a
previously naturally discovered ordinary species and runs after random species
selection. Fihs and Nidalees Lost Sock do not enter these helpers.

Let `U` be missing legal ranks and `X` ranks missing a permanent, same-specimen
maximum. A maximum implies its rank has been collected. Missing-rank assistance
uses existing Luck-adjusted rank weights within `U`.

Base/reinforcement Focus is one value, not two added values. Add Focus Tuner,
matching Attuned Focus and a deduplicated Collector's Whisper to that value.
The strongest proposed combination is 50 + 20 + 15 + 10 = **95%**.

Maxima Priority chooses uniformly among unfinished legal ranks, avoiding
Luck-starved lower-rank cleanup. Its maximum gate is 60% for F..UR and **15%
for UUR**, in either goal. For a mixed unfinished set, choose the target first
and use that rank's gate. A failed gate retains the original candidate for
the other eligible assistance check; it does not redraw a target.

| Goal | Priority and fallback |
|---|---|
| Ranks | Check missing-rank assistance first. If it fails, Maxima Priority may replace an already-maxed initial rank with an unfinished one. An initially missing rank is already useful and stays protected. |
| Maxima | Check Maxima Priority on an already-maxed initial rank first. If unsuccessful, missing-rank assistance may still work; missing ranks are also unfinished maxima. |
| Perfects | Preserve an initial UUR. Otherwise Maxima Priority supplies its 15% UUR gate only while that ordinary species' perfect is missing. If unsuccessful, missing-rank assistance may still work. |

There is **at most one final rank replacement**, not a chain of rerolled
results. Independent gates are evaluated against the original rank; the first
successful gate wins. No remaining target means a neutral fallback, not an
invalid pool or fabricated completion.

Choosing Perfects grants no UUR gate without the affix and never guarantees
UUR or a perfect. It needs no completed lower-rank maxima. The reduced UUR gate
also applies when UUR is the last Maxima target, so Perfects is not a trap
compared with selecting Maxima. Unrelated XP, economy and measurement affixes
keep working in every goal.

Goal changes steer future rod actions only. They do not equip different gear or rewrite an already-booked activity's effects; the technical contract must define their request/snapshot boundary.

### 6.4 Specimens, Measurement Bait And Exact Maxima

No bait-activation mechanic is introduced. Keep stocked bait's current Luck
and measurement effects. Two Trophy Chums supply 16 full specimen attempts.
Chum Catalyst adds up to another 100% of the bait-provided attempts;
Baitkeeper's Promise adds 50%; Measured Cast supplies up to 10 more.
Their credits add once, without recursive amplification.

Pool fractional attempt credits per fish: grant the integer part and one
extra attempt with the remaining fractional probability. This prevents
rounding away low-tier or unfinished rolls. For example, 1.6 attempts means
one guaranteed attempt and a 60% chance of another.

Each ordinary full attempt is one complete legal specimen:

1. Make its paired-endpoint check: the existing one-in-5000 chance where
   enabled, plus the additive endpoint parts of Weight Weaver/Trophy Lens.
2. If that fails, Trophy Lens may select the rank's maximum legal length;
   otherwise sample the normal legal length.
3. At that length, Weight Weaver may select the legal maximum weight;
   otherwise sample the normal legal weight window.
4. Retain the heaviest complete specimen, breaking weight ties by length.

Never combine a length from one specimen with a weight from another. Existing
rank boundaries and exact endpoints remain unchanged. Each measurement affix
also has its own small paired-endpoint addition, so it is not useful only when
paired with the other affix.

Existing eligible reinforcement adds its unchanged UUR endpoint-only trials.
Trophy's Oath then has **one separate 3% paired-maximum finish gate per eligible
ordinary UUR specimen**, not per attempt. It requires all ranks collected and
existing Focus eligibility/enabled state, not all lower maxima. This is an
explicitly stronger replacement for the initial weak signature proposal;
do not reinterpret its old trigger as if it already meant a direct 3% finish.

New precision additions exclude both exceptional species. Their existing
bait/measurement rules remain. Additional mixed pulls can encounter them
naturally; there is no exceptional-species targeting or guaranteed discovery.

Haul Appraiser/Mist Weaver affect only the first ordinary fish of their haul;
Moonthread can add one further complete attempt to the first ordinary net
fish. They cannot copy rod bait, finishers or rank steering across every pull.

### 6.5 Pulls, Outputs And Non-Clipping Budgets

Proposed rod Power continuation:

```text
bonus pulls = floor(Power / 200) + Bernoulli((Power mod 200) / 200)
```

This preserves existing <=200-Power behaviour, while avoiding wasted Power
above 200. With current gear and Line Tension, maximum Power is 225.
Aftercast, Deep Trawler, Overflow Knot and signature pull gates each execute
once per accepted activity; generated pulls never trigger pull generation.
Normal extra pulls still roll fish/junk/treasure. Rift Current is the explicitly
ordinary-only daily exception, not a category change to normal pulls.

| Group | Proposed reachable bound, not a silent clipping rule |
|---|---|
| Mixed results | Rod 5, boat 12, net 6; daily Rift can add one ordinary fish, giving total maxima 6 / 13 / 7. |
| Missing-rank Focus | 95% in primary biome; reinforcement replaces the base 20%, it is not added to it. |
| Full ordinary rod attempts | 50 with two Trophy Chums, Chum Catalyst, Measured Cast and Baitkeeper; plus existing eligible reinforcement endpoint trials and one Oath gate. |
| Normal-haul time reduction | 15% additive; no raid timer reduction. |
| Ordinary processing | Fillets +40%; scales +60%, additive within each resource. |
| Junk salvage | +70%; no change to junk probability. Absolute first-junk packets are added afterwards. |
| Player XP | +20% regular relative group; the qualifying Ocean's Memory catch can reach +120%, not the whole haul. |
| Family mastery | +70% regular; up to +150% on the appropriate first daily rod activity with both applicable daily powers. |
| Fish sale | +20%, pinned at acquisition. |
| Treasure coins | +375% additive; at most the first treasure can receive its one nonrecursive doubling gate. |
| Bait preservation | 25% per stocked slot at maximum; resolve once per accepted cast, not per fish. |

These are derived bounds of the proposed compatible source set. Never charge
for a bonus and silently discard it at an older cap. New sources require a
fresh composition audit. Luck uses the separately implemented uncapped curve,
not another validation ceiling; outside-primary maximums including Compass
are rod 19,500 / boat 34,100 / net 24,000 basis points.
Implement values in fixed-point integers with checked wide intermediates;
malformed future catalog compositions fail activation, not by clipping a
player's paid effects during an action.

XP's Tideforge group applies after the existing legal gear/bait multiplier.
Do not feed its relative bonus back through the old raw-stat XP validator.
Mastery credit is per accepted activity, not multiplied into ten activities by
a ten-pull boat; the base credit/curve remains a separate proposal gate.

Pin sale/processing modifiers when caught. Pool fractional resource credit by
owner and resource rather than floor every small bonus away. Junk's proposed
base spread is uniform 1..3 tin; ordinary treasure scrap is unchanged. Separate
the spread's own buff from affix gains. Selling and processing remain
alternative uses of one fish; explicit signature cache packets are separately
earned bonuses, not a second processing payout.

### 6.6 Signatures, Compatibility And Daily Ledgers

Proposed library layout: one Rod slot, one compatible Haul slot and one Waters
slot. Evaluate only the kind slot matching the action plus Waters.
The performing item may carry one compatible kind **or Waters** native power.
Thus at most three distinct powers apply: two kind/one Waters or one kind/two
Waters. Native Waters powers remain extractable sources for that library.
Deduplicate by signature ID; merely owning another item grants nothing.

The Haul library slot is shared by boat and net activity; it does not grant a separate selection to each. Changing active Reliquary selections counts as a loadout change and cannot bypass the accepted raid-commitment locks.

| Signature | Kind | Proposed rule |
|---|---|---|
| Echoed Line | rod | One nonrecursive independent mixed pull at 50% per accepted rod action. |
| Trophy's Oath | rod | One separate 3% paired-maximum finish gate per ordinary UUR specimen, only with all ranks collected and Focus eligible/enabled; never per attempt. |
| Baitkeeper's Promise | rod | Add 50% of stocked measurement-bait attempts; add 10pp per-slot bait preservation. Bait effects never need activation. |
| Collector's Whisper | rod | Add 10pp missing-rank assistance, with existing eligibility; never selects a species. |
| Salvage Sermon | rod | First junk in an action adds one scrap after percentage modifiers; once per action. |
| Tidal Memory | rod | First ordinary-fish rod action per biome/UTC day adds 50% of that activity base family-mastery credit. |
| Ghost Trawler | boat | One nonrecursive mixed pull at 50% per normal haul. |
| Cartographer's Wake | boat | Reroll one already-discovered ordinary-species catch per haul. If Schoolfinder also activates, use a second distinct eligible catch; never reroll a replacement. Retain only final outcomes. |
| Salvage Fleet | boat | Add 20% junk salvage; not treasure scrap or cache materials. |
| Tempest Rudder | boat | Reduce normal-haul time by 5%, never raid time. |
| Gilded Bilge | boat | 25% chance to double only the first treasure coin payout, after the additive treasure group; no recursion. |
| Fisherman's Folly | boat | First junk in a normal haul awards six normal fillets and four normal scales; no all-junk requirement, no percentage amplification. |
| Nets of Plenty | net | One nonrecursive mixed pull at 20% per normal haul. |
| Moonthread | net | First ordinary fish in a net haul gets one extra full paired attempt; no bait, rank steering, finisher or all-fish amplification. |
| Swiftwater Knot | net | Reduce normal-net time by 5%, never raids. |
| Whispering Mesh | net | Same distinct-catch duplicate-species quota as Cartographer; Selective Weave owns its separate one-catch quota. Neither source rerolls the same fish twice. |
| Scale Pact | net | Add 20% ordinary scales, pinned at catch acquisition. |
| Pearlbound | net | 50% chance to double the first treasure coin payout only; no recursion. |
| Rusted Crown | waters | First junk adds one tin after percentage modifiers; once per activity, not per pull. |
| Atlas of Seven Waters | waters | First activity containing ordinary fish per biome/UTC day adds 50% of base activity mastery; one shared cross-kind ledger. |
| Wayfarer's Compass | waters | Add 10 Luck-stat points outside primary biome only; does not activate primary affixes. |
| Forgotten Cache | waters | First ordinary fish per activity gives six normal fillets and four normal scales once, separate from eventual processing; no yield multiplier on this packet. |
| Ocean's Memory | waters | First newly collected ordinary species-rank per biome/UTC day adds 100% of that catch pre-Tideforge player XP, additive with Scholar; not the whole haul. |
| Rift Current | waters | First activity containing ordinary fish per biome/UTC day adds one ordinary-only legal fish, no special species or recursive bonus generation. |

Ordinary duplicate-species affixes and their compatible signatures each own
one quota on a **distinct** eligible catch. At most two catches are replaced
when both sources apply; neither rerolls its own or the other's replacement.
Use the legal ordinary biome pool; a result can still be a duplicate. Discarded
candidates grant no XP, coins, discovery or materials.

Daily bonuses use bounded replay-safe per-owner/biome/UTC-day ledgers. Atlas,
Ocean's Memory and Rift share their individual power's counter across activity
kinds; Tidal Memory is rod-only. They do not each get another daily token by
switching equipment. Evaluate daily eligibility atomically when results commit,
not when a haul is booked. No failed/early completion consumes a token.
Use the activity's immutable booked gear/effect snapshot; ordinary completion
state can advance during play without rewriting that snapshot.

### 6.7 Complete Builds And Measured Results

These are illustrative builds, not optimized or mandatory recipes.
All four slots are assumed unlocked; Prismatic quality, R10 rod reinforcement,
two Trophy Chums for rods, and primary affinity matching the tested water.
Each water is a separately fitted case, not one item's primary biome moving
freely between waters. Existing-gear controls have no Tideforge affixes,
signatures or innate bonus. Lower families retain their own lower base stats.

These builds are independent configurations, not simultaneous powers in one active loadout. The proposed innate bonus included here is +100 Luck bp in the primary biome. No additional free innate/mastery stat boost is included; the older +5% innate mastery suggestion remains unsettled and must not silently increase the stated mastery budgets.

| Build | Kind | Ordinary affixes | Active signatures | Goal |
|---|---|---|---|---|
| Existing Abyssal rod | rod | none | none | ranks |
| Existing Abyssal boat | boat | none | none | ranks |
| Existing Abyssal net | net | none | none | ranks |
| Rank Closer | rod | Focus Tuner, Attuned Focus, Line Tension, Aftercast | Collector's Whisper, Echoed Line, Forgotten Cache | ranks |
| Maxima Archivist | rod | Maxima Priority, Trophy Lens, Weight Weaver, Chum Catalyst | Trophy's Oath, Baitkeeper's Promise, Forgotten Cache | maxima |
| Perfect Hunter | rod | Maxima Priority, Trophy Lens, Weight Weaver, Chum Catalyst | Trophy's Oath, Baitkeeper's Promise, Forgotten Cache | perfects |
| Balanced Explorer | rod | Fortune, Scholar, Measured Cast, Aftercast | Echoed Line, Baitkeeper's Promise, Forgotten Cache | ranks |
| XP Trawler | boat | Scholar, Deep Trawler, Efficient Hull, Fortune | Ghost Trawler, Tempest Rudder, Ocean's Memory | ranks |
| Coin Runner | boat | Coinwright, Treasurekeeper, Sunken Treasury, Deep Trawler | Ghost Trawler, Gilded Bilge, Forgotten Cache | ranks |
| Treasure Runner | boat | Treasurekeeper, Sunken Treasury, Deepwater Cache, Deep Trawler | Ghost Trawler, Gilded Bilge, Forgotten Cache | ranks |
| Fillet Hauler | boat | Full Hold, Provisioner, Attuned Harvest, Deep Trawler | Ghost Trawler, Tempest Rudder, Forgotten Cache | ranks |
| Scale Weaver | net | Scale Collector, Scaleweaver, Attuned Harvest, Overflow Knot | Scale Pact, Nets of Plenty, Forgotten Cache | ranks |
| Salvage Fleet | boat | Scavenger, Wreckbreaker, Deep Trawler, Efficient Hull | Salvage Fleet, Tempest Rudder, Rusted Crown | ranks |
| Quick-Cycle Netter | net | Quick Set, Overflow Knot, Scholar, Fine Mesh | Nets of Plenty, Swiftwater Knot, Forgotten Cache | ranks |
| Developed River Maxima | rod | Maxima Priority, Trophy Lens, Weight Weaver, Chum Catalyst | Trophy's Oath, Baitkeeper's Promise, Forgotten Cache | maxima |
| Precision-check Boat | boat | Fortune, Resonance, Haul Appraiser, Deep Trawler | Ghost Trawler, Cartographer's Wake, Forgotten Cache | ranks |
| Precision-check Net | net | Fortune, Fine Mesh, Resonance, Mist Weaver | Moonthread, Nets of Plenty, Forgotten Cache | ranks |

Each comparison uses the actual public biome ticket pool and new Luck helper.
Species remains random. A rank-cleanup state has one UUR rank missing per
ordinary species; a rank-maximum cleanup state has all ranks collected and
only the named maximum missing. The independent perfect-chase state has all
ranks collected but **no lower maxima completed**.

Columns from those different states must not be added together. Counts mean
expected distinct newly completed targets per action, removing duplicate
credit within the action. The separate perfect probability is the chance of
at least one new ordinary perfect, not a chance for a selected fish.

| Build | Mixed pulls/action | Ordinary fish/action | New missing-UUR ranks/action | New F-maxima/action | Last-UUR maxima/action | At least 1 new perfect/action |
|---|---:|---:|---:|---:|---:|---:|
| Existing Abyssal rod | 1.925 | 1.501 | 0.76791 | 0.02532 | 0.00191 | 0.191% |
| Existing Abyssal boat | 10.000 | 7.795 | 0.35693 | 0.00374 | 0.00119 | 0.119% |
| Existing Abyssal net | 4.000 | 3.119 | 0.09386 | 0.00374 | 0.00031 | 0.031% |
| Rank Closer | 3.125 | 2.436 | 2.27472 | 0.04089 | 0.00312 | 0.312% |
| Maxima Archivist | 1.925 | 1.501 | 0.87646 | 0.33637 | 0.08580 | 1.286% |
| Perfect Hunter | 1.925 | 1.501 | 0.87646 | 0.10392 | 0.08580 | 8.401% |
| Balanced Explorer | 2.925 | 2.280 | 1.16455 | 0.05720 | 0.00562 | 0.561% |
| XP Trawler | 11.500 | 8.963 | 0.44522 | 0.00428 | 0.00149 | 0.149% |
| Coin Runner | 11.500 | 8.964 | 0.41166 | 0.00430 | 0.00138 | 0.138% |
| Treasure Runner | 11.500 | 8.964 | 0.41166 | 0.00430 | 0.00138 | 0.138% |
| Fillet Hauler | 11.500 | 8.964 | 0.41166 | 0.00430 | 0.00138 | 0.138% |
| Scale Weaver | 4.600 | 3.586 | 0.10837 | 0.00428 | 0.00036 | 0.036% |
| Salvage Fleet | 11.000 | 8.574 | 0.39383 | 0.00412 | 0.00132 | 0.132% |
| Quick-Cycle Netter | 4.600 | 3.586 | 0.11960 | 0.00377 | 0.00040 | 0.040% |
| Developed River Maxima | 1.250 | 0.975 | 0.56806 | 0.22553 | 0.05414 | 0.638% |
| Precision-check Boat | 11.500 | 8.962 | 0.49273 | 0.00474 | 0.00183 | 0.183% |
| Precision-check Net | 4.200 | 3.274 | 0.13588 | 0.00357 | 0.00072 | 0.072% |

The stronger precision-haul comparators use Luck and first-fish measurement
investment too. Under these controlled states, each of the 249 ordinary
species has a higher per-action missing-UUR chance with Rank Closer and a
higher perfect chance with Perfect Hunter than any compared haul build.
The same comparisons also pass with all rod affixes at their T10 minimum
rolls against maximum-roll haul builds. This is not an exhaustive proof that
these are the best possible loadouts or a claim of universal dominance for
already-common rank collection.

| Water | Rank Closer / precision boat | Perfect Hunter / best compared haul | F-maxima / best compared haul |
|---|---:|---:|---:|
| Meadow Pond | 8.71x | 14.88x | 15.34x |
| Whispering River | 7.63x | 120.98x | 71.91x |
| Hollow Marsh | 9.80x | 148.05x | 31.68x |
| Moonlit Lake | 10.76x | 221.39x | 75.75x |
| Sunken Coast | 4.45x | 14.61x | 32.12x |
| Glacial Reach | 5.32x | 48.71x | 57.32x |
| Abyssal Shelf | 4.62x | 46.86x | 70.93x |

Across 249 model species, the per-species Rank Closer / precision-boat
missing-UUR ratio has minimum 1.88x, median 5.80x and maximum 28.11x;
the Perfect Hunter / best compared haul perfect-chance ratio has minimum
1.63x, median 373.34x and maximum 1419.06x. These are model-species medians,
not production-player medians.

| Build | Seconds | Normal fillets if processed | Normal scales if processed | Junk tin under proposed spread | Treasure base payout units |
|---|---:|---:|---:|---:|---:|
| Existing Abyssal boat | 600.0 | 137.0 | 91.3 | 2.80 | 0.800 |
| Existing Abyssal net | 240.0 | 46.6 | 31.0 | 1.12 | 0.320 |
| XP Trawler | 510.0 | 158.1 | 105.4 | 3.22 | 0.920 |
| Coin Runner | 600.0 | 163.6 | 109.1 | 3.22 | 3.491 |
| Treasure Runner | 600.0 | 163.6 | 109.1 | 3.22 | 5.102 |
| Fillet Hauler | 570.0 | 226.6 | 119.6 | 3.22 | 0.920 |
| Scale Weaver | 240.0 | 65.0 | 61.2 | 1.29 | 0.368 |
| Salvage Fleet | 510.0 | 150.7 | 100.5 | 6.05 | 0.880 |
| Quick-Cycle Netter | 204.0 | 61.5 | 41.0 | 1.29 | 0.368 |

One treasure base payout unit is the mean unmodified treasure payout for that
water; these values include first-treasure doubling and are not coin figures.
The current-gear controls use proposed processing and junk rules, not existing
filleting income.

Material columns are counterfactuals under the proposed processing recipe,
not production filleting income. Cache packets are included only when that
complete build carries Forgotten Cache. A processed fish supplies no sale
coins. Timer gains matter only when another trip actually fits; they do not
multiply every casual haul's payout.

To check economy crossover without guessing natural best-of-many size
averages, bound every selected rank between its legal minimum and maximum
length. In every tested water, the Coin Runner's fish-sale lower bound exceeds
Perfect Hunter's upper bound, and the XP Trawler's repeat-fish XP lower bound
exceeds Perfect Hunter's upper bound. Discovery XP, daily powers and bait
costs are excluded; these are gross controlled reward bounds, not wallet
surplus or net-income predictions.

| Water | Rod sale upper | Coin boat sale lower | Rod repeat XP upper | XP boat repeat XP lower |
|---|---:|---:|---:|---:|
| Meadow Pond | 915.53 | 3109.24 | 472.68 | 4677.16 |
| Whispering River | 925.44 | 3210.58 | 462.76 | 4732.52 |
| Hollow Marsh | 831.64 | 2692.75 | 418.01 | 4014.14 |
| Moonlit Lake | 797.62 | 2471.21 | 452.59 | 4265.12 |
| Sunken Coast | 948.42 | 4104.22 | 482.93 | 5787.64 |
| Glacial Reach | 842.98 | 3381.34 | 486.14 | 5509.76 |
| Abyssal Shelf | 982.09 | 4189.66 | 468.28 | 5559.48 |

### 6.8 Evidence And Acceptance Limits

The reproducible analysis is `scripts/tideforge-effect-proof.ts`; it is not
imported into gameplay. Its bounded task output is
`.local/tideforge-effect-results.json`, with catalog and source hashes.
The comparison review reads that capture rather than sampling production.

It checks the 39 ten-tier grids, 24 signature contracts, 119 maximum-roll
build/water cases and 14 minimum-roll stress cases. It also checks baseline
odds against the existing compendium, rank/species mass conservation, pull
count generating functions, first-ordinary-fish measurement arithmetic,
exception exclusions, compatibility and the non-clipping source bounds.
Signature calendar/receipt behaviour is an authored future contract, not an
already implemented reducer proof.

Future runtime integration must preserve an unchanged Tideforge-disabled/zero-effect legacy path, including existing RNG consumption, plus immutable saved receipts and already-booked rewards. This analysis does not verify reducer mutations, feature activation, calendar ledgers or instance migration.

The earlier production activity median remains 23 actions/day from five
active accounts in its frozen seven-day window. It supports the rough
24-total-action reference; it is not Tideforge outcome data. Do not multiply
these fresh-state closure rates into completion-day promises: targets vanish
as collected, species remain random, and a raid can occupy the boat.
More play may progress faster; no grind extension is proposed.

The next implementation gate is still additive instances/preservation,
loadout locks and snapshots. These numbers need review and then isolated
runtime integration/playtesting, not a production switch.

## 7. Build Gates And Progress

1. **Mastery/access contract:** local slot milestones, activity XP and dormant
   effects are implemented in section 9. Additional-copy purchase eligibility
   belongs to the acquisition stage.
2. **Trader/reward contract:** affix ranges/counts, signature occurrence, stock
   versus rolling, daily visit windows, eligible families, new-copy starting quality
   and reinforcement, per-grade/achievement Mark awards.
3. **Effect contract:** candidate semantics/grids now have a locally verified
   effect engine and isolated runtime integration. Final balance, acquisition
   distributions and advanced website odds remain separate acceptance gates.
4. **Economy contract:** reviewed coin/Mark/shard/material costs, roll-finish
   conversions and legal visible improvements, recovery, junk spread and
   material-reward recipes. Fractional effect credit is already implemented.
5. **Technical contract:** additive instances, legacy quotes, immutable activity
   snapshots, current locks, privacy and replay have local preservation proofs.
   New Forge/acquisition/processing operations must extend those protections;
   production activation still requires separate approval.

Implementation remains staged across the whole connected feature, not a
reduced slice: instances/preservation, effects/progression, Forge/materials/
traders/libraries, then website integration and end-to-end proofs. The current
document consolidates decisions; unresolved entries are not permission to guess.

## 8. Instance And Preservation Foundation

This stage adds the instance and preservation foundation only. It does not
activate affixes, signatures, innate stat bonuses, mastery awards or curves,
additional-copy acquisition, Forge, Marks or processing. The effect numbers
in section 6 remain proposals, not approved balance.

Activation is an explicit deployment-owner action, not part of module publish
or initialization. Before activation, ordinary actions use the unchanged legacy
path and create no instance metadata. Activation copies existing owned rods,
boats and nets into protected originals, preserves their quality and eligible rod
reinforcement, maps all equipped definitions, and creates one zero-XP,
zero-curve family-progress row per owner, kind and family. It validates the
whole migration transaction before its activation marker is committed; repeats
are no-ops. Existing player, inventory, collection and booked-reward rows remain
authoritative and unchanged.

Original instance IDs are `(player_id << 64) | (kind_tag << 32) | definition_id`,
with rod/boat/net tags 1/2/3. Family keys use the same layout with family ID.
Bit 63 of the lower half is reserved for future non-original instances; copies
share family progress without inheriting another instance's quality or
reinforcement. Original primary biome initially matches the family's native
biome as metadata only. No innate Luck or mastery bonus is active.

After activation, selected instance identity and its own quality and
reinforcement drive rod, haul and raid gear selection. Definition-based legacy
equip, upgrade and reinforcement actions explicitly address protected
originals; old pending quotes retain their existing nonce, price and expiry.
Successful original progress changes mirror atomically into that original with
a checked revision increment. They never overwrite a selected same-family copy.
One active loadout remains; committed deployed/running raids block actual gear
changes across all three slots, but not same-instance no-ops or biome-only
changes. Prepared raids do not add a new lock, and existing ordinary haul and
Forge behavior is not restricted by this stage.

New cast, haul and raid activity metadata pins the selected instance, revision,
identity, available effective stats and versions without consuming RNG. Existing
activity and receipt payloads remain the reward authority, including replays
and already-booked activities. Migration backfills only running hauls and
prepared/deployed/running raids using their captured quality and stored stats;
legacy revision and primary biome remain unknown. Raid Luck and XP are not
invented. Legacy hauls did not store a content version, so their metadata uses
`None` and settlement retains the prior current-version fallback. New hauls
record their booking version and use it at settlement. This does not create a
historical catalog store.

Instance, loadout, family and snapshot tables are private and exposed only
through owner-scoped and authorized selected-adapter views. The public
activation flag contains no player identity. Snapshot deletion follows the
corresponding existing cast receipt, delivered haul or terminal raid retention;
active and booked activities are not prematurely pruned. Isolated preservation
proof and review remain required before any deployment.

## 9. Local Effects And Mastery Implementation Defaults

Status: Stage 2 implemented and verified locally, separately authorized by Boss.
These are local candidate defaults, not production activation or final balance.
`content/tideforge-effects.json` records the section 6 grids and signature
parameters without importing the analysis script into gameplay.

### Mastery Without A New Chore List

Mastery remains shared by owner, equipment kind and family. Copies share the
track, not quality, reinforcement, affixes or signatures. The candidate level
range is 1-100, with ordinary slots opening at **1 / 10 / 25 / 50**. One slot is
available immediately; locked affixes remain intact and visible.

Each accepted rod action, settled normal haul or full successful raid gives
100 base mastery XP to its booked gear family. A ten-pull boat is one activity,
not ten. Junk/treasure fishing results still count as using the equipment.
Preparing, deploying, cancelling, withdrawing, missing a deadline, early finish
and replay do not create additional mastery awards. Successful raids credit
their booked boat; this does not alter raid timers or ordinary raid rewards.

At level `L`, the next level costs `100 + 20 * (L - 1)` XP. With `n = L - 1`,
the cumulative threshold is `100 * n + 10 * n * (n - 1)`.

| Level | Cumulative XP | Unlocked slots |
|---|---:|---:|
| 1 | 0 | 1 |
| 10 | 1,620 | 2 |
| 25 | 7,920 | 3 |
| 50 | 28,420 | 4 |
| 100 | 106,920 | 4 |

At the unmodified 100 XP rate, slots two through four take 17, 80 and 285
activities respectively on that family/kind track. These are action counts,
not calendar promises: mixed play splits progress between tracks, and eligible
mastery powers add credit. Lifetime XP continues past level 100.

Foundation curve-0 rows move to curve 1 without resetting or inventing XP.
There is no historical-use backfill and no mastery Power/Luck stat grant.
Normal mastery bonuses and eligible Atlas/Tidal daily bonuses apply to the
same base activity credit, not recursively to one another. Fractional credit
carries forward on the relevant owner/family resource.

### Activation And Booking Boundary

Effects use a separate, explicit owner-only activation after the foundation.
Module publication and initialization do not activate them. Existing booked
activities do not gain effects or retroactive mastery. New activities pin the
resolved modifiers, native/library powers, goal, family progress and versions.
Settlement must not recompute those values from current equipment or catalogs.

The tested primary innate is **100 Luck basis points: one Luck-stat point**,
not +100% Luck. A primary-matching vanilla item therefore has a small nonzero
effect once this candidate is explicitly activated. Disabled and genuinely
zero-effect paths must retain legacy outcomes and RNG consumption.

Goals affect future rod actions only. Changing a goal does not swap equipment,
rewrite a booked activity or alter a raid. Actual Reliquary selection changes
respect committed-raid equipment locks; the Haul selection remains shared.
Owning an item is not sufficient to activate its unequipped native signature.

Daily powers use completion-time UTC days and shared owner/biome/power counters.
Changing equipment or activity kind does not refresh them. Failed completion
and replay do not consume another token or grant another reward.

This stage implements effect evaluation and catch-pinned sale/processing
modifiers, not the Forge, acquisition distributions, Dredge Mark payouts,
processing actions or proposed 1-3 tin base spread. Existing base junk remains
one tin; percentage salvage and explicit signature packets are separate.

### Verification And Remaining Work

All 69 module tests, 66 shared-rule tests, 982 composition-parity cases,
generated contracts, Rust workspace checks and web checks/build pass. The
isolated actual-WASM proof preserves 87 retained tables, promotes six family
curves without XP backfill, and settles an old and a new net naturally. Only the
new booking receives mastery. See [verification evidence](../verification.md#tideforge-effects-and-mastery-local)
for fixture limits and the bounded retry after a confirmation timeout.

This does not activate development or production gameplay. Forge, processing,
traders, dismantling/extraction, Dredge Marks/gambling, the Reliquary and website
controls remain the next connected implementation stages.

## 10. Stage 3 Local Economy Implementation Contract

Status: local implementation defaults following Boss's Stage 3 instruction.
These settle the backend test contract, not production balance approval.
`content/tideforge-economy.json` is the versioned numerical authority. Economy
activation is separate, owner-only and requires compatible foundation/effects
activation. An absent economy state preserves all legacy behavior.

### Acquisition And Permanent Records

- Choose equipment kind and an already-owned original definition, not affixes.
  Starter copies are not sold. Family prices apply equally to rod/boat/net.
- Camp copies use coins and roll one affix at T1/T2/T3 with weights 70/25/5.
  Wandering gambling uses Marks and rolls four at T8/T9/T10 with weights 70/25/5.
- The wandering trader arrives at 00:00, 06:00, 12:00 and 18:00 UTC every day,
  with each visit lasting two hours including its start and excluding its end.
  The website displays visit times in the player's local timezone. No purchase
  quota or stock-reroll fee is added. Quotes expire at the earlier of 120 seconds
  and the trader closing. Kind/family selection remains explicit.
- Roll compatible distinct affix IDs uniformly from the remaining legal IDs at
  each draw, retaining the existing maximum of two biome-conditional affixes
  including dormant ones. Acquisition requires the player's explicit
  `secondary_biome_id`: a defined water other than the item's primary water,
  used if Secondary Affinity rolls. It is previewed before purchase, otherwise
  unused, and grants no licence. Secondary water is not another random reroll.
  Finish is uniform over integer 0..10000 inclusive. Signature chances are 5% Camp
  and 25% wandering; choose uniformly from existing compatible native powers,
  including Waters where the existing validator allows it.
- New copies are Common quality 0, reinforcement 0, not favorites or originals.
  Their IDs use the owner's copy namespace and a monotonic durable sequence.
  They share existing family mastery, never another item's quality/upgrades.
  Keep all four wandering affixes even if some slots are dormant.
- Roll properties only on accepted purchase confirmation; previews show the
  source ranges, price and possible outcomes, not rerollable hidden results.
- Natural acquisition records each affix type's highest found tier and best
  finish at that tier in the permanent Codex. Forging never fabricates natural
  records. Discovery is type-wide per owner; it is not a natural-tier upgrade
  cap. Type shards live in the private Vault and are not tier-split currencies.

### Forge, Recovery And Item Safety

- Use the authored candidate tier-material table and one type shard for Add
  or Change. No extra coin fee for Add/Upgrade/Change. New additions start at
  finish 0. Upgrade can target a higher tier by summing every intervening row.
- Add/Change require that compatible type's Codex discovery and its shard.
  Add/Upgrade require each destination grade's shared ordinary clear. Changing
  an already-owned tier does not grant or require new material-grade access.
- Add/Change take optional `secondary_biome_id`, required for Secondary Affinity
  and rejected for other types. It must name a defined non-primary water, not
  grant access to it. Changing Secondary Affinity to a different water is a
  valid one-shard change preserving tier/finish; an identical target rejects.
  Enforce the existing two-conditional-affix limit before charging.
- Only unlocked slots can be developed. Preserve finish through upgrades and
  type changes, including fixed-value detours. Never overwrite other slots,
  quality, reinforcement, affinity, signature or family mastery.
- Refinement advances to the next 2000-BP finish boundary, bundling boundaries
  until at least one authored component visibly increases. Fixed-value tiers
  cannot sell refinement. Price is the difference between cumulative
  `ceil(1250 * finish / 10000)` coin prices; tiny already-rounded finishing
  fractions may be free, but cannot exceed finish 10000 or create resources.
- Dismantling returns one shard for every present affix, including dormant
  ones, with no material/coin refund. Signature extraction instead consumes
  the eligible item to unlock its permanent Reliquary power. Reject an already
  known power rather than silently consuming the item for nothing. Never pay
  both recovery routes. Retain Codex/mastery/library history.
- Originals cannot be consumed. Favorites and equipped items cannot be
  consumed. A booked active haul/raid item cannot be forged or consumed.
  During a committed raid, selected-loadout forging also respects its preset
  lock; unrelated unused gear remains editable. Forge operations do not equip
  an item implicitly. Existing booked rewards and snapshots remain unchanged.

### Processing, Refining And Marks

- Ordinary fish use the proposed F..UUR 6..33 fillet / 4..22 scale table,
  including eligible legacy inventory with zero retroactive effect bonus.
  Use only catch-pinned modifiers and the existing fractional-credit keys.
- Accept at most 50 unique owned fish. Favorites/bonus species are never
  processed; exact rank maxima/perfects need explicit trophy opt-in. Consume
  inventory and modifiers only, preserving XP, journals, records and collection.
  Quotes freeze exact yields, carry and forgone saved sale coins; stale carry
  requires a new preview. Selling and processing cannot both consume a fish.
- Conversion is separately 10 normal materials to one F material, requiring
  an ordinary F clear, without a coin fee. Adjacent refining is 20 to 18 plus
  one coin per batch, with the shared destination-grade clear. Preserve every
  remainder. Legacy refining retains its old recipe while economy is inactive.
- A multi-hop refinement consumes only the specified starting batches, uses
  whole newly produced intermediate batches, and returns intermediate leftovers.
  It does not silently consume existing higher-grade stock or skip any clear.
- New ordinary raid bookings pin the per-grade Marks 1/2/3/4/5/6/8/10/12/14.
  Full successful clears, including repeats, pay once; old bookings, partial
  withdrawals, missed/cancelled attempts, community and special raids pay none
  under this local version. Existing material rewards are otherwise unchanged.
- Each newly earned achievement after activation grants one Mark once.
  Existing awards and explicit historical/backfill paths grant none. No
  achievement notification is replayed to grant currency. There is no exchange.

### Mutation Interface And Preservation

Use private quote/receipt, Mark-wallet/award, raid-Mark-booking, Vault and Codex
tables with owner/selected-adapter views. Requests use bounded intent JSON with
decimal-string instance/catch IDs; clients never supply costs or rewards.
`prepare_tideforge_action(intent_json)` produces a scoped nonce and exact
preview; `commit_tideforge_action(nonce)` revalidates and commits atomically.
Discord counterparts use the established verified-adapter identity seam.

Intent actions cover Camp copy, wandering gamble, Add/Upgrade/Refine/Change,
dismantle, signature extraction, filleting, normal conversion and graded
refining. Quote identity, item revision, catalog, expiry, locks and relevant
resource/carry state must still match. Receipts make accepted confirmation
replay inert, including after another quote. Reject malformed/foreign/overflow
requests without spending or destroying anything.

Activation does not rewrite existing players, gear, mastery, catches, rewards
or active activities, and does not backfill currency or natural Codex entries
from arbitrary existing affix rows. Normal materials use existing ItemStack
semantics at slots 800/801 with keys `normal_fillets`/`normal_scales`, not a
new item-definition catalog. Reject conflicting slot labels rather than
overwriting them or creating resources during activation.
Currency balances/counters and permanent discovery remain durable independently
of bounded receipts. Stage 3 supplies backend contracts, not website controls.
