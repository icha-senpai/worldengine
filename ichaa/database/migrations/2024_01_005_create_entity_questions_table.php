<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_questions', function (Blueprint $table) {
            $table->id();

            // The entity this question is about
            $table->foreignId('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            // --- QUESTION CONTENT ---

            $table->text('question');

            // Optional longer context — why this question matters,
            // what you've already considered, what would help answer it
            $table->jsonb('context')->nullable();

            // --- STATUS ---

            $table->string('status')->default('unresolved');
            // unresolved  — not yet addressed
            // in_progress — actively being worked on
            // resolved    — answered and documented
            // deferred    — consciously set aside for later

            // When resolved, what the answer was
            $table->jsonb('resolution')->nullable(); // Tiptap JSON

            $table->timestamp('resolved_at')->nullable();

            // --- PRIORITY ---
            // Blocking means you cannot meaningfully develop this entity further
            // until this question is answered

            $table->string('priority')->default('medium');
            // low, medium, high, blocking

            // --- CONNECTIONS ---
            // Questions often involve multiple entities
            // e.g. "Does Aurelia foresee Seraphine's transformation?"
            // involves both Aurelia and Seraphine

            $table->jsonb('linked_entity_ids')->default(json_encode([]));

            // Questions can also involve group dynamics
            $table->jsonb('linked_group_relationship_ids')->default(json_encode([]));

            // Which external session raised this question
            // Nullable — questions can be raised directly without a session
            $table->unsignedBigInteger('source_session_log_id')->nullable();

            // --- ORDER ---
            // Manual ordering within an entity's question list
            // Blocking questions always surface first in the UI regardless of this
            $table->integer('sort_order')->default(0);

            // --- SOFT DELETE AND TIMESTAMPS ---
            // No visibility field — questions are always private

            $table->softDeletes();
            $table->timestamps();
        });

        // --- INDEXES ---

        // Full text search across question text
        \DB::statement("
            ALTER TABLE entity_questions
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(question, '')), 'A')
            ) STORED
        ");

        \DB::statement('CREATE INDEX entity_questions_search_vector_idx ON entity_questions USING GIN (search_vector)');

        // JSONB indexes for linked entity queries
        \DB::statement('CREATE INDEX entity_questions_linked_entities_idx ON entity_questions USING GIN (linked_entity_ids)');
        \DB::statement('CREATE INDEX entity_questions_linked_groups_idx ON entity_questions USING GIN (linked_group_relationship_ids)');

        Schema::table('entity_questions', function (Blueprint $table) {
            $table->index('entity_id');
            $table->index('status');
            $table->index('priority');
            $table->index('source_session_log_id');
            $table->index('deleted_at');

            // Dashboard warnings panel: "show me all blocking unresolved questions"
            $table->index(['priority', 'status']);

            // Entity card view: "show me all questions for this entity in priority order"
            $table->index(['entity_id', 'status', 'priority']);

            // Session log linkage: "show me all questions raised by this session"
            $table->index(['source_session_log_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_questions');
    }
};

/*
|--------------------------------------------------------------------------
| PRIORITY ENUM
|--------------------------------------------------------------------------
|
|   low      — would be nice to know, not urgent
|   medium   — should resolve this reasonably soon
|   high     — actively affects development decisions
|   blocking — cannot develop this entity further until resolved
|
| Blocking questions surface at the top of the entity card
| and appear in the dashboard warnings panel alongside
| unresolved power interactions and active contradictions.
|
|--------------------------------------------------------------------------
| STATUS FLOW
|--------------------------------------------------------------------------
|
|   unresolved → in_progress → resolved
|              ↓
|           deferred (consciously set aside, not forgotten)
|
| Deferred questions do not appear in the warnings panel
| but remain visible on the entity card in a muted state.
| They can be reactivated to unresolved at any time.
|
|--------------------------------------------------------------------------
| USAGE EXAMPLES
|--------------------------------------------------------------------------
|
| Aurelia Vex:
|   Q: "Does Aurelia foresee Seraphine's transformation before it happens?"
|   Priority: high
|   Linked entities: [seraphine_id]
|   Status: unresolved
|
| Brother Nox:
|   Q: "What is Brother Nox's actual blood status?"
|   Priority: medium
|   Status: unresolved
|
| Seraphine:
|   Q: "Does Seraphine experience genuine grief after becoming Death
|        or is grief no longer accessible to her?"
|   Priority: blocking
|   Status: in_progress
|
*/
