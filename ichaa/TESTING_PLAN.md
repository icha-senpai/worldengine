# Testing Plan

## Goal

Build a test suite that catches domain regressions quickly, keeps Vue pages honest, and only uses browser tests for the highest-value end-to-end flows.

## Current Baseline

### Backend

- Dashboard overview panels and aggregates are covered by the Laravel feature suite.
- Auth and profile flows are covered by the Laravel feature suite.
- Identity covers entity listing, creation defaults, completion scoring, publishability, aliases, notes, questions, versions, unpublish, and archive flows.
- Connections covers relationship filtering, create/update history, group relationship behavior, faction memberships, and access-default regression handling on create flows.
- Organization covers smart collections, membership sync behavior, and glossary CRUD/filtering.
- Lore covers documents, canon references, crossover entry points, and search-facing assertions.
- Temporal covers timelines, event placement/removal, character states, concurrency groups, and timeline access-default regression handling.
- Intelligence covers secrets, knowledge states, and perception-state workflows.
- Production covers pipeline progression, meta linking, session logs, and meta-note access-default regression handling.
- World covers power interactions, travel routes, location containment, and location control.
- Search has PostgreSQL-backed coverage.

### Frontend

- Dashboard display behavior is covered in Vitest.
- Shared formatter helpers are covered in Vitest.
- Shared page-builder helpers are covered in Vitest.
- Scaffold form rendering/parsing behavior is covered in Vitest.
- Search page rendering and query submission behavior is covered in Vitest.
- Dense read pages are covered for entities, relationships, collections, glossary, documents, character states, concurrency groups, timelines, secrets, knowledge states, perception states, power interactions, travel routes, location control, and pipeline.
- Create/edit scaffold forms are covered across the main scaffold-driven domain pages, including intelligence secrets, knowledge states, perception states, and the access-default contracts that have already drifted in relationships, collections, timelines, group relationships, and meta notes.
- Pipeline custom pages have direct Vitest coverage across index, show, create, and edit, including scene and character-study branches.

### Browser Smoke

- Verified-user login works.
- Smart-collection creation plus auto-matching member display works.
- Entity creation plus search works.
- Relationship creation works.
- Lore document creation plus search works.
- Profile update works.
- Pipeline item creation works.

## Testing Principles

- Keep backend tests PostgreSQL-first. This app depends on generated columns, JSONB behavior, and full-text search.
- Prefer feature tests for request, controller, validation, and workflow behavior.
- Use unit tests only for pure logic or calculators.
- Keep frontend tests focused on visible behavior and form payloads, not implementation details.
- Keep Playwright intentionally small: smoke and top-risk regressions only.
- Add factories before adding heavy seeders.
- Keep local and testing databases separate. `php artisan test` and Playwright should target `.env.testing`, not the live app database.
- When browser coverage depends on schema alignment, keep the local app database migrated too so UI smoke runs match the real runtime path.

## Current Highest-Value Gaps

### Browser

- Add a temporal browser flow that exercises timeline event placement if and when that authoring surface is actually exposed in the UI.
- Add one intelligence-oriented browser flow only if it covers a real cross-layer risk better than backend plus Vitest already do.

### Frontend

- Add read-page tests for the remaining dense organization, intelligence, world, and temporal screens beyond the current index/show batch.
- Add targeted regression tests for the remaining UI contracts that are still schema-sensitive or have not yet been pinned down on both create and edit paths, especially any custom-production behavior outside the now-covered pipeline surface.

### Backend

- Add more validation-focused regression coverage for the remaining schema-sensitive fields and nullable/default behavior where the app and database can drift apart.
- Expand search coverage into a broader cross-domain matrix only where it protects real user-facing workflows.

## CI Shape

- Run `php artisan test` and `npm test` on every pull request.
- Run `npm run test:e2e` on main-branch merges, release candidates, or a nightly schedule.
- Publish Playwright artifacts from `playwright-report` and `test-results` when browser tests fail.
- Keep `.env.testing` or equivalent CI environment variables pointed at an isolated Postgres database.

## Practical Rollout Order

1. Add backend regression tests first when a workflow has schema/default drift risk.
2. Add Vitest coverage next for shared UI contracts and dense read pages.
3. Add Playwright only when the feature is important enough to justify real browser cost.
4. Revisit flaky assertions immediately; do not let the browser suite become noisy.

## Definition Of Done

- Every route group in `routes/web.php` has at least one meaningful backend feature test.
- Every major page family has at least one Vitest layer covering form behavior or display behavior.
- The Playwright suite covers the app's core happy paths without becoming the main place business rules are tested.
- `php artisan test`, `npm test`, and `npm run test:e2e` are green locally when making testing-focused changes.
