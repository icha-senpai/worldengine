# Fishbound Tideforge — Biome Affinity & Resonance

**Document:** Biome system design · v0.2 · October 30, 2026  
**Project:** Fishbound, `icha-senpai/worldengine`  
**Companion document:** `Fishbound_Tideforge_Existing_Gear_Stats_and_Balance_Integration_v0.2.md`  
**Parent design:** `Fishbound_Tideforge_System_Design_v0.1.md`  
**Status:** **Hybrid model selected**. Numerical balance, trader economics, mastery XP and specific signature effects remain **proposed**, subject to simulation and explicit approval. Do not treat this as authorization to deploy.

> Repository working copy; the Desktop original is unchanged. The [current decision index](README.md) governs these proposals where older wording differs. Current code is a reference baseline, not a final Tideforge tuning contract. Proposed values remain open unless explicitly approved.

> **Core idea:** Equipment has an innate biome identity *outside* its proposed ordinary affix slots. Randomized affixes may amplify that identity, give it a secondary home, or reward different activities in the same waters. Old and cheap gear can remain respectable specialists without rendering existing higher-tier generalist equipment irrelevant.

## 0. Decision status

- **DECIDED:** Hybrid innate affinity plus amplifying affixes; vanilla first originals; shared family mastery for gambled copies; ten independent affix tiers (T1-T10); preservation of original quality and raid investment; no reset by attunement.
- **WORKING STRUCTURE:** Four ordinary affix slots; slot budgets and mastery unlocks remain to be finalized.
- **PROPOSED:** Initial percentages, drop weights, calendar timings, mastery gates, specific affix effects, signature powers and prices. These are starting proposals, not approved final tuning.
- **TO VERIFY:** Existing gear and gameplay details come from the cited commit. Reinspect the repository before implementation, especially if additional commits land.

Tideforge is a simple, fun and forgiving gear layer. ARPG inspiration means optional bonus combinations, not required expertise or punishing build traps. Ordinary choices should remain useful. More active players may progress faster; do not add friction or permanent bricking to enforce a multi-year completion date.

## 1. Existing content to anchor to

The current game has seven biomes, seven rod families and the same seven family names on boats and nets. `world.json` defines the biomes and rods; `haul-equipment.json` defines the independent boat/net equipment and their bonuses.

| Family | Default native biome | Biome ID | Current minimum player level for biome |
|---|---|---:|---:|
| Twig | Meadow Pond | 1 | 1 |
| River | Whispering River | 2 | 5 |
| Marsh | Hollow Marsh | 3 | 10 |
| Moonwood | Moonlit Lake | 4 | 18 |
| Tide | Sunken Coast | 5 | 28 |
| Glacial | Glacial Reach | 6 | 40 |
| Abyssal | Abyssal Shelf | 7 | 55 |

**Scope:** 7 families × 3 categories = **21 family/category mastery tracks**, if all present categories retain the proposed family-level mastery model. An individual *instance* still has its own affinity and affixes.

Existing biome licences, levels, species pools, fish/junk/treasure category weights, rod bait, collection focus, haul timing and raid limitations are the reference baseline unless explicitly changed. **Affinity does not grant entry to a locked biome** and must not change the existing biome access rules.

Older families retain lower base stats as part of the existing progression, but should remain reasonably comparable and respectable in useful roles. The goal is not identical stats or guaranteed best-in-slot status for every family. Evaluate affinity, mastery and affixes together while preserving earned investment.

Bait and affixes are complementary sources of effects. Bait, affixes, or both may be adjusted through explicit balance work. Existing bait behavior is the reference baseline, not a promise that it can never change; preserve earned progress and saved results while defining the combined effect rules.

## 2. Equipment identity, original gear and gambling

### 2.1 Equipment instances

The current ownership model is effectively one owned row per player and equipment definition. Tideforge requires uniquely identified instances, including multiple copies of the same definition:

| Property | Ownership | Meaning |
|---|---|---|
| `instance_id` | Instance | Immutable unique ID; never identify a duplicate by family ID alone. |
| `owner_player_id` | Instance | Authoritative owner. |
| `kind` / `definition_id` | Instance | `rod`, `boat`, `net`, plus original definition. |
| `is_protected_original` | Instance | First legitimate purchase/starter; cannot be destroyed or consumed. |
| `primary_biome_id` | Instance | Innate affinity; exactly one active primary biome. |
| `secondary_biome_id` | Affix value | Optional, only when a Secondary Affinity affix exists. |
| `quality_level` | Instance | Preserve existing Common–Prismatic investment. |
| `reinforcement_level` | Rod instance | Preserve and evolve existing rod-specific raid reinforcement. |
| `affix_slots` | Instance affix rows | Up to four ordinary affixes, with independent tiers and values. |
| `signature_power_id` | Instance | Optional rare gear power; separate from ordinary affixes. |
| `mastery_xp` / `mastery_level` | **Owner × kind × family** | Shared by all copies of that family/category. |
| `version` | Instance | Optimistic concurrency and quote invalidation. |

**First-purchase rule:** Original equipment starts without randomized affixes or signature powers, but receives its **native family affinity** when the system is activated. “Vanilla” means no *rolled extra* properties; it does not mean this design removes the new basic affinity bonus from original equipment.

**Gambled copy rule:** A new instance has its own primary biome and affixes, with starting quality and reinforcement rules still open. It immediately benefits from the player's already-earned **family mastery**. It **does not copy** another instance's quality, raid reinforcement, biome, affixes or signature power.

**Original protection:** Originals can be upgraded, forged and deliberately reattuned; they cannot be dismantled, extracted as a whole, discarded in bulk, or sacrificed by the Cube/Reliquary service. The player is never required to sacrifice an original to progress.

An angler has one active main equipment loadout, with one item per supported slot. Duplicate instances and saved presets do not create additional activity slots. An item committed to a raid locks changes to that active equipment loadout until the commitment ends, including direct equipment changes and preset switching. A second boat cannot bypass the reservation. Rod fishing and net activities remain available with the already selected gear.

### 2.2 Affinity inheritance and rolls

- Original Twig/River/…/Abyssal gear begins at its matching native biome, regardless of where the player owns or equips it.
- A gambled variant can roll a different biome. **PROPOSED initial weighted distribution:** 40% native family biome; the other 60% divided equally among the other eligible biomes (10% each with the original seven-biome catalog).
- New biomes and equipment definitions use catalog-driven `eligible_biome_ids`, `native_biome_id` and explicit weights. Never hardcode “seven” into game logic.
- Gambles draw from an approved global eligible pool, not solely the player's unlocked biome licences. Otherwise players could deliberately avoid licences to rig odds.
- Biome affinity does not override the owner's current location, equipment minimum level, licence gates or species encounter pools.
- All RNG, draw weights and selected results are determined in the Rust/SpaceTimeDB reducer and written transactionally.

## 3. Base innate affinity effect

**PROPOSED initial effect while the equipment is used in its primary biome:**

1. **+100 basis points (+1.0 percentage point) to the game's *rarity-luck stat***, not +1 percentage point to the UUR probability.
2. **+5% family mastery XP** from the qualifying activity.

Both effects are conditional, additive to relevant modifier buckets, and present on ordinary original equipment. No native bonus applies when the equipment is used elsewhere. There is **no negative penalty** away from the attuned biome.

**Meaning of “used”:**

- **Rod:** The rod was actually equipped for the accepted `/fish` cast and the cast occurred in its primary biome.
- **Boat/net:** That equipment instance launched a valid timed haul while the selected biome matched its primary biome. The bonus is captured in the immutable haul snapshot.
- **Raid:** A boat committed to a raid is still a boat, but **does not activate ordinary fishing-affinity bonuses for raid loot, raid timers or raid seals**. Whether one small mastery credit is awarded for a fully finished raid is an open, separately budgeted choice.

### 3.1 No hidden category manipulation

Innate rarity luck modifies the rank ticket weighting **after** the fish/junk/treasure category has been selected, following the game's existing logic. It does not silently reduce junk probability, increase treasure probability, pull species from another biome, grant additional catch attempts, or make a perfect measurement more likely.

If a future named affix specifically changes category or measurement behavior, that must be a separate, explicit effect with its own budget and tests.

## 4. Resonance and affinity-compatible affixes

Primary affinity costs **zero** of the four ordinary slots. The following are **PROPOSED** specialized ordinary affixes that a player may forge or find in gambled equipment:

| Affix ID (working) | T1 | T10 | Scope and behavior |
|---|---:|---:|---|
| `resonance` | +0.5% luck | +5.0% luck | Extra rarity-luck stat **only in primary biome**. |
| `attuned_fortune` | +0.3% luck | +3.0% luck | Extra primary-biome luck; see overlap warning below. |
| `attuned_wisdom` | +1.0% mastery XP | +10.0% mastery XP | Affects *gear-family mastery only*, not player XP. |
| `attuned_harvest` | +1.0% material yield | +10.0% material yield | Additional eligible ordinary fillet/scale yield, captured at catch. |
| `secondary_affinity` | +0.2% luck | +2.0% luck | Adds a lesser bonus in **one selected non-primary biome**. |

A tier sets the permitted numerical range. A T10 roll can still be imperfect and refined later. Example: Resonance T10 could roll between +4% and +5%, with the listed +5% representing its proposed absolute ceiling. Tier/value tables must be authored as data, not inferred from UI display values.

**Design tension still to settle:** `resonance` and `attuned_fortune` are mechanically similar. Keeping both allows a high-specialization build to invest **two** slots in primary-biome rarity luck. An alternative is replacing one with a genuinely different targeted effect. Do not claim a fully differentiated final affix catalogue until this is resolved.

### 4.1 Slot and stacking rules

- Up to **four ordinary affixes**, unlocked through family mastery as proposed in §7.
- At most **one copy of each exact affix ID per instance**.
- `secondary_affinity` can appear once; it stores its selected second biome in instance-affix data.
- Primary and secondary biome must be distinct. If a primary reattunement conflicts with the secondary, preserve the affix's tier/value but disable it and offer a one-time compatible retarget; never silently give it double power.
- Effects across ordinary affixes add within **declared stacking groups** and explicit caps. No multiply-a-multiplier chains. Example: +1% innate +5% Resonance +3% Attuned Fortune +5% general Fortune = **+14 percentage points to the luck-bonus stat**, or **1,400 basis points** total. That is **not** +14% absolute probability for a UUR.
- The proposed +14% is a **scenario ceiling**, not a final approved cap; it assumes three of four slots spent on those ordinary affixes and a matching primary biome.
- Conditional effects resolve from the active equipment only. Another equipped item's affinity does not leak into the current rod, haul or raid.

### 4.2 Secondary-biome behavior

A rod with primary Glacial Reach and T10 Secondary Affinity for Abyssal Shelf has:

- In **Glacial Reach**: innate primary benefits plus any primary-only affixes; secondary affix inactive.
- In **Abyssal Shelf**: only the weaker secondary bonus; primary-only Resonance/Attuned Fortune inactive. Do not double-count the innate 1% or innate mastery boost.
- In **all other biomes**: ordinary gear stats and non-affinity affixes remain; both biome bonuses inactive.

This makes single-biome specialists peak higher while dual-biome generalists cover more territory.

## 5. Illustrative equipment archetypes

| Build | Primary affinity | Key affixes | Intended tradeoff |
|---|---|---|---|
| Precision specialist | Glacial Reach | Resonance, Attuned Fortune, Weightkeeper, Trophy Hunter | Concentrated rarity + measurement chase; weaker outside Glacial. |
| Two-water explorer | Meadow Pond | Secondary Affinity: Sunken Coast, Fortune, Scholar, Prospector | Versatile, lower peak affinity bonus. |
| Salvage boat | Hollow Marsh | Attuned Harvest, Salvager, Treasure Dredger, Fortune | Materials and flexible broad harvesting; not targeted bait fishing. |
| Quick-harvest net | Moonlit Lake | Attuned Wisdom, Quick Set, Selective Weave, Fine Mesh | Frequent collection and mastery; no rod-bait mechanics. |
| Original Twig specialist | Meadow Pond, later changed intentionally | Crafted Resonance and specialization affixes | A legitimate niche for starter gear, not universally strongest. |

Affix names used outside the five defined affinity affixes are candidates from the larger Tideforge affix pool and need separate effect definitions and caps.

## 6. Reattunement and gambling services

The **Driftmarket** is the working name of the monthly weekend wandering trader. **Dredge Marks** is a working gambling-currency name. Both are design concepts, not current live content.

### 6.1 Gamble new equipment

- Choose an eligible equipment category/family in the interface; exact price and whether targeting has a premium are **OPEN**.
- Server checks access, mastery unlock, currency, inventory capacity and rules version.
- Server rolls the variant's primary biome, eligible affixes/tiers/values and optional signature power. Newly rolled gear inherits family mastery *by reference to the shared track*.
- Commit currency deduction, instance grant, affix grant, Codex updates and a replay-safe action receipt atomically.
- Result displays native biome, full affix list, dormant slots if any, and explicit quality/reinforcement level.
- Player may keep, favor, dismantle, extract or forge the item. Originals cannot be sacrificed.

### 6.2 Random primary-affinity reroll

- Works on originals and gambled variants after a mastery/feature gate.
- **Preview before acceptance:** Roll a replacement biome, show the result and compare to the existing biome. The player may keep the old or accept the new one.
- Preserve mastery, quality, reinforcement, signature power, affix IDs, tiers and numerical values.
- Define payment and consumed resource semantics carefully: **PROPOSED** charge when generating the offer, not when accepting, so preview cannot be spammed for free.
- A selected new primary biome that collides with Secondary Affinity triggers its safe conflict flow (§4.1).

### 6.3 Deterministic primary reattunement

- **PROPOSED unlock:** Mastery level 50 for that equipment family.
- More expensive than random reroll. Player chooses among all globally eligible biomes, even if not yet licensed; that affinity simply won't function until the biome is accessible.
- Preserve all permanent gear investment; require a review/confirm step and versioned transactional quote.

## 7. Family mastery integration

**AGREED:** Gambled instances inherit a **shared per-family mastery** progression. Working shape: 1–100 per owner × equipment kind × family.

| Mastery level | PROPOSED feature unlock |
|---:|---|
| 1 | Innate biome affinity active; no randomized-gear requirement. |
| 10 | First ordinary affix slot. |
| 25 | Second affix slot; family becomes eligible for additional gambled copies. |
| 50 | Third affix slot; deterministic reattunement becomes available. |
| 75 | Fourth affix slot; late-tier forging recipes unlocked. |
| 100 | Family mastery badge/cosmetic and a **small, capped** family benefit, not a universal multiplier. |

**Mastery activity:** Accepted rod casts award rod-family mastery. Completed boat hauls award boat-family mastery. Completed net hauls award net-family mastery. Mastery XP **must not multiply by all ten boat catch results** without balancing against the intended time-based progression. Rejected casts, failed commands, duplicate callbacks and replayed action receipts award none.

**Dormant affixes:** If a gambled piece arrives with more affixes than the player's currently unlocked slots, keep them visible and immutable but inactive until those family mastery slots open. No gambling reroll should delete dormant affixes merely because the family was low level.

The XP curve and catch-vs-haul mastery credit rates are **not yet approved**. Never make an all-gear mastery grind secretly mandatory to enjoy existing fishing. Propose simple mastery and gambling defaults for review; exact curves, access and economics remain open.

The accessible baseline is around 24 total fishing actions/casts per day in any mixture of rod, net and boat activity, plus roughly one or two raids or raid check-ins as booked durations allow. This is not 24 mandatory rod casts plus extra required hauls. More activity may accelerate progress. Earlier three-to-four-year language is background aspiration, not a mandate to slow players down. Distinguish actions from result counts; long raids can span daily check-ins.

## 8. Snapshot and provenance rules

### Rod casts

Read the active rod **instance**, family mastery, quality, reinforcement, live bait, current biome and applicable affixes at server action time. Build one authoritative cast plan. Record the exact used rules/version so displayed odds match actual RNG.

### Boats and nets

At `/fishboat` or `/fishnet` start, save:

- `equipment_instance_id`, definition/family, quality, gear/rules version;
- primary/secondary biome and actual selected biome;
- **fully resolved numeric modifier snapshot** (luck, XP, category effects, catch-count/duration effects, material-yield bonus, signature powers);
- unchanged haul completion deadline and captured category/species/rank context.

Never reread *current* equipment affinity on completion. Reforging, selling/dismantling variants or switching gear cannot retroactively change an in-flight normal haul. If an item is consumed while used by an outstanding normal haul, either prevent the destructive action or preserve a completely self-contained immutable snapshot; the former is preferable for clarity. Raid commitment separately locks the active equipment loadout, including preset switches, until the commitment ends.

### Filleting provenance

A caught fish's eligible `attuned_harvest` bonus must derive from its **catch/haul-time gear snapshot**, not the equipment worn on the day the player fillets it. Store either an immutable earned-yield bonus in basis points or signed/authoritative catch provenance sufficient to recompute it under the original rules. Older catches without provenance receive **no retroactive affinity bonus** (while remaining fully filletable under ordinary yield rules).

### Raids

A boat's ordinary affinity does **not** modify raid duration, supply requirement, raid seal requirements, legendary perfect odds, or raid material rewards. Raid-specific affixes or powers, if later introduced, require their own explicit rules and bounded economy simulation. Preserve the existing exclusive boat commitment while a raid is deployed/running.

## 9. Crafting, Vault, Codex and Reliquary intersections

**Forge:** Each ordinary affix is independently craftable from Tier 1 to Tier 10 if materials are available. Upgrade cost gently increases; no permanent forging-potential exhaustion or involuntary gear destruction. Refine numerical values separately; directly chosen compatible type changes preserve tier and relative roll finish under the accepted [Forge flow](forge-and-refinement-v0.1.md).

Ordinary affix tiers use raid materials. Material quantities, processing yields and any accompanying coin/shard costs will be revisited after affix-crafting and gear-refinement operations are defined. Fish processing is another material source, not a bypass of raid access gates. Higher-grade material crafting/refining retains the shared destination-grade ordinary raid-clear unlock. Conversion itself grants no seals, completion statistics or reinforcement eligibility.

Dismantling an eligible item returns matching Vault resources for all its affixes. Signature extraction is the competing choice: consume an eligible item to learn its signature in the permanent Reliquary instead of recovering its affixes. Do not grant both signature and affix-shard outcomes from the same item. Resource quantities, tier/value handling and any ordinary-material refund remain open. Protected originals and favorites retain their safeguards.

Junk rewards should have a variable quantity spread so percentage bonuses can meaningfully improve output. The range, distribution and fractional-rounding policy remain open; a spread alone does not prevent small bonuses being lost to integer rounding. This changes reward quantities, not biome encounter-category probabilities by implication.

**Shard Vault:** Stores affix-specific resources recovered by dismantling an eligible item without normal item-slot pressure. The original item's primary biome is not a universal resource and must not be copied to unrelated gear.

**Affix Codex:** Permanently records discovery, highest naturally found tier, highest crafted tier and best value (with tier context) regardless of whether the source item is later consumed. Target T10 and perfect-value collection marks remain valid across rerolls.

**Deepwater Reliquary:** Signature powers are permanently extracted from eligible gear into a separate library, then slotted by allowed category. Affinity-gated signature powers inspect the **active** equipment instance and the relevant activity biome; no duplicate stacking of the same signature between equipped gear and Reliquary.

## 10. Current-stat integration: important limits

All current validation limits and proposed Tideforge caps are subject to change. Higher limits may be needed for ten-tier affixes and additional stats. Affix definitions, values and supporting mechanics can be revised as the combined system is developed; no replacement limits or numeric tuning are approved yet.

The new affinity luck bonuses cannot simply be added blindly to existing limits.

- Rod rarity-luck rule currently validates **at most 12,000 bp (+120%)**.
- Boat/net rarity-luck rule currently validates **at most 26,600 bp (+266%)**.
- Existing Prismatic Abyssal Boat already sits at **26,600 bp**, so adding even basic native affinity requires a deliberate rules update (or another specifically approved design approach), not a silent clamp.
- Rod Power max is **200**; at Power 185 the Prismatic Abyssal Rod already has **92.5%** bonus-pull chance. The new system should not assume there's lots of room for generic Power affixes.

Refer to the companion stat document for precise baseline tables, XP caps, stacking math and migration rules. No proposed additive bonus is final until the current rarity weighting method has been tested for positivity, normalization, precise legendary hierarchy and overflow at the proposed new ceilings.

## 11. Future biome expansion

All affinity rules must be catalog-driven. Each biome record gets an immutable ID and versioned metadata; equipment-family native affinity maps to a biome ID by explicit catalog association rather than name matching.

When adding a new biome:

1. Add biome and species content and the matching unlock/licence gates.
2. Explicitly add it to allowed affinity roll pools and reattunement pools; **do not silently dilute existing odds** without versioning and disclosure.
3. Determine whether new gear families default to that biome, or whether existing family native mappings stay unchanged.
4. Record new weights as a catalog version and verify sum = 100% / 10,000 basis points.
5. Retain existing players' rolled affinities untouched.
6. Test all category / family / rarity interactions and future dual-affinity cases.

Existing random rolls and in-flight snapshots keep their original content/rules versions. Newly available biomes are new options, not automatic reassignment of old items.

## 12. UI and player explanations

The Tackle Box equipment card should visibly separate:

- **Base gear** (permanent original type and base stats), **quality**, **raid reinforcement** and **shared family mastery**;
- **Innate affinity** (biome name and conditional benefit); **Secondary Affinity** if equipped;
- Four ordinary affix slots with IDs, T1–T10, rolled values and activation states;
- Signature power (if any) and whether its effect is also available from the Reliquary;
- “Active here” / “Inactive in this biome” without suggesting gear loses its normal base statistics.

Provide an **effective-stats breakdown** for the selected biome: Base + Quality + Bait (rod only) + Mastery + General Affixes + Matching Affinity + Signature. Show separate rows for numerical luck versus actual forecast catch/perfect probabilities. Do not label a +1% luck stat bonus as “+1% UUR chance.”

Gambling cards must clearly show **new duplicate instance**, inherited family mastery, and which investments do **not** transfer. Reattunement UI must preserve the old result until the user accepts. Mobile views must not rely on hover-only explanations.

## 13. Server state and migration strategy

This is a conceptual schema, not a mandate to overwrite existing SpaceTimeDB tables directly. Prefer additive records, identity mapping and versioned adapters. Key design domains:

- `gear_instance` (private): stable instance identity, owner, kind, family/definition, quality, reinforcement, primary biome, original lock, version, timestamps;
- `gear_affix` (private): instance + slot + affix ID + tier + value + optional selected secondary biome;
- `gear_family_mastery` (private): owner + kind + family + XP/level and progression version;
- `affix_definition` / `biome_affinity_catalog` (public static/versioned catalogs);
- `gear_loadout` (private): equipped instance IDs, rather than only definition IDs;
- `gear_action_quote` / `gear_action_receipt` (private): replay-safe and conflict-safe crafting / reattunement;
- caller-scoped views for own equipment/affixes, public summarized gear where explicitly intended.

**Migration:** Create one protected original instance per existing owned rod/boat/net; assign family native biome; preserve exact quality and rod reinforcement; map selected equipment to that instance; do not duplicate originals on retries. The existing rod reinforcement key is currently keyed by player and definition, so it must be converted or mapped **per instance** before allowing duplicates. Same issue for haul equipment ownership and equipped references. Preserve all player/collection/raid state, historical catch results, active jobs and quotes. Rollout must be additive, with a reversible switch before enabling duplicate purchases.

**Do not grant extra gear, reprice old items, reset cooldowns or publish destructive schema updates as part of migration.**

## 14. Required verification

- Native family mapping for all 21 existing family/category combinations.
- No lost original gear, quality, reinforcement, equipped selection or pending haul/raid.
- Native, non-native and secondary-biome activation for rods, boats and nets.
- Same-affix uniqueness, secondary-primary collision and reattunement rollback.
- Original-vs-gamble inheritance: only shared family mastery transfers automatically.
- Event/receipt idempotency, concurrent reforge/gamble, quote expiry, disconnects and rejected payment paths.
- Timed haul snapshot reproducibility after gear swaps, reforging, item removal and catalog changes.
- Filleting provenance and no historical-yield inflation.
- Full per-biome rarity simulations at every category/family/quality boundary and maximum affix combination.
- Verify positive ticket weights, expected total normalization, stable special/legendary relationships and unchanged perfect-measurement sampler absent a dedicated effect.
- Future biome additions and deterministic preservation of existing instances and in-flight results.
- UI and accessibility: 390 px mobile, no ambiguous “luck chance” language, explicit affix tiers, clear pending/accepted reattunement, no destructive default.

## 15. Remaining decisions before implementation approval

1. Final innate luck and mastery XP boosts (currently proposed +1% luck-stat / +5% mastery XP).
2. Whether Resonance and Attuned Fortune both remain in the catalogue or one becomes a different specialization.
3. Exact per-tier affix minimum/maximum values and cost curves across all gear types.
4. Final legal stat ceilings after full distribution simulations, including future new families/biomes.
5. Affinity gamble category/family eligibility, cost, naturally rolled tier weights and exact signature occurrence rates.
6. Mastery XP curve and per-action awards; raid completion mastery credit, if any.
7. Trader schedule and whether reattunement services are limited to the visit or available year-round at higher cost.
8. Specific signature power list, effect precedence and duplicate suppression.
9. Final data migration and source-of-truth ownership path for gear instances.

## Source snapshot

Historical repository revision used: [`8cbd208`](https://github.com/icha-senpai/worldengine/commit/8cbd2088a56c2c7763cc0425ad646d17f9a64e71), October 30, 2026.

Current source reviewed for this import: fb486d3a; see the decision index for later clarifications.

- [World, biomes and rods](https://github.com/icha-senpai/worldengine/blob/8cbd2088a56c2c7763cc0425ad646d17f9a64e71/fishing-game/content/world.json)
- [Boats and nets](https://github.com/icha-senpai/worldengine/blob/8cbd2088a56c2c7763cc0425ad646d17f9a64e71/fishing-game/content/haul-equipment.json)
- [Existing casting logic](https://github.com/icha-senpai/worldengine/blob/8cbd2088a56c2c7763cc0425ad646d17f9a64e71/fishing-game/spacetimedb/src/casting.rs)
- [Existing timed haul logic](https://github.com/icha-senpai/worldengine/blob/8cbd2088a56c2c7763cc0425ad646d17f9a64e71/fishing-game/spacetimedb/src/hauls.rs)
- [Current rod luck/power bounds](https://github.com/icha-senpai/worldengine/blob/8cbd2088a56c2c7763cc0425ad646d17f9a64e71/fishing-game/crates/game-rules/src/rods.rs)
- [Current haul bounds](https://github.com/icha-senpai/worldengine/blob/8cbd2088a56c2c7763cc0425ad646d17f9a64e71/fishing-game/crates/game-rules/src/hauls.rs)
- [New raid system in commit](https://github.com/icha-senpai/worldengine/blob/8cbd2088a56c2c7763cc0425ad646d17f9a64e71/fishing-game/spacetimedb/src/raids.rs)

---

**Handoff principle:** Use the old game as the reference baseline when no Tideforge bonuses apply, except for separately approved balance changes. Add deliberate, transparent conditional bonuses where they do. All numbers marked proposed are design candidates requiring simulation, not silent implementation defaults.
