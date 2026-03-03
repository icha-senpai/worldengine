<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_aliases', function (Blueprint $table) {
            $table->id();

            // The entity this alias belongs to
            $table->foreignId('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            // --- ALIAS IDENTITY ---

            $table->string('alias');

            // What kind of name this is
            $table->string('alias_type');
            // name        — an alternate proper name
            // title       — a formal title (Silent Heir, God-Empress)
            // epithet     — a descriptive name (Death Incarnate, The Architect)
            // code_name   — operational alias used by a faction or organization
            // nickname    — informal name used by specific characters
            // author_label — your personal shorthand, never in-world

            // Who uses this alias and in what context
            $table->text('context')->nullable();

            // --- ERA SCOPING ---
            // Aliases can be active only during specific periods
            // Both nullable — null start means always was, null end means still active

            $table->string('era_start')->nullable(); // Freeform era reference
            $table->string('era_end')->nullable();   // Freeform era reference
            $table->boolean('is_active')->default(true);

            // --- KNOWLEDGE SCOPING ---
            // Not everyone knows every alias
            // Empty array means publicly known

            $table->jsonb('known_by_entity_ids')->default(json_encode([]));
            // Array of entity IDs that know or use this alias
            // Empty means universally known

            // --- VISIBILITY ---
            // Aliases inherit entity visibility by default
            // Override here for aliases that are themselves secret

            $table->string('visibility')->default('private'); // private, unlisted, public
            $table->string('content_classification')->default('restricted');

            // --- SOFT DELETE AND TIMESTAMPS ---

            $table->softDeletes();
            $table->timestamps();
        });

        // --- INDEXES ---

        // Full text search on alias — this is the core search index
        // When you search "Silent Heir" this is what finds Seraphine
        \DB::statement("
            ALTER TABLE entity_aliases
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(alias, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(context, '')), 'C')
            ) STORED
        ");

        \DB::statement('CREATE INDEX entity_aliases_search_vector_idx ON entity_aliases USING GIN (search_vector)');

        // JSONB index for known_by queries
        \DB::statement('CREATE INDEX entity_aliases_known_by_idx ON entity_aliases USING GIN (known_by_entity_ids)');

        // Standard indexes
        Schema::table('entity_aliases', function (Blueprint $table) {
            $table->index('entity_id');
            $table->index('alias_type');
            $table->index('is_active');
            $table->index('visibility');
            $table->index('deleted_at');

            // Compound index for the most common query pattern:
            // "give me all active aliases for this entity"
            $table->index(['entity_id', 'is_active']);

            // Compound index for era-scoped queries:
            // "which aliases were active during cycle 12"
            $table->index(['entity_id', 'era_start', 'era_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_aliases');
    }
};

/*
|--------------------------------------------------------------------------
| ALIAS TYPE ENUM
|--------------------------------------------------------------------------
|
|   name         — alternate proper name
|   title        — formal title conferred by institution or power
|   epithet      — descriptive name earned or given informally
|   code_name    — operational alias used by a faction or org
|   nickname     — informal name used by specific individuals
|   author_label — your shorthand, never appears in-world
|
|--------------------------------------------------------------------------
| SEARCH BEHAVIOR
|--------------------------------------------------------------------------
|
| When a user searches "Silent Heir":
|   1. Full text search hits entity_aliases.search_vector
|   2. Returns alias record with entity_id
|   3. Search result displays the entity (Seraphine Morbraith)
|      with a badge: "also known as: Silent Heir"
|
| Alias search results are weighted lower than direct entity name
| matches but higher than attribute or document matches.
|
|--------------------------------------------------------------------------
| ERA SCOPING EXAMPLES
|--------------------------------------------------------------------------
|
| Silent Heir:
|   era_start: null (always existed as a title)
|   era_end:   "Year 0 — The Transformation"
|   is_active: false (no longer used after she becomes Death)
|
| God-Empress:
|   era_start: "Year 2000 — The Revelation"
|   era_end:   null (still active)
|   is_active: true
|
| The Architect (Grey Line code name for Icha):
|   era_start: "Grey Line Formation"
|   era_end:   null
|   is_active: true
|   known_by_entity_ids: [grey_line_entity_id, icha_entity_id]
|   — not publicly known, only Grey Line members use it
|
*/
