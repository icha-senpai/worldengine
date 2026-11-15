# Crafting catalog and plans

Crafting uses a generated, typed recipe graph. `bitcraft/src/crafting/model.ts`
defines recipe identity, action, preference, yields and the shared route ordering.
`bitcraft/src/crafting/catalog.ts` hydrates item metadata and builds bounded,
cycle-aware trees. The UI does not infer recipe preference from display names.

## Updating the catalog

Run from `space/`, using the public static-data directory:

```powershell
npm run bitcraft:snapshot -- .runtime/bitcraft-release-input
npm run bitcraft:catalog -- .runtime/bitcraft-release-input
npm run bitcraft:snapshot -- .runtime/bitcraft-release-input --check
npm run bitcraft:catalog -- .runtime/bitcraft-release-input --check
```

The importer reads the checked-in public UI export (`catalog.json` and
`catalog-additions.json`) for item metadata and direct recipe definitions, plus
the public item, item-list, resource, extraction and skill definitions. It
resolves output bundles through one general path and includes eligible resource
destruction rewards. This replaces the separate wood-log, bait and package
material runtime enrichments. Refresh the base UI export first with
`scripts/import-bitcraft-snapshot.mjs`; it reads the public `cereal/cs` JSON
directly, without Laravel or a player database. The old PHP exporter is a legacy
reference and must not overwrite a newer snapshot.

The current release is November 14, 2026, pinned to
[`1470a29be23d770e3db99e9cf65a88ca57a6ef09`](https://github.com/BitCraftToolBox/BitCraft_GameData/tree/1470a29be23d770e3db99e9cf65a88ca57a6ef09/static).
Download the tables listed in `snapshotTables` plus `item_list_desc.json`
from that same commit into a disposable directory. Add `source.json` with
`repository`, `commit`, and `generatedAt` (the release commit timestamp, currently
`2026-11-14T08:43:41Z`). Never replace that date with the local import time.
The exported catalogue retains the release identity and SHA-256 of every input
table. Regenerate both artifacts together, inspect omissions, run the calculator
and catalog tests, and publish the local BitCraft module to activate the data.
The temporary source directory can be removed after checks pass; deployments
use the committed artifacts. This updates reference data, not retained player,
market, claim, or XP history.

The November refresh excludes developer-only recipes gated by `The Art of
Cheating`, including retired passive farming, and records exclusions in the
snapshot. Farming plans follow the deterministic placeable lifecycle from seeds
through fertilizer, water and harvest. Water is supplied as a full bucket; its
returned empty bucket is not consumed by the farm. Plans show gross inputs and
do not optimise bucket reuse. Farming duration is unknown because harvest work
depends on the player's tool. Unsupported branching lifecycles fail the import.

## November 10 relay and claim compatibility

BitCraftSync's replacement mirror serves the release world's regions as `idc-N`
on the existing `3000 + N` ports. BitJita continues to use `bitcraft-live-N`.
Collector state/player/claim subscriptions, boss recording, schema checks and the
fishing map use the provider-specific database name. The public HTTP
cache base remains `https://relay.bitcraftsync.app`. All nine current regions
are listed by its `/health` endpoint; `/cache-health` reports cache readiness.
Release-world tools use `space-bitcraft-release`; the old world's database remains
`space-bitcraft-tools`, with its history intact. Release-world markets and maps
use only BitCraftSync; BitJita's old-world cache is disabled in the new database.
The selected site player is scoped by world, while the existing browser identity
can still own copied widgets and guides. Claim upkeep follows the released server's
progressive tile brackets and `max(1, exp(5.5 * (5.65 - neighbours / tiles)))`;
the old static m/b parameters no longer drive this calculation.

Reviewed contracts include the new claim technology fields/types, parameters,
footprint enum and enemy enum variants. Both relay and boss contracts regenerate
with `npm run bitcraft:relay-schema`. For the release world, use
`npm run bitcraft:relay-schema -- --bitsync-only` to refresh BitCraftSync while
retaining the reviewed legacy BitJita contract; its old endpoint may be unavailable.
Add `--check` to verify the pinned contract without writing it.
If BitCraftSync cannot serve its schema,
explicitly download `region_schema.json` from schema commit
`1b74d90f165a89809fef37e526c0f16b3eb480b6` in the same GameData repository,
then run `npm run bitcraft:relay-schema -- --source-schema <path>` and repeat
with `--check`. This canonical source uses Rust enum names; the importer converts
only enum variant initials to BitCraftSync's existing lower-camel wire convention.
The BitJita contract is still checked against its live schema. Runtime strict
contract validation remains enabled; a successful offline check does not prove
the unavailable BitCraftSync feed is healthy.

Tracking accepts legacy, departure and destination waystones, and existing banks,
regional banks and converted local stashes. Ruined waystones are not player claim
structures. Existing eligibility and retained history are preserved.

The result is `bitcraft/src/content/crafting-catalog.json`. It contains source
hashes, stable route IDs, typed classifications, quantities and any omitted
recipes whose ingredient definitions are missing. Inspect omissions and the
generated diff when refreshing source data. `--check` verifies reproducibility
without changing the generated artifact. Ordinary builds and deployments use
the committed artifact and do not need the reference checkout or Laravel.

Source action phrases and special-material names are interpreted only at import.
Recipe selection on the server and browser uses the same explicit classifications
and comparison function. Normal tree/trunk sources remain primary for wood logs;
packages, special salvage, sifting and Ancient Mortar routes remain available as
secondary alternatives. A new import can add recipes without changing saved IDs.

## Browser state and loading

`craftingPlanState.ts` validates saved state and selects recipes by target key and
stable recipe ID. Version 1 plans stored alternative positions. The immutable
`craftingLegacyRoutes.json` compatibility map captures their previous ordering;
it is downloaded only when migrating a version 1 plan. Do not regenerate that
map against a newer catalog. Removed or unrecognised choices fall back to the
shared default rather than selecting a different recipe at the old position.
Existing checklist signatures remain quantity-sensitive; changed recipe
identities require rechecking the affected steps.

`useCraftingPlan.js` owns persistence, selection and branch loading. Requests for
the same branch share a promise, while each occurrence receives independent
recipe nodes. Changing targets or unmounting cancels pending requests and stale
responses cannot modify the new plan. Failed requests can be retried. Views own
presentation and emit selection/checklist actions.

## Quantities and estimates

Steps and materials retain per-branch batch rounding. Repeated compatible steps
are combined afterwards; this is not a global stock or surplus optimiser.
Held inventory is compared against gross material requirements.

Item-list yields use their probability-weighted average, including outcomes that
do not award the target. Both views share batch arithmetic, including fractional
yields. Average-output steps, their prerequisites, and related material totals
are marked as estimates. Estimated surplus is not guaranteed. Gathering action
drop rates and destruction time are not fully modelled: resource destruction
rewards have no invented per-action duration, and bundle averages describe the
bundle contents rather than a guaranteed number of successful gathering actions.

Run `npm test`, `npm run typecheck:scripts` and `npm run build` for changes. The
catalog/import, saved-state and branch-loading tests cover source gaps, invalid
data, old plan migration, reordered alternatives, retries and stale responses.
Verify recipe selection and estimates in-browser after local module publication.
Deploy the frontend and BitCraft module together through the normal updater;
this refactor does not change persisted database tables or reducer signatures.

## Provisioning the release world

Stop the managed main collector before provisioning. Run
`npx tsx scripts/bitcraft-release-init.ts` from `space/` with the explicit CLI,
server, owner credentials/config and data directory for that host. The fixed
source is `space-bitcraft-tools`; the fixed destination is `space-bitcraft-release`.
The command refuses to overwrite an existing release database. An interrupted
initial setup may use `--resume`; it accepts only untouched seed records or the
exact expected application state, and never writes the source database.

Provisioning compares all copied application fields (owner, guides, guide
administrators, widget profiles and provider settings), excludes old-world
samples/watches/feeds, verifies the source again, and registers/seals the new
database's own storage binding. No old market, claim or boss checkpoints are
copied. The original database is the retained old-world dataset, not a disposable
test database or an automatic backup. Do not reset or remove it.

`node scripts/activate-bitcraft-release.mjs` verifies the provisioning identity,
then selects the new main database and updates only the relevant private frontend
and collector environment keys. On Windows, start the managed main worker and
check `https://space.test`. On Linux, authorize production activation separately,
allow the additional public subscription route with the focused
`deploy/linux/enable-release-world.py --apply` administrator command, and run
`dataverse-update`. Its verified target override preserves the old database as an
archive and excludes it from publication. The updater still checks both database
identities and protects retained application records in the active one.

The new world may have only neutral claims before launch. An empty release-world
market/player search is not permission to reuse old-world API results. Select a
new-world character explicitly; copied widget settings may still refer to old
characters until their owners update them.
