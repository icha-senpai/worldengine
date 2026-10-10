# Permanent fishing atlas

Space renders its own normal-terrain and depth palettes from public BitJita
terrain data. The baked snapshot is served locally by Space. Runtime terrain
does not contact upstream map services, subscribe to relays, or re-render hexes.

Source: https://mapdata.bitjita.com/v1/manifest.json
Map: https://bitjita.com/map
Underlying game: BitCraft, Clockwork Labs.

Snapshot: 2026-10-09T01:03:58.780Z (October 8 local time).

`detail/20261009010358/` contains separate maps for the nine playable regions
and four adjoining outer-ocean regions. Each region descriptor owns 100
full-resolution tiles. The shared manifest joins all 1,300 native tiles into
one world, with five additional levels for efficient overview zooms. Both
normal-terrain and water-depth lossless WebP images are served. Native tiles use two pixels
per small game hex; exact inspection data retains one depth per large terrain
cell. Every optimized image was decoded and compared pixel for pixel against
its original PNG. Original PNG assets and their manifest remain available for
existing clients; returning visitors reuse previously cached PNG tiles without
downloading WebP replacements. The active atlas payload is approximately
35.5 MiB including the overview and exact depth files, down from 110.4 MiB.

Depth comes from source water-depth bytes, with no-data kept separate. The
deepest value is 230; no water reaches the byte saturation limit of 255.
Independent relay samples from all nine playable regions matched all 9,216
sampled water cells. Verification scripts and results are under
`output/research/map-atlas-relay-verification*`.

Regions follow the game's 5 by 5 grid, each 80 chunks wide (2,560 large cells /
7,680 small tiles). Source neighbor padding and the game's hex conversion
keep independently rendered tiles aligned across joins.

The browser loads visible pre-rendered tiles, limits decoded bitmap memory to
64 MiB, and uses coarse levels for large views. Versioned assets and the
content-addressed overview are saved in
CacheStorage without timed expiration. Subsequent visits reuse cached files;
clearing browser storage can require fetching Space's files again. Fish
locations remain live through one relay decoding worker. R9 and T1 are the
defaults, and camera positions are saved per region.

Rebuild from the Space directory:

    node scripts/fishing-map-bake.mjs
    python scripts/fishing-map-optimize.py

The optimization step requires Pillow with WebP support. It resumes existing
conversions, verifies every decoded pixel, and publishes a content-addressed
manifest only after all images pass. Raster optimization preserves the terrain
snapshot, tile extents, native resolution, and exact depth assets.

The task pins its source manifest in `output/research/terrain-source/manifest.json`
and reuses downloaded source files. It resumes interrupted builds, validates
payloads, and publishes the manifest after all assets are complete. No automatic
rebuild or terrain refresh runs at runtime. Deliberately replacing the pinned
source manifest is required to capture a future snapshot.

The lossless `world.<hash>.webp` and `overview.json` provide the initial overview
while the atlas loads. The overview also uses persistent CacheStorage.
Regenerate the original overview with `node scripts/fishing-map-overview.mjs`,
then run the optimization step again.

Coordinates follow the public game server's small/large offset hex conversion:
https://github.com/clockworklabs/BitCraftPublic/tree/main/BitCraftServer/packages/game/src/game/coordinates

School IDs and fleeing depth limits were checked against the October 8, 2026
public server and game data. Fleeing encounters are personal and short-lived;
the map shows ordinary and frenzied/chummed source schools. Its spawning ring
checks depth only. It cannot promise a spawn or account for blocking placeables.
