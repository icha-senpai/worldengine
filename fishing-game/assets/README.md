# Supplied artwork

- `fish/`: original PNG sprites from `fish-sprites.zip`; archive's `fishing/` prefix removed.
- `rank-cards/`: original F, D, C, B, A, S, SS, SSS, UR, UUR cards.
- `items/`: generated transparent pixel-art rusted tin and scrap inventory icons.
- `achievements/`: generated transparent pixel-art achievement medals, keyed by stable badge ID.
- `manifest.json`: keys, public URLs, PNG dimensions, bytes, SHA-256 hashes.

Images are extracted without resizing, recompressing, or changing transparency.
The website serves this directory as its static asset root; the bot can read the
same files from disk. No duplicate image copy is required.
Reimport with `pwsh -File scripts/Import-Assets.ps1`, or pass explicit archive paths.
Rebuild the manifest with `npm run assets:manifest` after adding artwork.
Archive provenance is retained. `content/species.json` uses these sprite names
as the game's fish names, including Nidalees Lost Sock as the one fake fish.
Rarity and stats are assigned separately from artwork.
