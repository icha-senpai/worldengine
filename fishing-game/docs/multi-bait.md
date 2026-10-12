# Multi-baiting (rules 14, local candidate)

Two bait slots are available from level 1, with no category restrictions. Equip
matching bait, two different baits in the same category, or any mixed pair.
Bait remains purchased stock; the second slot has no fee or unlock requirement.
The game's existing level cap remains 10,000.

Use the two selectors in Tackle Box → Gear, or `/bait bait_id:ID slot:1` and
`slot:2`. Omitting `slot` keeps the original slot-1 command behavior.
`bait_id:0` clears only the selected slot. Browser and Discord share the same
private server-owned loadout.

## Stacking

- Luck bonuses add to rod and quality luck. Two Trophy Chums add +50% bait luck.
- XP bonuses add to rod and quality XP, applied once to whole-cast base XP before
  integer flooring. Two Pink Slimes add +200% bait XP: 3× base XP with a Common
  Twig and 4× with the maximum +100% gear bonus.
- Resource bonuses apply once per equipped slot per cast. Two +3 tin chums give
  +6 tin; +3 tin with +3 scrap gives both. Normal junk/treasure rewards still apply.
- Luck-bait size attempts add. Two Trophy Chums give 16 attempts; Trophy with
  Fireflies gives 12. XP/material bait contributes no additional size attempts.
  With no luck bait, retain one ordinary attempt. Keep the heaviest specimen,
  using length for a weight tie; species and rank are still selected just once
  per pull, and the existing paired-endpoint chance remains 1 in 5,000 per attempt.

Maximum gear plus two Trophy Chums gives 185 power, +120% luck and 16 size
attempts. Maximum gear plus two Pink Slimes gives +300% XP (4× base). The current
catalog's rank pools remain strictly positive and normalized at this luck.
Fihs/Sock encounter-weight ordering and official UUR-perfect eligibility remain
unchanged. Collection Focus still operates on the selected species/rank before
size generation and can lower duplicate UUR odds while other ranks are missing.

## Consumption and depletion

Each active slot spends one use per accepted cast, including a two-pull cast.
Matching bait draws twice from the same stock. A ten-use pack therefore lasts
five fully stacked casts. Prices remain unchanged: two Trophy Chums cost 100
coins per cast in purchased uses, and two Pink Slimes cost 80.

Equipping a matching bait requires at least two shared uses. After consumption,
clear only slots that cannot be funded for the next cast; slot 1 takes priority
for shared stock. With three uses, one stacked cast leaves slot 1 equipped with
one use and clears slot 2, then the next accepted cast uses that final charge
once. Either slot works alone. Rejection and replay spend neither stock nor
materials/XP again. Slot changes do not spend gold, XP or cooldown time.

## Odds and saved history

Gear totals and Equipped compendium rates combine the funded slots, including
stock depletion and rule-version gating. Best for perfects uses Prismatic
Abyssal Rod plus two Trophy Chums. Baseline remains Common Twig without bait.
The new preset is checked against all 169 ordered catalog pairs at maximum
gear, and Rust checks rank-weight normalization across every biome/loadout.

`bait_loadout` appends `secondary_bait_id=0`. Cast equipment receipts append
secondary bait ID, remaining shared uses, resource item and quantity with zero
or empty defaults. Primary selections, old bait stock, historical rewards and
existing receipt fields are retained. Both client bindings are generated from
the same actual WASM; changed views require coordinated client deployment.
Rules-14 owner activation enables the slot without granting or spending stock.

## Verification and deployment status

`scripts/multi-bait-proof.ts` seeds rules-13 retained state, including bait stock,
old receipts and a saved Focus setting, then upgrades to the actual rules-14
production WASM with no deletion. All 55 prior tables match with only the
specified defaults. Activation is metadata-only and idempotent. A separate
fixture proves level-1 equip, all categories, duplicate bait, exact per-slot
spending, two pulls, odd stock, independent depletion, XP rounding, material
stacks, cooldown/replay and private browser/Discord projections.

`scripts/multi-bait-loadout-proof.ts` covers all 169 displayed pairs and shared
stock. `scripts/compendium-loadout-proof.ts` cross-checks 9,968 bands and 1,993,600
natural generated catches against Rust, validates exact endpoint probabilities,
and checks all maximum-gear pairs. Rust's 36 rule tests include sixteen-attempt
rank retention, positive normalized luck and additive XP up to 4× base.

Playwright exercised the real level-1 proof account through both slot selectors:
duplicate Trophy, duplicate Pink Slime and duplicate +3 scrap chum display their
combined totals. Both selections survive reload. Desktop 1440×1000 and mobile
390×844 layouts were inspected, with readable long names, no horizontal overflow,
and zero console errors. Screenshots: `output/playwright/multibait-desktop.png`
and `multibait-mobile.png` (ignored local artifacts).

Deployed on 2026-10-12 as part of rules 15, alongside rank-maxima recognition. Both slots are available from level 1 and allow matching baits and categories. The live Discord `/bait` command includes an optional slot, and the web displays both selectors. Existing primary loadouts and stock were preserved. See [deployment acceptance](rank-maxima-recognition.md).
