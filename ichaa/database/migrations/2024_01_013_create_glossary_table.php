<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('glossary', function (Blueprint $table) {
            $table->id();

            // --- IDENTITY ---

            $table->string('term');

            // What kind of term this is
            $table->string('usage_context');
            // in_world — characters actually use this term
            // meta     — your author shorthand, never appears in-world
            // both     — used in-world and as your authorial label

            // --- DEFINITION ---

            $table->jsonb('definition')->nullable(); // Tiptap JSON

            // --- ORIGIN ---

            // Which source universe this term originates from
            // Native for terms you invented
            $table->string('origin_universe')->nullable();
            // Same enum as source_universes on entities

            // Which entity first introduced or coined this term
            $table->unsignedBigInteger('first_appearance_entity_id')->nullable();

            // Which event marked the first use of this term
            $table->unsignedBigInteger('first_appearance_event_id')->nullable();

            // --- RELATED ENTITIES ---
            // Entities most closely associated with this term
            // The term "Edged State" relates to Seraphine primarily
            // The term "Spider's Chapel" relates to that organization

            $table->jsonb('related_entity_ids')->default(json_encode([]));

            // --- ERA SCOPING ---
            // Some terms are only used during specific periods
            // "Silent Heir" as a political title vs "God-Empress" post-Revelation

            $table->string('era_introduced')->nullable();
            $table->string('era_obsolete')->nullable();

            $table->string('term_status')->default('active');
            // active    — currently in use within the world
            // archaic   — historically used, no longer current
            // suppressed — actively discouraged or banned
            //              e.g. the word for what Seraphine is
            //              may be suppressed during the puppet cycles
            // legendary — term itself is mythological, meaning disputed or lost

            // --- SUPPRESSION DETAILS ---
            // Only relevant when term_status is suppressed

            $table->unsignedBigInteger('suppressed_by_entity_id')->nullable();
            // Which government, faction, or authority suppressed this term

            $table->string('suppressed_during_era')->nullable();
            $table->text('suppression_reason')->nullable();

            // --- VISIBILITY AND CLASSIFICATION ---

            $table->string('visibility')->default('private');
            $table->string('content_classification')->default('restricted');

            // --- SOFT DELETE AND TIMESTAMPS ---

            $table->softDeletes();
            $table->timestamps();
        });

        // --- INDEXES ---

        // Full text search — term and definition are both indexed
        // Searching "Edged State" surfaces the glossary entry
        // Searching "suppressed transformation" finds definitions containing it
        \DB::statement("
            ALTER TABLE glossary
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(term, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(suppression_reason, '')), 'D')
            ) STORED
        ");

        \DB::statement('CREATE INDEX glossary_search_vector_idx ON glossary USING GIN (search_vector)');

        // JSONB index for related entity queries
        \DB::statement('CREATE INDEX glossary_related_entities_idx ON glossary USING GIN (related_entity_ids)');

        Schema::table('glossary', function (Blueprint $table) {
            $table->index('usage_context');
            $table->index('origin_universe');
            $table->index('first_appearance_entity_id');
            $table->index('first_appearance_event_id');
            $table->index('term_status');
            $table->index('suppressed_by_entity_id');
            $table->index('visibility');
            $table->index('deleted_at');

            // Compound index for era-scoped glossary queries:
            // "which terms were in active use during cycle 12"
            $table->index(['term_status', 'era_introduced', 'era_obsolete']);

            // Compound index for suppression queries:
            // "show me all terms suppressed by this government"
            $table->index(['suppressed_by_entity_id', 'term_status']);

            // Compound index for usage context filtering:
            // "show me all in-world terms from the HP source universe"
            $table->index(['usage_context', 'origin_universe']);

            // Term uniqueness — the same term shouldn't be defined twice
            // unless it has different usage contexts
            $table->unique(['term', 'usage_context'], 'glossary_term_context_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glossary');
    }
};

/*
|--------------------------------------------------------------------------
| TERM STATUS ENUM
|--------------------------------------------------------------------------
|
|   active     — currently in use within the world
|   archaic    — historically used, fallen out of use
|   suppressed — actively banned or discouraged
|   legendary  — the term itself is uncertain or mythological
|
|--------------------------------------------------------------------------
| USAGE CONTEXT ENUM
|--------------------------------------------------------------------------
|
|   in_world — characters use this term, it exists within the fiction
|   meta     — your shorthand as author, never spoken in the world
|   both     — serves both functions simultaneously
|
| Example in_world terms:
|   "Edged State"       — Seraphine's pre-transformation psychological state
|   "The Puppet Cycles" — historical record term for Seraphine's operations
|   "Ash-Born"          — survivors of Seraphine's first cycle who formed
|                          the resistance core
|
| Example meta terms:
|   "The Perception Layer" — your architectural concept for how your world
|                             models truth vs perceived reality
|   "Hard Iteration"       — your term for Harry v1-v68 being destroyed
|                             and replaced rather than evolved
|
| Example both terms:
|   "Master of Death"      — in-world it is a recognized cosmological title,
|                             meta it is also your shorthand for Seraphine's
|                             transformation arc endpoint
|
|--------------------------------------------------------------------------
| SUPPRESSION EXAMPLE
|--------------------------------------------------------------------------
|
| During the puppet cycles, the word for what Seraphine truly is
| does not have a public name — the concept itself is suppressed.
| Any term that accurately describes her control mechanism
| would be banned by the installed governments she controls.
|
|   term: "Shadow Empress" (hypothetical suppressed term)
|   term_status: suppressed
|   suppressed_by_entity_id: United Earth Government (puppet era)
|   suppressed_during_era: "Cycles 1-47"
|   suppression_reason: "Official position is that no external
|                         controller exists. This term implies otherwise."
|
|--------------------------------------------------------------------------
| UNIQUE CONSTRAINT NOTE
|--------------------------------------------------------------------------
|
| The unique constraint is on [term, usage_context] not just [term].
| This allows "Master of Death" to exist twice:
|   once as in_world and once as meta — if you need separate definitions
|   for each context.
|
| In practice most terms will have usage_context: both and one record.
|
*/
