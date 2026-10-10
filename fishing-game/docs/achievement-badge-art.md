# Achievement badge artwork

Badge artwork uses one transparent PNG per stable achievement ID, stored at
`assets/achievements/badge-ID.png`. The image has no lettering; names, ranks,
requirements and titles remain selectable, readable website text. The website
and bot share the existing static asset root and manifest.

The complete prompt set is `content/achievement-badge-art.json`, prepared from
the live authoritative badge catalog by `scripts/build-achievement-badge-plan.mjs`.
There are 113 separate assets in nine themed batches: milestones and bonuses,
seven biome families, and all-waters collections. Each generation uses the
built-in image_gen tool and requests genuine transparency. No API key is needed.

Visual direction: compact round medals, scalloped antique metal edging, two
short ribbon tails, dark stepped outlines, visible square pixel blocks, and
large central emblems readable at the badge book's small display size. Each
biome has a distinct water/fish emblem. Rank medals add progressively stronger
metal, gemstone and laurel decoration. Every-rank medals have a prismatic crown
and ten jewel studs. The lost Sock and Fihs have individual bonus emblems.

Generation plan order:

1. Establish the style with Pond collection, a UUR collection, and the lost Sock.
2. Complete milestones and bonus badges.
3. Complete each biome's collection and ten rank medals plus its capstone.
4. Complete all-waters collection, rank medals, and the world capstone.

Generate each badge separately, inspect the silhouette and transparency, and
use approved artwork as a style reference where useful. Save the selected
output in the project before referencing it. Rebuild `npm run assets:manifest`
as batches are installed. Keep original generated files; presentation-scale
optimization must preserve alpha. A missing asset retains the existing badge
symbol while its batch is being completed.

All 113 medals are installed. The approved Pond collection (10), Meadow Pond
UUR collection (109), and lost Sock (18) samples were retained, and the remaining
110 images use the Pond medal as their style/layout reference. Fihs also uses
the supplied fish sprite as an emblem reference, retaining its blue body, pink
stripe and golden fins. The set contains 17 milestone/bonus medals, 12 medals
for each of the seven biomes, and 12 all-waters medals.

Original generated files remain in the Codex generated-images directory. The
selected PNGs preserve their generated dimensions and alpha. Per-image prompt,
reference and source-path records for the final 110 images are retained locally
under `.local/achievement-badge-generation/`.

Verification commands:

```sh
npm run assets:manifest
python scripts/validate-achievement-badge-alpha.py --complete
node scripts/achievement-badge-proof.mjs --complete
node scripts/achievement-badge-proof.mjs --complete --live
```

The alpha check requires actual transparent pixels and complete opaque subjects
inside their canvas margins. The asset check requires all 113 stable IDs,
distinct image hashes, square RGBA PNGs and manifest/source hash agreement.
The live option also checks every public HTTPS image against its source hash.
The read-only review server (`node scripts/review-achievement-badges.mjs`) shows
the nine labelled sheets at `http://127.0.0.1:5185`; it uses no game credentials.
