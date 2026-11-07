# Tideforge Design Workspace

Status: the instance/preservation foundation is implemented and verified locally.
Development/production activation and deployment are not authorized by these
documents. Numerical effect rules remain proposed, not approved gameplay.

This directory contains repository working copies of Boss's four Desktop design
documents. The Desktop originals are not edited. Original filenames, version
labels and historical source references are retained for provenance; the
repository copies incorporate the clarifications below.

## Reading Order

1. [System design](Fishbound_Tideforge_System_Design_v0.1.md):
   the connected equipment, mastery, gambling, crafting and collection loops.
2. [Existing gear and balance integration](Fishbound_Tideforge_Existing_Gear_Stats_and_Balance_Integration_v0.2.md):
   current gear values, stat calculations and migration responsibilities.
3. [Biome affinity and resonance](Fishbound_Tideforge_Biome_Affinity_and_Resonance_v0.2.md):
   innate affinity, conditional effects and attunement.
4. [Affix catalogue](Fishbound_Tideforge_Affix_Catalogue_v0.3.md):
   candidate ordinary affixes, signature powers and effect budgets.
5. [Builds and effect interactions](builds-and-interactions-v0.1.md):
   illustrative builds, candidate interaction matrices and remaining mechanic
   contracts. This is a draft recommendation, not approved balance.
6. [Forge and refinement](forge-and-refinement-v0.1.md):
   accepted four-action flow and retained roll progress. Recipes remain open.
7. [Forge cost model](forge-cost-model-v0.1.md):
   provisional material and shard cost comparison, not approved balance.
8. [Processing and material economy](processing-and-material-economy-v0.1.md):
   proposed filleting and conversion recipes, conditional fish batches and
   whole-batch comparisons; not approved balance or a daily-income forecast.
9. [Build contract](build-contract-v0.1.md): consolidated accepted rules and
   remaining implementation gates; the instance/preservation foundation is
   described in section 8. Individual raid payouts remain proposed.
10. [Balance review and production baseline](balance-review-v0.1.md): read-only
    production activity/economy observations, effect-size findings and proposed
    starting test targets; not approved balance.
11. [Uncapped Luck scaling](../luck-scaling.md): local formula implementation
    preserving current loadouts; separate production deployment approval needed.

## Decision Precedence

Boss's clarifications in this index govern the working copies where older
drafts disagree. Current source is the authority for what the game implements
today; design proposals describe future behavior, not existing functionality.
The latest affix catalogue is a candidate list, not blanket approval of its
numbers or unresolved differences from its companions.

The [proposed effect contract and build comparisons](build-contract-v0.1.md#6-proposed-effect-contract-and-build-comparisons)
supersede older effect sketches for current comparisons. Accepted directions
remain distinct: its native/library layout, grids, roll minima and stats are
proposals awaiting review, not approved or implemented balance.

Do not turn an old example, proposed cap or historical "agreed" label into a new
implementation decision without checking this index and the remaining questions.

### Interpretation And Terms

Family-wide rules and examples apply to the matching rod, boat and net unless
explicitly kind-specific. Additional copies include both coin-bought Camp gear
and Mark-gambled gear. Rod bait, Power, reinforcement and precision mechanics
do not become boat or net mechanics by implication.

Gear quality (Common–Prismatic), affix tier (T1–T10), shared family mastery or
gear level, and within-tier roll finish are distinct. Pulls can yield fish,
junk or treasure; they are not guaranteed fish. Read examples alongside the
established decisions and candidate/default labels, without turning settled
examples into new implementation blockers.

## Confirmed Direction

### Keep The Game Simple And Fun

ARPG inspiration means enjoyable gear bonuses and optional build experimentation,
not required Path of Exile-style expertise. Normal choices should be useful and
forgiving, not traps that punish someone for picking the wrong combination.
Specialisation can improve a goal without making other normal builds invalid.
Do not require players to study an interaction matrix to enjoy progression.

Do not add pain, permanent bricking or deliberate slowdown to stop highly active
players finishing sooner. Players choose their own pace; fun and accessibility
take precedence over enforcing a calendar-length grind.

### Sustainable Activity Baseline

Full progression should be accessible around 24 fishing actions/casts per day
in any mixture of rod, net and boat activity, plus roughly one or two raids or
raid check-ins as booked durations allow. This is not 24 mandatory rod casts
plus additional required haul grinding. Distinguish actions from result counts
when modelling different methods.

More activity can accelerate progress, and that is acceptable. Raids already
provide duration and access gates; do not add extra friction solely to keep
people playing for a prescribed number of years. Long raids can span daily
check-ins; this does not change their booked durations or guarantee two
completed raids every day.

### Mastery, Gear Sources And Dredge Marks

Gear levels unlock affix slots through mastery shared per owner, equipment kind
and family. Exact slot levels and the mastery XP curve remain open. The vanilla
original stays protected; copies inherit family mastery, not another item's
quality or reinforcement.

The Camp trader sells coin-bought additional gear with low-tier affixes. A
wandering trader visits once per month and offers gear with very-high-tier
affixes. Exact tier ranges and acquisition details remain open. Dismantling an
eligible item recovers matching Vault resources for all its affixes; extracting
its signature into the Reliquary is the mutually exclusive alternative.

Gambling uses Dredge Marks earned from raids and achievements. The pricing
equivalence is one Mark per 1,000 coins, with the approved River exception.
Prices apply per item to rods, boats and nets in each family; the
[build contract](build-contract-v0.1.md) records the full price table. This is
not approval for a currency exchange. One full ordinary F-to-UUR raid ladder
earns 65 Marks, so two earn 130 from raids alone; achievement Marks are
additional. The per-grade split and remaining implementation details are open.

River rods, boats and nets cost 1 Dredge Mark each. Their existing 500-coin
prices stay unchanged. All currently priced families therefore have whole-Mark
gambling prices.

### Bait and Affixes Are Designed Together

Bait is an additional source of effects alongside gear affixes. Bait, affixes,
or both may be adjusted so they complement each other. This does not mean that
every effect stacks by simple arithmetic: proposed units, eligibility,
precedence, stacking groups and limits are now authored for review.

The current bait behavior is a reference baseline, not an untouchable future
contract. Preserve earned progress and saved results; any future change needs
an explicit rule, version and balance review rather than an accidental nerf.

### Caps and Affix Values Are Open

Current validation limits and all proposed Tideforge caps are subject to change.
Higher limits may be needed for ten-tier affixes and additional stat sources.
Affix definitions, values and supporting mechanics may be revised as the stat
budgets and effect interactions are developed.

Ordinary affixes still use ten independent tiers, T1 through T10. Reopening cap values and effect tuning does not remove that agreed structure. Four ordinary slots remain the current working layout; unlock levels and slot budgets still need final approval.

No replacement limits, tier grids, distributions or final affix values are
approved yet. Raising a validator alone is not a completed balance design.

### Older Gear Remains Lower-Stat but Respectable

Earlier families retain lower base stats as part of the existing progression.
The goal is reasonably comparable, respectable equipment with useful roles,
not identical statistics or a guarantee that every old family is best in slot.
Affinity, mastery and affix interactions must be evaluated against that goal.
Existing quality, reinforcement and other earned progress must survive.

### Developed Equipment Roles

A developed rod is a precision and collection-completion tool. Species selection
stays random within the legal biome pool: no chosen-species targeting or
guaranteed species. Rod development should make finishing an encountered fish's
missing-rank ladder and exact rank-maxima ladder substantially easier, and
support focused perfect hunting. These are distinct goals, not merely a small
increase in general Luck. Proposed effects, rates and limits await review;
natural discovery, legal measurements and Fihs/Sock exceptions still apply.

Boats and nets remain broad, multi-result fishing. Developed haul equipment
should make a few trips worthwhile for player XP, coins from selling fish, and
crafting materials from filleting fish. Two boat trips plus two net trips are
a useful short-session economy benchmark. Current base counts yield 28 pulls
(two times ten plus two times four), not 24 guaranteed fish: junk and treasure
can replace fish results. Future catch counts and rewards remain open tuning.

### Buildcraft First, Optional Focus Steering

The direction is approachable ARPG-style gear experimentation across affixes,
bait, mastery, reinforcement and signature powers, together with an optional
selection for stronger focus on a progression goal. Build identity and investment
remain meaningful, but optimisation is optional rather than required homework.

A focus selector should steer an invested build rather than replace affixes or
make every rod equally effective. Ranks, Maxima and Perfects are candidate goals,
not a finalized interface or approved package of free bonuses. Proposed selector
effects, eligibility and interactions await review; unlocks and costs remain
open. Species stays random; choosing a progression goal does not select a fish species.

The [build-and-interaction draft](builds-and-interactions-v0.1.md) maps
illustrative builds and shared effect budgets. Maxima Priority is accepted as
an ordinary rod-affix direction, using an affix slot. The proposed exact
effects, values and focus/bait rules await review; no numerical tuning is approved.

### One Active Main Loadout

An angler has one usable active main loadout, with one equipped item per
supported equipment slot: currently rod, boat and net, and future slots only
when explicitly designed. Extra instances and saved presets are alternatives,
not extra concurrent equipment or additional activity slots.

When an item is committed to a raid, the active equipment loadout cannot be
changed or switched until that raid commitment ends. Enforce this for direct
equipment changes as well as preset activation; a second boat or another
loadout cannot bypass the boat reservation.

Rod fishing and net activities remain available with the already selected gear.
Ordinary bait consumption and gameplay rewards are not loadout switches. The
lock and its release must follow confirmed authoritative raid state, not a
browser timer or a preset-local flag.

### Material Crafting Needs Rebalancing

Material crafting and refining need adjustment, with a stronger and more
rewarding route as the direction. Review normal fish-processing yields,
normal-to-F conversion, higher-grade refining throughput and Forge costs
together. No specific recipe, conversion ratio or reward increase is chosen.

Fish processing provides another material source, not a way to bypass raid
access gates. Higher-grade material crafting/refining remains unlocked through
raid clears. Retain the current shared destination-grade unlock principle:
a qualifying ordinary raid clear at that grade unlocks it across species;
players need not clear every boss to access the shared crafting grade.

This supersedes the earlier proposal to remove raid-clear prerequisites for a
fully non-raiding refinement ladder. Material conversion itself still awards
no raid seals, completion statistics or reinforcement eligibility.

The [processing and material economy draft](processing-and-material-economy-v0.1.md)
now supplies candidate recipes and explicit arithmetic comparisons for review.
These do not approve yields, fees or access defaults, and do not establish
daily affordability.

### Affix Funding And Extraction

Ordinary affix tiers use raid materials. Exact material quantities, other costs
and the grade-to-tier mapping remain to be designed. A direct T1-to-F through
T10-to-UUR mapping is a working proposal, not an approved recipe.

Dismantling an eligible item returns matching Vault resources for all its
affixes, including dormant affixes, rather than selecting only one to recover.
Quantities and tier/value handling remain open.

Signature extraction is the competing route: consume an eligible item to learn
its signature in the permanent Reliquary, instead of recovering its affixes.
Do not grant both the signature and affix-shard outcomes from the same item.
Any ordinary-material refund is a separate economy decision, not an implicit
extra award. Protected originals and favorites retain their safeguards.

The permanent Reliquary library and same-ID deduplication are accepted; the
three Rod / Haul / Waters slots are a proposed layout, not an approved count.

Boss accepted the four-action Forge flow and retained-progress direction on
November 1, 2026: Add Affix, guaranteed Upgrade Tier, guaranteed visible Improve
Roll, and directly chosen Change Affix that preserves tier and relative roll
finish. The [Forge and refinement draft](forge-and-refinement-v0.1.md) records
that flow; detailed conversion and permission contracts remain open.

The [first Forge cost model](forge-cost-model-v0.1.md) proposes recipes for
comparison only. Its shard counts, grade mapping, material quantities, recovery
yields and all coin prices are not approved balance.

### Junk Needs a Yield Spread

Junk rewards should have a variable quantity spread rather than always using
one fixed unit, so percentage modifiers can meaningfully improve output.
The range, distribution and fractional-rounding policy remain to be designed.
A spread alone does not guarantee that small percentage bonuses survive
per-result integer rounding.

This concerns reward quantities. It does not by itself authorize changing the
biome's fish/junk/treasure encounter probabilities.

## Current Code Baseline

Reference revision reviewed for this import: `fb486d3a`.

Consistency audit at `304a1070`: `content/`, `crates/game-rules/` and
`spacetimedb/` are unchanged from the `fb486d3a` source review. This is not a
production-state check.

- The ordinary personal raid ladder is 54 active hours per species, not 108.
  Read the exact schedule from [the raid catalog](../../content/raids.json).
  Raids and website-only raid management are already live.
- Trophy Chum currently adds 2,500 basis points of rarity Luck and supplies
  eight measurement attempts when stocked and used. Two stocked Trophy Chum
  slots combine to 5,000 basis points and sixteen attempts. The 25% Luck stat
  is not a 25% measurement-bait activation probability.
- Current catalog maxima are 12,000 Luck bp for rods and 26,600 for boats.
  The local [uncapped formula](../luck-scaling.md) removes those validation
  ceilings while preserving existing odds. This is not yet a production
  deployment. XP and Power limits are unchanged; Luck affix proposals must
  be retested against the new curve rather than copied as approved values.
- Ownership and equipped references still use equipment definitions rather
  than duplicate instances. The existing raid reservation is per angler.
- Existing material refining uses 60 input for 50 output plus five coins per
  batch and requires a destination-grade clear. These are current rules,
  not approved future Tideforge tuning.
- Ordinary junk currently awards one rusted tin per result. Filleting,
  equipment affixes, gear-family mastery, gambling, the Vault, the Affix Codex
  and the Reliquary are proposed new systems, not implemented Tideforge features.

## Still Open Before Implementation

- Full affix/signature definitions, including the accepted Maxima Priority
  direction, all ten tier ranges, proc semantics, compatibility, stacking and
  revised effective-stat limits.
- Family roles and the degree of competitiveness expected from older gear.
- Mastery XP curves and slot levels; trader stock-versus-roll interaction,
  tier ranges, the exact visit window within the monthly cadence, purchase
  permissions, inventory limits and new-copy starting quality/reinforcement.
- Per-grade raid and achievement Mark payouts.
- Exact processing, crafting, refining, junk-yield and shard recipes, including
  rounding, recovery quantities and tier/value handling, and
  natural-versus-forged Codex progress.
- Resolution of companion differences such as Attuned Fortune's inclusion,
  Attuned Wisdom's values and overlapping Focus/measurement effects.
- Instance migration, pending quotes, active-activity snapshots and loadout-lock
  boundaries, with idempotent activation and no loss of existing ownership.
- Progression simulations using around 24 total mixed-method fishing actions
  and normal raid check-ins as the accessible baseline, alongside heavier play.
  Earlier three-to-four-year language is background aspiration, not a mandate
  to slow players down or a verified completion forecast.

Mastery and gambling defaults should be proposed for review, not left for Boss
to invent percentage by percentage. The sources, currency, pricing equivalence
and raid-ladder budget above are settled; propose the remaining curves, unlocks,
eligibility, trader interaction and payout details. Favour automatic use-based
mastery, legible choices and useful recovery value from unwanted gear.

## Preservation and Scope

Design the full interconnected system; implementation can proceed in reviewed
stages after the contracts and decisions are settled. Keep complex gear and
crafting management on the website; do not restore Discord raid commands.

Use additive, owner-authorized migration and isolated preservation proofs.
Protect existing accounts, items, quality, reinforcement, collections, records,
currencies and booked activities. Git is the source-history mechanism. Boss has
opted out of automatic deployment backups; no backup or recovery archive is
authorized by these documents.
