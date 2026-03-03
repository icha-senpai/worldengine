<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('power_interactions', function (Blueprint $table) {
            $table->id();

            // --- THE TWO SYSTEMS ---
            // Both point to system, magic, technology, or concept entities
            // e.g. HP Magic system entity + Cosmere Investiture system entity

            $table->foreignId('system_a_entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            $table->foreignId('system_b_entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            // Short descriptive name for this interaction
            // e.g. "HP Magic + Investiture Contact"
            // e.g. "Warp Exposure on Investiture Near Perpendicularity"
            $table->string('interaction_name');

            // Full explanation of the interaction
            $table->jsonb('description')->nullable(); // Tiptap JSON

            // --- DIRECTIONALITY ---

            $table->string('directionality');
            // symmetrical  — both systems affect each other equally
            // asymmetrical — one system dominates
            // contextual   — direction depends on conditions

            $table->unsignedBigInteger('dominant_system_entity_id')->nullable();
            // For asymmetrical — which system wins

            $table->text('dominant_reason')->nullable();
            // Why this system dominates in this interaction

            $table->text('subordinate_effect')->nullable();
            // What happens to the weaker system

            // --- MULTI-EFFECT SYSTEM ---
            // A single interaction can have multiple simultaneous effects
            // Warp + Investiture near a Perpendicularity:
            //   Effect 1: amplifies raw power output
            //   Effect 2: corrupts cognitive function
            //   Effect 3: destabilizes the Perpendicularity itself

            $table->jsonb('effects')->default(json_encode([]));
            // Array of effect objects:
            // [{
            //   effect_type:     suppresses|amplifies|negates|transforms|
            //                    corrupts|destabilizes|catalyzes|unpredictable
            //   affected_aspect: raw_power|cognitive_function|
            //                    physical_manifestation|spiritual_component|
            //                    emotional_resonance|reality_anchor|other
            //   magnitude:       negligible|minor|moderate|significant|catastrophic
            //   description:     text — what specifically happens
            // }]

            // --- CONDITIONS AND TRIGGERS ---

            $table->boolean('proximity_required')->default(false);
            $table->text('proximity_range')->nullable();

            $table->jsonb('location_conditions')->nullable();
            // Conditions related to where this occurs
            // e.g. near a Perpendicularity, inside the Warp, at a convergence point

            $table->jsonb('practitioner_conditions')->nullable();
            // Conditions related to who is involved
            // e.g. must have awareness of both systems, specific bloodline required

            $table->jsonb('temporal_conditions')->nullable();
            // Conditions related to when this occurs
            // e.g. only during specific eras, only post-Revelation

            $table->jsonb('artifact_conditions')->nullable();
            // Specific artifacts that trigger or modify the interaction

            $table->text('conditions_notes')->nullable();
            // Freeform conditions that don't fit the structured fields

            $table->string('trigger_type');
            // proximity, deliberate_combination, accidental_contact,
            // ritual_invocation, environmental, other

            $table->text('trigger_description')->nullable();
            // What specifically causes the interaction to occur

            $table->string('trigger_frequency');
            // rare, occasional, common, inevitable

            // --- SCALE ---

            $table->string('interaction_scale');
            // personal, skirmish, battle, regional,
            // planetary, cosmic, universal

            $table->string('scale_variance');
            // uniform             — same at all scales
            // intensifies_with_scale
            // degrades_with_scale
            // transforms_with_scale — different effects at different scales

            $table->text('scale_notes')->nullable();

            // --- KNOWLEDGE AND DANGER ---

            $table->string('knowledge_state');
            // established       — tested, documented, understood
            // theorized         — predicted, not yet tested
            // rumored           — heard of but unverified
            // unknown           — no information
            // forbidden_knowledge — known but actively suppressed

            $table->string('danger_rating');
            // benign, minor_risk, significant_risk,
            // catastrophic_risk, existential_risk, unknown_risk

            $table->text('danger_notes')->nullable();

            // Automatically flagged when knowledge_state is unknown/rumored
            // AND danger_rating is catastrophic/existential/unknown
            // Set by application layer on save
            $table->boolean('unresolved_flag')->default(false);

            $table->string('canon_certainty');
            // established, theorized, unknown

            // --- SOURCE UNIVERSES ---

            $table->string('source_universe_a')->nullable();
            // Which universe system A comes from

            $table->string('source_universe_b')->nullable();
            // Which universe system B comes from

            $table->boolean('crossover_specific')->default(true);
            // Is this interaction only relevant in your AU crossover context
            // or would it exist in either source universe independently

            // --- VISIBILITY ---

            $table->string('visibility')->default('private');
            $table->string('content_classification')->default('restricted');

            // --- SOFT DELETE AND TIMESTAMPS ---

            $table->softDeletes();
            $table->timestamps();

            // Prevent duplicate interaction records for the same system pair
            // Note: system_a + system_b is treated as unordered pair
            // Enforced at application layer — HP+Cosmere and Cosmere+HP
            // should not both exist as separate records
            $table->unique(
                ['system_a_entity_id', 'system_b_entity_id'],
                'power_interactions_unique_pair'
            );
        });

        // --- POWER INTERACTION INSTANCES ---
        // Records actual documented occurrences of an interaction in your world
        // The interaction record defines the rule
        // The instance record documents a specific time it happened

        Schema::create('power_interaction_instances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('power_interaction_id')
                ->constrained('power_interactions')
                ->cascadeOnDelete();

            // The event or scene where this interaction was observed
            // Points to an event entity
            $table->unsignedBigInteger('event_entity_id')->nullable();

            // The entities involved in this specific instance
            // May be different from the system entities on the interaction
            // e.g. the interaction is HP Magic + Investiture
            // the instance involves Seraphine (HP) and a Cosmere traveler
            $table->jsonb('involved_entity_ids')->nullable();

            $table->text('notes')->nullable();
            // What specifically happened in this instance
            // How it differed from or confirmed the general interaction rule

            // Did this instance match the expected interaction rule
            $table->string('outcome_match')->default('confirmed');
            // confirmed    — matched the documented interaction rule
            // partial      — partially matched, some unexpected effects
            // contradicted — contradicted the documented rule
            // new_discovery — revealed something not in the rule

            $table->text('outcome_notes')->nullable();
            // Especially important for contradicted and new_discovery

            $table->string('observed_at_era')->nullable();

            $table->timestamps();
        });

        // --- INDEXES ---

        \DB::statement("
            ALTER TABLE power_interactions
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(interaction_name, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(trigger_description, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(danger_notes, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(conditions_notes, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(scale_notes, '')), 'D')
            ) STORED
        ");

        \DB::statement('CREATE INDEX power_interactions_search_vector_idx ON power_interactions USING GIN (search_vector)');

        // JSONB indexes for condition queries
        \DB::statement('CREATE INDEX power_interactions_effects_idx ON power_interactions USING GIN (effects)');
        \DB::statement('CREATE INDEX power_interactions_location_conditions_idx ON power_interactions USING GIN (location_conditions)');
        \DB::statement('CREATE INDEX power_interactions_artifact_conditions_idx ON power_interactions USING GIN (artifact_conditions)');
        \DB::statement('CREATE INDEX power_interaction_instances_involved_idx ON power_interaction_instances USING GIN (involved_entity_ids)');

        Schema::table('power_interactions', function (Blueprint $table) {
            $table->index('system_a_entity_id');
            $table->index('system_b_entity_id');
            $table->index('directionality');
            $table->index('dominant_system_entity_id');
            $table->index('trigger_type');
            $table->index('trigger_frequency');
            $table->index('interaction_scale');
            $table->index('scale_variance');
            $table->index('knowledge_state');
            $table->index('danger_rating');
            $table->index('unresolved_flag');
            $table->index('canon_certainty');
            $table->index('source_universe_a');
            $table->index('source_universe_b');
            $table->index('crossover_specific');
            $table->index('visibility');
            $table->index('deleted_at');

            // Compound index for dashboard warnings panel:
            // "show me all flagged dangerous unknown interactions"
            $table->index(['unresolved_flag', 'danger_rating']);

            // Compound index for source universe interaction queries:
            // "show me all interactions involving Cosmere systems"
            $table->index(['source_universe_a', 'source_universe_b']);

            // Compound index for scale-specific queries:
            // "show me all interactions that transform at cosmic scale"
            $table->index(['scale_variance', 'interaction_scale']);
        });

        Schema::table('power_interaction_instances', function (Blueprint $table) {
            $table->index('power_interaction_id');
            $table->index('event_entity_id');
            $table->index('outcome_match');
            $table->index('observed_at_era');

            // Compound index for interaction instance review:
            // "show me all contradicting instances — rules that need updating"
            $table->index(['power_interaction_id', 'outcome_match']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('power_interaction_instances');
        Schema::dropIfExists('power_interactions');
    }
};

/*
|--------------------------------------------------------------------------
| EFFECT TYPE ENUM
|--------------------------------------------------------------------------
|
|   suppresses    — reduces or blocks the other system
|   amplifies     — increases the other system's output or power
|   negates       — completely cancels the other system
|   transforms    — changes the nature of the other system
|   corrupts      — damages or destabilizes the other system
|   destabilizes  — creates instability without full corruption
|   catalyzes     — triggers latent potential in the other system
|   unpredictable — outcome varies, no consistent rule
|
|--------------------------------------------------------------------------
| AFFECTED ASPECT ENUM
|--------------------------------------------------------------------------
|
|   raw_power              — the brute force output of the system
|   cognitive_function     — the mental/intellectual aspects
|   physical_manifestation — how it physically appears or acts
|   spiritual_component    — soul, spirit, or metaphysical aspects
|   emotional_resonance    — emotional connection to the power
|   reality_anchor         — the system's connection to stable reality
|   other
|
|--------------------------------------------------------------------------
| UNRESOLVED FLAG LOGIC
|--------------------------------------------------------------------------
|
| The unresolved_flag is set to true automatically when:
|   knowledge_state IN (unknown, rumored)
|   AND danger_rating IN (catastrophic_risk, existential_risk, unknown_risk)
|
| These interactions surface in the dashboard warnings panel alongside
| blocking entity questions and active contradictions.
|
| Example flagged interaction:
|   Death-level power (Seraphine post-transformation)
|   + Cosmere Shard direct contact
|   knowledge_state: unknown
|   danger_rating: existential_risk
|   danger_notes: "Nobody has attempted this. The Shards are
|                  the splintered pieces of Adonalsium — a being
|                  of near-universal power. Contact between Death
|                  incarnate and a Shard may destabilize the
|                  Cosmere's fundamental structure or Seraphine's
|                  own cosmological anchoring."
|   unresolved_flag: true
|
|--------------------------------------------------------------------------
| UNIQUE CONSTRAINT NOTE
|--------------------------------------------------------------------------
|
| The unique constraint treats the pair as ordered (a, b).
| HP Magic as system_a + Investiture as system_b is constrained.
| But Investiture as system_a + HP Magic as system_b would not
| be caught by the constraint.
|
| The application layer enforces the unordered pair convention:
| always store with the lower entity ID as system_a.
| This prevents the same interaction being documented twice
| from different directions.
|
*/
