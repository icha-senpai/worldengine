<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contradictions_and_conflicts', function (Blueprint $table) {
            $table->id();

            // --- IDENTITY ---

            $table->string('title');
            // Short label for dashboard and list views
            // "Elder Wand ownership — Battle of Hogwarts vs AU timeline"
            // "Seraphine power tier inconsistency — pre and post cycle 12"
            // "Mirror Library existence conditions conflict"

            $table->jsonb('description')->nullable(); // Tiptap JSON
            // Full description of what is inconsistent and why it matters

            // --- CONTRADICTION TYPE ---

            $table->string('contradiction_type');
            // factual         — two facts that cannot both be true
            // logical         — a logical impossibility in the world's rules
            // timeline        — events that cannot have occurred as ordered
            // crossover       — source canon elements that conflict with each other
            //                   or with your AU rules
            // power_system    — power rules that contradict each other
            // character_consistency — a character behaves inconsistently with
            //                        their established psychology or history
            // cosmological    — contradictions at the level of fundamental
            //                   world mechanics or metaphysics

            // --- PRIORITY ---

            $table->string('priority')->default('medium');
            // low      — noted, not urgent
            // medium   — should resolve eventually
            // high     — actively affects development decisions
            // blocking — cannot proceed meaningfully until resolved

            // --- STATUS ---

            $table->string('status')->default('unresolved');
            // unresolved  — not yet addressed
            // in_progress — actively being worked through
            // resolved    — contradiction resolved and documented
            // accepted    — consciously accepted as an irresolvable tension
            //               or deliberate ambiguity
            // deferred    — set aside for later

            $table->jsonb('resolution')->nullable(); // Tiptap JSON
            // How the contradiction was resolved
            // Only populated when status is resolved or accepted

            $table->timestamp('resolved_at')->nullable();
            $table->date('discovered_at')->nullable();

            // --- LINKED RECORDS ---
            // The specific records that conflict with each other
            // All arrays — a contradiction may involve multiple records

            $table->jsonb('entity_ids')->default(json_encode([]));
            $table->jsonb('relationship_ids')->default(json_encode([]));
            $table->jsonb('group_relationship_ids')->default(json_encode([]));
            $table->jsonb('timeline_entry_ids')->default(json_encode([]));
            $table->jsonb('power_interaction_ids')->default(json_encode([]));
            $table->jsonb('document_ids')->default(json_encode([]));
            $table->jsonb('meta_ids')->default(json_encode([]));
            $table->jsonb('pipeline_item_ids')->default(json_encode([]));
            $table->jsonb('canon_reference_ids')->default(json_encode([]));

            // Which external session discovered this contradiction
            $table->unsignedBigInteger('discovered_via_session_id')->nullable();

            // --- SOFT DELETE AND TIMESTAMPS ---
            // No visibility field — contradictions are always private

            $table->softDeletes();
            $table->timestamps();
        });

        // --- ADD DEFERRED FOREIGN KEY ---

        Schema::table('contradictions_and_conflicts', function (Blueprint $table) {
            $table->foreign('discovered_via_session_id')
                ->references('id')
                ->on('session_log')
                ->nullOnDelete();
        });

        // --- INDEXES ---

        \DB::statement("
            ALTER TABLE contradictions_and_conflicts
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(contradiction_type, '')), 'B')
            ) STORED
        ");

        \DB::statement('CREATE INDEX contradictions_search_vector_idx ON contradictions_and_conflicts USING GIN (search_vector)');

        \DB::statement('CREATE INDEX contradictions_entity_ids_idx ON contradictions_and_conflicts USING GIN (entity_ids)');
        \DB::statement('CREATE INDEX contradictions_relationship_ids_idx ON contradictions_and_conflicts USING GIN (relationship_ids)');
        \DB::statement('CREATE INDEX contradictions_timeline_ids_idx ON contradictions_and_conflicts USING GIN (timeline_entry_ids)');
        \DB::statement('CREATE INDEX contradictions_power_ids_idx ON contradictions_and_conflicts USING GIN (power_interaction_ids)');
        \DB::statement('CREATE INDEX contradictions_canon_ids_idx ON contradictions_and_conflicts USING GIN (canon_reference_ids)');

        Schema::table('contradictions_and_conflicts', function (Blueprint $table) {
            $table->index('contradiction_type');
            $table->index('priority');
            $table->index('status');
            $table->index('resolved_at');
            $table->index('discovered_at');
            $table->index('discovered_via_session_id');
            $table->index('deleted_at');

            // Compound index for dashboard warnings panel:
            // "show me all blocking unresolved contradictions"
            $table->index(['priority', 'status']);

            // Compound index for type-filtered contradiction queries:
            // "show me all unresolved timeline contradictions"
            $table->index(['contradiction_type', 'status']);

            // Compound index for resolution timeline:
            // "show me everything resolved in the last month"
            $table->index(['status', 'resolved_at']);

            // Compound index for session-linked discoveries:
            // "show me all contradictions this session uncovered"
            $table->index(['discovered_via_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('contradictions_and_conflicts', function (Blueprint $table) {
            $table->dropForeign(['discovered_via_session_id']);
        });

        Schema::dropIfExists('contradictions_and_conflicts');
    }
};

/*
|--------------------------------------------------------------------------
| CONTRADICTION TYPE ENUM
|--------------------------------------------------------------------------
|
|   factual              — two facts that cannot both be true
|                          "Entity A is stated to die in cycle 3
|                           but appears in a cycle 7 scene"
|
|   logical              — a logical impossibility in the world's rules
|                          "Seraphine cannot be in two locations
|                           simultaneously during the Revelation events
|                           as currently plotted"
|
|   timeline             — events ordered in ways that are impossible
|                          "The Elder Wand changes hands three times
|                           in a single day as the timeline currently reads"
|
|   crossover            — source canon elements conflicting with each other
|                          or with your AU mechanics
|                          "HP Legilimency requires eye contact but
|                           Cosmere Forgery does not — how does an entity
|                           with both abilities use them simultaneously"
|
|   power_system         — power rules contradicting each other
|                          "The established rule is that Investiture
|                           suppresses HP magic at proximity but a
|                           documented instance shows amplification"
|
|   character_consistency — psychological or behavioral inconsistency
|                           "Johnny's established pattern is never to
|                            initiate contact with Seraphine but he
|                            initiates in chapter 12 without explanation"
|
|   cosmological         — fundamental world mechanics contradicting
|                          "The Mirror Library is established as atemporal
|                           but a timeline entry places it at a specific
|                           moment in AU Year 400"
|
|--------------------------------------------------------------------------
| STATUS ENUM
|--------------------------------------------------------------------------
|
|   unresolved  — not yet addressed
|   in_progress — being actively worked through
|   resolved    — contradiction resolved, resolution documented
|   accepted    — irresolvable or deliberate — consciously accepted
|                 Some contradictions in complex worlds are features
|                 not bugs — unreliable narrators, deliberate ambiguity,
|                 in-world historiographical errors. Accepted status
|                 acknowledges this without marking it resolved.
|   deferred    — set aside, not forgotten
|
|--------------------------------------------------------------------------
| DASHBOARD WARNINGS PANEL
|--------------------------------------------------------------------------
|
| The warnings panel aggregates blocking issues from multiple tables:
|
|   FROM entity_questions:
|     WHERE priority = 'blocking' AND status != 'resolved'
|
|   FROM meta:
|     WHERE meta_note_type = 'active_task'
|     AND action_status NOT IN ('resolved', 'deferred')
|     AND priority IN ('high', 'blocking')
|
|   FROM power_interactions:
|     WHERE unresolved_flag = true
|     AND deleted_at IS NULL
|
|   FROM contradictions_and_conflicts:
|     WHERE priority = 'blocking'
|     AND status NOT IN ('resolved', 'accepted', 'deferred')
|     AND deleted_at IS NULL
|
| These four queries together produce the complete picture of
| what is actively blocking or endangering your world's integrity.
|
|--------------------------------------------------------------------------
| THE ACCEPTED STATUS
|--------------------------------------------------------------------------
|
| Not every contradiction needs resolution.
| Some are load-bearing ambiguities — the Mirror Library's precise
| origin is contradicted by three different in-world sources
| and that contradiction is part of its nature.
|
| Accepted status means: "I have looked at this, I understand
| the inconsistency, and I am choosing to leave it unresolved
| because the ambiguity serves the world."
|
| Accepted contradictions do not appear in the warnings panel.
| They appear in a separate "Accepted Tensions" view.
| The resolution field for accepted records documents why
| the ambiguity was consciously preserved.
|
*/
