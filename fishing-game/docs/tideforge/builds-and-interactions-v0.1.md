# Tideforge Builds and Effect Interactions

Status: draft recommendations for discussion, not approved mechanics or tuning.
This pass does not authorize implementation, commits or deployment. No numerical
caps, tier values, recipes or RNG distributions are changed here.

Read the [decision index](README.md) first. It governs the agreed direction.
The [affix catalogue](Fishbound_Tideforge_Affix_Catalogue_v0.3.md) supplies the
39 ordinary affix candidates and 24 signature candidates reviewed below.
Names are working labels, not an approved production ID registry.

## 1. Confirmed Design Constraints

- Rods are precision and completion tools for ranks, rank maxima and perfects.
  Species stays random. There is no selected-species guarantee or targeting.
- Boats and nets provide broad catches, XP, sale income and filleting materials.
  A few completed trips should feel worthwhile; constant uptime is not required.
- ARPG-style builds supply the power. Optional focus steering complements them;
  it must not make every rod equally good merely by selecting the same goal.
- Earlier gear retains lower base stats but should remain respectable.
- One main equipment loadout is active. Raid commitment locks equipment changes
  and preset switching; extra owned boats do not create extra activity slots.
- Bait, affix definitions and caps can change together. Ordinary affixes have
  ten independent tiers. Four ordinary slots are the current working layout.
- Keep choices approachable and forgiving. Optimisation is optional; no normal
  build should require a guide or punish someone for choosing fun bonuses.
- Use roughly 24 total mixed-method fishing actions and one or two raid
  activities/check-ins as the accessible baseline. Faster progress is allowed;
  do not add friction to enforce a multi-year completion date.

## 2. Example Build Archetypes

These are optional examples, not optimal builds, required sets or promised drops.
They are not a guide players must study; ordinary combinations should remain
useful without following a named archetype.
Each row uses four ordinary slots under the working layout. The listed signature
is one compatible power direction, not a complete Reliquary loadout or a grant.
All example numbers still depend on future balancing.

| Build | Gear | Example ordinary affixes | Signature direction | Strength and optional emphasis |
|---|---|---|---|---|
| Rank Closer | Rod | Focus Tuner, Attuned Focus, Aftercast, Fortune | Collector's Whisper | Missing-rank assistance and opportunities; fewer measurement and economy slots. |
| Maxima Archivist | Rod | Maxima Priority, Trophy Lens, Weight Weaver, Measured Cast | Echoed Line | Unfinished rank maxima and measurements, rather than extra general Luck/XP. |
| Perfect Hunter | Rod | Chum Catalyst, Weight Weaver, Trophy Lens, Measured Cast | Trophy's Oath | Bait/measurement synergy and eligible UUR perfect opportunities; limited economy bonuses. |
| Balanced Explorer | Rod | Fortune, Scholar, Secondary Affinity, Aftercast | Tidal Memory | Wider utility and coverage, below a specialist's peak in its chosen job. |
| XP Trawler | Boat | Scholar, Deep Trawler, Efficient Hull, Fortune | Ghost Trawler | Player XP, volume and cadence; fewer direct sale/material modifiers. |
| Coin Runner | Boat | Coinwright, Treasurekeeper, Sunken Treasury, Deep Trawler | Gilded Bilge | Fish-sale and treasure income; no implied increase in treasure encounter probability. |
| Fillet Hauler | Boat | Full Hold, Provisioner, Attuned Harvest, Deep Trawler | Ghost Trawler | Fillet production and volume; fish consumed for materials cannot also be sold. |
| Scale Weaver | Net | Scale Collector, Scaleweaver, Attuned Harvest, Overflow Knot | Scale Pact | Scale production; fewer fillet, XP and sale bonuses. |
| Salvage Fleet | Boat | Scavenger, Wreckbreaker, Attuned Harvest, Deep Trawler | Salvage Fleet | Useful junk and processing resources; no deliberate junk-probability increase. |
| Quick-Cycle Netter | Net | Quick Set, Overflow Knot, Scholar, Selective Weave | Swiftwater Knot | Frequent broad trips; speed helps cadence, not the payout of an individual trip by itself. |

Maxima Priority is accepted as an ordinary rod-affix direction using one slot.
It is the additional candidate in the working catalogue; no tier values or
exact rank-selection mechanics are approved.
Chum Catalyst and bait-trigger wording need redesign against actual bait rules
before any example using them becomes an executable build.

Same-bucket combinations in these examples are intentional investments, not
independent multipliers. They remain useful examples only if approved budgets
leave meaningful room for their combined effects.

### Affix Funding And Item Recovery

Ordinary affix tiers use raid materials. Exact quantities and any accompanying
coin/shard costs remain open. The direct T1-to-F through T10-to-UUR mapping is a
working proposal, not a final recipe.

Higher-grade material crafting/refining requires the corresponding ordinary
raid-grade unlock. Fish-derived materials help fund crafting after access is
earned; they do not bypass that access gate.

An eligible item has two competing recovery routes: dismantle it for matching
Vault resources from all its affixes, or extract its signature into the permanent
Reliquary. Do not recover both the signature and affix-shard outcomes from the
same item. Exact resource quantities remain open and originals stay protected.

## 3. Proposed Focus Layer

Keep the distinction between a build's ordinary effects and any explicitly
authored focus synergy. Choosing a goal does not mint a universal stat bonus.
Unrelated affixes keep their normal effects; a goal is not permission to disable
XP, sale or harvesting properties without an explicit designed restriction.

| Candidate goal | Need being addressed after random species selection | Sources to develop | Boundary |
|---|---|---|---|
| Ranks | Ranks of this species never caught naturally | Existing missing-rank Focus, reinforcement and compatible focus affixes | Do not replace the species or bypass natural discovery and Focus eligibility. |
| Maxima | Legal ranks lacking a recorded exact rank maximum | A distinct maxima-priority effect plus measurement investment | A previous best length and best weight from different fish is not one maximum specimen. |
| Perfects | Legitimate perfect opportunities on eligible ordinary UUR catches | Bait, measurements, reinforcement and perfect-related signatures | Do not require all lower-rank maxima first; do not guarantee a UUR rank or a perfect. |

### Accepted Maxima Priority Direction

The earlier candidate list had measurement effects but no explicit priority for
unfinished rank maxima once all ranks were caught. Maxima Priority fills that
role. Focus Tuner and Attuned Focus still describe missing ranks, not missing
maxima; higher Luck alone can make low-rank cleanup less convenient.

Maxima Priority uses an ordinary rod-affix slot, with a separate eligible-rank
mask and separately authored strength. Choosing it adds completion assistance;
not choosing it must not make normal fishing invalid or permanently lose
progress. Exact eligibility and rank-selection behavior still need a contract.

Use permanent, same-specimen maximum records, not current inventory ownership.
When no eligible unfinished ranks remain, the effect must have a defined neutral
fallback rather than returning an invalid pool or fabricating completion.

### Perfect-Focus Boundary

The current candidate list also does not fully define how the optional Perfects
selector strengthens a build. That remains a contract to design, not a free
bonus implicitly added by this document. Keep the existing ordinary-species and
rank-collection requirements as the reference eligibility.

Do not reinterpret a missing-rank Focus percentage as the same probability of
promoting a catch to UUR. Do not reinterpret a chance for an endpoint trial as
a direct perfect probability. Any future rank bias needs its own approval and
an audit of XP, sale value and processing income, not only collection speed.

## 4. Proposed Composition Rules

1. Evaluate only the equipment performing this action and its applicable powers.
   An equipped boat's properties do not improve a rod cast or a net trip.
2. Resolve bonuses by their declared effect bucket and eligibility. Matching
   affinity, Secondary Affinity and general Luck are not independent transforms.
3. Keep species selection random for rods. Apply any authored rank assistance
   only after species selection and within that species' legal ranks.
4. Arbitrate rank-selection assistance once per pull. Do not independently chain
   multiple Focus rerolls simply because several sources contribute.
5. Budget pull generation separately from measurement attempts and endpoint
   trials. More Power, extra pulls and extra measurements are different effects.
6. Bound bonus pulls without recursion. Every ordinary extra pull follows the
   normal fish/junk/treasure path; it is not automatically an extra fish.
7. Snapshot relevant gear, modifiers, rule versions and timed-action settings.
   A later build, focus change or balance patch cannot reinterpret a booked trip.
8. Pin deferred sale/processing modifiers at acquisition. Old specimens do not
   acquire today's harvesting bonuses by changing gear before processing.
9. Deduplicate identical signature IDs between equipment and Reliquary sources.
   Different IDs sharing an effect still require an explicit shared budget.
10. Show the active contribution, inactive conditions and any cap loss. Do not
    sell a supposedly stronger affix whose entire benefit is silently discarded.

These are proposed evaluation responsibilities, not a completed reducer/RNG
sequence. Exact proc order, thresholds and chance distributions remain open.

## 5. Ordinary Affix Interaction Matrix

R/B/N mean rod, boat and net. All rows remain candidates. Focus connections are
roles, not permission to convert one effect into another when a goal changes.

### General Candidates

| Affix | Kinds | Primary role | Shared interaction or unresolved contract |
|---|---|---|---|
| Fortune | R/B/N | Rarity Luck | Shares the Luck sum with quality, eligible bait, affinity and Fine Mesh. Not absolute UUR probability. |
| Scholar | R/B/N | Player XP | Shares player-XP budget; never silently changes family mastery XP. |
| Old Salt | R/B/N | Family mastery | Shares mastery budget with Surveyor, Attuned Wisdom and eligible powers. |
| Coinwright | R/B/N | Deferred fish-sale income | Pin to the catch once. Same fish cannot be sold and filleted. |
| Scavenger | R/B/N | Junk-resource yield | Shares salvage budget with kind-specific bonuses; define yield spread and fractional payouts. |
| Treasurekeeper | R/B/N | Treasure coin yield | Shares treasure payout budget; no category-odds change. |
| Provisioner | R/B/N | Ordinary fillet yield | Shares fillet budget with Full Hold and Attuned Harvest. |
| Scaleweaver | R/B/N | Ordinary scale yield | Shares scale budget with Scale Collector, Attuned Harvest and Scale Pact. |

### Rod Candidates

| Affix | Primary role | Shared interaction or unresolved contract |
|---|---|---|
| Line Tension | Power and throughput | Shares the action pull budget with Aftercast and extra-pull signatures; saturation must be disclosed. |
| Chum Catalyst | Bait synergy | No current measurement-bait activation proc exists; redefine the trigger/effect before tuning it. |
| Weight Weaver | Bait-linked measurements | Shares measurement budget with Measured Cast and other attempts; "bait activates" needs a real definition. |
| Trophy Lens | Length opportunity | Define length-only versus full-pair attempts and legal final-specimen selection. |
| Focus Tuner | Missing-rank Focus | Combine with Attuned Focus, reinforcement and Collector's Whisper; do not assume missing-maxima support. |
| Bait Conservation | Consumable efficiency | Define per-slot versus per-cast preservation, including two slots using the same bait and retries. |
| Aftercast | Extra rod pull | Shares total pull budget with Power and Echoed Line; no recursive extra pulls. |
| Measured Cast | Bait-independent measurement | Shares weight/measurement budget with Weight Weaver; no accidental extra endpoint trial. |
| Maxima Priority | Unfinished rank-maxima assistance | Uses permanent same-specimen maximum records; eligibility and selection strength remain open. |

### Boat Candidates

| Affix | Primary role | Shared interaction or unresolved contract |
|---|---|---|
| Deep Trawler | Extra haul pull | Shares boat pull budget with Ghost Trawler and compatible Waters effects. |
| Efficient Hull | Normal trip cadence | Shares duration budget with Tempest Rudder; never changes raid timing. |
| Schoolfinder | Broad catch variety | Shares duplicate-reroll budget with Cartographer's Wake; define rank preservation and target selection. |
| Wreckbreaker | Junk-resource yield | Add within Scavenger/Salvage Fleet budget, not as another multiplier. |
| Sunken Treasury | Treasure coins | Shares payout budget with Treasurekeeper, Deepwater Cache and coin-double procs. |
| Full Hold | Deferred fillet yield | Shares fillet budget with Provisioner and Attuned Harvest; pin at acquisition. |
| Surveyor | Boat-family mastery | Shares mastery budget with Old Salt and Attuned Wisdom; credit trips, not unreviewed per-fish mastery. |
| Haul Appraiser | Measurement on one haul fish | Define the selected ordinary fish and per-trip proc scope; not one full proc for every result. |

### Net Candidates

| Affix | Primary role | Shared interaction or unresolved contract |
|---|---|---|
| Fine Mesh | Net rarity Luck | Same Luck bucket as Fortune and affinity; not a second rarity transformation. |
| Quick Set | Normal trip cadence | Shares duration budget with Swiftwater Knot; retain meaningful per-trip rewards. |
| Overflow Knot | Extra haul pull | Shares net pull budget with Nets of Plenty and compatible Waters effects. |
| Selective Weave | Broad catch variety | Shares duplicate-reroll budget with Whispering Mesh; no chosen species. |
| Silt Sifter | Junk-resource yield | Shares Scavenger/salvage budget; no implied encounter change. |
| Pearl Catcher | Treasure coins | Shares treasure payout budget with Treasurekeeper, Deepwater Cache and Pearlbound. |
| Scale Collector | Deferred scale yield | Shares scale budget with Scaleweaver, Attuned Harvest and Scale Pact. |
| Mist Weaver | Measurement on one haul fish | Shares haul-measurement budget with Moonthread; specify one selected ordinary fish, not every pull. |

### Biome Candidates

| Affix | Kinds | Primary role | Shared interaction or unresolved contract |
|---|---|---|---|
| Resonance | R/B/N | Primary-biome Luck | Shares Luck budget; primary matching does not grant another species pool. |
| Attuned Harvest | R/B/N | Primary-biome processing | Add once to each eligible fillet/scale budget; do not multiply general and kind-specific yields. |
| Attuned Wisdom | R/B/N | Primary-biome mastery | Shares mastery budget; older and newer draft values remain unresolved. |
| Deepwater Cache | R/B/N | Primary-biome treasure coins | Shares treasure payout budget; not increased treasure probability. |
| Secondary Affinity | R/B/N | Lesser alternate-biome Luck | Does not activate primary-biome effects; preserve safe primary/secondary collision behavior. |
| Attuned Focus | R | Primary-biome missing-rank Focus | Same Focus arbitration as Focus Tuner; missing-maxima behavior is not implicit. |

## 6. Signature Interaction Matrix

Same-ID equipment/library duplicates activate once. The rows below describe
different-ID interactions; distinct names do not automatically justify separate
multipliers, daily entitlements or extra action budgets.

### Rod Powers

| Signature | Interaction | Contract still needed |
|---|---|---|
| Echoed Line | Power, Aftercast and extra-pull budget | Proc scope and ordering when the action has no remaining pull budget. |
| Trophy's Oath | Eligible ordinary UUR endpoint trials | Trial probability versus proc probability, eligibility and combined trial budget. |
| Baitkeeper's Promise | Chum Catalyst and bait synergy | Replace the nonexistent current bait-activation assumption before tuning. |
| Collector's Whisper | Missing-rank Focus sources | Shared Focus arbitration and explicit allowed goal hooks, not automatic maxima support. |
| Salvage Sermon | First-tin reward and salvage economics | Separate flat scrap award from percentage tin yield; no proc chain from bonus resources. |
| Tidal Memory | Family mastery and first-rod-catch daily event | Define interaction with Atlas of Seven Waters without inventing per-item daily resets. |

### Boat Powers

| Signature | Interaction | Contract still needed |
|---|---|---|
| Ghost Trawler | Deep Trawler and total boat pulls | One normal-haul pull budget, no recursive bonus catch generation. |
| Cartographer's Wake | Schoolfinder and duplicate rerolls | Maximum rerolls, selected duplicate and legal species/rank replacement. |
| Salvage Fleet | Scavenger, Wreckbreaker and salvage yield | Combined resource budget, spread and fractional payout semantics. |
| Tempest Rudder | Efficient Hull and ordinary-haul duration | Combined reduction applied to the original base duration, not repeated timer reductions. |
| Gilded Bilge | Treasure coin modifiers | One selected treasure event; define modifier/doubling order and prevent doubling chains. |
| Fisherman's Folly | Entire-haul consolation | Define payout and evaluate the final result set, including bonus pulls; all junk is not merely no fish. |

### Net Powers

| Signature | Interaction | Contract still needed |
|---|---|---|
| Nets of Plenty | Overflow Knot and total net pulls | Shared pull budget and nonrecursive resolution. |
| Moonthread | Mist Weaver and measurement attempts | Select one eligible ordinary fish and define pair/dimension attempts. |
| Swiftwater Knot | Quick Set and ordinary-net duration | Shared reduction from base duration; no raid-timer effect. |
| Whispering Mesh | Selective Weave and duplicate rerolls | One legal replacement policy, not a species-targeting guarantee. |
| Scale Pact | Scale Collector, Scaleweaver and Attuned Harvest | One acquisition-pinned scale-yield budget. |
| Pearlbound | Treasurekeeper, Pearl Catcher and treasure coins | Selected treasure event, payout order and noncompounding doubling. |

### Waters Powers

| Signature | Interaction | Contract still needed |
|---|---|---|
| Rusted Crown | First-tin awards and salvage sources | Define resource type and overlap with Salvage Sermon; bonus resources are not new first events. |
| Atlas of Seven Waters | Mastery sources and Tidal Memory | Explicit daily trigger scope; changing instances must not renew an account entitlement. |
| Wayfarer's Compass | Innate and Secondary Affinity | Outside-primary behavior must not activate primary-only affixes. |
| Forgotten Cache | Normal-material economy | Define direct material grant versus deferred processing modifier and award exactly the authored result. |
| Ocean's Memory | Player XP and permanent rank novelty | Check lifetime progress before acquisition; a UTC reset does not make an old rank new again. |
| Rift Current | Rod/haul extra-pull budgets | Define trigger, daily scope and category semantics; "ordinary catch" must not silently promise a fish. |

## 7. Contracts To Close Before Numerical Tuning

| Area | Required design decision | Risk if left implicit |
|---|---|---|
| Focus steering | Which sources have goal-specific hooks and what each hook does | Free universal power or a selector that has no actual effect. |
| Maxima priority | Define the accepted affix's eligible ranks and selection strength | An unspecified effect cannot reliably help finish a maxima ladder. |
| Perfect focus | Define eligible synergy separately from rank promotion | Treating a missing-rank percentage as UUR probability can distort collection and the economy. |
| Measurement units | Full specimen candidates, length attempts, weight attempts and endpoint trials | A weight bonus accidentally grants extra perfect trials or changes rank classification. |
| Bait interaction | Real triggers and consumption rules for both stocked slots | Building around a nonexistent activation event or spending/retaining bait twice. |
| Pulls and procs | Per-action, per-pull, selected-fish and per-trip scopes | Multiplying a one-trip proc by every result or creating recursive reward chains. |
| Duplicate species | Shared reroll policy and rank/category preservation | Variety bonuses secretly become rarity, treasure or special-fish targeting. |
| Yield arithmetic | Base quantity spread, checked sums and fractional handling | Small affixes do nothing, or split batches produce different total rewards. |
| Daily powers | Account, biome, UTC day and named trigger boundaries | Swapping gear/powers renews a consumed first-event reward. |
| Goal changes in raids | Where goal preferences live relative to the equipment lock | A preference accidentally bypasses a locked equipment/power preset. |

Current measurement bait can supply multiple full-specimen attempts, while
reinforcement can supply additional endpoint trials. Keep those units distinct.
Do not apply one generic "attempts" limit to both and silently remove existing
investment. Rod-only goal steering must not leak into boat/net actions or remove
their current baseline behavior without a separately approved rule change.

## 8. How The Builds Must Be Evaluated

These are design acceptance questions, not measured results or a new scoring
harness. No completion-time or profitability claim has been simulated here.

- Compare the same species/rank progress and equivalent gear investment,
  selected biome, bait availability and activity schedule.
- Check missing ranks, all ranks known with unfinished maxima, and eligible UUR
  perfect hunting while lower-rank maxima are still unfinished. Do not require
  impossible collection states or invent a new maxima prerequisite.
- Report XP, sale income and material output together with collection progress;
  a new rank bias can change all four, not just the advertised goal.
- Report results per accepted action, completed trip and practical play session,
  not only theoretical nonstop hourly output. Active rod play may involve many
  more commands; broad hauls should be rewarding per check-in and completed trip.
- Test generalist versus specialist builds, matching versus nonmatching biomes,
  and low/high families at comparable investment. Do not claim that affinity
  alone proves older gear respectable.
- Compare sale versus processing opportunity costs and identify bottlenecks in
  fillets, scales, coins and shards rather than reporting only total catches.
- Keep ordinary material/XP modifiers out of raid timing, seals, supplies and
  special encounter odds unless a separate raid system is explicitly designed.

## 9. Next Design Work

Draft simple affix-crafting and gear-refinement rules using raid materials,
including the two recovery routes above. Revisit material quantities and yields
after those costs and operations have a concrete shape, rather than asking Boss
to price an undefined economy.

See the [Forge and refinement proposal](forge-and-refinement-v0.1.md) for the
next draft of those operations.

Propose straightforward mastery and gambling defaults for review. Do not make
Boss specify every formula, or make players learn an advanced build system to
progress. Define focus hooks and measurement/bait units behind clear UI language
before authoring final tier grids and caps.

Keep the complete gear, Forge, Vault, Codex, Reliquary, gambling and material
progression in scope. More active players progressing faster is acceptable;
these are dependency decisions, not permission to ship a disconnected slice.
