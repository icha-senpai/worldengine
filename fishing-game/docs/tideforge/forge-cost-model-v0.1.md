# Tideforge Forge Costs: First Comparison

Status: proposed cost model for review, not approved recipes or measured balance.
Documentation only; no gameplay implementation or deployment is authorized.

The [decision index](README.md) governs accepted direction. The
[Forge flow](forge-and-refinement-v0.1.md) defines the four actions and retained
progress. Boss accepted that flow in discussion on November 1, 2026; the
quantities below are a new proposal, not part of that acceptance.

## 1. A Useful Starting Budget

Recommended starting point: the guaranteed materials from one full successful
ordinary personal raid fund four affix steps at its grade.

With the current four-slot working layout, that is enough material to advance
all four affixes on one item by the corresponding step. Four slots are still
a working layout, not a newly approved mastery unlock schedule.

This budget covers raid materials only. It does not supply affix shards, unlock
slots, pay roll-refinement coins, or also pay rod reinforcement. Sharing a
material stock is a choice about what to develop next, not four free upgrades
on top of reinforcement from the same clear.

Random raid bonuses are spare progress, not necessary to meet the budget.
More active players can build additional gear faster; no daily purchase limit
or extra waiting timer is proposed.

## 2. Resource Roles And Candidate Recipes

| Action | Candidate cost | Retained progress |
|---|---|---|
| Add Affix | One matching affix shard plus the T1 materials below | Starts at the previewed T1 value; other properties stay. |
| Upgrade Tier | Destination-tier materials below; no additional affix shard | Keeps the affix and its relative roll finish. |
| Improve Roll | Existing coins; exact prices and meaningful steps remain open | Guaranteed visible progress, retained through upgrades and type changes. |
| Change Affix | One shard matching the replacement; no repeat tier-material payment | Keeps this slot's tier and relative roll finish. |

No extra coin service fee is included in the Add, Upgrade or Change comparison.
That is a proposed simplification, not a change to existing reinforcement costs.
No new Forge currency is needed.

The candidate shard is specific to affix type, not split into ten separate
tier currencies. Finding the type supplies its identity; raid materials fund
its tier development. This replaces the older candidate of consuming another
matching shard at every tier. Neither shard recipe has been implemented.

Access and compatibility still apply. A type change cannot unlock a higher
material grade, copy a developed slot onto another item, bypass a locked slot,
or count the forged replacement as a naturally found Codex result.

### Candidate Tier Materials

Use the proposed direct mapping T1 = F through T10 = UUR. Each payment consumes
both listed materials, at the row's grade. Add pays T1; each later upgrade pays
its destination row. A target-tier preview sums every required intervening row.

| Destination tier | Material grade | Raid Fillets per affix | Raid Scales per affix |
|---|---|---:|---:|
| T1 | F | 15 | 10 |
| T2 | D | 27 | 18 |
| T3 | C | 36 | 24 |
| T4 | B | 44 | 30 |
| T5 | A | 60 | 40 |
| T6 | S | 72 | 48 |
| T7 | SS | 96 | 63 |
| T8 | SSS | 130 | 85 |
| T9 | UR | 156 | 102 |
| T10 | UUR | 180 | 120 |

These are fixed candidate recipe quantities, not an instruction to price live
forging dynamically from raid rewards. They were calculated from the current
catalog as floor(base fillets / 4) and floor(base scales / 4), separately.
Rounding down lets four payments fit within one guaranteed clear.

Do not apply the older candidate 1.0x-to-2.0x cost curve on top of this table.
Higher grades already require longer raids and more material units. This model
keeps the fraction of a matching clear steady rather than adding a second
escalating multiplier.

Proposed access rule: Add and Upgrade require the shared ordinary raid clear
for their destination grade, alongside the relevant item/affix permissions.
The existing higher-grade material-refining unlock remains destination-grade
based. Improve Roll and Change do not create a new tier and add no second
raid-clear requirement for an already-owned tier.

### Example: Developing Four Affixes

- Four T1 additions cost 60 F fillets, 40 F scales and four matching shards.
- Advancing those four affixes from T1 to T2 costs 108 D fillets and 72 D scales,
  without finding four more copies of the affixes.
- Changing one of those T2 affixes costs one replacement-type shard, without
  paying the D materials again or losing its roll improvement.

These examples assume four unlocked, compatible slots. They are material
comparisons, not a guarantee that a new player can perform every action at once.

## 3. Roll Refinement Must Not Become A Pricing Trap

Keep equivalent roll improvement equally priced across affix types and tiers.
Refining at T1 and upgrading later should not be cheaper or more expensive than
upgrading first and then buying the same improvement.

Do not choose a coin price or promise a fixed number of clicks before legal
tier ranges and discrete-value handling are authored. Some affixes may have
only one or two meaningful values at a tier. Never charge for a rounded-away
increase, reset paid finish, or sell a value above the legal tier maximum.

Fixed-value affixes need no roll-refinement payment. A fully refined affix
stays fully refined after an upgrade or compatible type change; exact
representation and conversion still require a rule contract.

## 4. Recovery Without Extra Homework

Candidate simple shard recovery: dismantling an eligible item returns one
matching type shard for every ordinary affix, including dormant affixes.
This proposed count is tier-independent, matching the one-shard identity cost
above; exact quantities are not already approved by the all-affix guarantee.

Signature extraction remains the mutually exclusive route: consume the item
to learn its signature in the Reliquary instead of recovering affix shards.
Protected originals cannot be consumed; favorite safeguards and explicit
previews remain required.

For this first comparison, assume no raid-material or coin refund. That is a
budget assumption, not a final recovery policy. Any investment refund or
high-tier recovery bonus needs its own recipe and loop review.

Changing A into B consumes a B shard and does not also return an A shard.
Dismantling afterward returns the item's current affixes once, not its history
of replaced effects. Adding, changing and dismantling must not create extra
type shards from the same paid slot. Naturally found affixes remain a source
of shards, and optional gear gambling must be priced with that output in view.

## 5. Verified Source Baseline And Arithmetic

Local source reference: `fb486d3a`, reviewed November 1, 2026. This is not a
fresh inspection of the production database or an assertion about live stock.

- The [raid catalog](../../content/raids.json) defines the ten base material
  rewards and the current 60-input, 50-output, five-coin refining batch.
- The [raid rules](../../crates/game-rules/src/raids.rs) award the full base
  quantity plus a nonnegative bonus on a full successful clear.
- The [authoritative raid reducers](../../spacetimedb/src/raids.rs) require a
  shared destination-grade clear for refining. Existing rod reinforcement
  consumes a full base reward pair at each grade, plus its own coin cost.

The proposed table was calculated and checked against all ten catalog rows:
four payments never exceed either guaranteed base material reward. Across
one full ladder, four affixes consume 3,264 fillets and 2,160 scales; base raid
rewards total 3,269 fillets and 2,167 scales. Those totals span ten distinct
grades, not interchangeable materials.

Thus one clear per grade covers this proposal's four-affix material ladder
for one item, excluding reinforcement and other costs. It does not also fund
four affixes on every rod, boat and net, or prove completion time.

## 6. What We Still Need To Compare

[The first processing/refining comparison](processing-and-material-economy-v0.1.md)
proposes candidate material income and conversion recipes against this budget.
Its fish batches are conditional examples, not measured daily returns; the
questions below remain open.

- Coin income, bait, supplies, existing purchases and refinement affordability.
- Shard discovery and optional gambling frequency, including unwanted-item value.
- Normal fish-processing yields, normal-to-F conversion and improved upward
  refining, retaining the shared destination-grade clear unlock.
- One favourite item versus a whole loadout, alternate builds and reinforcement
  competing for the same material stock.
- Meaningful legal refinement steps, fixed-value tiers and retained-finish
  conversion between affixes.

Evaluate the accessible schedule of around 24 total mixed-method fishing
actions plus normal raid check-ins, and allow heavier play to progress faster.
No income simulation or daily affordability proof has been run for this model.
Do not turn the material-only examples into a promised number of days.
