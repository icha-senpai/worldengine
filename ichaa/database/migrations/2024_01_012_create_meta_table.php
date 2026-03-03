<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta', function (Blueprint $table) {
            $table->id();

            // --- IDENTITY ---

            $table->string('title');

            // What kind of meta note this is
            $table->string('category');
            // themes_and_motifs, tensions_and_contradictions,
            // design_notes_and_author_intent, secrets_and_hidden_truth,
            // moral_dilemmas, sensory_palettes, symbols_and_iconography

            // What function this note serves
            $table->string('meta_note_type')->default('passive');
            // passive      — observation, mood board, noticed pattern
            // active_task  — something you need to do
            // decision     — a design decision made and recorded
            // question     — open design question not yet answered
            // reminder     — something to come back to

            // --- CONTENT ---

            $table->jsonb('content')->nullable(); // Tiptap JSON — main content

            // --- SENSORY PALETTE FIELDS ---
            // Only populated for category: sensory_palettes
            // Structured to capture all five senses plus world-specific registers

            $table->text('sense_sight')->nullable();
            $table->text('sense_sound')->nullable();
            $table->text('sense_smell')->nullable();
            $table->text('sense_taste')->nullable();
            $table->text('sense_touch')->nullable();
            $table->text('sense_magical')->nullable();
            // What magic feels like here — Investiture vs Warp vs HP magic
            $table->text('emotional_register')->nullable();
            // The feeling this place or moment evokes

            // --- SYMBOL FIELDS ---
            // Only populated for category: symbols_and_iconography

            $table->string('symbol_name')->nullable();
            $table->unsignedBigInteger('symbol_origin_entity_id')->nullable();
            // Which faction, religion, or culture created this symbol
            $table->text('symbol_usage_context')->nullable();
            // Who uses it, when, why
            $table->jsonb('symbol_associated_entity_ids')->nullable();
            // Array of entity IDs connected to this symbol
            $table->unsignedBigInteger('symbol_media_reference_id')->nullable();
            // Links to an image in media_references if you have a visual
            $table->string('symbol_scope')->nullable();
            // in_world — characters actually use this symbol
            // meta     — your authorial symbolism, not in-world
            // both

            // --- ACTION STATE FIELDS ---
            // Only relevant for meta_note_type: active_task and question

            $table->string('priority')->nullable();
            // low, medium, high, blocking

            $table->string('action_status')->nullable();
            // pending, in_progress, resolved, deferred

            $table->timestamp('resolved_at')->nullable();
            $table->jsonb('resolution_notes')->nullable(); // Tiptap JSON

            // --- DESIGN EVOLUTION ---
            // Tracks when a design decision was replaced
            // Old thinking preserved, chain of evolution visible

            $table->unsignedBigInteger('superseded_by_meta_id')->nullable();
            // Points to the newer version of this design decision

            $table->timestamp('superseded_at')->nullable();
            $table->text('supersession_reason')->nullable();

            // --- VISIBILITY ---
            // Meta is almost always private
            // Author-only by default — your design space, not your canon

            $table->string('visibility')->default('private');
            $table->string('content_classification')->default('restricted');

            // --- SOFT DELETE AND TIMESTAMPS ---

            $table->softDeletes();
            $table->timestamps();
        });

        // --- META ENTITIES ---
        // Attaches meta notes to specific entities
        // A theme note about power without safety connects to
        // Seraphine, the Morbraith Syndicate, the puppet cycle system

        Schema::create('meta_entities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('meta_id')
                ->constrained('meta')
                ->cascadeOnDelete();

            $table->foreignId('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            $table->text('connection_notes')->nullable();
            // Why this meta note connects to this entity specifically

            $table->timestamps();

            $table->unique(
                ['meta_id', 'entity_id'],
                'meta_entities_unique'
            );
        });

        // --- META GROUP RELATIONSHIPS ---
        // Attaches meta notes to group dynamics
        // A thematic note about triangulated desire connects to
        // the Hermione-Ginny-Johnny dynamic as a whole

        Schema::create('meta_group_relationships', function (Blueprint $table) {
            $table->id();

            $table->foreignId('meta_id')
                ->constrained('meta')
                ->cascadeOnDelete();

            $table->foreignId('group_relationship_id')
                ->constrained('group_relationships')
                ->cascadeOnDelete();

            $table->text('connection_notes')->nullable();

            $table->timestamps();

            $table->unique(
                ['meta_id', 'group_relationship_id'],
                'meta_group_relationships_unique'
            );
        });

        // --- INDEXES ---

        \DB::statement("
            ALTER TABLE meta
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(category, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(symbol_name, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(sense_sight, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(sense_sound, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(sense_smell, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(symbol_usage_context, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(supersession_reason, '')), 'D')
            ) STORED
        ");

        \DB::statement('CREATE INDEX meta_search_vector_idx ON meta USING GIN (search_vector)');

        \DB::statement('CREATE INDEX meta_symbol_entities_idx ON meta USING GIN (symbol_associated_entity_ids)');

        Schema::table('meta', function (Blueprint $table) {
            $table->index('category');
            $table->index('meta_note_type');
            $table->index('priority');
            $table->index('action_status');
            $table->index('superseded_by_meta_id');
            $table->index('symbol_origin_entity_id');
            $table->index('symbol_media_reference_id');
            $table->index('visibility');
            $table->index('deleted_at');

            // Compound index for dashboard active tasks panel:
            // "show me all blocking unresolved design tasks"
            $table->index(['meta_note_type', 'action_status', 'priority']);

            // Compound index for design history queries:
            // "show me all decisions that have been superseded"
            $table->index(['superseded_by_meta_id', 'meta_note_type']);

            // Compound index for category browsing:
            // "show me all sensory palettes" or "all active symbols"
            $table->index(['category', 'meta_note_type']);
        });

        Schema::table('meta_entities', function (Blueprint $table) {
            $table->index('meta_id');
            $table->index('entity_id');
            $table->index(['entity_id', 'meta_id']);
        });

        Schema::table('meta_group_relationships', function (Blueprint $table) {
            $table->index('meta_id');
            $table->index('group_relationship_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_group_relationships');
        Schema::dropIfExists('meta_entities');
        Schema::dropIfExists('meta');
    }
};

/*
|--------------------------------------------------------------------------
| CATEGORY ENUM
|--------------------------------------------------------------------------
|
|   themes_and_motifs            — recurring patterns across the world
|   tensions_and_contradictions  — structural tensions in the narrative
|   design_notes_and_author_intent — why things are the way they are
|   secrets_and_hidden_truth     — author-level design secrets not yet
|                                   expressed in the world
|   moral_dilemmas               — ethical questions the world poses
|   sensory_palettes             — sight, sound, smell, taste, touch,
|                                   magical sense, emotional register
|   symbols_and_iconography      — in-world and authorial symbolism
|
|--------------------------------------------------------------------------
| THE DISTINCTION: META SECRETS VS IN-WORLD SECRETS
|--------------------------------------------------------------------------
|
| Meta secrets (this table, category: secrets_and_hidden_truth):
|   Things YOU know as architect that have no in-world expression yet.
|   "Seraphine's galactic campaign will eventually fail via a Cosmere
|    mechanism I haven't designed yet."
|   — This is author planning space. No character can discover it.
|
| In-world secrets (secrets table, coming in Phase 7):
|   Facts that EXIST within the world that characters could discover.
|   "Seraphine killed Hermione. Johnny witnessed it."
|   — This is world-level truth. Characters can learn it.
|
|--------------------------------------------------------------------------
| SUPERSESSION CHAIN EXAMPLE
|--------------------------------------------------------------------------
|
| Original design decision (meta id: 1):
|   title: "Seraphine becomes Master of Death through ritual"
|   meta_note_type: decision
|   superseded_by_meta_id: 7
|   superseded_at: [date]
|   supersession_reason: "Changed to manipulation + murder of Hermione —
|                          more psychologically true to her character"
|
| Replacement decision (meta id: 7):
|   title: "Seraphine becomes Master of Death through Hermione's death"
|   meta_note_type: decision
|   superseded_by_meta_id: null  ← current canonical design decision
|
| The old thinking is preserved. The chain is visible.
| The dashboard renders superseded decisions in muted style
| with a link to the current version.
|
|--------------------------------------------------------------------------
| ACTIVE TASK DASHBOARD PANEL
|--------------------------------------------------------------------------
|
| The meta table feeds a dashboard warnings panel alongside:
|   - Blocking entity questions
|   - Unresolved power interactions (existential/unknown risk)
|   - Active contradictions (blocking priority)
|
| Active tasks are filtered by:
|   meta_note_type = 'active_task' AND action_status != 'resolved'
|   ORDER BY priority DESC (blocking first)
|
*/
