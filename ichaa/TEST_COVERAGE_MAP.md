# Test Coverage Map

## Current Snapshot

- Backend: `php artisan test` currently passes 78 tests and 838 assertions.
- Frontend: `npm test` currently passes 8 Vitest files and 76 tests across dashboard display checks, shared helpers, scaffolded forms, dense read pages, and custom pipeline page contracts.
- Browser: `npm run test:e2e` currently passes 6 Playwright smoke flows.

This file is the quick "where are we actually protected?" map. `TESTING_PLAN.md` is the roadmap; this file is the current state.

## Covered Well

### Backend workflow coverage

- Dashboard has meaningful coverage for the overview aggregates and panel payloads it builds from pipeline, session, intelligence, and question data.
- Auth and profile flows are covered by the Laravel feature suite.
- Identity has meaningful coverage for entity listing, creation defaults, completion scoring, publishability, aliases, notes, questions, versions, unpublish, and archive flows.
- Connections covers relationship filtering, creation, update history, group membership changes, faction memberships, and access-default regression handling for relationship/group creation.
- Organization covers smart-collection syncing, collection membership behavior, and glossary CRUD/filtering.
- Lore covers documents, canon references, crossover entry points, and at least one global-search path.
- Temporal covers timeline creation, timeline event placement/removal, character states, concurrency groups, and timeline visibility-default handling.
- Intelligence covers secrets, knowledge states, and perception-state workflows.
- Production covers pipeline advancement, meta linking, session logs, and meta-note access-default handling.
- World covers power interactions, instance recording, resolve behavior, travel routes, location containment, and location control.
- System search has PostgreSQL-backed coverage rather than SQLite-only shortcuts.

### Frontend coverage

- Dashboard display behavior is covered.
- Shared formatter helpers are covered.
- Shared page-builder helpers are covered.
- The scaffold form shell is covered.
- Search page behavior is covered.
- Dense read pages are covered for entities, relationships, collections, glossary, documents, character states, concurrency groups, timelines, secrets, knowledge states, perception states, power interactions, travel routes, location control, and pipeline.
- Create/edit scaffold forms are covered for relationships, group relationships, faction memberships, collections, glossary, documents, canon references, crossover entry points, character states, concurrency groups, secrets, knowledge states, perception states, power interactions, location containment, travel routes, location control, session logs, and meta notes, with access-default regressions additionally pinned on create forms for relationships, group relationships, collections, timelines, and meta notes.
- The custom pipeline index/show/create/edit pages now have direct Vitest coverage for filtering, branch-specific rendering, type/stage toggles, option wiring, and submit routes.

### Browser smoke

- Verified-user login works.
- Smart-collection creation plus matching-member display works.
- Entity creation plus search works.
- Relationship creation works.
- Lore document creation plus search works.
- Profile update works.
- Pipeline-item creation works.

## Partial Or Light Coverage

These areas have some protection, but not enough to call them deeply covered yet.

- Frontend read-page coverage is still selective. The densest pages now have direct tests across a wider mix of index/show surfaces, but many other screens are still only protected indirectly by backend tests, helper tests, or smoke coverage.
- Browser coverage is still intentionally thin. Temporal timeline/event authoring is not yet browser-covered because the event-placement action is not surfaced directly in the current UI, and intelligence/world authoring flows are still mostly protected by backend plus Vitest coverage.
- Search is covered for entities and documents, but not as a broad cross-domain regression matrix.
- Validation edge cases exist in feature tests where they matter most, but there is not a comprehensive per-field validation suite for every resource.

## Intentionally Not Deep-Tested Right Now

These are still lower-value targets than business-rule and contract coverage.

- Exhaustive browser CRUD coverage for every domain page. Broad Playwright expansion is still expensive, so browser tests should stay focused on cross-layer confidence.
- Snapshot-heavy Vue tests for every scaffold page. They are noisy and lower-signal than route/payload assertions.
- Deep database-implementation tests for every generated column, GIN index, or search-vector expression. The current preference is to cover those through real workflows and search behavior.
- Full visual/regression coverage for every show/index layout. Shared scaffold coverage plus a few behavior-focused tests give better value here.

## Highest-Value Next Gaps

If we keep expanding, the next best returns are:

- Extend temporal browser coverage beyond timeline event placement into character state and concurrency-group authoring.
- Add a few more frontend read-page tests for remaining dense screens in intelligence, world, organization, and temporal pages beyond the current index/show batch.
- Add targeted validation/regression tests for the remaining schema-sensitive fields that still do not have explicit create/update contract coverage, especially any custom-production behaviors outside the now-covered pipeline surface.

## Rule Of Thumb

- Business rules should fail in backend feature tests first.
- Shared UI contracts should fail in Vitest next.
- Browser tests should only prove the app still basically works from a real user's perspective.
