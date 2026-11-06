# Tideforge Balance Review And Production Baseline

Status: analysis and proposed test targets, not approved gameplay changes.
Reviewed November 6, 2026. The [decision index](README.md) still governs accepted
rules. Existing candidate tables are unchanged by this review.

Subsequent local work: [uncapped Luck scaling](../luck-scaling.md) replaces the
linear model's validation ceilings. The 34,000-bp proposal and positivity limits
in this review describe the pre-redesign model, not a cap for the new formula.
Production observations and the original comparison numbers remain frozen.

Subsequent work: the [proposed effect contract and build comparisons](build-contract-v0.1.md#6-proposed-effect-contract-and-build-comparisons)
define candidate triggers and test complete proposed builds against public
catalog data. This is not a production proof or approval of the new rules.

## 1. Main Conclusion

The proposed costs are not the first thing to raise. Several T10 effects and
signatures are too small to feel like developed gear, while unresolved trigger
semantics, overlapping caps and cheap-gear recovery can produce unexpectedly
strong or misleading results.

Keep the accessible activity reference. Make ordinary choices useful, preserve
the original equipment investment, and test stronger precision/volume bonuses
before adding more costs or waiting periods. Production does not yet contain
Tideforge mastery, affixes, filleting or gambling; it cannot supply medians for
systems that do not exist.

## 2. What Was Measured

Read-only confirmed SQL queries ran against the production host's loopback
3127 database, identity
`c2002230cb292d622fb9716c64c8c73f84b1934e4eb6e6db546fb271ab32e488`.
The production database retains the historical name `fishbound-dev-local`;
the host and identity, not that name alone, distinguish it from Windows dev.

- Main capture: November 6, 2026, 21:19:35-21:19:36 UTC.
- Activity window: October 30, 00:00 UTC inclusive to November 6, 00:00 UTC
  exclusive: seven complete UTC days, excluding the partial current day.
- There are 10 accounts, of which five performed fishing actions in that window.
  All 10 have fished at least once. Do not call the all-account median the
  typical active player.
- One rod-only receipt correction was captured at 21:22:06 UTC, excluding
  receipts newer than the main capture. Hauls also write equipment receipts;
  `power > 0` selects normal rods under the reviewed current code.
- The main collection kept private rows in process memory on the production
  host and returned aggregates and public catalog data, not names, Discord
  IDs, guild/channel IDs, credentials or a database backup.
- Queries were sequential confirmed reads, not one globally frozen snapshot.
  No writers were stopped, reducers invoked, data migrated or services changed.

### Active-Player Baseline

Quartiles use linear interpolation at `(n - 1) * p`. With five active players,
these are descriptive values, not statistically reliable population estimates.

| Measure | Median | Useful spread / denominator |
|---|---:|---|
| Accepted fishing actions per calendar day | 23 | Per player: seven-day actions / 7; quartiles 7.29-35.29, range 1.00-49.71. |
| Actions on a day someone actually fished | 20 | 24 player-days; not the same denominator as the row above. |
| Actual coin credits per calendar day | 13,129 | Five active players; quartiles 3,948-27,389. |
| Actual coin debits per calendar day | 12,429 | Five active players; includes equipment purchases, not just recurring costs. |
| Current coin wallet | 46,386 | Five active players; range 60-144,380. A balance is not income. |
| Current player level | 104 | Five active players; range 7-214. This cohort is not a new-player sample. |
| Ordinary species discovered | 228 / 249 | Current lifetime progress, not discoveries in the seven-day window. |
| Known ordinary species-rank pairs | 943 / 2,490 | Current lifetime progress. |
| Species with all 10 ordinary ranks | 1 | Range 0-42; the main collection gap remains substantial. |
| Exact ordinary rank maxima | 13 | Range 3-79; lifetime progress. |
| Ordinary perfected species | 1 | Five ordinary perfect-species records across the cohort in total. |
| Highest shared ordinary raid grade unlocked | F | Median cleared-grade count 1; range 0-10, where zero means no clear. |

The all-account median level is 4.5 and wallet is 100 coins, heavily affected by
accounts with only a few lifetime actions. Conversely, using only the richest
or highest-level player would overprice normal crafting.

Do not subtract the two income/spending medians to invent a median net income:
the middle player can differ between distributions. Actual credits include
sales of previously held fish; they are not all freshly generated that day.

### Coverage And Method Mix

The window contains 814 actions and 3,246 result rows: 2,533 fish, 454 junk and
259 treasure. All window rows have modern pull keys and recorded XP, and all
action groups were classified without guessing:

| Method | Accepted actions / completed trips | Ordinary fish |
|---|---:|---:|
| Rod | 372 | 500 |
| Boat | 139 | 1,095 |
| Net | 303 | 938 |

A `pull:` journal key encodes interaction ID and pull index. Retained haul-trip
IDs identify boat/net groups; contiguous one/two-pull groups outside those IDs
identify rods. Reserved high-bit interaction IDs identify special raid catches
and are not counted as ordinary rod activity. Each completed trip counts as
one action, not 10 or four actions. Failed commands and status checks are not
completed fishing actions.

The retained rod-only receipt sample is 253 casts, with median Power 104,
quartiles 104-185. Sixteen used two Trophy Chums; the sample also contains
85 four-attempt casts and 152 one-attempt casts. This is a rolling receipt
sample, not the seven-complete-day activity cohort or equal-player weighting.

Only three players have ordinary raid clears; only one has all ten grades
unlocked and one rod has level-10 reinforcement. There are 18 retained raid
attempts: 16 completed, one missed and one cancelled, across personal and
community raids. That is not enough evidence to tune raid difficulty or
signature occurrence empirically.

## 3. Clear Low-End Problems

| Candidate | What the draft actually buys | Recommendation |
|---|---|---|
| Deep Trawler T10: 10% chance of one boat pull | 0.1 extra pull on a 10-pull trip: only +1% average volume. | Increase substantially; test one guaranteed extra pull at T10. |
| Overflow Knot T10: 10% chance of one net pull | +2.5% average volume, not +10%. | Test 40% chance of one extra pull: +10% average volume. |
| Ghost Trawler / Nets of Plenty: 5% chance of one pull | Only +0.5% boat / +1.25% net volume. | Test 50% boat / 20% net proc chances for +5% average volume each. |
| Fortune T10: +5 percentage points to Luck stat | At Prismatic Abyssal Boat, only about +1.37% relative UUR ticket probability. | Test +25 Luck-stat points, not +25 percentage points of UUR chance. |
| Scholar T10: +12 points to the XP bonus | At a +374% XP boat, only +2.53% actual XP. | Test +20% relative fishing XP in a separately bounded new modifier group. |
| Line Tension T10: +6 Power | About +1.56% output at Power 185, or +1.97% at Power 104. | Redesign throughput value before increasing raw Power into saturation. |
| Aftercast T10: 4% extra-pull chance | About +2.08% output at Power 185. | Test a 20% nonrecursive extra-pull chance, roughly +10.4% there. |
| Weight Weaver / Measured Cast | Even if their triggers work, expected added attempts are tiny beside 16 bait attempts. | Author real triggers and test several meaningful full-specimen attempts, not tiny dimension-only procs. |
| Trophy's Oath: 2% extra endpoint opportunity | If that means an ordinary 1-in-5,000 trial, its isolated hit chance is just 1 in 250,000 eligible fish. | Test five guaranteed additional eligible endpoint trials instead. Never reinterpret the draft as a direct 2% perfect award. |
| Fisherman's Folly: all-junk boat consolation | Even the highest current junk biome gives `0.18^10`, about one in 28 million base hauls. | Change its trigger; bigger consolation quantities do not repair an effectively absent trigger. |

These are effect-size calculations, not measurements of future builds.
Weight-only or length-only attempts are not automatically legitimate extra
specimens or endpoint trials. Resolve that contract before putting numbers in
an implementation.

### Conditional Affixes Need A Reason To Exist

Resonance currently matches Fortune's +5 Luck points but works in fewer places.
Attuned Wisdom matches Old Salt's +20% mastery but is conditional. Attuned Focus
matches Focus Tuner's +5 points but is conditional. Deepwater Cache's +20%
treasure coins is lower than unconditional Treasurekeeper's +25%.

Different IDs allow stacking, so these can still be used together; they are
not worthless in every build. But a biome specialist should normally be
stronger in its biome than the unconditional alternative, not merely a second
copy that consumes another slot.

Treasure bonuses also start from a small coin source. The window's coin ledger
credits are 633,675 from fish sales versus 11,159 from treasure. A +25% treasure
modifier on those treasure credits would add only about 2,790 coins across
the cohort for the week. This comparison includes inventory-sale timing; it
is not a normalized future-build income forecast.

## 4. High-End And Misleading-Value Risks

- **Luck has a real mathematical limit.** The current graded-luck model starts
  producing nonpositive low-rank weights above 34,742 bp in Sunken Coast.
  +350% fails there. Arbitrarily raising the validator to +400% is not safe.
- **Focus can waste paid slots.** Current UUR reinforcement + Focus Tuner +
  Attuned Focus + Collector's Whisper totals 63%, above the proposed 60% cap.
- **Material/salvage stacks also overflow draft caps.** Scaleweaver + Scale
  Collector + Attuned Harvest + Scale Pact total +60% scales against +50%.
  Scavenger + Wreckbreaker + Salvage Fleet total +70% against +50%.
  Full Hold + Provisioner + Attuned Harvest total +40% fillets and do fit.
- **Extra pulls and timers compound economically.** The proposed stronger
  pull bonuses plus the existing proposed 15% timer reduction imply roughly
  +35.3% throughput at continuous occupancy for a fully invested boat or net
  before Luck/yield bonuses. That is not a promise of +35.3% per casual session:
  a timer saving matters only when it enables another trip.
- **Rare trials must not become direct awards.** A 2% proc for one 1-in-5,000
  trial and a direct 2% perfect chance differ by a factor of 5,000 before
  existing successes. Fihs/Sock remain outside ordinary perfect assistance.
- **Fixed-value detours can grant free refinement if finish is inferred from
  the displayed value.** Changing through a zero-width affix must not convert
  an unfinished slot into 100% paid finish. Preserve finish independently;
  a fixed displayed maximum is not proof of fully paid refinement.
- **Low-family items are cheap shared resources.** If two uniformly selected
  rod affixes can come from the same 23-ID compatible pool at every family,
  a particular shard costs 11.5 purchases in expectation: 5,750 coins from
  500-coin River gear versus 1,495,000 from 130,000-coin Abyssal gear. That is a
  conditional example, not a decided roll table. The same issue applies to
  global signature unlocks and 1-Mark versus 130-Mark gambling.

Cheap gear supplying shards/library progress can be intentional and friendly.
Do not secretly raise accepted River prices or require destroying expensive
gear to solve it. Higher-family prices buy better base equipment; they should
not automatically become the normal shard-acquisition cost.

## 5. Proposed Starting Test Targets

All rows are recommendations for comparison, not replacements for approved
rules or permission to implement. Ten-tier grids still need authorship.

| Area | Starting target | Reason / boundary |
|---|---|---|
| Daily reference | Keep approximately 24 mixed fishing actions plus normal raid check-ins | Active-player median is 23; do not raise it to the highest player's activity. |
| Deep Trawler / Overflow Knot T10 | +10% expected base trip volume | Boat: guaranteed +1 pull; net: 40% chance of +1. Retain nonrecursive shared caps. |
| Ghost Trawler / Nets of Plenty | +5% expected base trip volume | Boat: 50% chance of +1; net: 20% chance of +1. |
| Efficient Hull / Quick Set | Keep T10 -10%; combined ordinary timer ceiling -15% | Useful cadence benefit without changing raid time. |
| Fortune / Fine Mesh T10 | +25 points to the rarity-Luck stat | Stronger than the draft without treating the stat as absolute catch chance. |
| Resonance T10 | +40 Luck-stat points in primary biome | A meaningful conditional advantage over Fortune. |
| Innate biome Luck | Keep the candidate +1 Luck-stat point for this comparison | Do not inflate a free layer to hide weak paid affixes. |
| Luck validation budgets | Rod 20,000 bp; hauls 34,000 bp, subject to full Rust integration proofs | Current hypothetical totals: rod 18,600; boat 33,200; net 23,100 bp, excluding unapproved mastery raw stats. These are validation budgets, not a license to clip bonuses. |
| Scholar T10 | +20% relative fishing XP | Separate additive Tideforge XP group applied after the existing XP multiplier; exact rounding and total group budget still need a contract. |
| Old Salt / Surveyor / Attuned Wisdom T10 | +20% / +20% / +30% mastery XP | Test pacing against the actual mastery curve; avoid conditional equivalence. |
| Coinwright / Provisioner / Scaleweaver / Full Hold / Scale Collector | Keep +20% / +12% / +12% / +18% / +18% initially | These affect their intended output directly and need not all be inflated. |
| Treasurekeeper / Sunken Treasury / Pearl Catcher / Deepwater Cache T10 | Test +100% / +125% / +125% / +150% treasure coins | Small underlying coin source; sum in one payout group, with nonrecursive doubling. This does not change treasure encounter odds. |
| Trophy's Oath | +5 eligible endpoint trials, guaranteed | Two Trophy Chums plus UUR reinforcement: endpoint-only probability rises from about 0.519% to 0.618%, not to a guaranteed perfect. Natural sampler maxima are separate. |
| Scale / salvage budgets | Test +75% additional yield in each separate group | Covers current draft combinations without hidden wasted value; does not approve further new sources beyond the budget. |
| Roll refinement | 250 coins per 20 percentage points of retained roll finish; at most 1,250 coins from minimum to maximum | Bundle steps and their prices when integer rounding needs a larger finish change for a visible legal improvement. Never make a discrete tier a cheaper route to the same paid finish. Fixed-value affixes have no paid refinement. |
| Junk base quantity | Test uniformly 1-3 tin per junk, retaining fractional modifier credit | Doubles mean base tin, leaves existing treasure scrap unchanged, and keeps percentage bonuses effective. |

The Luck arithmetic was checked against all seven production ticket pools:
positive weights, unchanged total normalization, and u64-safe weights/totals
at 12,000 / 18,600 / 20,000 / 26,600 / 33,200 / 34,000 bp. The deliberate
35,000-bp check fails positivity in Sunken Coast. This is an exact calculation
from public catalog data, not a compiled Rust or end-to-end integration proof.

Do not add raw mastery Luck/Power on top of this budget without recalculating
the allowed combinations. Measurement, Focus/Maxima arbitration, other
signatures and the final XP budget are still open, not implicitly approved
by this table.

Refinement prices apply to retained finish, not merely the number of buttons
pressed. For example, if a discrete tier has only a minimum and maximum, one
visible minimum-to-maximum payment may bundle all five 250-coin finish steps.
It must not sell the full retained finish for 250 coins merely because the
range is narrow. A fixed-value type keeps its latent finish through changes;
its displayed maximum does not award free finish for a later variable type.

## 6. Processing, Forge Costs And Real Activity

Using each active player's actual method/rank mix, normalized to 24 actions,
then taking equal-player medians:

| Mutually exclusive use of ordinary fish | Median counterfactual output |
|---|---:|
| Ordinary fish acquired | 74.35 fish per 24 actions |
| Sell every ordinary fish | 15,660 coins per 24 actions |
| Process every ordinary fish under the proposed table | 1,160 normal fillets + 774 normal scales |

These are unprotected upper-bound scenarios, not actual earned filleting
income. The sale counterfactual uses the current matching rank base values
and saved journal lengths, not an affix modifier or a repricing of saved fish.
Ordinary flags, lengths, rank values/weights/XP, category weights and raid
grade rows used in these calculations match the local source.

A rank-blind 50% sale / 50% processing split, applied to those separate marginal
medians, gives approximately 7,830 sale coins and 580 fillets / 387 scales
per 24-action session before bonuses. These medians need not describe the
same player. They ignore trophy retention, favorites, bait/supplies and new
Forge spending; selective processing of cheap fish can change the tradeoff.

At the proposed 10:1 F conversion, that illustrative split supplies roughly
58 F fillets and 38 F scales, almost the 60/40 materials for four T1 additions.
The matching shards and grade unlock are separate. Do not count the sale
coins from the processed half too.

### Higher Tiers Are Still Primarily Raid-Funded

Under the 20-to-18 refinement proposal, from empty intermediate stocks:

| Material-only target, after grade unlocks | Normal materials needed through F conversion | Additional refining coins |
|---|---:|---:|
| One affix, Add T1 through T10 | 17,350 fillets + 11,900 scales | 729 |
| Four affixes, Add T1 through T10 on one item | 66,800 fillets + 44,400 scales | 2,786 |

This backward whole-batch calculation reserves every intermediate tier payment
and retains leftovers. It does not assume material grades are interchangeable.
With only the illustrative 50% processing flow and no fresh raid rewards,
these are about 31 and 115 normalized 24-action sessions respectively.
They are not completion-day forecasts; all raid-grade unlocks are presupposed,
and simultaneous fresh raid rewards can shorten the material path substantially.

The proposed matching raid rewards already fund four same-grade affix payments
per clear. Keep that initial budget and the proposed 20-to-18 refining recipe
for comparison, rather than raising costs because mature players have coins.
Reinforcement spends from the same stock and remains additional.

### Dredge Marks

The accepted 65 Marks per full ladder stays. The proposed individual payout
split yields 2 Marks per occupied hour for repeat F versus about 0.933 for UUR,
before deployment gaps: frequent F clears are about 2.14 times faster for
Marks at continuous uptime. Two complete ladders occupy 108 raid hours.

This can be an intentional reward for more check-ins, which fits the accepted
freedom to play more. It is not evidence that UUR is best Mark farming.
Whole-Mark F payouts constrain time-proportional distributions; do not silently
change the accepted ladder budget or River prices to force equal rates.

## 7. Mastery And Trader Access

Production activity can anchor pacing, but there is no gear-mastery XP history
to fit. The old 10/25/50/75 slot proposal has no measured time-to-unlock curve.

Recommended next comparison:

- First slot at mastery level 1, then candidate slot levels 10/25/50 instead of
  10/25/50/75. This changes an old proposal, not an accepted unlock schedule.
- Target four usable slots after roughly 1-2 weeks of focused ordinary play,
  not 1-2 weeks for every family simultaneously. This is a design target,
  not a forecast derived from existing mastery data.
- Keep level-100 completion primarily cosmetic/modest, not required to make
  an item useful. Mastery affixes should be replaceable once their job is done.
- Avoid requiring the second slot before the player can buy their first
  shard-supplying Camp copy. That would gate entry to the system behind itself.
- Compare two-affix T1-T3 Camp copies against four-affix T8-T10 monthly copies;
  those exact counts/ranges remain proposals. High-tier dormant effects stay
  visible and preserved. New rods should not grant another rod's reinforcement.
- Use a forgiving monthly availability window; do not hide basic forging,
  ordinary shards or recovery behind that visit. Signature probabilities,
  targeting and duplicate handling still require explicit tables.

Older families do not need equal base stats. As an example, Prismatic Twig
has only about 58% of Prismatic Abyssal's baseline rod pulls per action.
The same +1-point innate Luck bonus does not close that gap. Test developed
older specialists against undeveloped later generalists at their chosen goal,
and equal-investment families separately; do not claim affinity alone has
already made every family competitive.

## 8. Scope And Remaining Evidence

This reviews all ten current Tideforge documents, the 39 ordinary-affix
candidates, 24 signature candidates, current authoritative formulas and
available production activity/progression/economy data.

It is not a complete distribution simulation, approved ten-tier catalog,
mastery curve, trader roll table or implementation specification. More
activity is allowed to progress faster; none of the findings justifies
punitive quotas, weaker saved equipment or stretching completion to years.

Next: resolve the weak/unsettled effect semantics, compare the starting
targets as complete builds, then author the integrated mastery/trader/effect
contract. Keep production deployment a separate explicit approval.

## 9. Ordinary Affix Coverage

"Keep" means a reasonable first comparison, not approved balance.
"Open" means missing semantics prevent a defensible numerical verdict.

| Candidate | Assessment | Next treatment |
|---|---|---|
| Fortune | Low at developed gear | Test the stronger Luck-stat target. |
| Scholar | Low when additive to large existing XP bonuses | Test the separate relative-XP group. |
| Old Salt | Curve-dependent | Keep +20% as a first comparison; make it replaceable after mastery. |
| Coinwright | Keep | +20% sale output is real; pin it once and exclude processed fish. |
| Scavenger | Keep with rounding | Preserve fractional credit and show salvage-budget use. |
| Treasurekeeper | Low economic contribution | Test a larger payout modifier without encounter changes. |
| Provisioner | Keep | Direct processing output; test sale opportunity cost. |
| Scaleweaver | Keep; cap collision | Preserve credit and compare the full scale stack. |
| Line Tension | Low / saturating | Redesign value at both Power 104 and 185; do not silently clamp. |
| Chum Catalyst | Open | Replace the nonexistent bait-activation trigger with a real synergy. |
| Weight Weaver | Open / likely low with bait | Specify full-pair versus weight-only behavior; compare against 16 attempts. |
| Trophy Lens | Open / likely low with bait | Legal specimen selection matters more than its headline proc percentage. |
| Focus Tuner | Usable; cap collision | Compare extra rank progress, not raw UUR probability. |
| Bait Conservation | Keep | Retaining 15% of uses is meaningful; define two-slot consumption once. |
| Aftercast | Low | Test stronger independent throughput inside the three-pull budget. |
| Measured Cast | Low with developed bait | +0.12 expected attempt is small; define meaningful specimen attempts. |
| Maxima Priority | Open; important gap | Choose unfinished ranks only after random species selection; define arbitration with missing-rank Focus. |
| Deep Trawler | Low | Match the +10% expected-volume target. |
| Efficient Hull | Keep | Useful when the player starts another ordinary trip. |
| Schoolfinder | Open variety value | Specify one duplicate, legal replacement and rank handling; no chosen species. |
| Wreckbreaker | Keep; cap collision | One additive salvage budget with Scavenger and powers. |
| Sunken Treasury | Low | Test stronger payout, not more treasure events. |
| Full Hold | Keep | Direct fillet benefit, pinned to acquired fish. |
| Surveyor | Curve-dependent | Compare with generic mastery and activity credit; never 10 automatic full-action awards per haul. |
| Haul Appraiser | Low / open measurement | A 15% proc on one fish is not a 15% benefit to every fish. |
| Fine Mesh | Low at developed gear | Same rarity-Luck treatment as Fortune; no second rarity transform. |
| Quick Set | Keep | Ordinary cadence only, combined timer budget. |
| Overflow Knot | Low | Scale expected benefit to the four-pull base. |
| Selective Weave | Open variety value | Same legal duplicate policy as boat variety effects. |
| Silt Sifter | Keep; cap collision | Preserve fractional salvage and shared-budget visibility. |
| Pearl Catcher | Low | Compare total coin output after stronger payout modifiers. |
| Scale Collector | Keep; cap collision | +18% scales is meaningful; current full combination exceeds the draft cap. |
| Mist Weaver | Low / open measurement | One selected ordinary fish, not a full extra roll for every pull. |
| Resonance | Weak conditional value | Test a stronger primary-biome effect than Fortune. |
| Attuned Harvest | Keep | Both ordinary resources benefit; pin acquisition modifiers. |
| Attuned Wisdom | Weak conditional value | Test +30% versus unconditional +20%, then check the mastery curve. |
| Deepwater Cache | Weak conditional and low economic value | Must beat the unconditional alternative in its eligible context. |
| Secondary Affinity | Low / identity needs work | +2 Luck points is a tiny alternate-water benefit; does not activate primary-only affixes. |
| Attuned Focus | Weak conditional; cap collision | Must justify a slot versus Focus Tuner without wasting reinforcement. |

## 10. Signature Coverage

| Candidate | Assessment | Next treatment |
|---|---|---|
| Echoed Line | Low | 2% extra rod pull is smaller than it sounds; compare after the rod throughput redesign. |
| Trophy's Oath | Ambiguous; potentially negligible or excessive | Use explicit extra 1-in-5,000 trials, not an unspecified "perfect opportunity." |
| Baitkeeper's Promise | Open | No current activation probability exists to receive its +1 point. |
| Collector's Whisper | Usable; cap collision | Include reinforcement and both ordinary Focus IDs in the same budget. |
| Salvage Sermon | Useful flat reward; collision risk | One extra resource on first rod tin can exceed small percentage affixes; define interaction with Rusted Crown. |
| Tidal Memory | Low after more than a few daily actions | +20% on one catch is not +20% daily mastery; test useful flat/session progress rather than mandatory biome chores. |
| Ghost Trawler | Low | Test +5% average boat volume. |
| Cartographer's Wake | Open variety value | Guaranteed one legal duplicate reroll can be useful; it must not become chosen-species farming. |
| Salvage Fleet | Keep; cap collision | Compare total percentage salvage and flat awards separately. |
| Tempest Rudder | Keep | -5% ordinary boat timer completes the candidate -15% budget. |
| Gilded Bilge | Low absolute coin value | One rare selected treasure proc needs comparison after payout changes. |
| Fisherman's Folly | Trigger effectively absent | Redesign trigger rather than inflate an extremely rare payout. |
| Nets of Plenty | Low | Test +5% average net volume. |
| Moonthread | Open / low scope | One selected fish and legal full-pair attempts; no implicit per-pull multiplication. |
| Swiftwater Knot | Keep | -5% ordinary net timer, not another compounding reduction. |
| Whispering Mesh | Open variety value | One duplicate replacement, no guarantee and no special-species targeting. |
| Scale Pact | Keep; cap collision | +20% pinned scales is real; full current combination needs budget repair. |
| Pearlbound | Low absolute coin value | A 10% proc on one treasure is not +10% total session income. |
| Rusted Crown | Useful flat reward; collision risk | Separate first-event scope from percentage salvage and Salvage Sermon. |
| Atlas of Seven Waters | Low after more than a few daily actions | Avoid disguising a first-catch-only bonus as a large whole-session gain. |
| Wayfarer's Compass | Open / likely small if based only on innate Luck | Define the outside-primary quantity; do not activate primary-only effects by implication. |
| Forgotten Cache | Open | No probability/quantity exists to price; distinguish a direct grant from a deferred yield modifier. |
| Ocean's Memory | Open; declines as collection fills | Define amount and permanent novelty; a new UTC day does not make an old rank new. |
| Rift Current | Open; duplication risk | Define proc scope and daily budget, then include its pulls in the same ordinary action cap. |

For the five-extra-trial Trophy's Oath comparison, the 26-trial starting case
requires two Trophy Chums, level-10 reinforcement, Focus enabled, all ranks
already known, and an ordinary UUR encounter. Natural maxima can occur in
addition to endpoint trials. These figures are not per-cast perfect odds.

## 11. Reproducibility Notes

The core read-only queries are projections, not `SELECT *` exports:

```sql
SELECT key, player_id, catch_id, caught_at, outcome, species_id, rarity,
       length_mm, xp_granted, biome_id FROM journal_entry
SELECT player_id, item, delta, reason, created_at FROM economy_ledger
SELECT interaction_id, player_id, kind, equipment_id, quality_level,
       luck_bp, xp_bonus_bp, started_at, completed_at, status,
       total_xp, total_coins FROM haul_trip
SELECT player_id, species_id, count, rank_counts FROM player_species_progress
SELECT player_id, species_id, rarity FROM rank_maximum
SELECT player_id, species_id FROM perfect_species
SELECT player_id, species_id, cleared_grades, successful_clears FROM raid_badge
SELECT player_id, rod_id, level FROM rod_reinforcement
```

Additional explicit projections read player balances/creation/progress counters
without identity columns, public profile levels, owned gear quality, material
stacks, inventory sale values/favorites, history-completeness flags and public
species/rank/biome/raid definitions. Timestamp filtering and aggregation ran in
Python on the host, with arbitrary-precision integers for IDs and reward math.
All mutation counters returned zero. There were 12,157 journal rows and
12,593 retained economy-ledger rows at capture.

Gross coin distributions sum only positive `coins` ledger deltas within the
window, separately per active player, then divide by seven. Spending uses
negative deltas, never absolute net deltas. Retained ledger history covers
the selected window. Two players' sale-history flags are complete; the
seven-day ledger is used instead of pretending all lifetime sale histories
are complete. That completeness count is across all 10 accounts, not just
the active cohort.

Processing yield is `3 * (rank_ordinal + 2)` fillets and
`2 * (rank_ordinal + 2)` scales per ordinary fish. Normalized output is each
player's window total divided by their window action count, multiplied by 24;
the median is then taken over the five active players. This is not the pooled
fish-per-action mean, a mandatory activity mix or a prediction for new builds.
