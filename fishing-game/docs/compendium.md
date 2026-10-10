# Compendium field notes

The public compendium shows 24 fish per page on desktop and phones. Name, biome,
rank eligibility, and Common Twig/no-bait cast odds remain on the compact cards.
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
Figures are baseline rates; equipped rod/bait luck and power change the results.

Native modal behavior traps focus, supports Escape, and returns focus to the
card. Close button and backdrop clicks dismiss it. Background scroll is locked
while open, the header stays visible as notes scroll, and leaving the Compendium
tab closes the dialog. Opening/closing does not reset filters or pagination.

`npx tsx scripts/compendium-proof.ts` cross-checks all 2,492 displayed rank ranges
against the real Rust generator's minimum/maximum endpoints and 498,400 sampled
catches. It also checks distribution totals, biome pools, and exceptional odds.
The Rust example is test-only and is never included in the deployed module.
