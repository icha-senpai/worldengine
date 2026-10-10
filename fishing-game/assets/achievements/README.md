# Achievement medals

Transparent pixel-art achievement badges generated with the built-in image_gen
tool. Filenames map to stable achievement IDs, not localized names.

All 113 achievement medals are installed across nine themed batches: 17
milestone/bonus badges, 12 for each of seven biomes, and 12 all-waters badges.
Each biome has its own emblem, all ten ranks have distinct decorations, and
every-rank capstones have fuller wreaths and crowns.

The three approved style samples were retained:

- `badge-10.png`: Pond collection; the base style reference.
- `badge-109.png`: Meadow Pond UUR collection; prismatic rank decoration.
- `badge-18.png`: The lost sock; optional bonus emblem.

The complete 113-asset prompt set is `content/achievement-badge-art.json`.
The remaining 110 medals use the Pond sample as their style reference; the
Fihs bonus also references its supplied fish sprite. Original generated images
remain in the Codex generated-images directory. The selected PNGs retain their
original dimensions and alpha.

The website badge book consumes these through `assets/manifest.json`; absent
art keeps the existing badge symbol. Earned images show full color and locked
images use CSS grayscale and opacity. No achievement logic changes with art.

See `docs/achievement-badge-art.md` for the generation and verification workflow.
