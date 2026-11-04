# Tideforge Forge and Refinement

Status: the four-action Forge flow and retained-progress direction were accepted
on November 1, 2026. Recipes and detailed edge-case contracts remain open.
This is documentation only; no implementation or deployment is authorized.

The [decision index](README.md) governs confirmed direction. The
[affix catalogue](Fishbound_Tideforge_Affix_Catalogue_v0.3.md) still contains
candidate values, not final balance.

## 1. The Player-Facing Idea

Pick an item, pick an affix slot, choose what to improve, and see the exact
result and price before paying. No success roll, item damage, permanent bricking
or hidden downgrade is required for ordinary forging.

The main screen has four actions:

| Action | What the player chooses | Flow result |
|---|---|---|
| Add Affix | A compatible affix for a free unlocked slot | Add it at T1 with a previewed starting value. |
| Upgrade Tier | An existing affix and a higher target tier | Guaranteed advancement, preserving improvement already earned on its roll. |
| Improve Roll | An affix below its current tier's maximum | A guaranteed visible increase toward that maximum. |
| Change Affix | A compatible replacement for one affix | Replace its effect while keeping its tier and relative roll finish. |

Changing type is a choice, not a promise that unlike stats are numerically
comparable. The preview shows both effects. It is not a random reroll the player
must repeat until something usable appears.

Dismantling and signature extraction are separate item-recovery actions, with
clear confirmation because they consume the eligible item.

## 2. Add Affix

Working contract (detailed permissions and conversions still open):

- Show compatible affixes and their effects plainly, including requirements.
- Let the player choose the desired affix rather than gamble inside this action.
- Consume matching Vault resources and the relevant entry-tier raid materials.
- Add a T1 affix at a known, previewed starting value; no second hidden roll.
- Do not change other affixes, equipment quality, reinforcement or signature.

Discovery/access rules for adding an affix still need a final contract. This
proposal does not silently unlock every Codex entry or bypass family slot gates.
Show unavailable choices with a short reason, not a failed payment.

## 3. Upgrade Tier

Working contract (detailed permissions and conversions still open):

- Upgrade through the affix's ten tiers with guaranteed results.
- Use raid materials for tier advancement; exact grades and quantities are open.
- Respect the required higher-grade raid-clear access.
- Preserve relative roll finish: improvement already earned does not reset
  because the affix moved into a stronger tier.
- Keep all other item properties unchanged.

A fully refined affix remains fully refined when its tier increases.
The player should not have to perfect the same roll again at every tier.

Offer an optional higher target tier with one combined preview, rather than
requiring repeated confirmations. Its price is the sum of the required steps;
it does not skip material grades, permissions or costs.

The candidate tier ranges must support non-decreasing values when preserving
roll finish. If they do not, fix the authored ranges rather than selling a
downgrade. No tier table or conversion formula is finalized in this document.

## 4. Improve Roll

Working contract (detailed permissions and conversions still open):

- Every accepted payment gives a visible improvement; no failed refinement roll.
- Show the current value, next value and current-tier maximum.
- Reach the maximum through a finite progression, not endless no-progress RNG.
- Once the value is maximal, disable payment and say it is already finished.
- Preserve earned roll finish through later tier upgrades and type changes.

Roll finish means position within the affix's legal value range, not the item's
Common-to-Prismatic equipment quality. Players need not manage a separate
currency, skill tree or hidden allocation system for it.

Recommended pricing shape: roll refinement uses existing coins, with the cost
of an equivalent improvement independent of affix type and tier. Exact prices
and improvement steps remain open. This makes refining early versus late a
preference, not a cheap-first trick or an expensive mistake.

Raid materials still fund tier advancement, and their crafting unlocks remain.
Improving an already-owned roll is not creating a higher tier; this proposal
does not add another raid-clear gate to coin-only roll improvement.

Affixes with fixed values per tier need no Improve Roll action. Discrete values,
rounding and zero-width ranges must have explicit authored handling: never
charge for an invisible change or invent a new hidden random roll on upgrade.

## 5. Change Affix

Working contract (detailed permissions and conversions still open):

- Choose the replacement directly from compatible choices.
- Use matching resources for the new affix and an explicitly previewed fee.
- Keep the existing tier and relative roll finish.
- Keep that tier on this existing slot only; do not copy it to another slot or
  item, or grant new material-crafting permissions.
- Preserve equipment quality, reinforcement, affinity, signature and other slots.

This keeps an unwanted earlier choice fixable without rebuilding the item.
The replacement must obey kind compatibility, duplicate-affix restrictions and
the final slot rules. Do not charge for an invalid selection.

Changing type does not dismantle the item or grant extraction rewards. Any
old-affix resource refund would need a separately authored recipe; none is
implicitly promised here.

Old Codex records stay. The newly forged replacement is not falsely recorded as
a naturally found affix of that type/tier/value.

Earlier catalogue examples mentioned random type rerolls. The accepted ordinary
Forge flow instead uses direct choice; optional equipment gambling remains
separate. This does not approve its currency, timing or distribution.

## 6. Dismantle Or Extract Signature

These choices follow the confirmed recovery direction:

| Choice | Item outcome | Recovery |
|---|---|---|
| Dismantle | Eligible item is consumed | Matching Vault resources from all its affixes, including dormant affixes. |
| Extract Signature | Eligible item is consumed | Its signature is recorded in the permanent Reliquary instead of affix-shard recovery. |

Never grant both routes from the same item. Exact quantities, tier/value handling,
duplicate-signature compensation and any normal-material refund remain open.
Choosing signature extraction is unavailable if the item has no signature.

Protected originals cannot be consumed. Favorites and other protection flags
must be respected. Existing Codex, collection and mastery history survives
either route; individual quality/reinforcement does not transfer to another item.

Preview all recovered resources or the signature unlock before confirmation.
An already-known signature must be identified clearly; do not let an ambiguous
button silently destroy valuable gear for an unspecified duplicate reward.

## 7. Keep Costs Understandable

Recommended resource roles, not final recipes:

- Raid materials advance affix tiers.
- Matching Vault resources identify the affix being added or changed.
- Existing coins fund roll improvement and any plainly shown service fees.
- No additional Forge-only currency is proposed.

The direct T1-to-F through T10-to-UUR material mapping remains a proposal.
The [first Forge cost model](forge-cost-model-v0.1.md) supplies candidate
recipes for comparison only; its material quantities, shard counts, recovery
yields and coin prices remain unapproved.

Retained finish is paid progress, not a free resource refund. Pricing must work
equally well whether the player refines before or after an upgrade/type change.
Test change-type and dismantling loops before setting recovery prices; do not
solve a bad recipe by making the UI obscure or breaking the all-affix guarantee.

## 8. Clear Previews And Safe Use

The normal preview should show only what helps the decision:

- The exact item and slot being changed.
- Before and after effects, tier and any relevant condition.
- The resources paid or recovered.
- Whether the item survives or is consumed.
- A short reason if the action is unavailable.

Do not require players to understand stacking equations. Optional detail can
explain caps, but normal comparisons must not advertise fully wasted bonuses
as useful improvements.

Working on unrelated unused gear must not implicitly switch the active loadout.
Existing raid reservations and activity-use protections remain enforced; no
Forge action may destroy committed gear or bypass a locked preset. Exact editing
boundaries for equipped/in-use items still require a contract, not an invented
blanket lock on the whole workshop.

For timed activities, later forging cannot change the booked outcomes. Payment
and item mutation are atomic, authorized and replay-safe; rejected or expired
requests cost nothing.

## 9. What Still Needs Designing

- Review candidate costs and recovery, then compare fish processing and refining income.
- Roll-finish representation, meaningful steps and discrete-tier edge cases.
- Final discovery, slot and in-use editing permissions.
- How recovery quantities account for naturally found versus forged properties.
- Simple mastery and gambling defaults, without extra player homework.

Judge affordability around the accessible mixed-method activity baseline and
normal raid check-ins. Faster players may finish sooner. Do not price crafting
to enforce a prescribed multi-year completion date.

The [processing and material economy comparison](processing-and-material-economy-v0.1.md)
provides the first candidate recipes against the Forge budget. Review its
assumptions alongside costs; it is not an affordability proof.
