<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_log', function (Blueprint $table) {
            $table->id();

            // --- IDENTITY ---

            $table->string('title');
            // Short descriptive title for this session
            // "Seraphine transformation arc deep dive"
            // "Crossover physics for Cosmere + 40K"
            // "Johnny and Hermione pre-war dynamic"

            $table->date('session_date');

            $table->string('external_tool');
            // notion, chatgpt, qwen, claude, handwritten,
            // voice_memo, other

            // --- FOCUS ---
            // What this session was about

            $table->jsonb('focus_entity_ids')->default(json_encode([]));
            // Array of entity IDs that were the primary focus

            $table->jsonb('focus_group_relationship_ids')->default(json_encode([]));
            // Array of group relationship IDs if group dynamics were explored

            $table->jsonb('focus_collection_ids')->default(json_encode([]));
            // Array of collection IDs if a collection was the focus

            $table->string('focus_description')->nullable();
            // Short freeform description of what was being worked on
            // e.g. "Worked through Year 0 timeline in detail"
            // "Explored what Seraphine's psychology looks like post-transformation"

            // --- SESSION CONTENT ---

            $table->jsonb('decisions_made')->nullable(); // Tiptap JSON
            // Design decisions reached during this session
            // These should propagate to meta records marked as decision type

            $table->jsonb('changes_applied')->nullable(); // Tiptap JSON
            // Changes actually made to the database during or after this session
            // Which entities were updated, which records were created

            $table->jsonb('open_threads')->nullable(); // Tiptap JSON
            // Things raised but not resolved
            // Leads into follow_up_question_ids

            // --- FOLLOW UP ---

            $table->jsonb('follow_up_question_ids')->default(json_encode([]));
            // Array of entity_questions IDs created from this session
            // Populated when you create questions from the open threads
            // of this session and link them back

            // --- SIGNIFICANCE ---

            $table->string('session_significance')->default('minor');
            // minor       — small refinements, notes, minor decisions
            // moderate    — meaningful decisions, several things resolved
            // major       — significant decisions, major arc work
            // foundational — this session established something fundamental
            //                about your world that everything else builds on

            // --- GENERAL NOTES ---

            $table->jsonb('notes')->nullable(); // Tiptap JSON
            // Anything that doesn't fit the structured fields

            // --- SOFT DELETE AND TIMESTAMPS ---
            // No visibility field — session logs are always private

            $table->softDeletes();
            $table->timestamps();
        });

        // --- ADD DEFERRED FOREIGN KEY ---
        // entity_questions.source_session_log_id was created as an
        // unconstrained integer in migration 5 because session_log
        // did not exist yet. Add the constraint now.

        Schema::table('entity_questions', function (Blueprint $table) {
            $table->foreign('source_session_log_id')
                ->references('id')
                ->on('session_log')
                ->nullOnDelete();
        });

        // --- INDEXES ---

        \DB::statement("
            ALTER TABLE session_log
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(external_tool, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(focus_description, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(session_significance, '')), 'C')
            ) STORED
        ");

        \DB::statement('CREATE INDEX session_log_search_vector_idx ON session_log USING GIN (search_vector)');

        \DB::statement('CREATE INDEX session_log_focus_entities_idx ON session_log USING GIN (focus_entity_ids)');
        \DB::statement('CREATE INDEX session_log_focus_groups_idx ON session_log USING GIN (focus_group_relationship_ids)');
        \DB::statement('CREATE INDEX session_log_follow_up_questions_idx ON session_log USING GIN (follow_up_question_ids)');

        Schema::table('session_log', function (Blueprint $table) {
            $table->index('session_date');
            $table->index('external_tool');
            $table->index('session_significance');
            $table->index('deleted_at');

            // Compound index for date-ordered session browsing:
            // "show me all sessions in reverse chronological order"
            $table->index(['session_date', 'session_significance']);

            // Compound index for tool-filtered queries:
            // "show me all ChatGPT sessions that were foundational"
            $table->index(['external_tool', 'session_significance']);
        });
    }

    public function down(): void
    {
        Schema::table('entity_questions', function (Blueprint $table) {
            $table->dropForeign(['source_session_log_id']);
        });

        Schema::dropIfExists('session_log');
    }
};

/*
|--------------------------------------------------------------------------
| EXTERNAL TOOL ENUM
|--------------------------------------------------------------------------
|
|   notion       — Notion AI or Notion workspace sessions
|   chatgpt      — ChatGPT sessions
|   qwen         — Qwen sessions
|   claude       — Claude sessions outside this system
|   handwritten  — physical notes, journals, or sketches
|   voice_memo   — recorded thinking sessions
|   other
|
|--------------------------------------------------------------------------
| SESSION SIGNIFICANCE ENUM
|--------------------------------------------------------------------------
|
|   minor       — small refinements, exploratory notes
|   moderate    — meaningful decisions, several things resolved
|   major       — significant decisions, major arc or system work
|   foundational — established something load-bearing for the world
|
| Foundational sessions are the ones you want to be able to find
| easily years later when you're asking "why did I decide X?"
| They surface prominently in the session log timeline view.
|
|--------------------------------------------------------------------------
| THE WORKFLOW THIS TABLE SUPPORTS
|--------------------------------------------------------------------------
|
| The intended workflow for external sessions:
|
|   1. You work through a problem in ChatGPT, Notion, Qwen, etc.
|   2. You return to Horizon and create a session_log entry.
|   3. You fill in decisions_made — the things you settled.
|      These decisions propagate to meta records if significant.
|   4. You fill in changes_applied — what you actually updated
|      in the database as a result of this session.
|   5. You capture open_threads — things raised but unresolved.
|   6. For each open thread that needs tracking, you create
|      entity_questions records and link them back via
|      follow_up_question_ids.
|
| The result: no external thinking disappears.
| Every session becomes part of the design history.
| You can trace any entity question back to the session
| that raised it via source_session_log_id on entity_questions.
|
|--------------------------------------------------------------------------
| DEFERRED FK RESOLUTION
|--------------------------------------------------------------------------
|
| entity_questions.source_session_log_id was created in migration 5
| as an unconstrained integer. The constraint is added here now
| that session_log exists.
|
| The down() method drops the constraint before dropping session_log
| to avoid FK violation during rollback.
|
|--------------------------------------------------------------------------
| EXAMPLE RECORD
|--------------------------------------------------------------------------
|
| title: "Year 0 — Seraphine transformation arc deep dive"
| session_date: 2024-03-15
| external_tool: chatgpt
| focus_entity_ids: [seraphine_id, hermione_id]
| focus_description: "Worked through the exact mechanics of how
|                     Hermione's death triggers the transformation.
|                     Explored psychological state in the hours before."
| session_significance: foundational
|
| decisions_made:
|   "1. The Resurrection Stone is transferred to Seraphine through
|       Hermione's hand at the moment of death — not sought.
|    2. Seraphine does not experience the transformation as pain.
|       She experiences it as relief. The edged state resolves.
|    3. The first thing she does after is look for Harry.
|       He is not there. He does not yet exist."
|
| changes_applied:
|   "Updated Seraphine entity: added true_nature JSONB content,
|    updated power_tier_ceiling to cosmic, updated type_status to
|    transformed. Created Year 0 character state tracker snapshot.
|    Updated Hermione relationship charge_history."
|
| open_threads:
|   "1. Does Seraphine tell anyone what happened immediately after?
|       Or does she disappear for a period?
|    2. Where does the Invisibility Cloak physically go during
|       the transformation itself?"
|
| follow_up_question_ids: [question_id_1, question_id_2]
|
*/
