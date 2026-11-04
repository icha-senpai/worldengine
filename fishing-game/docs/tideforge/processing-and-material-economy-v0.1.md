# Tideforge Processing And Material Economy

Status: provisional recipes and arithmetic comparisons, not approved balance.
Documentation only; no implementation, migration or deployment is authorized.

The [decision index](README.md) governs accepted direction. These proposals
test the [first Forge budget](forge-cost-model-v0.1.md), whose quantities also
remain provisional. Neither document establishes daily affordability.

## 1. Simple Player Choices

Keep an eligible fish, sell it for its saved coin value, or fillet it for
materials. Selling and filleting consume the same specimen, so the player
cannot collect both rewards. Catch XP and lifetime collection progress stay.

Filleting should be a useful choice for ordinary surplus fish, not a reason
to destroy trophies. Proposed defaults:

- Show the exact material reward and the saved sale coins being given up.
- Skip favorites and exact rank maxima/perfects in default bulk selections.
  Deliberately processing a valuable ordinary specimen needs explicit opt-in.
- Exclude Fihs and Nidalees Lost Sock from ordinary processing. This is a
  proposed special-fish rule, not approval of a separate processing recipe.
- Preserve all discovery, rank, maxima, perfect and record history.
- Never grant a new discovery or catch reward by processing an existing fish.

## 2. Candidate Ordinary Filleting Yields

Use the specimen's earned rank as the simple base-yield input. Rank already
depends on both measurements; this proposal adds no separate weight/length
multiplier, species-targeting rule or new rank roll at the filleting screen.

| Earned rank | Normal fillets | Normal scales |
|---|---:|---:|
| F | 6 | 4 |
| D | 9 | 6 |
| C | 12 | 8 |
| B | 15 | 10 |
| A | 18 | 12 |
| S | 21 | 14 |
| SS | 24 | 16 |
| SSS | 27 | 18 |
| UR | 30 | 20 |
| UUR | 33 | 22 |

For rank index r from F = 0 to UUR = 9, the candidate base quantities are
3 * (r + 2) fillets and 2 * (r + 2) scales. These are authored proposal values,
not existing game yields.

The base table applies equally to eligible ordinary species and catch methods.
Boat/net volume and their catch-pinned yield effects provide specialisation;
do not impose a hidden penalty on rod-caught fish. Early-biome fish may be
useful material stock, not automatically obsolete because their sale price is
lower. Whether this makes one biome too attractive still needs comparison.

Catch-acquisition provenance fixes eligible bonuses. Equipping harvesting
gear before filleting cannot improve old catches. Eligible legacy inventory
may use the eventual ordinary base recipe, but receives no retroactive affix
or affinity bonus. Sold or otherwise consumed fish cannot be recovered.

## 3. Candidate Normal-To-F Conversion

Convert each resource separately:

- 10 normal fillets -> 1 F-grade Raid Fillet.
- 10 normal scales -> 1 F-grade Raid Scale.

No additional coin fee is included in this proposal. Forgoing the fish's sale
value already has a cost. Keep unspent normal materials; never round a small
conversion request into destroying leftovers.

Proposed access: one qualifying ordinary F-grade raid clear unlocks this entry
conversion. Higher-grade refining keeps the shared destination-grade clear
requirement. A clear on every species is not needed.

Conversions award materials only, not raid seals, raid completion statistics,
new material permissions or reinforcement eligibility.

## 4. Stronger, Smaller Upward Refining Batches

Proposed adjacent-grade recipe, separately for fillets and scales:

**20 lower-grade materials + 1 coin -> 18 next-grade materials.**

The current source recipe is 60 input -> 50 output + 5 coins. The proposal
retains 90% per step instead of about 83.33%, lowers the minimum batch, and
reduces the coin fee per input material. It does not create a downward route
or allow refining beyond UUR.

The shared destination-grade ordinary clear is required at every step.
A multi-grade preview sums the actual intermediate batches, fees and unlocked
steps. Do not silently skip grades or discard intermediate leftovers.
No extra real-time processing timer or daily conversion quota is proposed.

### Ideal Retention Is Not A Small-Stockpile Quote

Across nine grade steps, ignoring whole-batch constraints:

- Current retention: (50 / 60)^9 = approximately 19.38%.
- Proposed retention: (18 / 20)^9 = approximately 38.74%.

That is approximately twice the output for the same input in the large-batch
limit, not a promise that every conversion request is twice as good. Whole
batches matter, especially when starting from empty intermediate stocks.

### Exact Example: One Proposed T10 Upgrade

The Forge cost draft asks for 180 UUR fillets and 120 UUR scales for T9 -> T10
on one affix. Starting with only F materials, zero intermediate stocks and all
required grade clears:

| Refining recipe | F fillets needed | F scales needed | Total conversion coins |
|---|---:|---:|---:|
| Current 60 -> 50, fee 5 | 1,320 | 1,080 | 905 |
| Proposed 20 -> 18, fee 1 | 580 | 400 | 282 |

These compare conversion recipes applied to the proposed Forge target; there
is no implemented T10 Forge purchase today. They exclude the costs of earlier
affix tiers, earning F materials, supplies, shards and roll refinement.
Intermediate and final surplus materials remain owned, not destroyed.

For reproducibility, batch counts in forward order F->D through UR->UUR are:

- Current fillets: 22, 18, 15, 12, 10, 8, 6, 5, 4; 100 batches, 500 coins.
- Current scales: 18, 15, 12, 10, 8, 6, 5, 4, 3; 81 batches, 405 coins.
- Proposed fillets: 29, 26, 23, 20, 18, 16, 14, 12, 10; 168 batches, 168 coins.
- Proposed scales: 20, 18, 16, 14, 12, 10, 9, 8, 7; 114 batches, 114 coins.

The calculation works backward from each target. At each hop, batches =
ceil(required output / output per batch); required input = batches * input
per batch. Fees sum across both resources and all hops. This is a deterministic
empty-stock comparison, not a random fishing simulation or an optimal spending
plan for an existing player inventory.

### Why Not Simply Use 1:1 Refining?

With the current guaranteed raid rewards and durations, the proposed 90%
retention gives roughly 46.49-48.00 UUR-equivalent fillets per occupied boat
hour across grades in an ideal, no-batch-rounding comparison. Scales range
from 30.60 to 32.00 on the same basis. Direct UUR gives 48 and 32 respectively.

This is a useful starting alignment, not measured player income. It excludes
bonuses, fees, supplies, deployment/check-in gaps and whole batches. Faster
low-grade clears require more check-ins; long raids give more per completion.
At 1:1, continuously repeating F would instead yield 120 fillets and 80 scales
per occupied hour before fees, versus direct UUR's 48 and 32. That may be an
acceptable active-play reward, but it should be a deliberate choice, not a
hidden consequence of a seemingly harmless conversion buff.

## 5. Conditional Fishing-Batch Comparisons

The following are invented rank mixes of **24 actual eligible fish**, all
filleted, with no yield bonuses and no starting materials. They are not the
expected result of 24 rod casts, 24 mixed actions, or four boat/net trips.

| Conditional fish batch | Normal fillets / scales | F fillets / scales after conversion | Normal remainder |
|---|---:|---:|---:|
| 24 F | 144 / 96 | 14 / 9 | 4 / 6 |
| 24 C | 288 / 192 | 28 / 19 | 8 / 2 |
| 24 S | 504 / 336 | 50 / 33 | 4 / 6 |
| 12 F + 8 C + 4 S | 252 / 168 | 25 / 16 | 2 / 8 |

One proposed T1 addition costs 15 F fillets and 10 F scales plus its shard.
The 24-F example falls slightly short; 25 F fish provide exactly that material
pair. The mixed example covers one addition with graded materials left over.
The 24-S example covers three. None of these examples supplies the affix shards.

Two current boat hauls plus two net hauls give 28 pulls, not guaranteed fish.
Junk, treasure, exceptional catches, unsold trophies and the player's decision
to sell some fish reduce what is available to fillet. A boat committed to a raid
is unavailable for ordinary hauls. Do not budget both uses at the same time.

## 6. Small Percentage Bonuses Must Survive

Proposed rounding policy: persist fractional material credit per owner and
normal resource type, rather than flooring each fish separately. Keep the
carry through single-fish/bulk processing and login/day boundaries.

For a processing transaction and one resource:

    credit = prior_carry + sum(base_quantity * (10000 + pinned_bonus_bp))
    awarded_units = floor(credit / 10000)
    next_carry = credit mod 10000

This defines payout rounding, not effect stacking or a new cap. The effect
rules must first provide each catch's valid pinned bonus. Applying a hypothetical
+25% to the mixed example gives 315 normal fillets and 210 normal scales:
31 F fillets and 21 F scales, with five normal fillets remaining.
The +25% is an illustration, not an approved affix value.

Atomic settlement must consume each specimen once, update the resource carry
once and record the exact reward. A rejected or replayed request cannot change
carry or pay twice. Preview changes caused by concurrent processing require a
fresh confirmation rather than silently accepting different rewards.

The same fractional-credit idea can inform junk modifiers later. This draft
does not choose a junk base range or change encounter-category probabilities.

## 7. What Is Verified And What Is Still Missing

Local source reference: `fb486d3a`, reviewed November 1, 2026.

- [Haul rules](../../crates/game-rules/src/hauls.rs) define boat 10/600 seconds
  and net 4/240 seconds.
- [Catch settlement](../../spacetimedb/src/casting.rs) pins sale coins using
  rank base value and a length ratio clamped to 0.6-2.0, with integer rounding.
  [Sale settlement](../../spacetimedb/src/inventory.rs) uses that saved value.
  No existing sale formula or saved coin value is repriced by this proposal.
- [Raid content](../../content/raids.json) supplies the current refining
  recipe, base rewards and durations used for the comparisons.
- Yield-table arithmetic, the conditional batches, backward whole-batch
  conversions and ideal retention/rate comparisons were calculated explicitly.

This is not a full economy simulation. Still needed: actual rank distributions
at representative gear/biomes, the sell-versus-fillet split, catch-time yield
budgets, shard/gambling supply, coin income and existing spending. Roll-refinement
coin prices remain open until legal visible steps and income can be compared.
Do not infer an affordable complete loadout or a number of days from this draft.
