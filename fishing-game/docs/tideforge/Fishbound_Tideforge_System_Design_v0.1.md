# Fishbound: Tideforge Equipment, Crafting & Progression System

**Design document:** v0.1 · October 30, 2026  
**Status:** Collaborative design in progress; **not** an approved implementation specification  
**Purpose:** Preserve the complete design direction discussed so far, including established decisions, proposed mechanics, dependencies, and questions still to settle. Intended as a durable starting point for further design with ChatGPT/Devin before implementation.

> Repository working copy; the Desktop original is unchanged. The [current decision index](README.md) governs these proposals where older wording differs. Current code is a reference baseline, not a final Tideforge tuning contract. Proposed values remain open unless explicitly approved.

> **Design philosophy:** The player keeps getting stronger and finds new goals to chase, but advancement opens choices rather than forcing one activity. Old equipment remains relevant; rare outcomes stay rare; progress already earned stays earned. We are designing the **whole interconnected system**, not restricting design to a minimal vertical slice.

## 0. Decision status legend

To prevent an implementation agent from mistaking brainstorming for final requirements:

- **AGREED:** Explicitly selected or positively affirmed during discussion.
- **DIRECTION:** Strong design direction or working structure, but specifics remain negotiable.
- **PROPOSED:** Suggestion worth designing in full before approving.
- **OPEN:** Requires a decision, numerical calibration, or investigation against the actual game.
- **EXISTING:** Functionality already in Fishbound, based on the repository review and developer's descriptions; integration must preserve its behavior unless explicitly changed.

**Nothing marked DIRECTION or PROPOSED is authorization to silently implement or lock its numerical values.**

---

## 1. Vision & high-level goals

**AGREED / DIRECTION**

Fishbound is an asynchronous Discord-first fishing and collection game with a companion website.

Tideforge is a simple, fun and forgiving gear layer. ARPG inspiration means optional bonus combinations, not required expertise or punishing build traps. Ordinary choices should remain useful. More active players may progress faster; do not add friction or permanent bricking to enforce a multi-year completion date.

The accessible baseline is around 24 total fishing actions/casts per day in any mixture of rod, net and boat activity, plus roughly one or two raids or raid check-ins as booked durations allow. This is not 24 mandatory rod casts plus extra required hauls. More activity may accelerate progress. Earlier three-to-four-year language is background aspiration, not a mandate to slow players down. Distinguish actions from result counts; long raids can span daily check-ins.

Progress already has multiple dimensions: player level, discovering species, finding ranks, achieving exact rank maxima, chasing perfect UURs, improving equipment, completing raid grades, and pursuing unusual legendary encounters. New systems should **add more routes and more interesting decisions** rather than erase existing grinds.

The new Tideforge system connects:

1. **Equipment instances and randomized affixes** inspired by ARPG itemization.
2. **Gambling** inspired by Diablo III's Blood Shards/Kadala concept.
3. **Gear-family mastery** through actual rod, boat, and net use.
4. **Forging** inspired by Last Epoch, with accessible, repeatable affix improvements.
5. **An Affix Shard Vault** for extracted crafting resources.
6. **An Affix Codex** recording discovery, tiers, and perfect values permanently.
7. **A Cube-style power library** for extracting and equipping signature abilities.
8. **Biome specialization** that can keep earlier equipment useful.
9. **Fish filleting** as an alternative source of normal fillets and scales, convertible into raid-grade material pathways.
10. **A monthly wandering trader** to create periodic excitement and concentrated gear-gambling opportunities.

A player should be able to largely ignore a subsystem and still enjoy meaningful progression elsewhere. Full 100% completion may involve every system, but ordinary play must not require each one.

### Non-goals / guardrails

- No mandatory prestige reset; prestige was discussed, **not selected**.
- No erasing old collections, equipment quality, raid progress, or earned records.
- No unrestricted bonus stacking that turns UUR perfects into common catches.
- No forced duplicate-gear farming for baseline game access.
- No permanent item bricking or arbitrary forging-potential exhaustion.
- No assumption that gambling must deliver the optimal item to progress.
- No need to create numerous new currencies when existing coins/materials can carry costs.

---

## 2. Existing Fishbound systems to preserve

**Historical source snapshot; integration context.** The connected `icha-senpai/worldengine` repository was inspected during the October 30 conversation, including the then-latest Fishbound raid commit, [`8cbd208`](https://github.com/icha-senpai/worldengine/commit/8cbd2088a56c2c7763cc0425ad646d17f9a64e71). This section summarizes what the new design must account for; verify against current code before implementing.

Current source reviewed for this import: fb486d3a; see the decision index for later clarifications.

### Equipment today

- Seven core gear **families**, each with a rod, boat, and net variant (21 category/family combinations).
- Ownership currently behaves like **one copy per equipment definition**, not an inventory of individually rolled instances.
- Rods, boats, and nets have individual owned quality upgrades from **Common → Uncommon → Rare → Epic → Legendary → Mythic → Prismatic**.
- Boats and nets have independently equipped slots and independent quality; rods are selected separately.
- Boats/net statistics are not simple rod clones: the existing catalog uses **4× matching rod base luck/XP for boats** and **2× for nets**, with their own quality additions. They do not use rod bait, rod power, or rod quality.
- A normal boat haul takes **10 minutes** and yields **10 pulls**; a net takes **4 minutes** and yields **4 pulls**. Ordinary rod fishing can happen alongside normal hauls.
- Hauls capture their starting gear/stats; switching equipment later does **not** retroactively change an active haul's outcome.
- Specialized bait on rods creates a separate progression axis for rank maxima/perfect measurements. Normal luck influences rarity weights on rods, boats, and nets.

Bait and affixes are complementary sources of effects. Bait, affixes, or both may be adjusted through explicit balance work. Existing bait behavior is the reference baseline, not a promise that it can never change; preserve earned progress and saved results while defining the combined effect rules.

### Raids and reinforcement

The raid system has ten grades (`F, D, C, B, A, S, SS, SSS, UR, UUR`) across **249 ordinary species**, plus two special legendary encounters. Progress is stored per species, and grades unlock sequentially per species. A complete one-species grade ladder is **54 hours** of raid time, before deployment overhead; all 249 full ladders total **560.25 days** if pursued serially and continuously. Community expeditions also exist.

- Deployed/running raids reserve the boat, so it cannot simultaneously run a regular boat haul. Rod casts and nets continue independently.
- Personal raids produce **graded Raid Fillets and Raid Scales**.
- The raid workshop currently refines **60 of a material at one grade into 50 at the next**, with a small coin cost (currently **5 coins per batch**).
- **Important existing rule:** the destination grade must already have been cleared in a raid before refinement to that grade. This rule is retained for Tideforge's higher-grade access; fish-derived materials fund crafting without bypassing raid progress.
- Rod reinforcement is a distinct, existing raid-earned advancement path and improves Collection Focus / eligible end-point perfect opportunities. Preserve its identity unless a separate design decision changes its prerequisites.
- Raid actions and rewards run through authoritative SpaceTimeDB reducers and replay-safe receipts; completion can occur while clients are offline.

Raids and website-only raid management are already live. Tideforge remains design-only and is not deployed.

---

## 3. Gear identity: vanilla originals and additional rolled instances

**AGREED**

- The **first original purchase** of each equipment family/type is the **vanilla item** with its ordinary deterministic identity.
- Players may later obtain **additional instances** of the same family/type as coin-bought Camp copies with low-tier affixes or through the monthly wandering trader's very-high-tier gear.
- **Additional copies inherit gear-family mastery**, whether bought at Camp or obtained through Mark gambling.
- The vanilla original remains valuable, can be developed, and should have strong protection against accidental destruction or replacement.
- Each **item instance** has its own randomized affixes, quality/upgrades, and (where eligible) raid reinforcement. These do not magically copy from another instance of the family.

An angler has one active main equipment loadout, with one item per supported slot. Duplicate instances and saved presets do not create additional activity slots. An item committed to a raid locks changes to that active equipment loadout until the commitment ends, including direct equipment changes and preset switching. A second boat cannot bypass the reservation. Rod fishing and net activities remain available with the already selected gear.

### Proposed identity structure

**DIRECTION**

Each owned piece has:

| Property | Scope | Notes |
|---|---|---|
| Unique `instance_id` | Item instance | Immutable identity; use this for actions and equipment selection. |
| `equipment_kind` | Definition | Rod, boat, or net. |
| `family_id` / base definition | Definition | The familiar Twig/River/.../Abyssal family and its innate stats. |
| `is_original` | Instance | Marks and protects the first vanilla acquisition. |
| Base stats and family unlock | Definition | No random changes to base catalog identities. |
| Quality tier | Instance | Existing Common–Prismatic progression adapted to instances. |
| Reinforcement level | Eligible rod instance | Retains existing raid advancement logic, now item-specific. |
| Affix slots, tiers, values | Instance | Individual randomized/crafted properties. |
| Signature power | Instance | Candidate for use or permanent extraction. |
| Mastery level/XP | **Owner + gear family** | Shared by all original and additional copies of that family/type. |
| Biome specialization | Instance | Innate primary biome per instance plus compatible affinity affixes; exact assignment weights/services open. |
| Favorite/locked status | Instance | Protect from salvage, extraction, and bulk actions. |

**Migration principle:** existing owned rods/boats/nets must each become exactly one legitimate, preserved original instance carrying their real quality/reinforcement. Convert equipped references and quote/reducer paths safely. No duplication of old items, no loss of upgraded gear, and no account-specific reset.

**AGREED / OPEN:** Coin-bought Camp copies and monthly wandering-trader gear are both additional-instance sources. Exact acquisition eligibility, unlocks and family/category targeting remain open; see the [build contract](build-contract-v0.1.md).

---

## 4. Gear-family mastery & levels

Older families retain lower base stats as part of the existing progression, but should remain reasonably comparable and respectable in useful roles. The goal is not identical stats or guaranteed best-in-slot status for every family. Evaluate affinity, mastery and affixes together while preserving earned investment.

**AGREED:** Gear levels unlock affix slots through mastery shared per owner, equipment kind and family. Progression is earned through use, and additional equipment inherits its family mastery. Exact slot-unlock levels remain open.

**PROPOSED:** Use an individual **1–100 mastery track for each player × equipment-kind × family**. The family, not an individual additional copy, owns the level. The item still has its own quality and affixes.

- **Rod mastery** from real rod casts.
- **Boat mastery** from completed boat trips.
- **Net mastery** from completed net trips.
- Only authoritative accepted gameplay events award mastery XP; retries or duplicate claims do not.
- Avoid letting ten boat catches grant ten times the intended *mastery action* XP without analyzing relative effort. Catch XP and gear mastery XP need not be the same.
- Normal gameplay should progress mastery naturally, without a dedicated grind screen.

### Candidate mastery milestone progression

| Family mastery | Candidate benefit |
|---|---|
| Level 10 | Unlock/open first affix slot |
| Level 25 | Second affix slot |
| Level 50 | Third affix slot |
| Level 75 | Fourth affix slot |
| Level 100 | Family mastery perk, visual distinction, or cosmetic recognition |

Small bounded stat improvements might occur every five or ten levels. The milestone table above is **proposed, not approved balance**. The number and timing of slots must be reconciled with affixes on additional copies before mastery unlocks a slot.

**OPEN:** XP curve, catch-versus-trip weighting, exact dormant-slot activation/editing rules, mastery overflow beyond the working cap and future family integration.

---

## 5. Randomized affixes: ARPG-style itemization

**AGREED**

- An item may have **multiple affixes** rather than a single rerolled bonus.
- Each affix has its **own tier**.
- The tier system is **T1 through T10**.
- The Forge directly adds a chosen T1 affix, guarantees tier advancement and visible roll improvement, and directly changes type while retaining tier and paid roll finish.
- Higher tiers cost somewhat more but **not an absurd exponential amount**.
- Specific rolls and high-tier values create an ongoing chase independent of the base gear's quality.
- Biome-specific benefits should make old gear situationally competitive.

### Proposed affix categories

**DIRECTION / PROPOSED; complete catalog not yet designed**

| Pool | Possible effects | Design constraints |
|---|---|---|
| General | Luck, experience, bonus pulls, measurement-related quality | Must respect total caps. |
| Rod-focused | Selected-biome affinity, missing rank collection assistance, targeted size/perfect chase | Do not duplicate bait benefits without cost/limits. |
| Boat-focused | Broad-haul specialization, treasure chances, catch diversity, biome affinity | Preserve non-targeted, no-bait identity of boats. |
| Net-focused | Variety, uncommon-catch support, specific-haul specializations, biome affinity | Preserve distinct role from rod and boat. |
| Signature | Unusual build-changing abilities with fixed identities | Extractable into the Reliquary; no unconditional best-in-slot power. |

An affix has an identity, category, gear compatibility, tier, numerical range, actual rolled value, and stacking group/limit. Higher tiers unlock stronger ranges. **Affix tier and base equipment quality are separate progressions**; a modest-quality item can potentially roll an unusually valuable affix, subject to balance rules.

All current validation limits and proposed Tideforge caps are subject to change. Higher limits may be needed for ten-tier affixes and additional stats. Affix definitions, values and supporting mechanics can be revised as the combined system is developed; no replacement limits or numeric tuning are approved yet.

### Biome affinity

**AGREED:** Equipment can be particularly strong in a biome, making earlier families viable specialists rather than disposable stepping stones.

**DECIDED:** Use hybrid innate affinity with amplifying affixes, as described in the biome design. Family affinity weights, attunement services and numerical effects remain open pending balance work.

### Older example item (illustrative, not a valid current-catalogue build)

**Abyssal Rod**, Prismatic quality, SS reinforcement, Family Mastery 64/100:

- T4: +8.2% general luck
- T5: +13.4% luck in the Abyssal biome
- T3: +3.1% bonus pull chance
- T2: +2.8% measurement-related quality
- Signature power: *Abyssal Resonance* (effect TBD)

**These values and effects are examples, not the final affix catalog or caps.**

---

## 6. The wandering trader & equipment gambling

**AGREED:** A wandering trader visits once per month and offers gear with very-high-tier affixes; the exact visit window remains open. The Camp trader sells additional gear for coins with low-tier affixes. **Driftmarket** remains a working event name.

Gambling uses **Dredge Marks** earned from raids and achievements. One Mark is equivalent to 1,000 coins for pricing; River rods, boats and nets cost 1 Mark each as the approved exception. The [build contract](build-contract-v0.1.md) lists the accepted per-item family prices and proposed per-grade payout split. One full ordinary F-to-UUR raid ladder earns 65 Marks, and two earn 130 from raids alone; achievement Marks are extra. This pricing equivalence does not authorize currency exchange.

The gameplay loop:

1. Earn Dredge Marks from raids and achievements.
2. Visit the trader during the scheduled window.
3. Review available choices under the final eligibility and targeting rules.
4. Spend Dredge Marks to obtain an additional equipment instance; stock versus roll remains open.
5. Decide whether to **equip, forge, store, dismantle, or extract an eligible signature**.
6. Dismantling recovers Vault resources for all affixes; signature extraction consumes the item instead.

**Design principles:**

- Gambling results cannot overwrite an existing original or owned item.
- Mastery remains shared across copies of that kind/family.
- Unwanted rolls should retain salvage/extraction value.
- The trader is a community event, not a compulsory appointment to remain competitive.
- A monthly visit must offer enough opportunity that missing one visit is not catastrophic.

**OPEN:** Exact monthly visit window and time zone; stock-versus-roll interaction; purchase eligibility and selection; individual raid-grade and achievement payouts; inventory capacity and duplicate limits; pity or duplicate protection (none approved); whether exclusive trader modifiers exist.

---

## 7. The Forge: flexible, material-funded crafting

**AGREED:** Borrow Last Epoch's enjoyable affix forging and vault feel, **without** forcing Last Epoch's item-crafting restrictions. Any eligible affix should eventually be upgradeable if the player has appropriate resources. No permanent bricking.

Ordinary affix tiers use raid materials. The [Forge flow](forge-and-refinement-v0.1.md) is accepted; [Forge costs](forge-cost-model-v0.1.md) and [processing recipes](processing-and-material-economy-v0.1.md) propose quantities and yields, not approved values. Fish processing is another material source, not a bypass of raid access gates. Higher-grade material crafting/refining retains the shared destination-grade ordinary raid-clear unlock. Conversion itself grants no seals, completion statistics or reinforcement eligibility.

A direct T1-to-F through T10-to-UUR mapping is a working proposal, not an approved recipe.

Dismantling an eligible item returns matching Vault resources for all its affixes, including dormant affixes. Signature extraction is the competing choice: consume an eligible item to learn its signature in the permanent Reliquary instead of recovering its affixes. Do not grant both signature and affix-shard outcomes from the same item. Resource quantities, tier/value handling and any ordinary-material refund remain open. Protected originals and favorites retain their safeguards.

### Forge actions

| Action | Intended operation | Status |
|---|---|---|
| **Refine value** | Guaranteed visible improvement toward the legal tier maximum; affix identity stays | AGREED (flow) |
| **Reforge affix** | Directly choose a compatible replacement, retaining tier and relative roll finish | AGREED (flow) |
| **Upgrade tier** | Guaranteed T1-to-T10 advancement while retaining roll finish | AGREED (flow) |
| **Protect/lock** | Preserve favorite affixes while working on others | DIRECTION |
| **Dismantle** | Consume eligible equipment to recover Vault resources for all its affixes | AGREED |
| **Extract signature** | Consume eligible equipment to permanently learn its signature instead of recovering affixes | AGREED |
| **Add affix** | Directly choose a compatible T1 modifier for an unlocked slot | AGREED (flow) |

### Earlier candidate ten-tier cost curve (not selected)

The **principle** of small incremental cost growth is agreed; exact prices are not.

The [first Forge cost model](forge-cost-model-v0.1.md) proposes a quarter-clear
comparison as an alternative to this historical table, not an extra multiplier
or shard ladder to stack with it. All recipes remain provisional.

| Destination tier | Candidate affix shard count | Relative non-shard crafting cost |
|---|---:|---:|
| T1 | 1 | 1.0× |
| T2 | 1 | 1.1× |
| T3 | 1 | 1.2× |
| T4 | 1 | 1.3× |
| T5 | 2 | 1.4× |
| T6 | 2 | 1.5× |
| T7 | 2 | 1.6× |
| T8 | 3 | 1.7× |
| T9 | 3 | 1.8× |
| T10 | 4 | 2.0× |

**Candidate only.** Costs must be calibrated against real catch/day distributions, actual material generation, gambling frequency, and all the affixes a player might wish to perfect. In particular, a T10 should be achievable rather than an absurd multi-year task per single affix.

### Crafting behavior requirements

- Preview cost, before/after stats, and any irreversible effects prior to confirmation.
- Costs deducted only by server-authoritative successful transactions.
- Stale previews, insufficient funds, invalid tiers, disconnects, and duplicate requests cannot accidentally spend twice.
- Protected or equipped originals cannot be destroyed through an accidental misclick.
- Upgrading an affix's tier and perfecting its numeric roll are distinct actions and progression goals.
- **AGREED / OPEN:** Ordinary tier upgrades are guaranteed and do not regress. Detailed conversions, prices, locking/reshaping costs and signature acquisition remain open.

---

## 8. Affix Shard Vault

**AGREED:** Build a Last Epoch-style **account-wide Vault** for extracted affix crafting materials, separate from the normal fish inventory.

Vault properties:

- Permanent, effectively unlimited storage for **affix shards** or equivalent crafting components.
- Automatic Vault storage for all affixes on dismantling, with no physical tackle-box clutter; signature extraction is the mutually exclusive Reliquary route.
- Organized by affix identity, compatible gear categories, and perhaps tier/rarity.
- Displays quantities, tier eligibility, and what Forge actions each resource enables.
- Consuming a shard changes inventory but **not historical collection progress**.
- Rare shards can be collected long before a player chooses to use them.

**OPEN:** Exact shard taxonomy, tier/value handling, and deterministic vs chance-based quantities. Do not invent these during coding.

---

## 9. The Affix Codex: permanent collection progression

**AGREED:** A separate **Affix Codex** is a completionist collection system. The Vault is **what you own now**; the Codex is **what you have ever achieved**.

For every affix identity, permanently record:

1. **Discovery:** whether the player has found/extracted that affix.
2. **Highest tier:** maximum T1–T10 achieved via extraction or crafting.
3. **Best numerical roll:** personal best value and its tier, with enough normalization to compare distinct tier ranges accurately.
4. **Tier mastery:** reach T10.
5. **Perfection mark:** reach the maximum possible value of the top tier.

Progress is permanent even after crafting consumes every shard. Optional milestones can reward cosmetics, titles, badges, or restrained convenience rather than multiplying raw stats too aggressively.

Possible Codex UI: total affixes discovered, each item's tier-track, strongest value seen, perfected marks, searchable categories, and an overall completion percentage.

**OPEN:** Whether Codex discovery occurs on *rolling* an affix or specifically on *extracting* it. Earlier discussion used extraction as the canonical milestone, but crafting upgrades should unquestionably raise the best-tier record. Also decide how different modifiers with multiple variants contribute to 100% completion.

---

## 10. The Deepwater Reliquary: Cube-style extracted powers

**AGREED / DIRECTION:** Add a Kanai's Cube-inspired **permanent power library**, distinct from the consumable Affix Vault.

- Selected randomized equipment can carry a **signature power**.
- **Extracting** the signature power consumes an eligible equipment item and permanently unlocks that power in the account library.
- The player can activate extracted powers without equipping the source item.
- Extracted powers are independent from the source item's temporary random affix rolls: signature extraction grants the defined power, not affix-shard outcomes.
- Do not stack the same signature power simultaneously through equipped gear and the Reliquary.

### Proposed three Reliquary slots

| Slot | Intended power class |
|---|---|
| **Rod** | Rod/casting-related signature ability |
| **Haul** | Boat/net or haul-related signature ability |
| **Waters** | Biome or environmental specialization |

Saved presets could combine gear selection, chosen powers, and biome plans for different goals: general fishing, rank-maxima hunting, UUR collection, and broad hauling.

**OPEN:** Three slot classes are proposed rather than approved. Need actual power catalog, unlock timing, slot restrictions, duplicates/cross-category effects, and balance policies. In a multi-gear setup, determine whether a Rod power should affect boat/net actions at all; default assumption is **no unless explicitly designed**.

---

## 11. Fish filleting: another material source

**AGREED:** Players should be able to **sell**, **keep**, or **fillet** suitable fish. Filleting generates **normal fillets and normal scales**. Those can be **combined into F-grade Raid Fillets and Raid Scales** to feed the existing material-refinement ladder.

Ordinary affix tiers use raid materials. The [Forge flow](forge-and-refinement-v0.1.md) is accepted; [Forge costs](forge-cost-model-v0.1.md) and [processing recipes](processing-and-material-economy-v0.1.md) propose quantities and yields, not approved values. Fish processing is another material source, not a bypass of raid access gates. Higher-grade material crafting/refining retains the shared destination-grade ordinary raid-clear unlock. Conversion itself grants no seals, completion statistics or reinforcement eligibility.

### Material flow

```text
Ordinary fishing / boat haul / net haul
                 │
          Caught fish instance
                 │
      ┌──────────┼──────────┐
      ▼          ▼          ▼
     Sell       Keep       Fillet
     Coins    Collection   Normal Fillets + Normal Scales
                              │
                              ▼
                       Craft F-grade materials
                              │
                              ▼
                   Current baseline: 60:50 grade refining
                              │
                              ▼
                        D → C → ... → UUR
                              │
                    ┌─────────┴─────────┐
                    ▼                   ▼
             General gear crafting   Raid-related uses
```

### Filleting rules

**DIRECTION**

- Filleting consumes the **specific owned specimen**, like selling does; it does not erase previously registered discoveries, rank maxima, perfect marks, or records.
- Calculate normal fillets/scales from the specimen, with **size and/or rank influencing yields**. Formula not decided.
- Yield normalization should account for species size so a huge fish species does not automatically monopolize material farming.
- Players can fillet in batches. Preview exact materials to be awarded and which specimens will be consumed.
- Favorites and valuable specimens have opt-in protection; default safeguards prevent an accidental UUR perfect from being filleted.
- Special legendary fish should not automatically be eligible for ordinary filleting. **OPEN** whether they are wholly excluded or receive their own special processing path.
- Filleting needs a durable material ledger and authoritative transaction/replay safeguards.

### Raid Clears Unlock Higher-Grade Crafting

Higher-grade material crafting/refining retains the shared destination-grade ordinary raid-clear unlock. Fish processing provides materials but does not bypass this access gate. A qualifying clear at a grade unlocks shared access across species; clearing every boss is not required.

This supersedes the earlier proposal to remove the raid-clear prerequisite for a fully non-raiding refinement ladder. Conversion itself awards no raid seals, completion statistics or reinforcement eligibility.

### A necessary economy check

The existing 60→50 conversion compounds across nine grade steps. Without direct high-grade farming, converting F materials all the way to UUR could become wildly expensive. Evaluate viability against the accepted operations and candidate material budgets.

Design and simulate F-material generation per fish (by rank, size, biome, gear type), costs to refine every tier, and attainable UUR crafting with the required raid-grade unlocks. Evaluate candidate quantities against the accepted operations; exact conversion rates and refinery perks remain open.

---

## 12. Interlocking progression loops

The intent is several complementary choices rather than one optimal mandatory route.

### Collection player

Fish for new species and ranks, chase exact maxima and perfect UURs, fillet surplus fish for materials, develop targeted rod affixes, and gradually fill the Affix Codex.

### Broad-haul player

Use boats and nets to produce many untargeted catches. Trade off their excellent broad statistics against the lack of bait/targeting. Process excess fish through filleting and feed the Forge.

### Raid-focused player

Commit a boat to raids, earn graded materials efficiently, complete species raid seals, and reinforce rods. While a raid is active, ordinary boat hauls are unavailable. Rod and net play remain available.

### Gear enthusiast

Gamble during monthly trader visits, chase favored affix identities and signature powers, grow the Vault and Codex, then forge specialist equipment.

### Casual player

Fish at a comfortable pace, enjoy incremental gains, use existing deterministic original equipment, and engage with complicated subsystems only if desired.

These approaches should all remain rewarding. **No single loop should render the others pointless.**

---

## 13. Progression balance and guardrails

The accessible baseline is around 24 total fishing actions/casts per day in any mixture of rod, net and boat activity, plus roughly one or two raids or raid check-ins as booked durations allow. This is not 24 mandatory rod casts plus extra required hauls. More activity may accelerate progress. Earlier three-to-four-year language is background aspiration, not a mandate to slow players down. Distinguish actions from result counts; long raids can span daily check-ins.

### Important modeling requirements

1. Simulate at least **casual**, **comfortable daily**, **active**, and **very high activity** profiles; include actual rod, net, and boat schedules rather than pretending all pulls are equivalent.
2. Measure the effect of boat 10-pull hauls every 10 minutes and net 4-pull hauls every 4 minutes. Account for the fact that normal rod casts, nets, and boats can overlap when a raid isn't reserving the boat.
3. Model **rarity acquisition**, exact length + weight maxima, specialized bait, extra measurement attempts, Collection Focus, raid reinforcement, gear affixes, and Reliquary powers together.
4. Preserve the meaningful possibility of extreme outcomes, from ten rusty tins to spectacular rare hauls. RNG can generate community folklore; do not flatten it for cosmetic consistency.
5. Distinguish additive and multiplicative bonuses, establish global caps/stacking groups, and test probability distributions rather than relying on displayed percentages.
6. Define what counts as **100% completion** versus optional extreme trophies, especially the two legendary perfects, without enforcing a calendar-length grind.
7. Ensure earning enough crafting shards and material to perfect a few beloved pieces is practical, while the entire Vault/Codex/equipment ecosystem can support a much longer pursuit.
8. Track actual player behavior over time rather than relying only on mathematical worst/best cases.

### Suggested testing metrics

- Rod casts, completed net trips, completed boat hauls, and raid time per day.
- Breakdown of fish/junk/treasure and rarity distribution by method and biome.
- Normal fillet/scale production per day, grade refinement throughput and bottlenecks.
- Gambling purchases, duplicate rates, kept items, dismantle rates, and currencies spent.
- Affix tier progression, attempts/cost to T10, fraction of items at perfect values.
- Vault shard stockpiles, Codex discovery and perfection progress.
- Old-family gear usage after unlocking new families; biome specialization effectiveness.
- Total time to representative outcomes under each play profile; check outliers.

**No final numeric economy can be approved from this document alone.**

---

## 14. UI and quality-of-life plan

**DIRECTION / PROPOSED**

Keep the present Tackle Box structure and add cohesive interfaces rather than scattering critical systems across unrelated menus.

- **Gear inventory:** category/family filters; original vs additional indicator with Camp/Mark source provenance; rarity/affix tier summary; item locks; equipped status; compare stats with equipped item.
- **Item detail:** base stats, quality, mastery, reinforcement, affixes, signature power, biome affinity, provenance/history.
- **Forge:** select item and exact affix; preview valid actions, before/after rolls and costs; confirm transactions; clear error and retry states.
- **Shard Vault:** searchable affix storage, quantity, tier/compatibility labels, quick jump to Forge.
- **Affix Codex:** discovered/undiscovered entries, highest-tier progress, best roll, perfection marks, category completion and search.
- **Reliquary:** power library and active slot selections, unambiguous incompatibility/stacking states, preset support.
- **Trader:** regular Camp coin purchases and monthly Mark trader schedule, wallet and selection/roll controls; result reveal and keep/extract/dismantle choices.
- **Filleting:** individual and bulk selections, protected-item defaults, full material preview, confirmation, and ledger updates.
- **Build presets:** switch equipment/power selections without modifying in-progress haul snapshots; equipment changes and preset switches are locked during raid commitment.
- Keyboard and mobile accessibility; no deceptive disabled controls or disappearing actions.

---

## 15. Technical architecture (Rust + SpaceTimeDB + Svelte + Discord)

**DIRECTION:** Server-authoritative implementation; do not expose any client-controlled RNG or trust local clocks for economic actions.

### Conceptual data domains, not committed table names

- **EquipmentInstance:** unique item identity, ownership, base definition, quality, original/protection flags, affix rolls, signature power link and reinforcement where applicable.
- **GearFamilyMastery:** player, kind/family, XP, current level and milestones.
- **AffixDefinition / AffixTierDefinition:** content-driven pools, legal kinds, numeric ranges, tier growth, balancing caps and version.
- **EquipmentAffix:** item + slot + definition + tier + numerical roll.
- **ShardBalance:** player + affix shard identity + quantity.
- **AffixCodexProgress:** permanent discovery, highest tier achieved, best values and perfection marks.
- **ExtractedPower:** player + permanent unlocked signature ID.
- **ReliquaryLoadout / Preset:** per-player active powers, saved configurations and validation data.
- **TraderCalendar / GambleReceipt:** event windows, authenticated actions and idempotent RNG result records.
- **FilletReceipt / CraftingReceipt:** exact source specimen/item IDs, material accounting and replay protection.

These can be one or multiple tables depending on indexing, query patterns, retention, and schema evolution. **Do not automatically create them all as-is.**

### Cross-cutting invariants

- Exact, immutable **instance IDs** are mandatory when duplicate equipment definitions become legal.
- Every economic mutation is atomic and independently authorized server-side.
- Preview/quote APIs cannot authorize an action after inventory, equipment state, price/catalog version, or target selection changes.
- Request receipts are replay-safe and resist duplicate Discord/web submissions.
- Equipment affixes and Reliquary powers are **snapshotted** for hauls/raids at the appropriate start/commit boundary; allowed later changes cannot alter a saved result.
- Existing player account linking, collection progress, equipment, quality, raid seals and reinforcement must survive additive upgrades and preservation proofs.
- Gear ownership / private crafting data must remain player-scoped; only explicitly public profile/Codex fields are exposed.
- Content catalogs define legal affixes, tier prices, powers, slot compatibility, and maximum stacking so a live balance patch doesn't arbitrarily reinterpret in-flight activity.
- Prevent unauthorized migration/reset, double grants, item duplication, negative/overflow balances, and loss of original protected gear.

An angler has one active main equipment loadout, with one item per supported slot. Duplicate instances and saved presets do not create additional activity slots. An item committed to a raid locks changes to that active equipment loadout until the commitment ends, including direct equipment changes and preset switching. A second boat cannot bypass the reservation. Rod fishing and net activities remain available with the already selected gear.

Junk rewards should have a variable quantity spread so percentage bonuses can meaningfully improve output. The range, distribution and fractional-rounding policy remain open; a spread alone does not prevent small bonuses being lost to integer rounding. This changes reward quantities, not biome encounter-category probabilities by implication.

### Verification expectations

Tests should cover migration from single-definition ownership, duplicate instance acquisition, original protection, concurrent gambles/reforges, failed payment/receipt replay, disconnected clients, favorite safeguards, gear snapshot boundaries, crafting to T10, Codex permanence after spending, extraction once-only semantics, refining with the required raid-grade unlock, preserving raid-only seals/reinforcement gates, and distribution simulations at scale.

---

## 16. Decisions already made vs still open

### Explicitly agreed

- Design the **full system**, not only a small pilot feature.
- Bring Diablo-style random equipment gambling into Fishbound.
- First original equipment purchase is vanilla; randomized duplicates become possible later.
- **All original and additional copies share owner/kind/family mastery progression.**
- Gear levels unlock affix slots; exact mastery milestones remain open.
- Camp sells low-tier affix copies for coins; the monthly wandering trader offers very-high-tier gear for Dredge Marks earned from raids and achievements.
- Add gear leveling, multi-stat affixes, biome usefulness, and room for older gear.
- **Ten independent affix tiers**, with moderate progression costs and resource-funded forging.
- The four ordinary Forge actions use direct T1 Add, guaranteed Upgrade Tier, guaranteed visible Improve Roll and direct Change Affix, retaining paid roll finish.
- Add a Last Epoch-like **Affix Shard Vault**.
- Add a permanent **Affix Codex** with discovery, tier mastery, and perfect-roll records.
- Add fish filleting that yields **normal fillets and normal scales**.
- Convert normal materials into **F-grade raid materials** as another material source while retaining raid-grade access gates.
- Keep raid progress meaningful while keeping the gear economy accessible and forgiving.
- Retain shared **destination-raid-clear access** for higher-grade material crafting/refining, without granting raid seals or raid-reinforcement eligibility through conversion.

### Strong directions but not final design approval

- Detailed Forge permissions, conversions and prices remain open.
- Mastery 1–100 per owner/gear family, with four affix slots unlocked over time.
- Up to four affixes and a signature power on some gear.
- Cube-like **Deepwater Reliquary**, with proposed Rod / Haul / Waters slots.
- Signature extraction consumes equipment and permanently unlocks the corresponding power.
- Build presets, protected originals, favorite item locks, and batch filleting.

### Open design questions (to settle before the final implementation brief)

Propose simple mastery and gambling defaults for review; exact curves, access, payout distribution and trader interaction remain open within the accepted sources and pricing.

1. **What is the actual affix catalog?** Complete effect IDs, categories, compatibility, tier-specific ranges, weighting, rarities, and hard caps.
2. **How should hybrid biome affinity be balanced?** Set assignment weights, bonuses and family roles while retaining innate primary affinity and amplifying affixes.
3. **What is the gear-mastering XP curve?** Exactly which actions grant it, at what pace, and how do mastery slots interact with rolled loot?
4. **What are the remaining gambling contracts?** Individual raid-grade and achievement payouts, acquisition eligibility, family targeting, inventory limits, stock-versus-roll interaction and the exact monthly visit window.
5. **How do Forge actions work mathematically?** Final shard recipes, costs, lock rules, legal visible refinement steps, retained-finish conversions and extraction payouts. Ordinary tier upgrades and paid roll improvements are guaranteed; success probabilities and value reroll distributions are not open questions for those actions.
6. **How does the signature power system work?** Full power catalog, actual slot count, category rules, unlock timing, conflict/stacking restrictions.
7. **What precisely is a shard?** Affix-specific, affix+tier-specific, or some other taxonomy? Determine quantities and tier/value handling for recovery of all affixes.
8. **How does Codex discovery trigger?** Rolling vs extracting; highest-value records across tier ranges; exact criteria for fully perfected entries.
9. **What do fish fillet into?** Yield formulas by species/rank/length/weight; protections; eligible fish; exact F-grade conversion ratio.
10. **Can fish-derived materials support UUR crafting?** Recalculate the existing 60:50 chain with required raid-grade unlocks and consider balance interventions.
11. **How is the old item model migrated?** Instance IDs, equipped references, upgrade quotes, quality, reinforcement, originals, and rollback safety.
12. **How should accessible mixed-activity progress feel?** Define core completion versus optional legendary/perfect pursuits without slowing more active players.
13. **How do newly introduced biomes, rods, nets and boats extend the system?** Catalog-driven compatibility and future-safe ID/version rules.
14. **How is power stored for active raids/hauls?** Explicit snapshot details and normal-haul switching semantics, while enforcing the raid loadout lock.
15. **Which elements are visible in Discord versus web?** Preserve Discord's fast-action identity; use web for complex crafting where appropriate.

---

## 17. Intended next design deliverables

Before a final Devin implementation handoff, expand this into **one integrated, approved specification** containing:

1. Complete gear-instance/ownership state machine and migration contracts.
2. Full affix/power/biome catalog with tier ranges, roll weights and exclusions.
3. Final mastery curves and remaining recipes and budgets, building on the [build contract](build-contract-v0.1.md), accepted [Forge flow](forge-and-refinement-v0.1.md), candidate [Forge costs](forge-cost-model-v0.1.md) and [processing comparison](processing-and-material-economy-v0.1.md).
4. Reliquary power library, activation semantics, and category interaction matrix.
5. Vault and Codex schemas, completion semantics, and client flows.
6. Player activity/progression simulations for the accessible mixed-method baseline and heavier play, with optional long-term chase.
7. UI flows for all systems, including mobile, bulk actions, destructive confirmations and build presets.
8. Rust/SpaceTimeDB reducer/receipt rules, data migration/rollback strategy and verification suite.
9. Final success criteria against real Fishbound stats and enjoyable, accessible mixed-activity progression.

**Bottom line:** Tideforge makes *fishing, hauling, raiding, rolling and dismantling gear, collecting Codex entries, and crafting* feed each other. Players should always have another meaningful chase, but never have to pursue every chase at once.

*End of v0.1 design capture.*
