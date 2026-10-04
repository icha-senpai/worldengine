# Companion website

Run `npm run dev`, `npm run check`, and `npm run build` from the repository root.
The dashboard shows live scoped player state, catches, collection, journal,
favorites, confirmed sales, and record snapshots. The full artwork/catalog
preview remains at /catalog.
Shared images are served directly from `../../assets`, not copied to static.
Generated contracts are imported through `@fishing-game/generated`.
Public runtime settings are declared in src/env.ts. Connections start on mount
and stop on unmount. Private rows clear on disconnect and revoked identity.
See root docs for service setup and the remaining live Discord verification.
