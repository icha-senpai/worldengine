# Crafting catalog and plans

Crafting uses a generated, typed recipe graph. `bitcraft/src/crafting/model.ts`
defines recipe identity, action, preference, yields and the shared route ordering.
`bitcraft/src/crafting/catalog.ts` hydrates item metadata and builds bounded,
cycle-aware trees. The UI does not infer recipe preference from display names.

## Updating the catalog

Run from `space/`, using the public static-data directory:

```powershell
npm run bitcraft:catalog -- ../tmp/BitCraft_GameData-20261008-fishing/static
npm run bitcraft:catalog -- ../tmp/BitCraft_GameData-20261008-fishing/static --check
```

The importer reads the checked-in public UI export (`catalog.json` and
`catalog-additions.json`) for item metadata and direct recipe definitions, plus
the public item, item-list, resource, extraction and skill definitions. It
resolves output bundles through one general path and includes eligible resource
destruction rewards. This replaces the separate wood-log, bait and package
material runtime enrichments. Refreshing the base UI export still uses
`scripts/export-bitcraft-ui.php`; regenerate the crafting graph afterwards.

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
