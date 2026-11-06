# Fishbound Tideforge — Affix Catalogue & Signature Powers

**Document:** Design v0.3 · October 31, 2026  
**Project:** Fishbound (`icha-senpai/worldengine`)  
**Companion specs:** `Fishbound_Tideforge_System_Design_v0.1.md`, `Fishbound_Tideforge_Biome_Affinity_and_Resonance_v0.2.md`, `Fishbound_Tideforge_Existing_Gear_Stats_and_Balance_Integration_v0.2.md`  
**State:** **Design candidate, not a deployment approval.** Catalogue effects and most numeric values are proposed, not yet accepted as a tuned production economy.

> Repository working copy; the Desktop original is unchanged. The [current decision index](README.md) governs these proposals where older wording differs. Current code is a reference baseline, not a final Tideforge tuning contract. Proposed values remain open unless explicitly approved.

> **Core principle:** The original gear stays valuable. Players chase better rolled instances, specialize across biomes and activities, and deterministically develop affixes over time. Each route contributes to optional long-term progression without turning existing fishing achievements into trivial outcomes.

## 0. Design authority, baseline, and scope

Tideforge is a simple, fun and forgiving gear layer. ARPG inspiration means optional bonus combinations, not required expertise or punishing build traps. Ordinary choices should remain useful. More active players may progress faster; do not add friction or permanent bricking to enforce a multi-year completion date.

### Agreed foundations

- The accessible baseline is around 24 total fishing actions/casts per day in any mixture of rod, net and boat activity, plus roughly one or two raids or raid check-ins as booked durations allow. This is not 24 mandatory rod casts plus extra required hauls. More activity may accelerate progress. Earlier three-to-four-year language is background aspiration, not a mandate to slow players down. Distinguish actions from result counts; long raids can span daily check-ins.
- The ordinary **F → UUR raid ladder has been shortened to a total of 54 hours per species**, replacing the older 108-hour total. Across 249 ordinary species, theoretical sequential raid uptime is 13,446 hours = 560.25 days before resource farming, deployment gaps, and scheduling. Many players are expected to finish one ladder first, then progress on other bosses opportunistically. The precise grade-by-grade revised schedule must be read from the current game catalogue, not reconstructed by halving each old value blindly.
- One protected original of each gear family starts vanilla; Camp coin purchases and Mark gambling may add extra **instances**. Originals and all additional copies share **owner + equipment-kind + family mastery**, but copies do not inherit another instance's quality, reinforcement, biome, affixes, or signature.
- Preserve base Power/Luck/XP, Common→Prismatic quality, and existing rod raid reinforcement. Innate primary biome affinity is separate from the **four ordinary affix slots** in the working layout. Individual ordinary affixes have **T1–T10** progression and independently improvable rolls.
- The Forge directly adds a chosen T1 affix, guarantees tier advancement and visible roll improvement, and directly changes type while retaining tier and paid roll finish. The Shard Vault holds extracts; the Codex permanently tracks discovery and perfection; the Deepwater Reliquary stores extracted signature powers.
- Fish filleting provides another material source; higher-grade crafting/refining retains the shared destination-grade raid-clear unlock. Conversion does not grant raid seals or reinforcement eligibility.
- The permanent Reliquary library and same-ID deduplication are accepted. **Rod / Haul / Waters** is a proposed layout, not an approved slot count.

### Proposed, still negotiable in this version

The **39-affix and 24-signature candidate pools, all values below, slot budgets, effect caps, proc order, signature extraction chances, and category exclusions** require design review and simulation. Maxima Priority's role is accepted; its mechanics and values remain open. Do **not** silently treat this document as contradicting approved earlier rules; discrepancy notes are in §12.

### Existing repo constraints verified from the October 30 commit

- Existing seven waters: Meadow Pond, Whispering River, Hollow Marsh, Moonlit Lake, Sunken Coast, Glacial Reach, Abyssal Shelf.
- Rods have Power, Luck, XP; boats and nets have Luck and XP. Rod Power currently yields **50 basis points of bonus-pull chance per Power**, capped at 200 Power. Prismatic Abyssal Rod already reaches **185 Power** before new affixes/mastery; allowing arbitrary new Power would need explicit capping and disclosure.
- Current rod rarity-luck validation is **12,000 bp**; haul validation is **26,600 bp**. Prismatic Abyssal Boat reaches **26,600 bp** already. New luck must have a deliberately extended, mathematically safe budget; it must not be silently discarded or shoved through incompatible validators.
- Boat/net ordinary hauls are **10 pulls/600 sec** and **4 pulls/240 sec** respectively, with no bait, and their effective stats are snapshotted at deployment. Boat raid commitment excludes normal boat fishing.
- Existing category weights (fish/junk/treasure), species pools, legal rank bands, natural measurements, Collection Focus, bait behavior, and special Fihs/Sock rules remain authoritative absent a separately reviewed change.

Bait and affixes are complementary sources of effects. Bait, affixes, or both may be adjusted through explicit balance work. Existing bait behavior is the reference baseline, not a promise that it can never change; preserve earned progress and saved results while defining the combined effect rules.

Older families retain lower base stats as part of the existing progression, but should remain reasonably comparable and respectable in useful roles. The goal is not identical stats or guaranteed best-in-slot status for every family. Evaluate affinity, mastery and affixes together while preserving earned investment.

---

## 1. Core item anatomy

Each owned instance is conceptually:

`instance_id · owner · kind (rod/boat/net) · family_definition · protected_original · quality · reinforcement (rods) · primary_biome · signature_power? · affix_slots[0..4] · version`

Shared family mastery is stored **outside** the instance. The player may own multiple instances of one family. Affixes must be stored individually with stable affix IDs, integer tier (1–10), numerical value in fixed-point units, any option/secondary biome, provenance, and independent versioning for quote safety.

An angler has one active main equipment loadout, with one item per supported slot. Duplicate instances and saved presets do not create additional activity slots. An item committed to a raid locks changes to that active equipment loadout until the commitment ends, including direct equipment changes and preset switching. A second boat cannot bypass the reservation. Rod fishing and net activities remain available with the already selected gear.

- **No same affix ID twice on one item.** Compatible effects from *different* IDs can stack only within their common budgets.
- **At most two biome-affix slots per item** is proposed. Primary biome costs no slot. `Secondary Affinity` cannot choose the primary biome. `Attuned Focus` is rod-only because nets and boats do not have a corresponding rod-reinforcement behavior.
- Original items cannot be consumed by dismantling or signature extraction, individually or in bulk. Allowed: owner-approved crafting, strengthening, and reattunement.
- Ownership, class, biome gating, gear snapshotting, and inventory protection are checked on the SpaceTimeDB server. The client never generates authoritative rolls or currencies.

## 2. Tier semantics (all ordinary affixes)

**Ten tiers:** T1 through T10. The table values in §3 are the **proposed maximum value for a perfect roll at the named tier endpoints**. A rolled instance can have a lower value within a tier. Values in between are not approved yet; they must be stored in authored data, not guessed by a frontend interpolation algorithm.

A sensible *candidate* interpolation is linear max-value steps, rounded in natural fixed-point units (basis points or integer counts), with the minimum rolled value at each tier roughly **80% of that tier's maximum**. That 80% rule is an illustration, not an approved balance commitment. Effects such as discrete attempts and trigger chance need individually authored integer/pct grids.

**Crafting semantics:**

1. **Add / Imprint affix:** Consume appropriate stored shards + coins/materials to fill a free unlocked slot.
2. **Upgrade affix:** Guaranteed Tn → T(n+1) on resource payment; no loss of existing affix or item. Value must not decrease upon tier transition.
3. **Refine value:** Every accepted payment gives a visible increase toward the legal tier maximum. No failed refinement roll or paid no-progress result; retained roll finish survives tier upgrades and compatible type changes.
4. **Reforge type:** Directly choose a compatible replacement, retaining the slot's tier and relative roll finish while preserving other affixes.
5. **Dismantle to Vault:** Consume an eligible item to recover resources for all its affixes. Signature extraction is the mutually exclusive alternative. Ensure loss/provenance and confirmation semantics are clear.
6. **Perfect:** A T10 affix may be refined to its exact designed ceiling. Record natural T10 and forged T10 separately in the Codex.

The [Forge flow](forge-and-refinement-v0.1.md) is accepted; recipes remain open.

**Cost principle:** Higher tiers cost somewhat more, **not** an exponential wall. Ordinary affix tiers use raid materials, with costs to be designed around the mixed-activity baseline. Exact recipes and recovery quantities remain open.

A direct T1-to-F through T10-to-UUR mapping is a working proposal, not an approved recipe.

## 3. Full ordinary affix catalogue (39)

**Initial historical candidates:** the numeric and trigger tables in sections
3–5 are superseded for current comparisons by the [proposed effect contract](build-contract-v0.1.md#6-proposed-effect-contract-and-build-comparisons).
They remain here as history, not a second current authority; the new proposal
is neither approved nor implemented.

Columns describe the proposed maximum effect at T1 and T10. **`pp` = percentage points added to an event's probability**; `% stat` means a percent-style bonus added to an existing stat, not absolute rare-catch probability.

### 3.1 General (8)

**Eligibility:** Rod · Boat · Net.

| Affix | T1 max | T10 max | Exact intended effect | Shared effect bucket |
|---|---:|---:|---|---|
| **Fortune** | +0.5% Luck stat | +5% Luck stat | Adds rarity-luck basis points to the eligible activity; not a direct probability increase. | `rarity_luck` |
| **Scholar** | +1% player XP | +12% player XP | Improves player XP from eligible fishing activities. | `player_xp` |
| **Old Salt** | +2% mastery XP | +20% mastery XP | Improves account × kind × family mastery XP from qualifying usage. | `mastery_xp` |
| **Coinwright** | +2% fish sale value | +20% fish sale value | Adds a catch-pinned value modifier for fish sold later; never recalculated by swapping items. | `sale_yield` |
| **Scavenger** | +3% junk salvage | +25% junk salvage | Additional rusted tin/scrap *resources* from eligible junk; does not alter category odds. | `junk_salvage` |
| **Treasurekeeper** | +3% treasure coins | +25% treasure coins | More coins from treasure events; does not increase their encounter frequency. | `treasure_coin` |
| **Provisioner** | +1% ordinary fillets | +12% ordinary fillets | Increases ordinary fillet yield when catch is eventually processed; bonus pinned at acquisition. | `fillet_yield` |
| **Scaleweaver** | +1% ordinary scales | +12% ordinary scales | Increases ordinary scale yield for eligible fish; bonus pinned at acquisition. | `scale_yield` |

### 3.2 Rod-specific (9)

**Eligibility:** Rod.

Chum Catalyst, Weight Weaver and Baitkeeper activation wording assumes a new
mechanic. Current Trophy Chum supplies Luck and measurement attempts, not an
activation roll. The proposed effect contract now supplies replacement
bait/affix rules for review; these older triggers are not live.

| Affix | T1 max | T10 max | Exact intended effect | Shared effect bucket |
|---|---:|---:|---|---|
| **Line Tension** | +1 Power | +6 Power | Increases rod power and therefore existing bonus-pull chance; obey approved Power/pull budgets. | `rod_power` |
| **Chum Catalyst** | +0.2 percentage points | +2 percentage points | Increases trigger chance of eligible measurement bait, not rarity-luck bait. | `bait_proc` |
| **Weight Weaver** | 5% | 35% | When measurement bait activates, chance for ONE additional independent legal weight reroll. | `weight_extra_proc` |
| **Trophy Lens** | 2% | 12% | Chance of ONE additional legal length attempt on ordinary fish. | `length_extra_proc` |
| **Focus Tuner** | +0.5 percentage points | +5 percentage points | Improves Collection Focus missing-rank reroll when Focus is eligible and enabled. | `focus_proc` |
| **Bait Conservation** | 2% | 15% | Chance to retain an eligible bait use on an accepted cast; action-specific handling required. | `bait_preserve` |
| **Aftercast** | 0.5% | 4% | Chance to add one nonrecursive rod catch, inside per-cast pull budget. | `extra_rod_pull` |
| **Measured Cast** | 2% | 12% | Chance for one independent extra legal weight attempt on ordinary fish (separate from bait). | `weight_extra_proc` |
| **Maxima Priority** | TBD | TBD | Helps favour legal ranks lacking a recorded exact rank maximum for the randomly encountered ordinary species; exact eligibility, selection method and strength remain open. | `maxima_priority` |

### 3.3 Boat-specific (8)

**Eligibility:** Boat.

| Affix | T1 max | T10 max | Exact intended effect | Shared effect bucket |
|---|---:|---:|---|---|
| **Deep Trawler** | 1% | 10% | Chance for one extra catch per boat haul, within total haul cap. | `boat_extra_pull` |
| **Efficient Hull** | 1% faster | 10% faster | Reduces normal boat-haul duration; does NOT change raid start/end times. | `boat_duration` |
| **Schoolfinder** | 2% | 20% | Chance to reroll one duplicate ordinary species in a boat haul via valid biome pool. | `duplicate_species` |
| **Wreckbreaker** | +3% junk salvage | +25% junk salvage | More salvage resources from boat junk; shares cap with Scavenger. | `junk_salvage` |
| **Sunken Treasury** | +3% treasure coins | +25% treasure coins | More coins from boat treasure; shares treasure reward cap. | `treasure_coin` |
| **Full Hold** | +2% ordinary fillets | +18% ordinary fillets | Additional normal fillets from fish caught on a boat; capture modifier at catch. | `fillet_yield` |
| **Surveyor** | +2% mastery XP | +20% mastery XP | Boat-family mastery XP for completed normal hauls. | `mastery_xp` |
| **Haul Appraiser** | 2% | 15% | Chance for one ordinary fish in the haul to receive one legal measurement attempt. | `haul_measure_proc` |

### 3.4 Net-specific (8)

**Eligibility:** Net.

| Affix | T1 max | T10 max | Exact intended effect | Shared effect bucket |
|---|---:|---:|---|---|
| **Fine Mesh** | +0.5% Luck stat | +5% Luck stat | Increases net rarity-luck; shares bucket with Fortune. | `rarity_luck` |
| **Quick Set** | 1% faster | 10% faster | Reduces ordinary net timer, within combined duration cap. | `net_duration` |
| **Overflow Knot** | 1% | 10% | Chance for one extra net catch, within overall catch cap. | `net_extra_pull` |
| **Selective Weave** | 2% | 18% | Chance to reroll one duplicate ordinary species in a net haul. | `duplicate_species` |
| **Silt Sifter** | +3% junk salvage | +25% junk salvage | Improves net junk salvage, not junk catch probability. | `junk_salvage` |
| **Pearl Catcher** | +3% treasure coins | +25% treasure coins | Improves coin reward of net treasure. | `treasure_coin` |
| **Scale Collector** | +2% ordinary scales | +18% ordinary scales | Additional normal scales from net-caught fish, pinned to the catch. | `scale_yield` |
| **Mist Weaver** | 2% | 15% | Chance for one ordinary net catch to get one additional legal measurement attempt. | `haul_measure_proc` |

### 3.5 Hybrid biome (6)

**Eligibility:** Rod · Boat · Net, except Attuned Focus: Rod.

| Affix | T1 max | T10 max | Exact intended effect | Shared effect bucket |
|---|---:|---:|---|---|
| **Resonance** | +0.5% Luck stat | +5% Luck stat | Rarity-luck bonus in the *innate primary* biome only. | `rarity_luck` |
| **Attuned Harvest** | +1% normal materials | +10% normal materials | Ordinary fillet and scale yield in primary biome; captured when fish caught. | `material_yield` |
| **Attuned Wisdom** | +2% mastery XP | +20% mastery XP | Family mastery XP only in primary biome. | `mastery_xp` |
| **Deepwater Cache** | +2% treasure coins | +20% treasure coins | Treasure coin rewards in primary biome. | `treasure_coin` |
| **Secondary Affinity** | +0.2% Luck stat | +2% Luck stat | Small rarity-luck bonus in a *different chosen biome*. Does not activate primary-affinity affixes there. | `rarity_luck` |
| **Attuned Focus** | +0.5 percentage points | +5 percentage points | Rod-only: improves eligible Collection Focus in primary biome. | `focus_proc` |

**Hybrid rules:** primary biome is innate and separate from slot budget. A secondary biome receives *only* its own secondary-affix effect and does not activate primary-only Resonance, Attuned Harvest, etc. If the primary biome is rerolled to match the secondary biome, preserve the secondary affix but disable it until its target is changed; never silently stack twice.

### 3.6 Catalogue accounting and collision notes

- General 8 + Rod 9 + Boat 8 + Net 8 + Biome 6 = **39 total ordinary affix IDs**.
- `Fortune` + `Fine Mesh` + `Resonance` share `rarity_luck`, **not** independent multiplicative probability transforms.
- `Aftercast` and `Line Tension` both improve rod action throughput; bonus pulls must be counted in one nonrecursive budget.
- `Weight Weaver`, `Measured Cast`, and the signature `Moonthread`/`Trophy's Oath` all touch measurement opportunities; they must not be implemented as unbounded loops.
- `Scavenger`, `Wreckbreaker`, `Silt Sifter`, `Salvage Fleet`, and `Rusted Crown` share salvage economics and have to be tested together.
- `Old Salt`, `Surveyor`, `Attuned Wisdom`, and certain signatures all affect mastery XP; cap effective bonus without making family mastery instant.
- `Coinwright`, filleting yield, and `Attuned Harvest` must be **recorded at catch acquisition**, not dynamically recalculated when fish are sold or filleted days later.

Junk rewards should have a variable quantity spread so percentage bonuses can meaningfully improve output. The range, distribution and fractional-rounding policy remain open; a spread alone does not prevent small bonuses being lost to integer rounding. This changes reward quantities, not biome encounter-category probabilities by implication.

---

## 4. Full signature power catalogue (24)

**Initial historical candidates:** current comparison rules are in the
[proposed effect contract](build-contract-v0.1.md#6-proposed-effect-contract-and-build-comparisons).

Signature powers are **separate from ordinary affixes**. They do not have ordinary T1–T10 shards unless a new decision explicitly creates signature tiers. Unlock them from eligible additional gear carrying a signature; exact trader-specific signature availability remains open. Extracting one permanently records it in the Reliquary. A protected original can be **imprinted** with a previously unlocked compatible signature by paying an authored cost, not sacrificed.

Active signature on equipped gear + extracted signature in a Reliquary slot = **deduplicated by signature ID**. Powers with similar effects but different IDs share cap/budget. The six powers per pool below are proposed and subject to balancing.

### 4.1 Rod signatures (6)

| Signature | Proposed effect | Trigger / restriction |
|---|---|---|
| **Echoed Line** | 2% chance for one extra rod pull | Nonrecursive, shares rod-pull cap with Aftercast and Power. |
| **Trophy's Oath** | 2% chance for one additional endpoint-perfect opportunity | Only eligible, fully rank-collected ordinary UUR fish; max one additional roll; no special fish. |
| **Baitkeeper's Promise** | +1 percentage point to measurement-bait activation | Shares proc budget with Chum Catalyst; not standard rarity bait. |
| **Collector's Whisper** | +3 percentage points to eligible Collection Focus | Requires Focus enabled/unlocked; shares Focus cap. |
| **Salvage Sermon** | First rusted tin in a rod action yields one extra scrap | No change to junk encounter odds; reward once per action. |
| **Tidal Memory** | First qualifying rod catch per biome/day gains +20% family mastery XP | UTC daily key, once per biome; family only. |

### 4.2 Boat signatures (6)

| Signature | Proposed effect | Trigger / restriction |
|---|---|---|
| **Ghost Trawler** | 5% chance for one extra boat haul catch | Nonrecursive; shares 12-catch max with Deep Trawler. |
| **Cartographer's Wake** | Reroll one duplicate ordinary species per haul | Reroll uses current legal biome pool, no guaranteed species. |
| **Salvage Fleet** | +20% salvage resources from boat junk | Shares overall junk-salvage cap. |
| **Tempest Rudder** | Normal boat-haul duration −5% | Does not affect raid timers. |
| **Gilded Bilge** | 5% chance to double coin reward from one treasure in haul | Does not double fish, materials, or discovery progress. |
| **Fisherman's Folly** | Modest consolation crafting resource if entire haul is junk | Reward must stay inferior to normal fishing progression; author actual payout. |

### 4.3 Net signatures (6)

| Signature | Proposed effect | Trigger / restriction |
|---|---|---|
| **Nets of Plenty** | 5% chance for one extra net catch | Shares 6-catch max with Overflow Knot. |
| **Moonthread** | 10% chance of extra length and weight attempt on one ordinary catch | Legal rank band; one selected fish only. |
| **Swiftwater Knot** | Normal net duration −5% | Shares 15% duration-reduction budget. |
| **Whispering Mesh** | Reroll one duplicate ordinary species per net haul | No guaranteed species, may still repeat. |
| **Scale Pact** | +20% normal scales from eligible net fish | Pinned to acquisition; shares yield budget. |
| **Pearlbound** | 10% chance to double one net treasure coin reward | One event, no compounding chain. |

### 4.4 Waters / Universal signatures (6)

| Signature | Proposed effect | Trigger / restriction |
|---|---|---|
| **Rusted Crown** | First rusted tin from eligible activity gives one extra salvage resource | Junk remains junk; no encounter odds changes. |
| **Atlas of Seven Waters** | First eligible catch per biome/day gains +20% family mastery XP | Data-driven new biomes; per-account UTC daily ledger. |
| **Wayfarer's Compass** | Reduced innate-affinity Luck outside primary biome | Outside-only conditional effect; must not activate primary-affix multipliers. |
| **Forgotten Cache** | Chance for extra normal materials from first eligible fish per activity | Specify proc probability and quantity prior to release. |
| **Ocean's Memory** | Extra player XP on first newly acquired species-rank per day | Once per UTC day, distinguish new rank from replayed catch. |
| **Rift Current** | Once per biome/day, qualifying activity may add one ordinary catch | Needs explicit trigger odds, nonrecursive, respect rod/haul caps. |

### 4.5 Slot behavior, extraction, and imprinting

The following three-slot layout is proposed; the final slot count, unlocks and
compatibility remain open.

| Reliquary slot | Supports | When it checks equipment |
|---|---|---|
| **Rod** | Rod signatures | Accepted rod action only |
| **Haul** | Boat/net signatures | An accepted normal boat/net haul, with proper compatibility |
| **Waters** | Universal/biome signatures | An eligible activity, evaluating *that activity's* equipment and its current biome |

- Extracting a unique signature unlocks it permanently; duplicate compensation remains open and cannot create duplicate active power levels or award the item's affix-shard outcomes.
- A signature may be active natively on an equipped item or through one applicable Reliquary slot. For the same signature ID, choose **one effect**, never two.
- Equipment signatures don't make a rod power work on a boat or vice versa. The active equipment, not anything merely owned, defines compatibility.
- Signature effects that mention 'daily' must use an explicit UTC reset, replay-safe account/activity counters, and bounded records. New biomes must integrate with these counters without schema-specific assumptions about exactly seven waters.
- Extraction rarity, gamble distribution, imprint price, unique-power library UI, and duplicate compensation remain uncalibrated.

---

## 5. Proposed global stacking and action budgets

**Initial historical candidates:** these budgets are superseded for current
comparisons by the [proposed effect contract](build-contract-v0.1.md#6-proposed-effect-contract-and-build-comparisons).

These are **design ceilings to model, not production limits approved by the user**:

| Mechanic | Proposed maximum | Implementation note |
|---|---|---|
| Total rod pulls/results per accepted action | **3** | Existing rod pull + Power bonus + affix/signature effects; extra pulls cannot re-trigger themselves. |
| Total boat pulls/results per completed haul | **12** | Base 10 + at most 2 from all bonus sources combined. |
| Total net pulls/results per completed haul | **6** | Base 4 + at most 2 from all bonus sources combined. |
| Normal haul timer reduction | **15%** | Boat/net only; applied to appropriate base duration, integer rounded; never applies to raid timers. |
| Collection Focus eligible reroll chance | **60%** | Count raid reinforcement + Focus Tuner + Attuned Focus + signatures; respect unlocked/active eligibility. |
| Historical measurement-bait activation proposal | **30% (unresolved historical candidate; not a current cap)** | No current 25% activation proc exists: Trophy Chum adds 25% rarity Luck and eight measurement attempts per stocked slot (sixteen for two). Define or replace this proposed activation mechanic during bait/affix co-design. |
| Extra weight attempts | **2** additional eligible attempts | Additional to normal bait attempts; preserve exact rank/measurement bands. |
| Additional ordinary filleting yield | **+50%** | All ordinary-yield affixes/signatures together; never create rare raid materials directly by category shift. |
| Extra junk salvage resources | **+50%** | Does not change junk encounter chance. |
| Rarity luck | **No final cap yet** | Existing Prismatic Abyssal Boat already hits current validation max; extend and prove graded-luck math before adopting the new bonuses. |
| Player XP & mastery XP | **No final cap yet** | Consider level inflation and the mixed-activity daily benchmark. |

All current validation limits and proposed Tideforge caps are subject to change. Higher limits may be needed for ten-tier affixes and additional stats. Affix definitions, values and supporting mechanics can be revised as the combined system is developed; no replacement limits or numeric tuning are approved yet.

### Evaluation order (proposal)

1. Validate player, biome licence, gear instance, action availability, and versioned rules.
2. Snapshot relevant item, family mastery, quality, reinforcement, innate affinity, ordinary affixes, active Reliquary powers, and any bait for this action.
3. Resolve fish/junk/treasure **category** from the biome's established category weights unless a specifically authorized new effect changes it. All currently proposed affinity/Luck effects do **not** change category weights.
4. Resolve ordinary species/rank from the legal biome pool using the game's existing graded rarity math plus permitted rarity-luck bucket, preserving exceptional fish behavior.
5. Apply Collection Focus *only* where eligible/enabled, with one capped shared Focus chance.
6. Generate legal measurements, apply approved independent enhancement attempts, select legitimate best outputs, and keep existing rank-maxima requirements.
7. Generate permitted, capped nonrecursive extra catches. Every catch re-enters the legal category/species/rank path, but no bonus-chain recursion.
8. Persist immutable catch modifiers/reward provenance; award coins, XP, mastery, materials, and records atomically with replay-safe receipts.

**Haul/raid snapshots:** Normal boat/net hauls already snapshot beginning stats. Adding item instances means snapshots must include the exact instance ID, effective affix and signature IDs, and versioned evaluated values or immutable rules. Gear changes while a haul runs must not retroactively affect it. A boat committed to a raid does **not** benefit from normal-haul timers, extra catches, treasure, or ordinary affinity farming effects. Rods and nets remain usable while a boat is on a raid, within existing rules.

An angler has one active main equipment loadout, with one item per supported slot. Duplicate instances and saved presets do not create additional activity slots. An item committed to a raid locks changes to that active equipment loadout until the commitment ends, including direct equipment changes and preset switching. A second boat cannot bypass the reservation. Rod fishing and net activities remain available with the already selected gear.

---

## 6. Gear progression and affix slots

**AGREED:** Gear levels unlock affix slots through mastery shared per owner, equipment kind and family. The following numerical mastery milestones are **proposed**, not approved unlock levels:

| Mastery level | Unlock |
|---|---|
| 1 | Natural innate biome affinity active. |
| 10 | First ordinary affix slot. |
| 25 | Second affix slot; access to additional instances for the family. |
| 50 | Third affix slot; advanced deterministic attunement. |
| 75 | Fourth affix slot; advanced resonance crafting recipes. |
| 100 | Family mastery completion perk and cosmetic recognition. |

The same owner × kind × family mastery applies to originals and all additional items, including Camp and Mark copies. Individual items retain their own quality and raid reinforcement. Any free raw-stat improvements from mastery need explicit separate design and must fit the approved power/luck/XP limits when those limits are determined; don't silently add them to catalogue effects.

Mastery should be earned through normal accepted actions, including regular rod casts, boat hauls, and net hauls. Define XP relative to actual activity and possible activity/day; don't equate one boat haul's 10 pulls to 10 full rod mastery actions without explicit intention. Optional raid mastery credits should be small and separately authored.

---

## 7. Gambling, Vault, Forge, Codex, and affix lifecycle

### Gambling (monthly wandering trader; proposed Driftmarket)

- The Camp trader sells coin-bought additional gear with low-tier affixes. The wandering trader visits once per month and offers very-high-tier affixes; the exact visit window remains open. Original vanilla purchases remain protected.
- Gambling uses Dredge Marks from raids and achievements, priced at one Mark per 1,000 coins with the approved River exception. Each 130,000-coin Abyssal rod, boat or net is 130 Marks; one full ordinary F-to-UUR raid ladder earns 65 Marks and two earn 130 from raids alone. Achievement Marks are extra. See the [build contract](build-contract-v0.1.md) for the accepted family prices, proposed per-grade split and open details.
- Family eligibility and targeting remain open. Native biome may roll off-family (prior proposal: 40% native, remaining 60% shared across other eligible biomes).
- Additional gear from Camp or Mark gambling inherits family mastery but does not copy another instance's quality, reinforcement, biome, affixes or signature. The integration draft proposes unreinforced new rods; starting quality/reinforcement rules still need a final contract, not an assumption that either source grants raid progression.
- No real-money wagering is part of this design. Store roll tables, rarity, duplicates, and pricing as versioned game data. Display probabilities where appropriate.

### Vault and extraction

- Store affix shards account-wide by affix ID and amount, separate from inventory fish and normal materials.

Dismantling an eligible item returns matching Vault resources for all its affixes, including dormant affixes. Signature extraction is the competing choice: consume an eligible item to learn its signature in the permanent Reliquary instead of recovering its affixes. Do not grant both signature and affix-shard outcomes from the same item. Resource quantities, tier/value handling and any ordinary-material refund remain open. Protected originals and favorites retain their safeguards.

### Forge

- Each ordinary affix T1–T10 has individually authored costs, legal roll range, compatible gear kinds, and type/exclusivity rules.
- Guaranteed tier upgrades and visible roll improvement, plus directly chosen type changes, preserve paid roll finish.
- The best natural roll and best forged roll remain distinguishable in data. Proposals for tier-upgrade shards and currency from older drafts are examples, **not approved final recipes**.

Ordinary affix tiers use raid materials. The [Forge flow](forge-and-refinement-v0.1.md) is accepted; [Forge costs](forge-cost-model-v0.1.md) and [processing recipes](processing-and-material-economy-v0.1.md) propose quantities and yields, not approved values. Fish processing is another material source, not a bypass of raid access gates. Higher-grade material crafting/refining retains the shared destination-grade ordinary raid-clear unlock. Conversion itself grants no seals, completion statistics or reinforcement eligibility.

### Codex

For each affix, permanently track `discovered`, `naturally_extracted`, `highest_natural_tier`, `highest_crafted_tier`, `best_ever_value`, and an optional exact T10 perfect-roll mark. Consumed shards and destroyed rolled gear do not erase Codex discoveries. A naturally rolled T10 from eligible additional gear and a crafted T10 can reach equal power; Camp and Mark provenance remains distinguishable.

---

## 8. Build examples, not mandatory metas

| Build | Equipment/affixes | Signature angle | Tradeoff |
|---|---|---|---|
| **Rank Collector** | Rod: Fortune, Focus Tuner, Line Tension, Resonance | Collector's Whisper | Strong in one biome; weaker measurement refinement. |
| **Perfect Hunter** | Rod: Chum Catalyst, Weight Weaver, Trophy Lens, Measured Cast | Trophy's Oath | More legal measurement attempts; fewer economy bonuses. |
| **Long-haul Harvester** | Boat: Deep Trawler, Efficient Hull, Full Hold, Provisioner | Ghost Trawler | Material/catch volume, not targeted bait-fishing. |
| **Frequent Netter** | Net: Quick Set, Overflow Knot, Selective Weave, Scale Collector | Nets of Plenty | Frequent reliable gathers, moderate control. |
| **Rusted Tin Fisher Extraordinaire** | Boat/Net: Scavenger plus Wreckbreaker or Silt Sifter, with salvage-focused companion affixes | Rusted Crown | Profitable junk without artificially raising junk probability. |
| **Two-water Explorer** | Gear: Secondary Affinity, Fortune, Scholar, Attuned Wisdom | Wayfarer's Compass | Larger geographical coverage; below a single-biome specialist's peak. |

Every example is *illustrative*. Final balance may make a different slot combination optimal; do not claim these are mathematically best builds before simulation.

---

## 9. Verification and acceptance gates for Devin

### Required implementation conversations

1. **Affix collision audit:** Group effects by underlying event/stat to prove no repeated procs, recursive pulls, duplicated treasure multipliers, or over-cap stacking.
2. **Graded rarity math:** Validate every allowed final luck value in every biome and species/rank pool. Preserve positive valid weights, totals, Fihs/Sock special hierarchy, and legal maximum likelihoods.
3. **Regression of old gear:** Compare all existing rod/boat/net stats and quality outcomes before and after release; new defaults should not nerf existing gear. Prismatic Abyssal Boat already sits at +266% luck/+374% XP under existing validation.
4. **Collection and maxima:** Test rank eligibility, missing-rank focus, Trophy Chum interactions, measurement attempt limits, special fish, and rare maximum outcomes across realistic simulations.
5. **One-of-kind/duplicate migration:** Add uniquely identified gear instances without loss of existing owned rods, haul equipment, quality, reinforcements, selected loadouts, active raids/hauls, or pending receipts.
6. **Replay safety:** Concurrent gambles, reforge, upgrade, extract, equip, save loadout, and dismantle require atomic costs and idempotent request IDs.
7. **Material balance:** Verify Dredge Marks/shard supply, normal filleting, high-grade conversion, and raid material efficiency. Retain shared raid-grade access gates and avoid farmable infinite value loops.
8. **Progression forecasting:** The accessible baseline is around 24 total fishing actions/casts per day in any mixture of rod, net and boat activity, plus roughly one or two raids or raid check-ins as booked durations allow. This is not 24 mandatory rod casts plus extra required hauls. More activity may accelerate progress. Earlier three-to-four-year language is background aspiration, not a mandate to slow players down. Distinguish actions from result counts; long raids can span daily check-ins. Model heavier activity separately as optional stress cases.
9. **UI/UX:** Compare original vs variant, show exact affix tier/roll/range, current vs proposed effects, effective caps, protected/favorite flags, active biome, and snapshot notices for in-progress hauls.
10. **Live migration:** Use an isolated old-WASM-to-new-WASM preservation proof, pre/post canonical comparisons and safe additive activation with no player-data reset. Git is the source-history mechanism; no automatic backup or recovery archive is authorized.

### Acceptance requirements

- Every affix has a unique stable ID, gear eligibility, value units, 10 authored tiers, RNG/extraction weight, and cap group.
- Every signature has a stable ID, slot compatibility, activation trigger, snapshot semantics, anti-recursion rule, and collision group.
- Existing fish, junk, treasure, measurements, raid seals, and gear ownership still follow the reference baseline with Tideforge disabled, except for separately approved balance changes.
- Invalid or expired requests cost no resources; retries award/process once.
- Existing play can continue through new versioned feature flags if Tideforge is not activated yet.

---

## 10. Outstanding decisions requiring a dedicated design pass

Propose simple mastery and gambling defaults for review; exact curves, access and payout details remain open within the accepted sources and pricing.

1. **T1–T10 intermediate values** and the minimum-value roll for *each* individual affix, especially discrete extra-attempt procs.
2. **Real gear drop/gamble tables:** Tier distributions, affix count distributions, chance of signature, equipment definition targeting, and odds disclosure.
3. **Resources:** Final shard identity and recovery quantities, normal filleting yields, coin costs, individual raid-grade and achievement Mark payouts, monthly trader inventory, and bulk forging costs.
4. **Signature tuning:** Rare proc rates, particularly Forgotten Cache/Rift Current/Fisherman's Folly and universal power interaction with each activity.
5. **Final probability budgets:** Rarity-luck, XP, total measurement attempts, signature stack and protected legendary probability.
6. **Gear mastery XP curve:** Activity credit per rod cast/haul and affordability at the mixed-activity baseline; propose simple defaults for review.
7. **Crafting paths for originals and additional copies:** Initial affix compatibility, recovery quantities and retained-finish conversions within the dismantle-all or signature-extraction whole-item routes.
8. **Affix exclusivity:** A single effect family may need mutual-exclusion rules beyond preventing identical IDs; e.g. too many measurement affixes in one build.
9. **Roll snapshot provenance:** How item-instance, affinity, affixes, and signatures pin to each catch, including fish processed months later.
10. **Progression completion definition:** Which Codex/perfect legendary/cosmetic achievements count as core progress vs optional aspirational goals, without enforcing a calendar-length grind.

---

## 11. Data contracts: implementation sketch, not mandatory schema

Suggested versioned public catalog entries:

```json
{
  "id": "fortune",
  "eligibleKinds": ["rod", "boat", "net"],
  "effectBucket": "rarity_luck",
  "tierBounds": [{"tier": 1, "minBp": null, "maxBp": 50}, {"tier": 10, "minBp": null, "maxBp": 500}],
  "maxStacksPerItem": 1,
  "biomePredicate": "any",
  "catalogVersion": 1
}
```

The `null` tier minima are intentionally unresolved; all ten tiers and exact legal ranges must be authored before implementation. Avoid interpreting this illustrative JSON as a complete schema.

Suggested private operational entities: gear_instance, gear_affix, family_mastery, affix_shard_balance, signature_unlocked, reliquary_loadout, codex_affix_record, gambling_quote/receipt, forge_quote/receipt. Exact SpacetimeDB table names, indexes, migrations and client bindings are Devin's implementation design task.

---

## 12. Companion-document differences and how to resolve them

**Important:** This catalogue is the *latest candidate list*, not a claim that all earlier proposals were approved.

| Issue | Earlier biome design v0.2 | This catalogue v0.3 | Resolution before coding |
|---|---|---|---|
| Biome ordinary affix count | Five: Resonance, Attuned Fortune, Attuned Wisdom, Attuned Harvest, Secondary Affinity | Six: Resonance, Attuned Harvest, Attuned Wisdom, Deepwater Cache, Secondary Affinity, Attuned Focus | **Pending:** Treat six as the current catalogue proposal; explicitly decide whether to retire `Attuned Fortune` or reintroduce it as a seventh affix. |
| Attuned Wisdom | T1 +1% → T10 +10% | T1 +2% → T10 +20% | **Pending:** Test against mastery pacing; do not silently update production. |
| Attuned Focus | Not included as ordinary biome affix | Rod-only candidate +0.5 to +5 pp Focus chance | **Pending:** Decide if Focus Tuner, Attuned Focus, and raid reinforcement can coexist with the 60% shared cap. |
| General Fortune / roll examples | Earlier illustrations included larger or differently named affix effects | Current proposal Fortune +0.5% → +5% Luck stat | **Pending:** Finalize by a single authoritative effect catalog after simulation. |
| Raid ladder duration | Historical drafts cited the older 108-hour ladder | **54 hours per species** | **Current baseline:** Use the 54-hour total and inspect the current catalog for grade-specific durations. |
| Completion baseline | The historical 24-rod-cast / 3–4-year framing is superseded background | Around 24 total mixed-method fishing actions plus normal raid check-ins as durations allow | **Agreed update:** More activity may accelerate progress; no deliberate slowdown to enforce a calendar target. |

This document can be given to Devin with the companion specs for **design review**, but the above conflicts and the uncalibrated balance tables must be resolved before a production-ready implementation spec is accepted.

---

## 13. Snapshot references

Historical repository at design time: [`icha-senpai/worldengine`](https://github.com/icha-senpai/worldengine), raid-system commit [`8cbd208`](https://github.com/icha-senpai/worldengine/commit/8cbd2088a56c2c7763cc0425ad646d17f9a64e71). Relevant files: `fishing-game/content/world.json`, `fishing-game/content/crafting.json`, `fishing-game/content/haul-equipment.json`, `fishing-game/crates/game-rules/src/rods.rs`, `fishing-game/crates/game-rules/src/hauls.rs`, `fishing-game/spacetimedb/src/casting.rs`, `fishing-game/spacetimedb/src/hauls.rs`, `fishing-game/spacetimedb/src/raids.rs`.

Current source reviewed for this import: fb486d3a; see the decision index for later clarifications.

**Status reminder:** Design only. Run modern, repo-native tests and migration proof before considering live deployment.
