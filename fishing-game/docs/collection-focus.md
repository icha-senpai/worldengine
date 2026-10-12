# Collection Focus (rules 13, deployed October 12)

Discover every ordinary species in a biome to unlock Collection Focus there.
Fihs and Nidalees Lost Sock do not count toward the unlock. The biome licence
is still required. Focus starts off, costs nothing to toggle, and is saved
separately for each player and biome. It does not change cooldowns or bait use.

Use the Compendium toggle (the filtered biome, or your current water when viewing
all waters), or `/focus enabled:true` in Discord. The optional `biome_id` chooses
another unlocked water. `/focus enabled:false` switches it off. Browser and
Discord use the same server-owned preference.

## Catch behavior

Roll category, species and rank normally, with the normal gear/bait luck.
If Focus is active and the selected ordinary rank is already collected, give
that pull a 20% chance to replace its rank with a missing, positive-weight rank
of the **same fish**. Choose among missing ranks using their existing adjusted
rank weights. Then generate size, XP, sale value, and collection credit normally.
Existing bait size attempts work on the final selected rank.

The first pull updates collection before a possible bonus pull. No missing ranks,
a newly selected rank, either exceptional species, or an inactive setting needs
no Focus roll. Species/category encounter probabilities remain unchanged. Fihs
and the Sock are never rerolled. Collection Focus grants no separate XP/coin
reward, no rank guarantee, and no extra perfect credit: official perfect catches
still require both UUR maximum measurements, with the Sock excluded.

Focus is for rank completion. Once UUR is collected but lower ranks are missing,
it can divert duplicate UUR catches and lower repeat UUR/perfect odds. Switch it
off for that chase. Completed species automatically behave normally.

## Displayed odds

Baseline ignores personal Focus settings. Equipped and Best for perfects use the
viewer's enabled, unlocked biome settings and current rank history. Best means
Prismatic Abyssal Rod with Trophy Chum; it does not choose a Focus setting for you.
Rates are conditional on the current collection and update as ranks are found.

For per-pull species/rank probabilities `p_i`, known mass `D`, missing mass `M`,
and a 20% proc, a known rank receives `0.8 p_i`; a missing rank receives
`p_i (1 + 0.2 D/M)`. If `M = 0`, probabilities remain unchanged. Rank weights
exclude zero-weight bands. The compendium enumerates first-pull outcomes and
updates the known-rank mask before the second pull, including nonperfect UUR
catches that fill UUR before a second perfect attempt.

## Persistence and proof

A new private `collection_focus_preference` table holds the player/biome setting.
Only the linked browser or an authorized adapter's selected account sees it.
Existing player rows are unchanged. Cast equipment receipts append
`collection_focus_enabled=false` and `collection_focus_rerolls=0` defaults,
retaining historical rewards and dimensions. These receipt fields record cast
eligibility and the number of actual rank replacements, including zero for
active Focus with completed species.

Regenerate both client bindings from the production WASM. The changed adapter
receipt view requires coordinated module/client publication and reconnection;
old clients cannot decode its added columns. Owner activation adds rules 13
without changing XP thresholds or spending player state.

`scripts/collection-focus-proof.ts` migrates a seeded rules-12 fixture to the
actual production WASM with `--delete-data=never`, compares all 54 prior tables,
checks appended defaults, and proves owner-only/idempotent activation. A separate
fixture exercises real casts, both toggles, mastery/licence/identity gates,
private views, exceptions and replay. Every database is a fresh
`fishbound-proof-focus-*` namespace on loopback 3127. Local canonical snapshots
are verification artifacts, not recoverable backups. Seed reducers exist only
in the isolated fixture artifact, never production.

`scripts/collection-focus-odds-proof.ts` independently enumerates 39,840 rank and
paired-maxima cast probabilities for all 249 ordinary species, four collection
states and two gear setups. Rust tests check weighted reroll frequency over
200,000 seeded trials and unchanged RNG for new/complete ranks.

The live deployment on October 12 restored a 4,107-file cold backup, verified
7,379 retained rows, and proved rules 11 to 13 migration plus idempotent activation
before publishing. Public site/linker health, the deployed module hash, and all
15 global Discord commands including `/focus` passed acceptance. Live browser
QA confirmed the mode is visible and connected, with no console errors or mobile
overflow. The separate restore listener was closed; Space on 3100 was unchanged.
Evidence: `.local/deployments/collection-focus-20261012-095959`.

The earlier offline journey
simulation enabled Focus only after all-species completion; its journey timings
are estimates for that policy, not validated timings for this earlier per-biome
unlock.
