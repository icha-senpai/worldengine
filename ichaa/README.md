# Dataverse — Worldbuilding AU Management Tool

> **Status: Archived / Back-burnered**
>
> This project was shelved in favor of Notion + Notion AI, which handles the actual workflow better — native AI access to all structured data without custom API wiring. The architecture and schema work here is solid and worth revisiting if the use case changes. The database design is the real artifact.

---

## What This Was

A custom Laravel 12 / Vue 3 / PostgreSQL application for managing a 20+ universe crossover fiction AU. Designed to replace a sprawling Notion setup with a structured, queryable backend — with the eventual goal of wiring an AI chat interface to the data for contextual creative work.

**Why it got shelved:** Notion AI already does this natively. The custom app would have taken months to reach feature parity with what Notion provides out of the box. The schema design was the valuable output, not the application itself.

---

## Stack

- **Backend:** Laravel 12, Sanctum auth, Breeze scaffolding
- **Frontend:** Vue 3, Inertia.js, TailwindCSS 4
- **Database:** PostgreSQL
- **Architecture:** Lightweight DDD — domain-organized models and controllers, service-layer logic, named scopes. No repositories or aggregate roots.

---

## Domain Structure

Nine explicit domains, each with its own `app/Domain/` and `app/Http/Controllers/` subdirectory.

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

**47 tables total.** Full migration set runs clean. All deferred foreign keys resolved.

---

## What Was Built

### Fully functional (pages + controller + model)
- **Entities** — Index, Create, Show, Edit. Show page has Identity, Aliases, Notes, Questions, and Intelligence tabs all working.
- **Writing Pipeline** — Index, Create, Show, Edit. Type-aware fields for scenes, arcs, character studies. Stage advancement. Hierarchical parent/child structure.

### Controllers complete, no pages yet
All other domains have controllers and models but no Vue pages. The routes file is complete. The gap is ~70 Vue pages that weren't worth building given the pivot to Notion.

---

## Key Architecture Decisions

### Custom RBAC over Spatie
Horizon (the related ops platform) uses scoped squadron-level permissions. Spatie's permission package is global-role-oriented and doesn't cleanly support the "same user, different permissions per squadron" model. Custom RBAC was the right call for that system. Same reasoning applied here.

### Custom media library over Spatie Media Library
Polymorphic `media_references` table with collection-based organization and variant generation via Intervention Image. Spatie's package assumes a simpler attachment model and doesn't fit the domain's needs cleanly.

### Plain text over Tiptap JSON for notes/content
The original schema used `jsonb` for `content` fields (entity notes, pipeline item content) anticipating a rich text editor. The editor was never built. The columns were migrated to `text` type. Lesson: don't model for a feature that doesn't exist yet.

### No Eloquent magic, explicit everything
All relationships are explicitly typed. No dynamic properties relied on. Casts are declared. Scopes are named. The goal was code that could be read six months later without archaeology.

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

## Running Locally (if you come back to this)

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

Requires PHP 8.4, Node 20+, PostgreSQL 15+.

---

## Related

**Horizon** — the other active project. Custom operations and organization management platform for Star Citizen communities. Same stack (Laravel 12 + Vue 3 + Inertia), same DDD approach, actively maintained. The RBAC and media library decisions documented above were originally made for Horizon and carried over here.