<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_state_tracker', function (Blueprint $table) {
            $table->id();

            // The entity this snapshot is for
            // Not just characters — any entity can have state snapshots
            // Factions, governments, locations evolve too
            $table->foreignId('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            // Which timeline this snapshot belongs to
            // Points to a timeline entity
            $table->unsignedBigInteger('timeline_id')->nullable();

            // Which era this snapshot falls within
            // Points to an era entity
            $table->unsignedBigInteger('era_entity_id')->nullable();

            // --- TEMPORAL POSITION ---

            $table->string('au_date')->nullable();
            $table->string('source_date')->nullable();
            $table->integer('timeline_position')->default(0);
            // Numeric ordering within the entity's state history

            // --- SNAPSHOT IDENTITY ---

            $table->string('snapshot_label')->nullable();
            // "Year 0 — The Transformation"
            // "Harry v69 Online — Year 1300"
            // "Post-Revelation Seraphine"

            $table->string('snapshot_significance')->default('moderate');
            // minor, moderate, major, transformative

            $table->text('significance_reason')->nullable();
            // Why this moment warranted a snapshot

            // --- PSYCHOLOGICAL STATE ---
            // Explicit fields rather than generic JSONB
            // Ensures psychological state is always structured consistently

            $table->text('current_trauma_profile')->nullable();
            $table->text('active_psychological_patterns')->nullable();
            $table->text('coping_mechanisms')->nullable();
            $table->text('breaking_points')->nullable();

            $table->string('current_stability_level')->nullable();
            // stable, stressed, strained, breaking, broken, transformed

            $table->text('self_perception')->nullable();
            $table->text('core_wound')->nullable();
            $table->text('current_desire')->nullable();
            $table->text('current_fear')->nullable();
            $table->text('shadow_self')->nullable();

            // Relational psychology — how they relate to others
            $table->text('relational_patterns')->nullable();
            $table->text('current_relational_state')->nullable();
            // How key relationships are affecting psychology right now

            // The mask versus true self distinction
            $table->text('performed_self')->nullable();
            // What they show the world
            $table->text('true_self')->nullable();
            // What is underneath
            $table->string('mask_integrity')->nullable();
            // intact, cracking, compromised, shattered

            // --- PHYSICAL STATE ---
            // Only tracks changes from baseline
            // Baseline physical profile lives in entity attributes

            $table->text('physical_state_notes')->nullable();
            // What is different from baseline at this point

            $table->jsonb('significant_physical_changes')->nullable();
            // Array of changes:
            // [{ change, cause, era_onset, permanent (bool) }]

            $table->string('physical_integrity')->nullable();
            // baseline, altered, significantly_altered, transformed, transcendent

            // --- POWER STATE ---
            // Current operational power profile
            // Ceiling tier lives on the entity — this tracks current operating state

            $table->string('current_power_tier_operating')->nullable();
            // street_level, regional, national, continental,
            // planetary, cosmic, multiversal, transcendent

            $table->string('current_power_tier_influence')->nullable();
            // personal, local, factional, regional, national,
            // global, civilizational, universal

            $table->jsonb('available_abilities')->nullable();
            // What they can currently do

            $table->jsonb('restricted_abilities')->nullable();
            // What they have but cannot fully access yet
            // Seraphine pre-transformation has the Elder Wand
            // but hasn't yet unified the Hallows

            $table->jsonb('lost_abilities')->nullable();
            // What they had before but no longer have

            $table->jsonb('current_artifacts_and_hallows')->nullable();
            // What they currently possess
            // [{ entity_id, name, acquisition_era, notes }]

            $table->text('power_state_notes')->nullable();

            // --- RELATIONSHIP SUMMARY ---
            // Quick reference to key relationships at this snapshot
            // Not the full relationship records — curated summary

            $table->jsonb('key_relationships_summary')->nullable();
            // Array of relationship summaries:
            // [{
            //   relationship_entity_id,
            //   relationship_type_at_this_era,
            //   current_tension_charge,
            //   current_strength,
            //   summary_note
            // }]

            // --- GROUP RELATIONSHIP LINKS ---
            // Which group dynamics are active at this point

            $table->jsonb('active_group_relationship_ids')->nullable();
            // Array of group_relationship IDs active at this snapshot

            // --- GENERAL NOTES ---

            $table->jsonb('notes')->nullable(); // Tiptap JSON

            // --- VISIBILITY ---

            $table->string('visibility')->default('private');
            $table->string('content_classification')->default('restricted');

            // --- SOFT DELETE AND TIMESTAMPS ---

            $table->softDeletes();
            $table->timestamps();
        });

        // --- STATE RELATIONSHIPS ---
        // Connects state snapshots to the specific relationships
        // that were active or significant at that point in time
        // Seraphine's year 0 snapshot links to her relationship
        // with Hermione as it existed at that moment

        Schema::create('state_relationships', function (Blueprint $table) {
            $table->id();

            $table->foreignId('character_state_id')
                ->constrained('character_state_tracker')
                ->cascadeOnDelete();

            $table->foreignId('relationship_id')
                ->constrained('relationships')
                ->cascadeOnDelete();

            // Was this relationship still active at this state snapshot
            $table->boolean('is_active_at_snapshot')->default(true);

            // Optional note about the state of this relationship
            // at this specific moment in time
            $table->text('relationship_state_at_snapshot')->nullable();

            $table->timestamps();

            $table->unique(
                ['character_state_id', 'relationship_id'],
                'state_relationships_unique'
            );
        });

        // --- ADD DEFERRED FOREIGN KEYS ---

        Schema::table('character_state_tracker', function (Blueprint $table) {
            $table->foreign('timeline_id')
                ->references('id')
                ->on('entities')
                ->nullOnDelete();

            $table->foreign('era_entity_id')
                ->references('id')
                ->on('entities')
                ->nullOnDelete();
        });

        // --- INDEXES ---

        \DB::statement("
            ALTER TABLE character_state_tracker
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(snapshot_label, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(significance_reason, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(current_trauma_profile, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(core_wound, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(current_desire, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(current_fear, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(performed_self, '')), 'D') ||
                setweight(to_tsvector('english', coalesce(true_self, '')), 'D')
            ) STORED
        ");

        \DB::statement('CREATE INDEX character_state_search_vector_idx ON character_state_tracker USING GIN (search_vector)');

        // JSONB indexes
        \DB::statement('CREATE INDEX character_state_physical_changes_idx ON character_state_tracker USING GIN (significant_physical_changes)');
        \DB::statement('CREATE INDEX character_state_abilities_idx ON character_state_tracker USING GIN (available_abilities)');
        \DB::statement('CREATE INDEX character_state_artifacts_idx ON character_state_tracker USING GIN (current_artifacts_and_hallows)');
        \DB::statement('CREATE INDEX character_state_relationships_summary_idx ON character_state_tracker USING GIN (key_relationships_summary)');
        \DB::statement('CREATE INDEX character_state_group_ids_idx ON character_state_tracker USING GIN (active_group_relationship_ids)');

        Schema::table('character_state_tracker', function (Blueprint $table) {
            $table->index('entity_id');
            $table->index('timeline_id');
            $table->index('era_entity_id');
            $table->index('snapshot_significance');
            $table->index('current_stability_level');
            $table->index('mask_integrity');
            $table->index('physical_integrity');
            $table->index('current_power_tier_operating');
            $table->index('current_power_tier_influence');
            $table->index('visibility');
            $table->index('deleted_at');

            // Compound index for entity state history:
            // "give me all snapshots for this entity in chronological order"
            $table->index(['entity_id', 'timeline_position']);

            // Compound index for transformative moment queries:
            // "show me all transformative snapshots for this entity"
            $table->index(['entity_id', 'snapshot_significance']);

            // Compound index for era-scoped state queries:
            // "what was Seraphine's state during cycle 12"
            $table->index(['entity_id', 'era_entity_id']);

            // Compound index for stability queries:
            // "which entities were at breaking point during the Revelation"
            $table->index(['current_stability_level', 'era_entity_id']);

            // Compound index for power state queries:
            // "show me all entities operating at cosmic tier or above"
            $table->index(['current_power_tier_operating', 'entity_id']);
        });

        Schema::table('state_relationships', function (Blueprint $table) {
            $table->index('character_state_id');
            $table->index('relationship_id');
            $table->index('is_active_at_snapshot');

            // Compound index for snapshot relationship queries:
            // "show me all active relationships at this snapshot"
            $table->index(['character_state_id', 'is_active_at_snapshot']);
        });
    }

    public function down(): void
    {
        Schema::table('character_state_tracker', function (Blueprint $table) {
            $table->dropForeign(['timeline_id']);
            $table->dropForeign(['era_entity_id']);
        });

        Schema::dropIfExists('state_relationships');
        Schema::dropIfExists('character_state_tracker');
    }
};

/*
|--------------------------------------------------------------------------
| SNAPSHOT SIGNIFICANCE ENUM
|--------------------------------------------------------------------------
|
|   minor         — small but notable shift
|                   Johnny grows more cynical after cycle 15
|   moderate      — meaningful change
|                   Seraphine deploys her first puppet
|   major         — significant turning point
|                   Harry v69 comes online
|   transformative — fundamental identity change
|                   Seraphine becomes Master of Death
|                   These mark boundaries between fundamentally
|                   different versions of the same entity
|
| Transformative snapshots receive special visual treatment
| in both the timeline view and the entity card state history.
|
|--------------------------------------------------------------------------
| SERAPHINE — EXAMPLE SNAPSHOTS
|--------------------------------------------------------------------------
|
| Snapshot 1 — Year 0 Pre-Transformation:
|   snapshot_label: "Year 0 — The Edged State"
|   snapshot_significance: transformative
|   current_stability_level: breaking
|   performed_self: "Fragile pureblood seeking protection"
|   true_self: "Coiled catastrophic power suppressed by years of
|               conditioning and the edged state mechanism"
|   mask_integrity: shattered (about to break completely)
|   current_power_tier_operating: continental
|   available_abilities: [Elder Wand mastery, advanced Legilimency,
|                          Morbraith cursecraft]
|   restricted_abilities: [Full Hallow unification — Resurrection Stone
|                           not yet in possession]
|   current_artifacts_and_hallows: [
|     { entity_id: elder_wand_id, name: "Elder Wand",
|       acquisition_era: "Prior to Year 0" }
|     { entity_id: cloak_id, name: "Invisibility Cloak",
|       acquisition_era: "Inherited — Morbraith lineage" }
|   ]
|   core_wound: "18 years of suppression as a weapon rather than a person"
|   current_desire: "To stop being afraid of what she is"
|   current_fear: "That she will destroy everything she touches"
|
| Snapshot 2 — Year 0 Post-Transformation:
|   snapshot_label: "Year 0 — Master of Death"
|   snapshot_significance: transformative
|   current_stability_level: transformed
|   performed_self: null (the performance ends here)
|   true_self: "Death itself. No longer requires performance."
|   mask_integrity: shattered
|   physical_integrity: transcendent
|   current_power_tier_operating: cosmic
|   current_artifacts_and_hallows: [
|     { entity_id: elder_wand_id },
|     { entity_id: resurrection_stone_id,
|       acquisition_era: "Year 0 — Hermione's Death" },
|     { entity_id: cloak_id }
|   ]
|
|--------------------------------------------------------------------------
| NOTE ON TABLE NAME
|--------------------------------------------------------------------------
|
| The table is named character_state_tracker but it tracks any entity.
| Factions, governments, and locations have states that evolve too.
| The name reflects the primary use case — characters — but the
| design is deliberately generic.
|
*/
