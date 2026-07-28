# Dataverse — Worldbuilding AU Management Tool

> **Status: Active development**
>
> Dataverse is back in active development. The Laravel + Vue + PostgreSQL app is the live worldbuilding workspace again, with backend, frontend, and browser test coverage expanding alongside feature work.

---

## What This Is

A custom Laravel 13 / Inertia v3 / Vue 3 / PostgreSQL application for managing a 20+ universe crossover fiction AU. It is built to replace sprawling notes with a structured, queryable backend that can support creative workflows, cross-domain search, and AI-assisted exploration on top of clean domain data.

The schema design is still one of the strongest parts of the project, but the application itself is no longer just a parked artifact. The current focus is shipping the actual authoring and reference-management experience on top of that foundation.

---

## Stack

- **Backend:** Laravel 13, Breeze auth, Sanctum, Passport, Spatie permission
- **Frontend:** Vue 3, Inertia.js v3, TailwindCSS 4, Tiptap rich-text editing
- **Database:** PostgreSQL
- **API / MCP:** Versioned `/api/v1` authoring API, native Laravel MCP server, Dataverse and Bitcraft MCP tools
- **Architecture:** Lightweight DDD — domain-organized models and controllers, service-layer logic, named scopes. No repositories or aggregate roots.

---

## Domain Structure

The World Engine is organized around explicit domains, each with its own `app/Domain/` and matching `app/Http/Controllers/` area where the surface needs controllers. Bitcraft tooling is active alongside the core worldbuilding domains.

| Domain | Tables | Purpose |
|---|---|---|
| **Identity** | entities, entity_aliases, entity_notes, entity_questions | Core entity model — characters, locations, factions, objects, concepts, and 20+ other types |
| **Connections** | relationships, group_relationships, faction_memberships | How entities relate to each other — dyadic, group, and institutional |
| **Organization** | collections, glossary | Grouping and terminology management |
| **Lore** | documents, source_canon_reference, crossover_entry_points | In-world documents, canon sources, universe entry mechanics |
| **Temporal** | timeline, concurrency_groups, character_state_tracker | When things happen and character state at each point |
| **World** | power_interactions, spatial layer (4 tables) | Power system mechanics and spatial containment hierarchy |
| **Intelligence** | knowledge_states, secrets, perception_states | Who knows what, what's hidden, and perception vs. reality gaps |
| **Production** | writing_pipeline, session_log, meta, contradictions_and_conflicts | Actual writing workflow — scenes, drafts, author notes, session tracking |
| **System** | settings | Global config |
| **Bitcraft** | bitcraft_widget_profiles plus external Bitjita/SpacetimeDB data | Market, barter, crafting, settlement, and OBS widget tooling |

The migration set targets PostgreSQL and includes generated search vectors, JSONB document fields, soft-delete/restore flows, API revision tracking, OAuth tables, media references, and Bitcraft widget profiles.

---

## Current App Surface

The app now has live page stacks across the main domains, including Identity, Connections, Organization, Lore, Temporal, Intelligence, Production, Search, Profile, World, System/admin surfaces, and Bitcraft tools.

### Active UI areas

- **Identity** — entities, aliases, notes, questions, versions, publishing/archive lifecycle.
- **Connections** — relationships, group relationships, faction memberships.
- **Organization** — collections and glossary management.
- **Lore** — documents, canon references, crossover entry points.
- **Temporal** — timelines, concurrency groups, character states.
- **Intelligence** — secrets, knowledge states, perception states.
- **Production** — pipeline, meta, session logs.
- **World** — power interactions, travel routes, containment, location control.
- **System** — search, media library, trash/restore, revisions, Notion sync records.
- **Bitcraft** — market finder, barter stalls, crafting calculator, settlement projects, EXP tracker, inventory tracker, task tracker.
- **API/MCP** — authenticated `/api/v1` resource and action endpoints, media upload/replace, revision-aware mutations, Dataverse MCP catalog/tools, and read-only Bitcraft MCP tools.

Most create/edit/show/index pages are scaffold-backed, which keeps cross-domain behavior consistent and makes contract drift easier to catch in tests.

---

## Key Architecture Decisions

### Admin-gated Datacrypt Access
Authenticated app routes under `/datacrypt` require `auth`, `verified`, and the local `EnsureAdmin` middleware. User roles are provided through Spatie permission; the current app gate is intentionally simple: admins enter Datacrypt, non-admins are redirected home.

### Managed Media References
Dataverse keeps media as first-class `media_references` records with explicit attachment targets, managed uploads, external links, media-library pages, and API/MCP upload and replace flows. Check the local media code before assuming a generic package attachment pattern.

### Rich Documents Where The Surface Supports Them
Tiptap-backed rich document fields are active in shared scaffold forms and several custom pages. JSON scaffold fields must declare explicit behavior metadata such as `jsonMode`, and rich-document empty-state behavior should be handled deliberately instead of inferred from field names.

### No Eloquent magic, explicit everything
All relationships are explicitly typed. No dynamic properties relied on. Casts are declared. Scopes are named. The goal was code that could be read six months later without archaeology.

### Shared Catalogs Before One-Off Maps
Web, API, and MCP surfaces have shared catalogs and registries. Before adding a new resource path or tool surface, check `ResourcePageBuilder`, `DataverseWebResourceRegistry`, `TopLevelModeledResourceCatalog`, `ApiResourceRegistry`, and `DataverseMcpCatalog`.

---

## Hard-Won Lessons

### PostgreSQL generated columns block type changes
`search_vector` columns are `GENERATED ALWAYS AS ... STORED` tsvector columns that depend on other columns. If you try to `ALTER COLUMN content TYPE TEXT`, Postgres refuses because the generated column depends on it. The fix:

```sql
-- Drop the index first
DROP INDEX IF EXISTS entity_notes_search_vector_idx;
-- Drop the generated column
ALTER TABLE entity_notes DROP COLUMN IF EXISTS search_vector;
-- Change the type
ALTER TABLE entity_notes ALTER COLUMN content TYPE TEXT USING content::text;
-- Recreate the generated column with the new type
ALTER TABLE entity_notes ADD COLUMN search_vector tsvector
    GENERATED ALWAYS AS (to_tsvector('english', coalesce(content, ''))) STORED;
-- Recreate the index
CREATE INDEX entity_notes_search_vector_idx ON entity_notes USING gin(search_vector);
```

This pattern applies to any table with a `search_vector` generated column when you need to change a source column's type.

### Laravel route parameter inflection
Laravel's router auto-inflects singular resource names. Two specific traps in this project:

- `meta` → Laravel pluralizes to `metum` (treats it as Latin). Fix: `.parameters(['meta' => 'meta'])` on the resource route.
- `pipeline` → parameter name defaults to `pipeline` but must be explicitly set to match controller method signatures. Fix: `.parameters(['pipeline' => 'pipeline'])`.

### Vue 3 inline component objects don't auto-register in `<script setup>`
Defining a component as a plain object inside `<script setup>` and using it in the template does not work:

```js
// This does NOT work in <script setup>
const FieldRow = {
    props: ['label'],
    template: `<div>...</div>`,
}
```

The template renders nothing. Either import a real `.vue` file or replace with direct HTML. We replaced with direct HTML.

### Inertia form state with `useForm`
`form.isDirty` works correctly for detecting unsaved changes. `form.reset()` after a successful submission restores the form to its initial values — but only if you pass the data object to `useForm()` at declaration time, not as a computed. Pre-populate with `props.item.field ?? ''` directly in the `useForm({})` call.

---

## Database Schema Highlights

### Entity type system
Entities use a single `entity_type` string column with 20+ valid values grouped into categories (people, places, groups, supernatural, objects, concepts, events, etc.). This avoids a polymorphic table explosion while still allowing type-specific behavior at the application layer via computed properties and conditional UI sections.

### JSONB arrays for lightweight relations
Several tables use `jsonb` arrays for ID lists (`known_by_entity_ids`, `speakers_entity_ids`, `influenced_entity_ids`) instead of pivot tables, for cases where the relation is a simple set membership without additional attributes. These have GIN indexes for containment queries.

### Power interaction ordering
The `power_interactions` table enforces that `entity_a_id < entity_b_id` at the application layer to prevent duplicate inverse pairs. This is a soft constraint — the DB doesn't enforce it, the service layer does.

### search_vector on every major table
Full-text search via PostgreSQL's `tsvector` generated columns with weighted fields (title/name at weight A, type fields at B, notes at C/D). Single `SearchController` can query across all domains.

---

## Running Locally

```bash
# Install dependencies
composer install
npm install

# Environment
cp .env.example .env
php artisan key:generate

# Database — expects a local PostgreSQL instance
# Create the database first, then:
php artisan migrate

# Dev server
composer run dev
# or separately:
php artisan serve
npm run dev
```

Requires PHP 8.3+, Node 20+, and PostgreSQL.

---

## Testing

Backend and frontend now have separate test entry points:

```bash
# Laravel / PHPUnit
php artisan test

# Vue / Vitest
npm test

# Browser smoke tests
npm run test:e2e
```

### Notes

- The backend suite is designed around PostgreSQL-backed behavior in this app, not SQLite shortcuts.
- If you split local and test databases, keep a dedicated `.env.testing` that points at a separate Postgres database before running `php artisan test`.
- Local feature tests use a shared PostgreSQL test database bootstrap plus per-test transactions. Run separate `php artisan test` commands sequentially unless the test database strategy changes.
- The frontend suite covers shared scaffold helpers, scaffold form contracts, search behavior, and a targeted set of dense read pages.
- The Playwright smoke suite seeds a verified `e2e@example.com` user automatically and serves the app on `http://127.0.0.1:8011`.
- On a fresh machine you may need to install the Playwright browser once before the smoke suite can run:
  `node ./node_modules/playwright/cli.js install chromium`
- The current roadmap for expanding backend, frontend, and browser coverage lives in [TESTING_PLAN.md](TESTING_PLAN.md).
- The current coverage state, including what is intentionally still light, lives in [TEST_COVERAGE_MAP.md](TEST_COVERAGE_MAP.md).
