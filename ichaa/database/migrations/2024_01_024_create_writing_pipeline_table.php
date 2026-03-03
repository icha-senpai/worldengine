<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('writing_pipeline', function (Blueprint $table) {
            $table->id();

            // --- IDENTITY ---

            $table->string('title');

            $table->string('pipeline_type');
            // plot_thread, story_arc, chapter, scene,
            // dialogue_sample, character_arc_tracker_entry,
            // source_inspiration_log_entry

            // --- HIERARCHY ---
            // Preferred but not enforced
            // Arcs contain chapters, chapters contain scenes
            // plot_threads can span multiple arcs
            // A scene without a parent is still valid

            $table->unsignedBigInteger('parent_pipeline_item_id')->nullable();

            $table->integer('sort_order')->default(0);
            // Position within its parent or at top level

            // --- STAGE ---

            $table->string('pipeline_stage')->default('concept');
            // concept, outline, rough_draft, developing_draft,
            // polished_draft, final, archived_draft

            // --- CONTENT ---

            $table->jsonb('content')->nullable(); // Tiptap JSON
            // The actual writing or notes

            // Word count — updated automatically when content changes
            $table->integer('word_count')->default(0);

            // Reading time in minutes — derived from word_count
            // Stored so it doesn't need to be recalculated on every load
            $table->integer('reading_time_minutes')->default(0);

            // --- REVISION HISTORY ---
            // Snapshots of previous content versions
            // Not every save — only explicit revision saves

            $table->jsonb('revision_history')->default(json_encode([]));
            // Array of revision snapshot objects:
            // [{
            //   saved_at:    timestamp
            //   word_count:  integer
            //   stage:       string — what stage it was at this save
            //   content:     Tiptap JSON snapshot
            //   notes:       text — what changed in this revision
            // }]

            // --- TEMPORAL PLACEMENT ---
            // Where in your world's timeline does this occur

            $table->unsignedBigInteger('timeline_entry_id')->nullable();
            $table->integer('timeline_position')->nullable();
            // Numeric position for ordering within a story arc

            // --- SCENE SPECIFIC FIELDS ---
            // Only populated for pipeline_type: scene

            $table->unsignedBigInteger('pov_character_entity_id')->nullable();
            // Which character's POV this scene is written from

            $table->unsignedBigInteger('location_entity_id')->nullable();
            // Where this scene takes place

            $table->string('emotional_beat')->nullable();
            // tension_building, release, revelation, quiet_moment,
            // confrontation, turning_point, aftermath

            $table->text('narrative_purpose')->nullable();
            // What this scene accomplishes in the larger arc

            $table->jsonb('scene_content_warnings')->nullable();
            // Array of content warning strings

            $table->unsignedBigInteger('sensory_palette_meta_id')->nullable();
            // Links to a sensory palette meta record
            // Attaches the atmosphere and sensory profile for this scene

            // --- DIALOGUE SAMPLE SPECIFIC ---
            // Only populated for pipeline_type: dialogue_sample

            $table->unsignedBigInteger('speaker_entity_id')->nullable();
            // Primary speaker for standalone dialogue samples

            $table->jsonb('speakers_entity_ids')->nullable();
            // All speakers if multiple — array of entity IDs

            $table->boolean('add_to_voice_samples')->default(false);
            // Whether to also add this dialogue to the speaker's
            // voice_samples JSONB array on their entity record
            // Processed at application layer on save

            // --- CHARACTER ARC TRACKER SPECIFIC ---
            // Only populated for pipeline_type: character_arc_tracker_entry

            $table->unsignedBigInteger('tracked_entity_id')->nullable();
            // The character whose arc this tracks

            $table->string('arc_stage')->nullable();
            // inciting_event, rising_pressure, threshold_moment,
            // transformation, integration, aftermath

            $table->text('arc_notes')->nullable();
            // What specifically is happening in this character's arc
            // at this point

            // --- SOURCE INSPIRATION LOG SPECIFIC ---
            // Only populated for pipeline_type: source_inspiration_log_entry

            $table->string('inspiration_source_universe')->nullable();
            // Which universe this inspiration comes from

            $table->text('inspiration_source_element')->nullable();
            // The specific element, scene, or mechanic that inspired this

            $table->jsonb('influenced_entity_ids')->nullable();
            // Array of entity IDs that this inspiration affected

            $table->text('how_used')->nullable();
            // What specifically was borrowed

            $table->text('how_changed')->nullable();
            // What was altered from the source

            $table->string('deviation_level')->nullable();
            // minimal, moderate, significant, complete_reimagining

            $table->text('why_it_fits')->nullable();
            // Why this works in your AU context

            // --- GENERAL NOTES ---

            $table->jsonb('notes')->nullable(); // Tiptap JSON

            // --- VISIBILITY ---

            $table->string('visibility')->default('private');
            $table->string('content_classification')->default('restricted');

            // --- SOFT DELETE AND TIMESTAMPS ---

            $table->softDeletes();
            $table->timestamps();
        });

        // --- PIPELINE ENTITIES ---
        // Connects pipeline items to the entities they involve
        // A scene involves Seraphine, Johnny, and the Mirror Library

        Schema::create('pipeline_entities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pipeline_item_id')
                ->constrained('writing_pipeline')
                ->cascadeOnDelete();

            $table->foreignId('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            $table->string('involvement_type')->nullable();
            // present     — entity appears in this scene or arc
            // referenced  — entity is mentioned but not present
            // pov         — this entity's POV drives this item
            // subject     — this item is about this entity's arc
            // catalyst    — this entity's actions drive the plot here

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['pipeline_item_id', 'entity_id', 'involvement_type'],
                'pipeline_entities_unique'
            );
        });

        // --- PIPELINE GROUP RELATIONSHIPS ---
        // Connects pipeline items to group dynamics they involve
        // A scene may activate the Ash-Born triangle dynamic

        Schema::create('pipeline_group_relationships', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pipeline_item_id')
                ->constrained('writing_pipeline')
                ->cascadeOnDelete();

            $table->foreignId('group_relationship_id')
                ->constrained('group_relationships')
                ->cascadeOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['pipeline_item_id', 'group_relationship_id'],
                'pipeline_group_relationships_unique'
            );
        });

        // --- PIPELINE DOCUMENTS ---
        // Connects pipeline items to in-world documents they involve
        // A scene may feature the reading of a specific prophecy

        Schema::create('pipeline_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pipeline_item_id')
                ->constrained('writing_pipeline')
                ->cascadeOnDelete();

            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();

            $table->string('involvement_type')->nullable();
            // featured    — document is central to this scene
            // referenced  — document is mentioned
            // created_in  — this document is produced in this scene

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['pipeline_item_id', 'document_id'],
                'pipeline_documents_unique'
            );
        });

        // --- ADD DEFERRED FOREIGN KEYS ---

        Schema::table('writing_pipeline', function (Blueprint $table) {
            $table->foreign('parent_pipeline_item_id')
                ->references('id')
                ->on('writing_pipeline')
                ->nullOnDelete();

            $table->foreign('timeline_entry_id')
                ->references('id')
                ->on('timeline')
                ->nullOnDelete();

            $table->foreign('pov_character_entity_id')
                ->references('id')
                ->on('entities')
                ->nullOnDelete();

            $table->foreign('location_entity_id')
                ->references('id')
                ->on('entities')
                ->nullOnDelete();

            $table->foreign('sensory_palette_meta_id')
                ->references('id')
                ->on('meta')
                ->nullOnDelete();

            $table->foreign('speaker_entity_id')
                ->references('id')
                ->on('entities')
                ->nullOnDelete();

            $table->foreign('tracked_entity_id')
                ->references('id')
                ->on('entities')
                ->nullOnDelete();
        });

        // --- INDEXES ---

        \DB::statement("
            ALTER TABLE writing_pipeline
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(pipeline_type, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(pipeline_stage, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(narrative_purpose, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(arc_notes, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(how_used, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(how_changed, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(why_it_fits, '')), 'D')
            ) STORED
        ");

        \DB::statement('CREATE INDEX writing_pipeline_search_vector_idx ON writing_pipeline USING GIN (search_vector)');
        \DB::statement('CREATE INDEX writing_pipeline_influenced_entities_idx ON writing_pipeline USING GIN (influenced_entity_ids)');
        \DB::statement('CREATE INDEX writing_pipeline_speakers_idx ON writing_pipeline USING GIN (speakers_entity_ids)');
        \DB::statement('CREATE INDEX writing_pipeline_warnings_idx ON writing_pipeline USING GIN (scene_content_warnings)');

        Schema::table('writing_pipeline', function (Blueprint $table) {
            $table->index('pipeline_type');
            $table->index('pipeline_stage');
            $table->index('parent_pipeline_item_id');
            $table->index('timeline_entry_id');
            $table->index('timeline_position');
            $table->index('pov_character_entity_id');
            $table->index('location_entity_id');
            $table->index('emotional_beat');
            $table->index('sensory_palette_meta_id');
            $table->index('speaker_entity_id');
            $table->index('tracked_entity_id');
            $table->index('arc_stage');
            $table->index('inspiration_source_universe');
            $table->index('deviation_level');
            $table->index('add_to_voice_samples');
            $table->index('word_count');
            $table->index('visibility');
            $table->index('deleted_at');

            // Compound index for hierarchy traversal:
            // "give me all scenes within this chapter in order"
            $table->index(['parent_pipeline_item_id', 'sort_order']);

            // Compound index for stage-filtered pipeline view:
            // "show me all rough drafts across all arcs"
            $table->index(['pipeline_type', 'pipeline_stage']);

            // Compound index for POV character scene queries:
            // "show me all scenes from Seraphine's POV"
            $table->index(['pov_character_entity_id', 'pipeline_stage']);

            // Compound index for location scene queries:
            // "show me all scenes set in the Mirror Library"
            $table->index(['location_entity_id', 'pipeline_type']);

            // Compound index for emotional beat queries:
            // "show me all revelation beats in final stage"
            $table->index(['emotional_beat', 'pipeline_stage']);

            // Compound index for character arc tracking:
            // "show me Seraphine's full arc progression"
            $table->index(['tracked_entity_id', 'arc_stage']);
        });

        Schema::table('pipeline_entities', function (Blueprint $table) {
            $table->index('pipeline_item_id');
            $table->index('entity_id');
            $table->index('involvement_type');

            // Compound index for entity pipeline queries:
            // "show me all pipeline items Seraphine appears in"
            $table->index(['entity_id', 'involvement_type']);
        });

        Schema::table('pipeline_group_relationships', function (Blueprint $table) {
            $table->index('pipeline_item_id');
            $table->index('group_relationship_id');
        });

        Schema::table('pipeline_documents', function (Blueprint $table) {
            $table->index('pipeline_item_id');
            $table->index('document_id');
            $table->index('involvement_type');
        });
    }

    public function down(): void
    {
        Schema::table('writing_pipeline', function (Blueprint $table) {
            $table->dropForeign(['parent_pipeline_item_id']);
            $table->dropForeign(['timeline_entry_id']);
            $table->dropForeign(['pov_character_entity_id']);
            $table->dropForeign(['location_entity_id']);
            $table->dropForeign(['sensory_palette_meta_id']);
            $table->dropForeign(['speaker_entity_id']);
            $table->dropForeign(['tracked_entity_id']);
        });

        Schema::dropIfExists('pipeline_documents');
        Schema::dropIfExists('pipeline_group_relationships');
        Schema::dropIfExists('pipeline_entities');
        Schema::dropIfExists('writing_pipeline');
    }
};

/*
|--------------------------------------------------------------------------
| PIPELINE TYPE ENUM
|--------------------------------------------------------------------------
|
|   plot_thread                — overarching thread that spans arcs
|   story_arc                  — major narrative arc
|   chapter                    — chapter within an arc
|   scene                      — individual scene within a chapter
|   dialogue_sample            — standalone dialogue for voice reference
|   character_arc_tracker_entry — tracks one character's arc progression
|   source_inspiration_log_entry — documents what was borrowed and changed
|
|--------------------------------------------------------------------------
| PIPELINE STAGE ENUM
|--------------------------------------------------------------------------
|
|   concept          — idea captured, not yet developed
|   outline          — structure sketched, no prose
|   rough_draft      — first pass prose, may be rough
|   developing_draft — actively being refined
|   polished_draft   — near final, minor refinements remaining
|   final            — done
|   archived_draft   — old version kept for reference
|
|--------------------------------------------------------------------------
| EMOTIONAL BEAT ENUM (scene specific)
|--------------------------------------------------------------------------
|
|   tension_building — pressure accumulating
|   release          — tension resolving
|   revelation       — truth surfaces
|   quiet_moment     — rest between action
|   confrontation    — direct conflict
|   turning_point    — the thing that changes everything
|   aftermath        — processing what just happened
|
|--------------------------------------------------------------------------
| ARC STAGE ENUM (character_arc_tracker_entry specific)
|--------------------------------------------------------------------------
|
|   inciting_event    — what sets the arc in motion
|   rising_pressure   — pressure building on the character
|   threshold_moment  — the point of no return
|   transformation    — the actual change
|   integration       — processing the transformation
|   aftermath         — who they are after
|
|--------------------------------------------------------------------------
| VOICE SAMPLES AND DIALOGUE
|--------------------------------------------------------------------------
|
| Dialogue samples serve two functions simultaneously:
|
|   1. As standalone pipeline items — reference for voice and tone
|      "How Seraphine speaks to Harry v69 when giving instructions"
|
|   2. As voice_samples entries on character entities — quick reference
|      When add_to_voice_samples is true and the speaker is set,
|      the application layer adds a reference to this pipeline item
|      in the speaker entity's voice_samples JSONB array.
|
| This keeps voice samples accessible directly from the entity card
| without duplicating the content. The entity's voice_samples array
| holds pipeline item IDs, not the dialogue itself.
|
|--------------------------------------------------------------------------
| REVISION HISTORY NOTE
|--------------------------------------------------------------------------
|
| Revision history is not automatic on every save.
| You explicitly save a revision snapshot.
| This prevents revision history from becoming enormous.
|
| The application layer provides a "Save Revision" button
| separate from the standard autosave.
| Autosave updates content, word_count, reading_time_minutes.
| Save Revision creates a snapshot in revision_history.
|
*/
