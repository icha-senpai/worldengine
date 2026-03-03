<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- COLLECTIONS ---

        Schema::create('collections', function (Blueprint $table) {
            $table->id();

            // --- IDENTITY ---

            $table->string('name');
            $table->string('collection_type');
            // See full enum in comment block below

            $table->jsonb('description')->nullable(); // Tiptap JSON

            // --- NESTING ---
            // A collection can belong to a parent collection
            // The Puppet Cycles collection contains individual cycle collections
            // Null means top-level collection

            $table->unsignedBigInteger('parent_collection_id')->nullable();

            // --- POPULATION MODE ---

            $table->string('collection_mode')->default('manual');
            // manual  — you add and remove members explicitly
            // smart   — rule-based auto-population
            // hybrid  — smart rules plus manual additions and exclusions

            // Rules for smart and hybrid collections
            // Each rule is a condition entities must meet to be included
            // Example rule structure:
            // [
            //   { "field": "entity_type", "operator": "equals", "value": "character" },
            //   { "field": "source_universes", "operator": "contains", "value": "Harry Potter" }
            // ]
            $table->jsonb('rules')->nullable();

            // For hybrid collections — entity IDs manually excluded
            // despite meeting the smart rules
            $table->jsonb('excluded_entity_ids')->nullable();

            // --- VISIBILITY ---

            $table->string('visibility')->default('private');
            // private, unlisted, public

            // Controls how collection visibility affects member visibility
            $table->string('member_visibility_override')->default('independent');
            // independent — each member's own visibility controls public surfacing
            // restrict    — private collection suppresses even public members
            // cascade     — public collection makes members accessible through it

            $table->string('content_classification')->default('restricted');

            // --- COMPLETION ---

            $table->string('completion_state')->default('seeding');
            // seeding               — just started, intentionally incomplete
            // developing            — actively being populated
            // substantially_complete — most members present, minor gaps
            // complete              — deliberately finalized
            // perpetual             — open-ended by design, never meant to be complete

            $table->text('completion_notes')->nullable();

            // --- SOFT DELETE AND TIMESTAMPS ---

            $table->softDeletes();
            $table->timestamps();
        });

        // --- COLLECTION ENTITIES ---
        // Pivot connecting entities to collections
        // Tracks how the entity got in and what role it plays

        Schema::create('collection_entities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('collection_id')
                ->constrained('collections')
                ->cascadeOnDelete();

            $table->foreignId('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            // How this entity entered the collection
            $table->boolean('added_manually')->default(true);
            // true  — you explicitly added this entity
            // false — added automatically by a smart rule

            // Which rule triggered automatic addition (if added_manually is false)
            // Stores a snapshot of the rule that matched
            $table->jsonb('added_by_rule')->nullable();

            // What role or function this entity serves within this collection
            // Optional — most members just exist in the collection without a role
            // e.g. in the Puppet Cycles collection: "Cycle 12 Puppet Leader"
            // e.g. in the Morbraith Bloodline collection: "Primary Heir"
            $table->string('role_in_collection')->nullable();

            $table->text('notes')->nullable();

            // When this entity was added to this collection
            $table->timestamp('added_at')->useCurrent();

            // No soft delete on pivot
            // Removing an entity from a collection deletes the pivot row
            // The entity itself is never affected
            $table->timestamps();

            // An entity can only appear once per collection
            $table->unique(
                ['collection_id', 'entity_id'],
                'collection_entities_unique'
            );
        });

        // --- COLLECTION DOCUMENTS ---
        // Pivot connecting documents to collections
        // A collection can group related in-world documents

        Schema::create('collection_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('collection_id')
                ->constrained('collections')
                ->cascadeOnDelete();

            // document_id — constrained later when documents table exists
            $table->unsignedBigInteger('document_id');

            $table->string('role_in_collection')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('added_at')->useCurrent();
            $table->timestamps();

            $table->unique(
                ['collection_id', 'document_id'],
                'collection_documents_unique'
            );
        });

        // --- INDEXES ---

        // Full text search on collection name
        \DB::statement("
            ALTER TABLE collections
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(name, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(completion_notes, '')), 'C')
            ) STORED
        ");

        \DB::statement('CREATE INDEX collections_search_vector_idx ON collections USING GIN (search_vector)');

        // JSONB indexes
        \DB::statement('CREATE INDEX collections_rules_idx ON collections USING GIN (rules)');
        \DB::statement('CREATE INDEX collections_excluded_entities_idx ON collections USING GIN (excluded_entity_ids)');

        Schema::table('collections', function (Blueprint $table) {
            $table->index('collection_type');
            $table->index('collection_mode');
            $table->index('parent_collection_id');
            $table->index('visibility');
            $table->index('content_classification');
            $table->index('completion_state');
            $table->index('deleted_at');

            // Compound index for nested collection queries:
            // "give me all child collections of this parent"
            $table->index(['parent_collection_id', 'collection_type']);
        });

        Schema::table('collection_entities', function (Blueprint $table) {
            $table->index('collection_id');
            $table->index('entity_id');
            $table->index('added_manually');

            // Compound index for the primary collection view:
            // "give me all entities in this collection"
            $table->index(['collection_id', 'added_manually']);

            // Compound index for entity membership queries:
            // "show me all collections this entity belongs to"
            $table->index(['entity_id', 'collection_id']);
        });

        Schema::table('collection_documents', function (Blueprint $table) {
            $table->index('collection_id');
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_documents');
        Schema::dropIfExists('collection_entities');
        Schema::dropIfExists('collections');
    }
};

/*
|--------------------------------------------------------------------------
| COLLECTION TYPE ENUM — 18 types
|--------------------------------------------------------------------------
|
|   faction           — members of an organization or power bloc
|   era               — entities relevant to a specific time period
|   theme             — thematically connected across the world
|   geographic        — entities connected to a location or region
|   narrative         — entities that appear together in a story arc
|   crossover         — entities sharing a source universe or crossover mechanic
|   power_system      — entities connected to a specific magic or power system
|   bloodline         — entities sharing lineage
|   cycle             — entities belonging to a specific puppet cycle
|   personal          — your own organizational grouping, no world meaning
|   species_group     — entities of a shared species or subspecies
|   alliance          — temporary or permanent alliances between factions
|   conflict_parties  — all entities involved in a specific war or conflict
|   knowledge_circle  — entities that share specific secret knowledge
|   artistic_movement — in-world creative movements or schools of thought
|   pilgrim_route     — entities connected along a geographic or spiritual journey
|   puppet_apparatus  — everything related to a specific puppet deployment
|   convergence_web   — entities connected through a convergence point or mechanic
|
|--------------------------------------------------------------------------
| SMART COLLECTION RULES — STRUCTURE
|--------------------------------------------------------------------------
|
| Rules are stored as a JSONB array of condition objects.
| All conditions in the array must be met (AND logic).
| For OR logic, create multiple collections or use the hybrid mode
| with manual additions on top.
|
| Rule object shape:
| {
|   "field":    "entity_type",          — which field to check
|   "operator": "equals",               — equals, contains, in, not_in,
|                                          greater_than, less_than
|   "value":    "character"             — the value to match against
| }
|
| Example — all HP-origin characters at cosmic power tier or above:
| [
|   { "field": "entity_type",        "operator": "equals",   "value": "character" },
|   { "field": "source_universes",   "operator": "contains", "value": "Harry Potter" },
|   { "field": "power_tier_ceiling", "operator": "in",       "value": ["cosmic", "multiversal", "transcendent"] }
| ]
|
|--------------------------------------------------------------------------
| NESTING EXAMPLE — PUPPET CYCLES
|--------------------------------------------------------------------------
|
| The Puppet Cycles (top level, type: cycle, mode: manual)
|   ├── Cycle 1 (child, type: puppet_apparatus)
|   │     members: puppet_1, resistance_org_1, cycle_1_events...
|   ├── Cycle 12 (child, type: puppet_apparatus)
|   │     members: puppet_12, resistance_org_12, cycle_12_events...
|   └── Cycle 47 (child, type: puppet_apparatus)
|         members: puppet_47, resistance_org_47, cycle_47_events...
|
| Querying the top-level collection returns direct members only.
| Querying with include_descendants returns all members at all levels.
| The application layer handles descendant traversal — not the database.
|
*/
