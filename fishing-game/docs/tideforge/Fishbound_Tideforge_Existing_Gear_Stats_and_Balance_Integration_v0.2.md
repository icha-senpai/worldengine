# Fishbound Tideforge — Existing Gear Stats, Caps & Balance Integration

**Document:** Existing-stat preservation and adjustment specification · v0.2 · October 30, 2026  
**Project:** Fishbound, `icha-senpai/worldengine`  
**Companion document:** `Fishbound_Tideforge_Biome_Affinity_and_Resonance_v0.2.md`  
**Parent design:** `Fishbound_Tideforge_System_Design_v0.1.md`  
**Status:** Tables/formulas were historically verified against commit `8cbd208`; see the [decision index](README.md) for the later source-review baseline. Future bonus amounts, cap expansion and mastery stat milestones are **proposed**, not approved final balance. This is not a production-state check or deployment authorization.

> Repository working copy; the Desktop original is unchanged. The [current decision index](README.md) governs these proposals where older wording differs. Current code is a reference baseline, not a final Tideforge tuning contract. Proposed values remain open unless explicitly approved.

> Older numerical effect budgets here are historical; use the [proposed effect contract](build-contract-v0.1.md#6-proposed-effect-contract-and-build-comparisons) for current comparisons. Its values remain unapproved and unimplemented.

> **Core rule:** Tideforge must **add to** Fishbound's existing rods, boats, nets, quality upgrades, bait, raid reinforcement and RNG. It must not overwrite their identity, quietly devalue prior investment, or turn meticulously rare catches into a button press. Existing behavior is the reference baseline; separately approved balance changes may adjust it.

Tideforge is a simple, fun and forgiving gear layer. ARPG inspiration means optional bonus combinations, not required expertise or punishing build traps. Ordinary choices should remain useful. More active players may progress faster; do not add friction or permanent bricking to enforce a multi-year completion date.

## 0. Decisions and assumptions

- **EXISTING / VERIFIED:** Seven equipment families with rods, boats and nets; the exact base values, Common–Prismatic quality increments, relevant hard limits and current Rust formulas below.
- **DECIDED:** Original gear, quality investment, rod reinforcement and collection progress survive; all Camp-bought and Mark-gambled copies share **family mastery** but not individual-instance upgrades; a gear instance has primary innate biome affinity plus optional amplified affinity affixes.
- **DIRECTION:** Family mastery should add a sense of incremental growth. Small raw-stat boosts **plus** large customization unlocks were preferred conversationally, but the exact raw-stat gains are **not finalized**.
- **PROPOSED:** Stat budget allocation, future caps, affix category rules, signature effects and progression pacing. These need distribution and time-to-completion simulations.

## 1. Existing stats and what they mean

| Stat / layer | Used by | Current role | Tideforge treatment |
|---|---|---|---|
| **Rod Power** | Rods | Drives the second/bonus pull chance: 50 bp per Power. | Preserve formula initially; carefully budget any new Power/bonus-pull improvements. |
| **Rarity Luck** | Rods, boats, nets | Reweights ranks within eligible fish tickets. **Not** an absolute UUR chance and **not** the junk/treasure chance. | Existing gear bonus plus quality, bait where applicable, mastery/affixes/affinity under explicit limits. |
| **Player XP bonus** | Rods, boats, nets | Multiplies eligible XP from fishing actions. | Preserve existing bonus; distinguish from new **gear-family mastery XP**. |
| **Quality** | Each owned item | Common → Prismatic, guaranteed material recipes, bonuses. | Keep independently per gear instance; copies do not inherit quality. |
| **Rod reinforcement** | Rods | Raid-earned Collection Focus improvement and authored eligible perfect-roll opportunities. | Preserve per owned original and migrate to per-instance ownership safely. |
| **Bait** | Rods only | Separate luck/XP/measurement effects; specific bait has its own rules. | Co-design bait and affixes; keep rod bait separate from boat/net actions. |
| **Boat/net timed action** | Boats/nets | 10-min/10-result boat and 4-min/4-result net, captured start-state. | Preserve activity identity; snapshot new affixes and modifiers at start. |

Important distinctions:

Bait and affixes are complementary sources of effects. Bait, affixes, or both may be adjusted through explicit balance work. Existing bait behavior is the reference baseline, not a promise that it can never change; preserve earned progress and saved results while defining the combined effect rules.

1. A rod's **Power** can increase *how many pulls a cast yields*; it does **not** directly define the rarity of each pull.
2. **Rarity Luck** is measured in **basis points**. `+1% Luck` means `+100 bp` to the underlying Luck bonus, **not** +1 percentage point of UUR catch probability.
3. **XP bonuses** apply to player progression, whereas mastery bonuses apply to a distinct family mastery track; they should have different fields in the client and backend.
4. **Exact rank maxima/perfect measurements** are a distinct system; don't accidentally change the maximum-measurement sampler while updating rarity weights.

## 2. Exact existing base rods

Read from `fishing-game/content/world.json` at the pinned commit. Percentages are converted from basis points (`bp / 100`). Rod prices and licences are separate existing catalog concerns.

| Rod family | Base Power | Base Luck | Base player XP |
|---|---:|---:|---:|
| Twig | 1 | +0.0% | +0.0% |
| River | 28 | +10.5% | +14.5% |
| Marsh | 55 | +21.0% | +29.0% |
| Moonwood | 82 | +31.5% | +43.5% |
| Tide | 109 | +42.0% | +58.0% |
| Glacial | 136 | +52.5% | +72.5% |
| Abyssal | 163 | +63.0% | +87.0% |

These seven base values are the current reference baseline. No gear-mastery design should quietly rewrite `world.json` base bonuses to smuggle additional new power into the old system.

## 3. Exact existing base boats and nets

The game currently uses **4× the matching rod's base Luck and XP for boats** and **2× for nets**, with no Power or rod bait. This is a *base bonus multiplier*, not a 4× or 2× multiplication of final catch probabilities.

| Family | Boat base Luck | Boat base player XP | Net base Luck | Net base player XP |
|---|---:|---:|---:|---:|
| Twig | +0.0% | +0.0% | +0.0% | +0.0% |
| River | +42.0% | +58.0% | +21.0% | +29.0% |
| Marsh | +84.0% | +116.0% | +42.0% | +58.0% |
| Moonwood | +126.0% | +174.0% | +63.0% | +87.0% |
| Tide | +168.0% | +232.0% | +84.0% | +116.0% |
| Glacial | +210.0% | +290.0% | +105.0% | +145.0% |
| Abyssal | +252.0% | +348.0% | +126.0% | +174.0% |

Seven boats + seven nets are defined in `content/haul-equipment.json`. They each have independent ownership, equipped selection and quality upgrade level. Do not source their stats from the *currently equipped rod* at runtime; their family mapping is catalog data.

## 4. Existing quality table: Common through Prismatic

Every individual rod already supports the same guaranteed quality ladder. Boats and nets share the quality definitions but apply **2× the quality Luck/XP addition**, and **zero Power addition**.

| Quality | Level | Rod Power add | Rod Luck add | Rod XP add | Boat/Net Luck add | Boat/Net XP add |
|---|---:|---:|---:|---:|---:|---:|
| Common | 0 | +0 | +0.0% | +0.0% | +0.0% | +0.0% |
| Uncommon | 1 | +2 | +0.5% | +1.0% | +1.0% | +2.0% |
| Rare | 2 | +4 | +1.0% | +2.5% | +2.0% | +5.0% |
| Epic | 3 | +7 | +2.0% | +4.5% | +4.0% | +9.0% |
| Legendary | 4 | +11 | +3.5% | +7.0% | +7.0% | +14.0% |
| Mythic | 5 | +16 | +5.0% | +9.5% | +10.0% | +19.0% |
| Prismatic | 6 | +22 | +7.0% | +13.0% | +14.0% | +26.0% |

The `crafting.json` quality upgrade recipe costs, along with family-specific recipes, remain existing investment. The new Forge should not overwrite or relabel these quality levels as affix tiers. **Prismatic is equipment quality; T10 is an independent affix tier; UUR is a raid/rank concept.** Three different axes.

## 5. Effective totals at current Prismatic quality

These values exclude bait, new Tideforge affixes, proposed mastery gains, innate biome affinity and Reliquary powers. They are the *current game baseline*.

### 5.1 Prismatic rods

| Rod | Power | Luck | Player XP | Bonus-pull chance |
|---|---:|---:|---:|---:|
| Twig | 23 | +7.0% | +13.0% | 11.5% |
| River | 50 | +17.5% | +27.5% | 25.0% |
| Marsh | 77 | +28.0% | +42.0% | 38.5% |
| Moonwood | 104 | +38.5% | +56.5% | 52.0% |
| Tide | 131 | +49.0% | +71.0% | 65.5% |
| Glacial | 158 | +59.5% | +85.5% | 79.0% |
| Abyssal | 185 | +70.0% | +100.0% | 92.5% |

### 5.2 Prismatic boats and nets

| Family | Boat Luck | Boat XP | Net Luck | Net XP |
|---|---:|---:|---:|---:|
| Twig | +14.0% | +26.0% | +14.0% | +26.0% |
| River | +56.0% | +84.0% | +35.0% | +55.0% |
| Marsh | +98.0% | +142.0% | +56.0% | +84.0% |
| Moonwood | +140.0% | +200.0% | +77.0% | +113.0% |
| Tide | +182.0% | +258.0% | +98.0% | +142.0% |
| Glacial | +224.0% | +316.0% | +119.0% | +171.0% |
| Abyssal | +266.0% | +374.0% | +140.0% | +200.0% |

**Examples:**

- Prismatic Abyssal Rod = **185 Power**, **+70% Luck**, **+100% XP**, and **92.5% rod bonus-pull chance** before any new effects.
- Prismatic Abyssal Boat = **+266% Luck**, **+374% XP**; 10 pulls per scheduled ten-minute haul, with its original no-bait behavior.
- Prismatic Abyssal Net = **+140% Luck**, **+200% XP**; 4 pulls per four-minute haul, no bait.

## 6. Current actual formulas and limits

All current validation limits and proposed Tideforge caps are subject to change. Higher limits may be needed for ten-tier affixes and additional stats. Affix definitions, values and supporting mechanics can be revised as the combined system is developed; no replacement limits or numeric tuning are approved yet.

Prismatic Abyssal Rod with two Trophy Chums reaches 12,000 bp Luck; with two Pink XP Slimes it reaches 30,000 bp player XP. Prismatic Abyssal Boat reaches 26,600 bp Luck and 37,400 bp player XP before Tideforge.

### 6.1 Rod Power

The existing Rust helper is:

```text
bonus_pull_bp = Power × 50
Power must be <= 200
```

The backend samples the second pull with this probability. Therefore:

| Rod Power | Chance of second pull |
|---:|---:|
| 100 | 50.0% |
| 163 (Abyssal base) | 81.5% |
| 185 (Abyssal Prismatic) | 92.5% |
| 200 (current max) | 100.0% |

**Implication:** Adding generic Power affixes could rapidly saturate expensive rods. A +20 Power affix on an Abyssal Prismatic Rod would exceed the current legal 200 ceiling. Even +15 consumes the last headroom, after which any further bonus-pull improvement becomes worthless if silently clipped. Do **not** add unlimited Power through mastery or affixes as-is.

### 6.2 Rarity Luck

**Current limits from Rust:**

```text
Rods:         luck_bp <= 12,000   (+120%)
Boats/nets:   luck_bp <= 26,600   (+266%)
```

The current `graded_luck_weights` helper uses a normalizing weighted reallocation by rank score. It is **not** simply `original_rate × (1 + luck_percent)` on every rank independently, although one fixed-rarity relationship is explicitly tested. It requires valid positive weights, valid scores and safe arithmetic.

Before the new system, raw accumulation is:

```text
rod_luck_bp = rod_base_luck_bp + rod_quality_luck_bp + rod_bait_luck_bp
haul_luck_bp = haul_base_luck_bp + 2 × haul_quality_luck_bp
```

The global category probabilities (fish/junk/treasure, which vary by biome) remain independently selected and are not changed by this Luck calculation. A rarity-focused affix should never secretly turn tin probability down unless it *explicitly* modifies category weights as a separate feature.

### 6.3 Player XP bonus

```text
Rods:        xp_bonus_bp <= 30,000  (+300%)
Boats/nets:  xp_bonus_bp <= 37,400  (+374%)
XP awarded = floor(base_xp × (10,000 + xp_bonus_bp) / 10,000)
```

The Prismatic Abyssal Boat is **already at the haul XP maximum**. Therefore any future `Scholar`, mastery, innate affinity or signature modifier that increases *player XP* on that boat requires revising the limit or redesigning the bonus. A mastery-only XP modifier is different and need not consume the player-XP cap.

### 6.4 Timed hauls and raids

- `/fishboat`: one ten-minute haul for ten independently resolved pulls.
- `/fishnet`: one four-minute haul for four independently resolved pulls.
- Both preserve selected biome and captured gear/stat rules at start.
- Boats used in deployed/running raids are not available for ordinary boat hauls; rods and nets remain available, respecting their independent existing rules.
- Raid duration, grade seal progression, raid reward snapshots and special-fish perfect odds are **not** rewritten by new ordinary gear affixes.

## 7. What Tideforge adds and what it does *not* replace

| Source of strength | Applies to existing base stats? | Stored at | Notes |
|---|---|---|---|
| Base family | Yes, unchanged | Public definition | Permanent identity; no reroll. |
| Common–Prismatic quality | Yes, unchanged | Gear instance after migration | Existing resources/investments preserved. |
| Raid reinforcement | Yes, separate specialized benefit | Rod instance after migration | Do not merge with affix tiers or auto-inherit. |
| Shared family mastery | **Potential small addition** | Owner × kind × family | Raw gains proposed; slots/perks central. |
| Innate biome affinity | Conditional **new bonus** | Gear instance | Separate from four ordinary slots. |
| Ordinary affixes T1–T10 | Conditional/general **new modifiers** | Gear instance + affix | No duplicate exact IDs. |
| Signature power | New special effect | Instance, or Reliquary library | No duplicate stack if activated both places. |
| Existing bait | Yes, rod casts only | Cast loadout | Keep independent bait state; define consumption through bait/affix co-design. |

**Only family mastery is inherited by additional copies**, whether bought at Camp or obtained through Mark gambling. Quality, reinforcement, affixes, chosen primary biome and signature are their own properties for each instance.

## 8. Recommended calculation pipeline

**PROPOSED authoritative order** (Rust `game-rules` evaluation, server-side only):

```text
1. Authenticate action and identify current gear INSTANCE.
2. Load immutable base definition and the instance's quality / reinforcement.
3. Load shared family mastery and active affixes.
4. Determine context: rod cast, boat haul, net haul, raid; selected biome.
5. Resolve gear-allowed and biome-conditional effects.
6. Group effects by declared stat + stacking group; enforce exclusions.
7. Compute effective Power, Luck bp, player XP bp, mastery XP bp,
   measurement/bait modifiers, eligible resource modifiers, catch-count/timer modifiers.
8. Validate complete values against approved new per-kind caps and safe RNG rules.
9. Snapshot effective modifiers at action start where activity is timed.
10. Resolve RNG, record rewards/provenance, update mastery, emit idempotent receipt.
```

Suggested typed breakdown for UI and tests:

```text
EffectiveLuck {
  base_bp,
  quality_bp,
  bait_bp,             // rod only
  family_mastery_bp,   // if approved
  generic_affixes_bp,
  primary_affinity_bp,
  secondary_affinity_bp,
  signature_bp,
  total_bp,
  rules_version
}
```

Apply **basis-point integer arithmetic** with checked sums/products; do not use imprecise browser floating-point math for authoritative drops. Client displays a breakdown and forecast from server-approved rules, never makes the RNG decision.

### 8.1 Current versus proposed scenario

This historical illustrative budget is not a valid current-catalogue build. Attuned Fortune is an older candidate ID whose inclusion remains unresolved against the latest catalogue; it has not been silently retired or approved.

**Prismatic Abyssal Boat in Abyssal Shelf, hypothetical full luck build:**

```text
Existing base Luck                  +252.0%
Existing Prismatic quality           +14.0%
Current baseline                     +266.0%
Proposed innate primary affinity       +1.0%
Proposed T10 Resonance                 +5.0%
Proposed T10 Attuned Fortune           +3.0%
Proposed T10 general Fortune           +5.0%
------------------------------------------------
Proposed total before other bonuses  +280.0% (28,000 bp)
Current haul-Luck validator maximum  +266.0% (26,600 bp)
```

**This build is currently illegal** and cannot be made functional with a UI-only tweak. Any new cap must be intentional and tested. Mastery bonuses, signature powers and future family additions could require further headroom; do not merely set the cap to 28,000 and call the design finished.

**Prismatic Abyssal Rod in Abyssal Shelf, same example with no bait:**

```text
Existing base Luck                   +63.0%
Existing quality                      +7.0%
New example affinity/affixes         +14.0%
------------------------------------------------
Proposed subtotal                    +84.0% (8,400 bp)
Current rod-Luck validator maximum  +120.0% (12,000 bp)
```

If using a +15% Luck bait, the proposed rod example rises to **+99%**, still below the current rod-Luck ceiling. That does **not** establish that all future combinations or future rods fit. Extreme power/bonus-pull mechanics must be analyzed separately.

## 9. Stat-adjustment policy: protect the baseline

### 9.1 Baseline and preservation requirements

The following describes the current reference baseline and earned-state protections, not a blanket prohibition on explicitly approved balance changes.

1. All current rod base Power/Luck/XP values in `content/world.json`.
2. All 14 boat/net family base Luck/XP values in `content/haul-equipment.json`.
3. Existing **2× quality contribution** for boats/nets, independent of their **4×/2× base** pattern.
4. Common through Prismatic quality increments, recipes and materials.
5. Existing rod-bait behavior is the baseline for bait/affix co-design; preserve exact size/weight/collection-focus semantics unless explicitly rebalanced.
6. Current species content, encounter hierarchy, category weights and rank/measurement boundaries.
7. Existing earned quality, reinforcement, collections, rank maxima, perfect records, inventory and active activity state.

### 9.2 What needs deliberate extension

All current validation limits and proposed Tideforge caps are subject to change. Higher limits may be needed for ten-tier affixes and additional stats. Affix definitions, values and supporting mechanics can be revised as the combined system is developed; no replacement limits or numeric tuning are approved yet.

| Area | Why adjust | Required approach |
|---|---|---|
| Rod/haul Luck validators | Abyssal Boat already hits max; affix/affinity bonuses won't fit. | Simulate all combinations and authorize new safe bounds per activity; update Rust validation and proof tests together. |
| Haul player-XP validator | Abyssal Boat already reaches +374%. | Increase only if simulation justifies; alternatively put mastery bonus into **gear mastery XP** without changing player XP. |
| Power and bonus pulls | High-end rod has only 15 Power headroom. | Do not silently clamp; design caps / mutually exclusive bonuses / different top-tier effects so affixes have value. |
| Mastery rewards | Added progress may make casual gear grind too fast or introduce dead bonuses. | Separate mastery XP from player XP, measure real actions per day, grant modest capped increments. |
| Affinity-eligible Luck | Conditional by matching primary/secondary biome. | Explicit activation and stacking buckets, no hidden doubling for primary + secondary. |
| Extraction/forge snapshots | Duplicates mean family ID no longer uniquely identifies owned gear. | Migrate ownership/quotes/loadouts/raid reinforcement to instance references. |
| Stat display and compendium | Players must know why values differ by biome or instance. | Show breakdown and server-derived rates for actual loadout; avoid promising absolute rarity gains. |
| New gear/biomes | More content may exceed today’s worst-case maxima. | Keep content and caps versioned; validate every family/quality/match/candidate affix combination. |

### 9.3 What we should *not* do

- Do not nerf the existing Abyssal Boat to create room for Tideforge.
- Do not show an affix increasing Luck when its effect is silently clipped away.
- Do not use an unconditional `min(value, cap)` to make broken balance look safe without surfacing the loss of value and deliberately approving it.
- Do not multiply final UUR/perfect *probabilities* by generic gear bonuses; use the actual existing tier-weight model and explicit new effect types.
- Do not make boats or nets inherit equipped rod Power, bait, quality or reinforcement.
- Do not make gear-affix bonuses change deployed/running raid outcomes unless a separate raid-affix system is explicitly designed.

## 10. Family mastery: proposed raw-stat design

Older families retain lower base stats as part of the existing progression, but should remain reasonably comparable and respectable in useful roles. The goal is not identical stats or guaranteed best-in-slot status for every family. Evaluate affinity, mastery and affixes together while preserving earned investment.

The previous design conversation leaned toward **small raw-stat gains plus major affix-slot/customization unlocks**. Retain that direction, but don't commit a final formula before numerical analysis.

**Initial candidate:**

- **Mastery levels 10 / 25 / 50 / 75:** unlock affix slots, as defined in the Biome document; the dominant reward is increased buildcrafting potential, not simply another luck multiplier.
- **Mastery level 100:** cosmetic badge/title and a modest capped family bonus rather than another high-impact global multiplier.
- **Every 10 mastery levels:** a tiny family statistic increase if the eventual approved limits have room. For example, +10 basis points (+0.1%) to **family mastery XP gain**, not necessarily player XP or rarity Luck. This is a **candidate**, not a committed gain curve.

**Recommended dependency order:** Implement and verify mastery XP and feature unlocks *before* adding raw Power to rods or player-XP to boats inside the **complete staged build**. This is not a decision to ship a reduced slice. Once cap simulations settle, add approved raw gains through the same deterministic evaluator.

If we ultimately choose direct stats:

| Equipment kind | Candidate small gains | Special caution |
|---|---|---|
| Rod | Small Luck/XP or bonus-pull conditional benefit | At Power 185, extra Power quickly maxes second-pull chance. |
| Boat | Modest mastery XP, specialized material efficiency or small player XP | Existing +266% Luck / +374% player XP can already be at validation ceilings. |
| Net | Modest mastery XP, limited Luck/XP gains | Preserve 4-minute / 4-pull niche vs boats. |

Prefer modest gains with a clear individual identity rather than putting identical flat Luck buffs on all 21 families.

## 11. Ordinary affix families and their eligible stat targets

This is a role summary of the [current candidate catalogue](Fishbound_Tideforge_Affix_Catalogue_v0.3.md), not a second registry. Attuned Fortune survives only as an older unresolved alternative, not a new registered ID.

| Affix family | Numerical/effect target | Compatible categories | Stacking/exclusion requirement |
|---|---|---|---|
| Fortune / Resonance | Rarity Luck stat | All, with affinity conditions | Shared Luck budget; not absolute UUR chance. |
| Scholar | Player XP bonus | All | Relevant player-XP budget; distinct from mastery XP. |
| Old Salt / Surveyor / Attuned Wisdom | Gear-family mastery XP | Appropriate kinds; Surveyor boat-only | Separate mastery economy; once-per-action/trip credit. |
| Line Tension | Rod Power / bonus-pull mechanics | Rod only | Budget current Power headroom and combined nonrecursive pulls. |
| Chum Catalyst / Weight Weaver | Proposed bait synergy / measurement | Rod only | Current activation assumption unresolved; co-design with real bait rules. |
| Trophy Lens / Measured Cast | Legal measurement attempts | Rod only | Preserve rank/maxima rules and shared attempt budgets. |
| Focus Tuner / Attuned Focus / Maxima Priority | Missing-rank or unfinished-maxima assistance, as defined per effect | Rod only | Keep Focus and maxima eligibility distinct; species remains random. |
| Deep Trawler / Overflow Knot | Extra haul pulls | Boat / net respectively | One nonrecursive per-kind pull budget. |
| Efficient Hull / Quick Set | Shorter ordinary haul time | Boat / net | No raid timing effect; scheduled job safety. |
| Scavenger / Wreckbreaker / Silt Sifter / Attuned Harvest | Junk salvage or ordinary processing yield, as defined per effect | Appropriate kinds | Separate salvage/fillet/scale budgets and catch-pinned yield. |
| Secondary Affinity | Lesser bonus in another biome | All | One exact ID; different from primary biome. |

Some affix proposals overlap in function; the final catalog should remove redundancy or explicitly define the slot-cost tradeoffs. A proper catalogue needs **effect IDs, supported kinds, tier ranges, roll rarity weights, stacking group, exclusions and test cases** per entry.

## 12. What should happen to quality, reinforcement and the original item

**Quality:** Each instance can independently progress from Common to Prismatic; the existing quality recipe is the current reference baseline. Existing upgraded original maps to exactly one new instance with that unchanged quality. An additional Camp-bought or Mark-gambled copy does **not** automatically become Prismatic because another copy is Prismatic.

**Reinforcement:** Existing raid-earned `rod_reinforcement` is currently keyed using player ID and rod definition. Duplicate rods make that insufficient. The migrated design must associate reinforcement to a unique owned rod instance, with the old original retaining its actual level. This integration draft proposes that new additional rods begin unreinforced; starting quality and reinforcement rules require a final contract.

**Shared mastery:** Exactly one owner/kind/family mastery track is referenced by all copies. The level is never duplicated into independent XP streams. A new additional copy benefits from already-unlocked mastery slots, while its affixes remain instance-specific.

**Original locks:** No protected original can be consumed by extracting a signature or dismantling affix-bearing gear. Originals can be **recrafted**, **upgraded**, and **reattuned** through explicit safe actions; no implicit loss of records or history.

Dismantling an eligible item returns matching Vault resources for all its affixes, including dormant affixes. Signature extraction is the competing choice: consume an eligible item to learn its signature in the permanent Reliquary instead of recovering its affixes. Do not grant both signature and affix-shard outcomes from the same item. Resource quantities, tier/value handling and any ordinary-material refund remain open. Protected originals and favorites retain their safeguards.

## 13. Fishing catch and filleting integration

Ordinary affix tiers use raid materials. The [Forge flow](forge-and-refinement-v0.1.md) is accepted; [Forge costs](forge-cost-model-v0.1.md) and [processing recipes](processing-and-material-economy-v0.1.md) propose quantities and yields, not approved values. Fish processing is another material source, not a bypass of raid access gates. Higher-grade material crafting/refining retains the shared destination-grade ordinary raid-clear unlock. Conversion itself grants no seals, completion statistics or reinforcement eligibility.

Junk rewards should have a variable quantity spread so percentage bonuses can meaningfully improve output. The range, distribution and fractional-rounding policy remain open; a spread alone does not prevent small bonuses being lost to integer rounding. This changes reward quantities, not biome encounter-category probabilities by implication.

The player can **sell, keep or fillet** an eligible owned fish. Ordinary filleting produces normal fillets and scales, which may be converted into F-grade raid materials and then refined up the material ladder with the required raid-grade unlocks.

Stat effects on filleting must obey:

- Catch/haul-time gear and biome determine eligible `Attuned Harvest` bonuses; filleting-time gear does **not** retroactively amplify older fish.
- High-rank/large fish can be worth more materials if desired, but yields must be normalized and modeled so low-tier vs giant species don't create runaway sources.
- Fishing/filleting materials should be a meaningful alternative to raid materials, **not** a disguised way to earn raid seals, raid completion counts or raid-reinforcement eligibility.
- Preserve existing inventory protection, favorite tags, rank maxima and historical records after physical fish consumption.
- Show deterministic expected fillet/scales yield before bulk filleting; apply an atomic transaction per batch.

Higher-grade crafting/refining retains the shared destination-grade ordinary raid-clear unlock; a qualifying clear at that grade unlocks access across species. Conversion grants no raid seals, completion statistics or reinforcement eligibility.

## 14. Interface: make the complexity readable

Older illustrative gear card; Attuned Fortune is not in the current candidate catalogue:

```text
ABYSSAL BOAT · Prismatic · Level 82 family mastery

EXISTING FISHING STATS
  Base Luck                 +252.0%
  Quality Luck               +14.0%
  Base/Quality XP           +374.0%

CONDITIONAL TIDEFORGE STATS (Abyssal Shelf)
  Innate biome Luck           +1.0%  [proposed]
  T10 Resonance               +5.0%  [proposed]
  T10 Attuned Fortune         +3.0%  [proposed]
  T10 Fortune                 +5.0%  [proposed]
  Effective Luck            +280.0%  [requires new approved validator]

ACTIVITY
  Ordinary haul: 10 pulls / 10 minutes
  Bait: not applicable
  During raid: ordinary haul unavailable, affinity does not boost raid payout
```

Display the full source breakdown and final approved effective value, not simply a large total with unexplained color. Provide *server-side probability forecasts* if the client shows chances: resolve the actual selected biome, all eligible ranks, the current rules version, attunement, bait and rarity distribution.

For rod cards, separately display **Power**, **second-pull chance**, **Luck**, **player XP**, **mastery XP** and the chance/roll nature of measurement-related bonuses. For hauls, display equipment category, existing pulls/minutes, potential optional catch/timer affixes, and the snapshot rule.

## 15. Backend architecture and migration work

### 15.1 Required Rust / SpaceTimeDB changes

1. Introduce an immutable *definition* vs mutable *instance* split for rods, boats and nets.
2. Add private instance-affix data and a per-family mastery record; expose caller-scoped views for web and Discord adapters.
3. Add a deterministic server-side evaluator that combines base, quality, bait, reinforcement, mastery, affinity and eligible affixes.
4. Extend validators **only after approved simulation**; update both rod and haul rule paths, not just one caller.
5. Preserve existing RNG category weights, legitimate rarity redistributions, measurement sampling and rare/special encounter invariants.
6. Add replay-safe crafting/gambling/affinity receipts; stale quote and wrong-instance protections.
7. Store immutable effective modifier snapshots for new boat/net hauls; keep old snapshot rows compatible.
8. Save catch provenance when needed for future material-yield processing.
9. Pin active raids to their original boat instance safely; forbid destruction and loadout-switch loopholes during commitment.
10. Provide browser and native Discord-client bindings for new caller-scoped tables/reducers; never grant blanket access to another player's inventory.

An angler has one active main equipment loadout, with one item per supported slot. Duplicate instances and saved presets do not create additional activity slots. An item committed to a raid locks changes to that active equipment loadout until the commitment ends, including direct equipment changes and preset switching. A second boat cannot bypass the reservation. Rod fishing and net activities remain available with the already selected gear.

### 15.2 Existing files likely impacted

| Area | Existing implementation reference |
|---|---|
| World base rods and biomes | `fishing-game/content/world.json` |
| Base boat/net bonuses | `fishing-game/content/haul-equipment.json` |
| Quality / bait / recipes | `fishing-game/content/crafting.json` |
| Server rod rule math | `fishing-game/crates/game-rules/src/rods.rs` |
| Server haul rule math | `fishing-game/crates/game-rules/src/hauls.rs` |
| Rod cast evaluation | `fishing-game/spacetimedb/src/casting.rs` |
| Haul snapshot and settlement | `fishing-game/spacetimedb/src/hauls.rs` |
| Existing ownership schemas | `fishing-game/spacetimedb/src/tables.rs` |
| Current raid reinforcement, boat lock | `fishing-game/spacetimedb/src/raids.rs` |
| Web Tackle Box | `fishing-game/apps/web/src/lib/game/Gear.svelte`, `Dashboard.svelte` |
| Existing odds/compendium UI | `fishing-game/apps/web/src/lib/game/Compendium.svelte` and supporting modules |
| Client bindings / subscriptions | `fishing-game/packages/generated`, native Rust generated bindings, Svelte subscriptions |

The file list is a **touch-point checklist**, not a command to rewrite all files. Use the current repository state and existing integration patterns rather than assuming an older code location remains unchanged.

### 15.3 Data preservation requirements

- One existing ownership row converts into **one protected original instance**, precisely once. No duplicate originals on rerun.
- Existing quality and current rod reinforcement map to original instance; all additional copies start with independent investment.
- Preserve equipped rod/boat/net selections when converting definition IDs to instance IDs.
- Preserve all coins, tin, scrap, bait, fish inventory, collection/history, maxima, player XP, cooldowns and active scheduled actions.
- Old timed hauls already contain captured gear/quality/Luck/XP and must settle without rereading changed live equipment; verify prior-version compatibility.
- Existing raid jobs keep captured boat metadata and deadlines; do not move, cancel or rewrite scheduled raids during activation.
- Roll out through additive migrations, preservation proofs, owner-authorized activation, versioned clients and a reversible feature switch; **no data reset**.

## 16. Probability and progression proof plan

**Stat correctness matrix:** All 21 family/category combinations × 7 qualities × all 7 current biomes × representative affix builds (none, general, primary, secondary, stacked max) × eligible bait combinations for rods. Separately test all 10 raid reinforcement grades. Use catalogs rather than only hardcoded fixtures.

**Properties to assert:**

1. Zero Tideforge effects produce the reference result for Luck, XP, Power, second pull, rank distribution and measurement odds, except for separately approved and explicitly tested balance changes.
2. All rarity ticket weights are positive and normalized exactly as expected; no overflows or invalid pools for any legal loadout.
3. Fihs/Sock rarity hierarchy and authored special raid odds remain protected.
4. Maximum rod Power cannot produce more than the intended number of pulls; no invalid >100% chance.
5. Added player-XP and mastery XP are separately bounded and recorded exactly once per committed action.
6. Boat/net bonuses do not accidentally use rod bait, Power, owned rod quality, or rod reinforcement.
7. Haul outputs and item material provenance cannot be retroactively modified by swapping/reforging gear.
8. Raid commitment locks the active equipment loadout, including direct equipment changes and preset switching; normal rod/net activities remain available with the selected gear.
9. All prior owned original gear preserves quality, reinforcement and selected loadout exactly after the migration.
10. A new equipment instance inherits family mastery only, not other owned instances' upgrades or affixes.
11. Every displayed stat matches the actual server evaluator for a selected equipment/biome/bait profile.
12. Affix cap headroom never silently wastes a purchased or forged bonus.

The accessible baseline is around 24 total fishing actions/casts per day in any mixture of rod, net and boat activity, plus roughly one or two raids or raid check-ins as booked durations allow. This is not 24 mandatory rod casts plus extra required hauls. More activity may accelerate progress. Earlier three-to-four-year language is background aspiration, not a mandate to slow players down. Distinguish actions from result counts; long raids can span daily check-ins.

**Economy models:** Include optional heavy-activity stress cases, the exclusive boat-vs-raid choice, fish filleting opportunity costs and progressive mastery. Model both balanced RNG and long-tail scenarios with rare outcomes. Mastery should naturally overlap ordinary play.

## 17. Final open balance choices

Before implementation can treat these as final requirements, settle:

Propose simple mastery and gambling defaults for review; exact curves, access, recipes and payout details remain open within the accepted [Mark price schedule](build-contract-v0.1.md).

1. Exact effective Luck / XP validator bounds for rods, boats and nets under maximum supported builds.
2. Whether family mastery grants **direct** Power, Luck or player XP, or primarily mastery XP and unlocks, especially at upper-end gear.
3. Whether the older Attuned Fortune alternative should join general Fortune and Resonance as a distinct meaningful slot.
4. Numerical T1–T10 affix value bands by effect, and any total budget per item / activity.
5. Power/bonus-pull affix mechanics and how top rods avoid saturation/dead rolls.
6. Fixed vs rolled signature power categories, per-kind allowed scopes, and non-stacking rules.
7. Gear-XP gain formulas for casts, timed hauls, and optional raid completion credit.
8. Final Forge recipes and individual raid-grade and achievement Mark payouts; family gambling prices are settled in the [build contract](build-contract-v0.1.md).
9. Final fillet/scale yields and higher-grade material conversion costs, while retaining the shared raid-grade unlock and preserving raid rewards.
10. Acceptable increase in UUR and perfect acquisition rates and full-game completion time under each simulated playstyle.

## 18. Source snapshot

Historical snapshot verified against commit [`8cbd208`](https://github.com/icha-senpai/worldengine/commit/8cbd2088a56c2c7763cc0425ad646d17f9a64e71) (October 30, 2026). Reverify before development if the repo has moved.

Current source reviewed for this import: fb486d3a; see the decision index for later clarifications.

- [World: biomes and rods](https://github.com/icha-senpai/worldengine/blob/8cbd2088a56c2c7763cc0425ad646d17f9a64e71/fishing-game/content/world.json)
- [Boats and nets](https://github.com/icha-senpai/worldengine/blob/8cbd2088a56c2c7763cc0425ad646d17f9a64e71/fishing-game/content/haul-equipment.json)
- [Quality and bait](https://github.com/icha-senpai/worldengine/blob/8cbd2088a56c2c7763cc0425ad646d17f9a64e71/fishing-game/content/crafting.json)
- [Rod math](https://github.com/icha-senpai/worldengine/blob/8cbd2088a56c2c7763cc0425ad646d17f9a64e71/fishing-game/crates/game-rules/src/rods.rs)
- [Boat/net math](https://github.com/icha-senpai/worldengine/blob/8cbd2088a56c2c7763cc0425ad646d17f9a64e71/fishing-game/crates/game-rules/src/hauls.rs)
- [Rod casting](https://github.com/icha-senpai/worldengine/blob/8cbd2088a56c2c7763cc0425ad646d17f9a64e71/fishing-game/spacetimedb/src/casting.rs)
- [Haul snapshot/settlement](https://github.com/icha-senpai/worldengine/blob/8cbd2088a56c2c7763cc0425ad646d17f9a64e71/fishing-game/spacetimedb/src/hauls.rs)
- [Existing gear ownership](https://github.com/icha-senpai/worldengine/blob/8cbd2088a56c2c7763cc0425ad646d17f9a64e71/fishing-game/spacetimedb/src/tables.rs)
- [Raid and reinforcement logic](https://github.com/icha-senpai/worldengine/blob/8cbd2088a56c2c7763cc0425ad646d17f9a64e71/fishing-game/spacetimedb/src/raids.rs)

---

**Handoff rule:** Treat the existing tables and formulas as a preservation baseline. Treat new affinities/affixes and final caps as explicit design work to model and approve. No undocumented formula changes, no silent clipped affixes, and no destruction or resetting of existing player progress.
