## Agent World Model

Before making changes, build a small working model of the affected area instead of editing from isolated snippets.

For every non-trivial change, identify:

1. **User intent**

   * What behavior, bug, screen, model, or workflow is actually being changed?
   * What is explicitly requested versus merely tempting cleanup?

2. **Affected surface**

   * Which route, controller, model, migration/schema, service/action, Vue page, component, request class, policy, resource, and test area may be involved?
   * Check sibling files before creating a new pattern.

3. **Current invariants**

   * What must remain true after the change?
   * Preserve auth/verified route behavior, named routes, route parameters, generated columns, JSONB relation patterns, timeline placement behavior, soft-delete/restore flows, and existing response shapes.

4. **Change prediction**

   * Before editing, predict the likely blast radius.
   * If a change touches persistence, predict schema impact, model casts/relationships, validation, authorization, factories, seeders, and tests.
   * If a change touches UI, predict prop shape impact, component reuse, mobile behavior, overflow/wrapping, and shared layout impact.
   * If a change touches API behavior, predict response shape impact, consumers, resources, validation, and error handling.

5. **Action choice**

   * Prefer the smallest correct change that fits the existing architecture.
   * Prefer stable, boring, explicit Laravel/Vue code over cleverness.
   * Do not introduce abstractions unless they remove real duplication, clarify ownership, or reduce actual risk.
   * Do not refactor unrelated code while completing a requested change.

6. **Verification**

   * Use Laravel Boost documentation lookup before framework-specific changes.
   * Use schema/route inspection before changing persistence or routing behavior.
   * Run the smallest relevant test command first.
   * If tests fail, update the working model before making another edit. Do not patch randomly.

7. **Surprise handling**

   * If the codebase contradicts this file, trust the live code and dependency manifests first.
   * If the intended change would violate existing architecture, stop and explain the conflict.
   * If multiple safe approaches exist, surface the tradeoffs instead of silently choosing a large direction.

## Complexity and Responsibility Model

Use file size, responsibility count, and change risk as warning signals.

* Controllers coordinate requests and responses. They must not become workflow engines.
* Services/actions should own clear responsibilities. They must not become god objects.
* Vue pages/components should render and coordinate UI state. They must not become backend rule engines.
* Helpers must stay narrow. They must not become dumping grounds.
* If a file is growing because it owns multiple responsibilities, suggest a responsibility-based split before adding more logic.
* Split by real responsibility, not by aesthetics.
* Avoid abstraction confetti. Small files are not automatically better if the system becomes harder to trace.
* Keep important rules close to the write path: ownership, permissions, ordering, validation, mutation guards, lore integrity, entity relationships, generated search vectors, JSONB link fields, soft-delete/restore behavior, and timeline placement.
* Correctness and traceability beat cleverness.

## Trivial Change Fast Path

For truly trivial changes, do not perform a full architecture review.

A change is trivial only when it is limited to one small, obvious surface and does not affect behavior, persistence, routing, authorization, validation, API response shapes, shared components, generated files, build tooling, or tests.

Examples of trivial changes:

* Fixing a typo in visible text.
* Adjusting a label, heading, placeholder, or help message.
* Changing a small local Tailwind class on a single component.
* Renaming a local variable for clarity without changing logic.
* Removing dead whitespace or formatting that Pint/Prettier would normalize.
* Updating a comment or PHPDoc block without changing behavior.

For trivial changes:

1. Confirm the change is truly local.
2. Inspect the touched file enough to avoid breaking nearby patterns.
3. Make the smallest possible edit.
4. Do not refactor surrounding code.
5. Do not create new files, abstractions, components, services, routes, migrations, or tests unless the change stops being trivial.
6. Run formatting when required by the touched file type.
7. If no test is useful because the change is copy-only or visual-only, say so briefly in the final response.

A change is not trivial if it touches:

* Database schema or persisted data.
* Model relationships, casts, accessors, mutators, scopes, or observers.
* Controllers, services, actions, policies, Form Requests, Resources, or routes.
* Auth, permissions, ownership, validation, soft deletes, restore behavior, timeline placement, generated search vectors, or JSONB link fields.
* Shared Vue components, layouts, scaffold behavior, composables, stores, or global CSS.
* API or Inertia prop shapes.
* Build tooling, package versions, deployment config, or test setup.

If a “small” change reveals hidden coupling, treat it as non-trivial and use the full Agent World Model.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- tightenco/ziggy (ZIGGY) - v2
- laravel/boost (BOOST) - v2
- laravel/breeze (BREEZE) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/vue3 (INERTIA_VUE) - v3
- tailwindcss (TAILWINDCSS) - v4
- vue (VUE) - v3

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools when they are available and a good fit for the task, and fall back to shell commands or direct file reads when they are not.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/Pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`. Use `vendor/bin/pint --dirty --format agent` unless the user explicitly asks for a broader formatting pass.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for PHP backend testing. All PHP tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>

## Dataverse App Context

- This is Dataverse, a worldbuilding and AU management app for a large crossover fiction setting.
- The project is in active development. Treat the app as a live product, not an archived prototype.
- If documentation drifts from the codebase, prefer the live code in `composer.json`, `package.json`, `routes/`, `app/`, and `resources/js/`.
- The current stack in code is Laravel 13, Inertia v3, Vue 3, Tailwind 4, PostgreSQL, Breeze, Sanctum, Ziggy, PHPUnit, Vitest, and Playwright.

## App Shape

- The backend is organized by domain under `app/Domain/`:
  `Identity`, `Connections`, `Organization`, `Lore`, `Temporal`, `World`, `Intelligence`, `Production`, `System`.
- HTTP controllers mirror that domain split under `app/Http/Controllers/`.
- The frontend page structure mirrors the product areas under `resources/js/Pages/`, with notable sections including:
  `Entities`, `Relationships`, `GroupRelationships`, `Collections`, `Glossary`, `Lore`, `Temporal`, `Intelligence`, `Production`, `World`, `Search`, `Profile`, and `Auth`.
- Most CRUD surfaces are scaffold-backed. Before introducing a one-off page pattern, check the shared scaffold and sibling pages first.
- For scaffold-backed forms, field behavior metadata must be explicit instead of inferred from field names.
- Every scaffold field with `type: 'json'` must declare `jsonMode`.
- Any scaffold field using `jsonMode: 'object-list'` must also declare `jsonObjectFields`, and should declare `emptyValue` when an empty submit must become `[]` instead of `null`.
- If a scaffold field's empty-state semantics matter, declare `emptyValue` explicitly rather than relying on the scaffold default.

## Architecture Notes

- Follow the app's lightweight DDD style:
  domain-organized models/controllers, explicit service-layer logic, named scopes, and direct Eloquent usage.
- Do not introduce repository layers or aggregate-root ceremony unless the user explicitly wants an architectural shift.
- Favor explicit model relationships, casts, route parameters, and controller actions over framework magic.
- The app is modeling dense narrative/reference data. Keep changes readable and schema-aware rather than overly abstract.

## Code Organization, PSR, and Maintainability Rules

* Follow PSR-4 autoloading expectations: namespaces must match directory structure, and class names must match their file names.
* Follow PSR-12-style PHP formatting and Laravel conventions. Use Laravel Pint as the formatter source of truth for PHP files.
* Prefer boring, stable, readable code over clever abstractions.
* Correctness, reliability, and maintainability are more important than cleverness, terseness, or novelty.
* Do not introduce “magic” behavior when explicit code would be easier to understand and safer to maintain.
* Avoid spaghetti code. Keep responsibilities separated by domain, layer, and purpose.
* Avoid fat controllers. Controllers should coordinate requests, validation, authorization, services/actions, and responses, not contain large business workflows.
* Avoid god classes, god services, god components, god composables, and oversized utility files.
* If a file is growing large because it owns multiple responsibilities, suggest a responsibility-based split before adding more complexity.
* Split by real responsibility, not by aesthetics. Do not create tiny abstraction confetti just to make files smaller.
* Prefer domain services, actions, model methods, named scopes, Form Requests, resources, policies, and Vue components where the existing codebase already uses those patterns.
* Keep business rules close to the write path. Permission checks, ownership checks, validation, ordering rules, and mutation guards should live near the code that changes data.
* Keep Vue components focused. If a component becomes difficult to scan, extract real reusable components or composables rather than piling more logic into one file.
* Keep shared helpers narrow and purposeful. Do not turn helper files into dumping grounds.
* Prefer explicit names over short names. Code should explain its intent before comments are needed.
* Comments should explain why something exists, not narrate obvious code.

## Laravel Structure Rules

* Controllers should stay thin.
* Request validation belongs in Form Requests when validation is non-trivial or reused.
* Authorization belongs in policies, gates, middleware, or existing authorization patterns.
* Data presentation for APIs should use Resources when the touched surface already uses them.
* Reusable query logic should prefer named scopes or dedicated query/service methods over repeated inline query chains.
* Complex writes should use services/actions that match the existing bounded context.
* Database writes that must succeed or fail together should use `DB::transaction()`.
* Do not hide database mutations inside accessors, computed attributes, view helpers, or frontend-driven assumptions.
* Do not place business rules in Vue components when the rule affects backend correctness, permissions, ownership, lore integrity, timeline placement, entity relationships, generated search vectors, JSONB link fields, soft-delete/restore behavior, and persisted state.
* Do not place large workflow logic directly in routes.
* Do not use route closures for new complex behavior unless the surrounding area already uses that pattern and the change is intentionally small.

## Stability Over Cleverness

* Prefer the smallest correct change that fits the existing architecture.
* Do not rewrite working code just because a cleaner pattern exists.
* Do not introduce a new abstraction unless it removes real duplication, clarifies ownership, or reduces actual risk.
* Do not optimize prematurely.
* Do not trade readability for fewer lines.
* Do not trade explicitness for cleverness.
* When in doubt, choose the boring Laravel/Vue solution that future maintainers can understand quickly.
* If a better architecture is possible but outside the requested scope, suggest it separately instead of implementing it silently.


## Routing and Behavior Gotchas

- All application routes in `routes/web.php` are behind both `auth` and `verified`, except Breeze auth routes from `routes/auth.php`.
- The dashboard lives at `/`, search at `/search`, and trash at `/trash`.
- Production route parameters have known inflection traps:
  `meta` must stay mapped to `{meta}` and `pipeline` must stay mapped to `{pipeline}`.
- Timeline placement is a real feature surface:
  `timelines/{timeline}/events/{event}` places events and `timelines/{timeline}/events/{entry}` removes them.
- There is a restore flow for soft-deleted records via the trash UI. Prefer restore/archive/delete patterns that match existing behavior instead of inventing parallel lifecycle rules.

## Data and Schema Notes

- PostgreSQL is the real target. Do not quietly swap behavior toward SQLite assumptions when writing tests or data logic.
- Several major tables use generated `search_vector` columns. If a source column type needs to change, account for generated-column dependencies first.
- Some lightweight relations are stored as `jsonb` ID arrays instead of pivots. Check the table and existing query pattern before normalizing or refactoring.
- `power_interactions` depends on application-layer ordering of the entity pair to avoid inverse duplicates. Preserve that behavior in validation and service logic.

## Frontend Notes

- This is an Inertia/Vue app, not a Blade-first app. Page work should usually land in `resources/js/Pages` and related shared Vue components/layouts.
- Reuse shared layouts, form controls, buttons, dropdowns, and scaffold pieces before adding local styling or duplicated controls.
- Keep the current visual language and density model unless the user asks for redesign work.
- Dense read pages, metadata chips, pills, and side panels are common in this app. Watch for text overflow, wrapping, and mobile collapse when adjusting UI.
- Inline object components inside `<script setup>` have already bitten this codebase. Prefer real `.vue` components or direct template markup.

## Content and Domain Modeling

- Entities are the core record type and cover many real-world/story concepts through a single typed model, not separate per-type tables.
- A lot of the app's value is cross-domain linking: entities to notes, relationships, timeline events, pipeline items, secrets, collections, and world data.
- Be cautious with field defaults and enum-like string values. Schema-sensitive drift has already happened in timeline and relationship-adjacent areas.
- Content models are surface-specific in this app. Several notes, lore, and pipeline fields already use structured rich-text/JSON payloads, so verify the touched surface in code before assuming either plain text or a universal editor contract.

## Testing Reality

- Backend tests run with `php artisan test`.
- Frontend unit tests run with `npm test`.
- Browser smoke tests run with `npm run test:e2e`.
- The Playwright suite builds first and uses a seeded verified `e2e@example.com` user.
- Prefer the smallest relevant test run while iterating, but every real behavior change should leave test coverage or a test update behind.

## Working Defaults For This Repo

- Check sibling files before introducing a new page pattern, service style, or controller shape.
- When working in a specific domain, inspect both the backend domain/service side and the matching Inertia pages before deciding where logic belongs.
- For UI work, preserve breakpoints and avoid solving sizing problems with global hacks when a shared Vue-owned fix is the real seam.
- If the README and the app disagree on version numbers, treat the dependency manifests as source of truth and update docs only when the user asks.

## Change Scope Rules

* Do not invent new features, scope, routes, screens, models, services, or data relationships unless explicitly requested.
* Do not restructure the project, rename files, move directories, or modify build/tooling config unless explicitly requested.
* Do not add new dependencies without approval.
* Do not refactor unrelated code while completing a requested fix or feature.
* Make changes surgically and locally.
* Small coordinated changes across related files are allowed only when directly required by the requested behavior.
* Preserve existing public method signatures, route names, component props, and response shapes unless the requested change requires otherwise.
* When multiple valid approaches exist, surface the tradeoffs instead of silently choosing a large architectural direction.

## Inertia, Vue, and API Boundaries

* This is an Inertia/Vue application. Vue is the view layer and Laravel owns routing, data access, validation, and backend behavior.
* Inertia routes may return `Inertia::render()` and pass props directly to pages.
* API routes must return JSON only and must not return Inertia or Blade responses.
* Do not mix API response conventions into first-party Inertia page controllers.
* Do not leak view-layer assumptions into API response payloads.
* Use Laravel Form Requests when adding or significantly touching validated write endpoints.
* Use Eloquent API Resources when the touched API surface already uses them, or when intentionally normalizing a touched API payload.

## AI Refactoring Safety Rails

* Never delete code unless explicitly instructed.
* Do not remove tests, migrations, routes, models, or components as “cleanup” unless explicitly requested.
* If old code appears unused, confirm by inspecting routes, references, tests, and sibling patterns before changing it.
* Controllers should stay thin. Business logic belongs in the existing domain, service, action, or application-layer pattern used by the bounded context being touched.
* Avoid broad refactors across multiple domains without approval.
* Keep functions simple unless real complexity is required.
* Do not introduce abstractions before a real second seam appears.
* Avoid speculative interfaces, repository layers, or “DDD for show” patterns that do not remove real duplication or risk.

## Backend Structure Guidance

* Follow the existing pattern of the bounded context being touched.
* If a context already uses services, actions, presenters, named scopes, or direct Eloquent queries, extend that local pattern instead of inventing a competing one.
* Route closures, controllers, and existing service classes may still exist in older areas. Improve them surgically instead of using a requested fix as an excuse for broad architectural cleanup.
* Presentation boundaries in Vue components must not dictate backend structure.
* Keep invariants close to the write path. Permission checks, ownership rules, ordering rules, validation, and mutation guards should live near the code that changes state.
* Preserve existing `DB::transaction()` boundaries around write operations. Do not move transaction boundaries casually during refactors.

## Output Expectations For Code Changes

* Show only the changed sections unless the full file is explicitly requested.
* Explain reasoning only where it prevents mistakes or clarifies a tradeoff.
* Prefer real code over pseudo-code.
* After modifying PHP files, run the existing formatter/test commands required by this repo.
* After modifying tests, run the smallest relevant test command first.
* When a requested change would violate these repo rules, stop and explain the conflict before proceeding.
