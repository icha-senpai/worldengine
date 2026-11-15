# Release-world fishing map

The active terrain snapshot is BitJita mapdata v1, generated 14 November 2026.
It contains 900 full-resolution tiles across the nine release regions. Region
names come from the shared release-region catalog. The old island extensions
are absent from this source. Terrain and depth are a snapshot; fish-school
positions update live from BitCraftSync `idc-N` resource/location subscriptions.

Verified one release terrain chunk against the source snapshot before importing:
chunk (250, 100), dimension 1, in Serek. Fish-school resource IDs were also
checked against the release relay's resource definitions.

Refresh locally from the project directory:

```powershell
npm run bitcraft:map
npm run bitcraft:map-overview
npx vitest run tests/fishing-map-atlas.test.js tests/fishing-map.test.js tests/fishing-map-cache.test.js tests/fishing-map-relay.test.js
npm run build
```

The baker resumes tiles from the pinned source and publishes the new manifest
only after all levels finish. `--refresh` fetches the current source manifest;
the package command includes it. Raw source tiles use the ignored
`output/research/terrain-source` work directory. Never change a manifest's date
or labels to imply terrain was refreshed without rebuilding its assets.

The dated terrain URLs isolate browser caches from the previous world. Release
camera positions use a separate key so old-world saved views are not restored.
The page shows the terrain date and separately credits its live school source.
