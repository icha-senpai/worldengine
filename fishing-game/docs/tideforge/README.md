# Tideforge Design Workspace

Status: design and discussion only. These documents do not authorize gameplay
changes, migrations, commits, or production deployment.

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

## Decision Precedence

Boss's clarifications in this index govern the working copies where older
drafts disagree. Current source is the authority for what the game implements
today; design proposals describe future behavior, not existing functionality.
The latest affix catalogue is a candidate list, not blanket approval of its
numbers or unresolved differences from its companions.

Do not turn an old example, proposed cap or historical "agreed" label into a new
implementation decision without checking this index and the remaining questions.

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

### Bait and Affixes Are Designed Together

Bait is an additional source of effects alongside gear affixes. Bait, affixes,
or both may be adjusted so they complement each other. This does not mean that
every effect stacks by simple arithmetic: individual effects still need defined
units, eligibility, precedence, stacking groups and limits.

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
increase in general Luck. Exact effects, rates and limits remain to be designed;
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
not a finalized interface or approved package of free bonuses. Exact selector
effects, eligibility, unlocks, costs and interactions remain open. Species stays
random; choosing a progression goal does not select a fish species.

The [build-and-interaction draft](builds-and-interactions-v0.1.md) maps
illustrative builds and shared effect budgets. Maxima Priority is accepted as
an ordinary rod-affix direction, using an affix slot. Its exact effects, values
and focus/bait contracts remain open; no numerical tuning is approved.

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
affixes, rather than selecting only one affix to recover. Quantities and
tier/value handling remain open.

Signature extraction is the competing route: consume an eligible item to learn
its signature in the permanent Reliquary, instead of recovering its affixes.
Do not grant both the signature and affix-shard outcomes from the same item.
Any ordinary-material refund is a separate economy decision, not an implicit
extra award. Protected originals and favorites retain their safeguards.

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

- The ordinary personal raid ladder is 54 active hours per species, not 108.
  Read the exact schedule from [the raid catalog](../../content/raids.json).
  Raids and website-only raid management are already live.
- Trophy Chum currently adds 2,500 basis points of rarity Luck and supplies
  eight measurement attempts when stocked and used. Two stocked Trophy Chum
  slots combine to 5,000 basis points and sixteen attempts. The 25% Luck stat
  is not a 25% measurement-bait activation probability.
- Prismatic Abyssal plus two Trophy Chums reaches the current rod Luck limit;
  two Pink XP Slimes reach the current rod XP limit. Prismatic Abyssal Boat
  reaches the current haul Luck and XP limits before Tideforge bonuses.
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
- Mastery XP curves, gambling access, event schedule, currency, inventory
  constraints and rolled-item starting quality/reinforcement rules.
- Exact processing, crafting, refining, junk-yield and shard recipes, including
  rounding, extraction outcomes and natural-versus-forged Codex progress.
- Resolution of companion differences such as Attuned Fortune's inclusion,
  Attuned Wisdom's values and overlapping Focus/measurement effects.
- Instance migration, pending quotes, active-activity snapshots and loadout-lock
  boundaries, with idempotent activation and no loss of existing ownership.
- Progression simulations using around 24 total mixed-method fishing actions
  and normal raid check-ins as the accessible baseline, alongside heavier play.
  Earlier three-to-four-year language is background aspiration, not a mandate
  to slow players down or a verified completion forecast.

Mastery and gambling defaults should be proposed for review, not left for Boss
to invent percentage by percentage. Favour automatic use-based mastery,
legible choices and useful recovery value from unwanted gear. Exact unlocks,
currencies, rates and trader availability remain open.

## Preservation and Scope

Design the full interconnected system; implementation can proceed in reviewed
stages after the contracts and decisions are settled. Keep complex gear and
crafting management on the website; do not restore Discord raid commands.

Use additive, owner-authorized migration and isolated preservation proofs.
Protect existing accounts, items, quality, reinforcement, collections, records,
currencies and booked activities. Git is the source-history mechanism. Boss has
opted out of automatic deployment backups; no backup or recovery archive is
authorized by these documents.
