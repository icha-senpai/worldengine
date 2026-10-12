# Compendium field notes

The public compendium shows 24 fish per page on desktop and phones. Name, biome,
rank eligibility, cast odds, and perfect-catch odds remain on the compact cards.
Three buttons in the filters choose Baseline (Common Twig/no bait), Equipped
(the linked viewer's current rod, quality and stocked bait), or Best for perfects.
The selected loadout and its luck, bonus-pull chance and size attempts appear below
the filters. Switching presets updates cards and details without resetting search,
rank, biome or pagination. Equipped is disabled for guests and falls back to
Baseline when the account disconnects; spent bait supplies no bonuses.
Clicking or keyboard-opening a card reveals a parchment dialog with reference
length/weight, actual catchable ranges, the share of that species' catches at each
rank, and per-rank length/weight ranges and cast odds. Values use inches, pounds,
and ounces. The reference size is the species' benchmark, not a statistical mean.

`compendium.ts` derives the display from the bundled species, world, and size-rule
content. Integer length bands use the next rank's threshold minus one millimeter.
Weight uses the authoritative cubic scaling, nearest-gram rounding, ±12% condition
variation, rank weight floor, and configured caps. Weight ranges are envelopes
across lengths in that band, not an independent weight roll. Fihs has only the UUR
band and Nidalees Lost Sock only F; their actual encounter rarity is explained by
their catalog lore and base cast odds.

The distribution bars are conditional on catching this species in one pull. Cast
probability is separate: biome fish chance times that species/rank's normalized
tickets, followed by `(1 + q) * p - q * p * p` for at least one success across a cast,
where the Common Twig's `q` is 0.005. Rank-specific cast probabilities do not sum
to the all-rank cast probability because a bonus cast can contain two ranks.
The selected loadout supplies `q = power / 200`. Luck uses the authoritative
integer graded-ticket adjustment, with score 9 for the exceptional Sock. The
distribution bars reflect the selected loadout's conditional rank shares.

Perfect rates always target both exact UUR maxima, independently of the rank
filter. One size attempt includes the special 1-in-5000 endpoint roll plus the
natural endpoint when that roll misses. With attempt probability `s` and `n` size
attempts, the retained specimen is perfect with probability `1 - (1 - s)^n`:
the greatest weight and then greatest length selection always keeps a perfect.
Multiply this by the UUR species/rank pull probability, then include the independent
bonus pull with the same at-least-one-per-cast formula. Extra size attempts do not
repeat the species or rank selection; XP and resource bait effects do not enter
catch odds.

Best for perfects currently selects Prismatic Abyssal Rod and Trophy Chum: power
185, 9500 basis points of luck, and eight size attempts. It is an aspirational
comparison regardless of ownership. The preset comes from the current catalogs;
`scripts/compendium-loadout-proof.ts` verifies that it beats every available gear
combination for every eligible species, plus Rust rank weights, bonus pulls and
perfect probabilities for baseline, equipped, best and legacy rules.

Native modal behavior traps focus, supports Escape, and returns focus to the
card. Close button and backdrop clicks dismiss it. Background scroll is locked
while open, the header stays visible as notes scroll, and leaving the Compendium
tab closes the dialog. Opening/closing does not reset filters or pagination.

`npx tsx scripts/compendium-proof.ts` cross-checks all 2,492 displayed rank ranges
against the real Rust generator's minimum/maximum endpoints and 498,400 sampled
catches. It also checks distribution totals, biome pools, and exceptional odds.
The Rust example is test-only and is never included in the deployed module.
