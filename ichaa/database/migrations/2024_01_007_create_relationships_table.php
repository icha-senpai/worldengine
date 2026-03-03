<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relationships', function (Blueprint $table) {
            $table->id();

            // --- THE TWO ENTITIES ---

            $table->foreignId('from_entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            $table->foreignId('to_entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            // --- RELATIONSHIP TYPE ---
            // Fixed enum with other + notes escape hatch

            $table->string('relationship_type');
            // See full enum in comment block below

            // When relationship_type is 'other', this explains what it actually is
            $table->text('other_type_notes')->nullable();

            // --- DIRECTIONALITY ---

            $table->string('direction');
            // one_way        — from affects to, to is not meaningfully affected back
            // mutual_equal   — both parties affected equally
            // mutual_unequal — both affected but differently

            // Perspective notes — only populated for mutual_unequal
            // How this relationship looks from each side independently
            $table->jsonb('perspective_a')->nullable(); // from_entity's perspective
            $table->jsonb('perspective_b')->nullable(); // to_entity's perspective

            // --- TENSION CHARGE ---

            // Current charge — always reflects the latest state
            $table->string('current_tension_charge');
            // positive, neutral, negative, complex, volatile

            // Full charge history — one entry per era or significant shift
            // Each entry: { era, charge, caused_by, notes }
            $table->jsonb('charge_history')->default(json_encode([]));

            // --- RELATIONSHIP STRENGTH ---
            // How significant is this relationship to each party independently
            // Directional — captures asymmetry like Johnny and Seraphine

            $table->string('strength_from_a')->nullable();
            // peripheral, significant, defining, absolute

            $table->string('strength_from_b')->nullable();
            // peripheral, significant, defining, absolute

            // --- TEMPORAL SCOPE ---

            $table->string('time_period_start')->nullable(); // Freeform era reference
            $table->string('time_period_end')->nullable();   // Freeform era reference
            $table->boolean('is_active')->default(true);

            // --- RELATIONSHIP HISTORY ---
            // How the relationship evolved over time
            // Not just the charge — the nature, the dynamic, the key moments
            // Each entry: { era, description, significant_shift (bool), notes }

            $table->jsonb('relationship_history')->default(json_encode([]));

            // --- PERCEPTION LAYER ---
            // What observers think this relationship is versus what it actually is
            // Seraphine and Johnny appear to be eternal enemies
            // They are actually player and game piece

            $table->string('perceived_type')->nullable();
            // Same enum as relationship_type — what outsiders believe this is
            $table->string('true_type')->nullable();
            // Same enum — what it actually is (if different from relationship_type)
            $table->string('perception_divergence')->nullable();
            // none, partial, complete
            $table->jsonb('perceived_by')->nullable();
            // Array of entity IDs that hold the false perception

            // --- GENERAL NOTES ---

            $table->jsonb('notes')->nullable(); // Tiptap JSON for rich notes

            // --- VISIBILITY AND CLASSIFICATION ---

            $table->string('visibility')->default('private');
            $table->string('content_classification')->default('restricted');

            // --- SOFT DELETE AND TIMESTAMPS ---

            $table->softDeletes();
            $table->timestamps();
        });

        // --- INDEXES ---

        // Full text search across notes
        \DB::statement("
            ALTER TABLE relationships
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(relationship_type, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(other_type_notes, '')), 'B')
            ) STORED
        ");

        \DB::statement('CREATE INDEX relationships_search_vector_idx ON relationships USING GIN (search_vector)');

        // JSONB indexes
        \DB::statement('CREATE INDEX relationships_charge_history_idx ON relationships USING GIN (charge_history)');
        \DB::statement('CREATE INDEX relationships_history_idx ON relationships USING GIN (relationship_history)');
        \DB::statement('CREATE INDEX relationships_perceived_by_idx ON relationships USING GIN (perceived_by)');

        Schema::table('relationships', function (Blueprint $table) {
            $table->index('from_entity_id');
            $table->index('to_entity_id');
            $table->index('relationship_type');
            $table->index('direction');
            $table->index('current_tension_charge');
            $table->index('is_active');
            $table->index('visibility');
            $table->index('content_classification');
            $table->index('deleted_at');

            // Compound index for entity card relationship tab:
            // "give me all active relationships involving this entity"
            $table->index(['from_entity_id', 'is_active']);
            $table->index(['to_entity_id', 'is_active']);

            // Compound index for relationship type filtering:
            // "show me all sibling relationships" or "all controls relationships"
            $table->index(['relationship_type', 'is_active']);

            // Compound index for the visual node map on entity card:
            // "give me all relationships from this entity of any type"
            $table->index(['from_entity_id', 'relationship_type']);

            // Prevent exact duplicate relationships
            // Same from, to, type, and direction should not exist twice
            $table->unique(['from_entity_id', 'to_entity_id', 'relationship_type', 'direction'],
                'relationships_unique_pair');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relationships');
    }
};

/*
|--------------------------------------------------------------------------
| RELATIONSHIP TYPE ENUM — fixed with other escape hatch
|--------------------------------------------------------------------------
|
| Familial:
|   parent_of, sibling_of, child_of, ancestor_of,
|   descendant_of, bonded_bloodline
|
| Romantic & Intimate:
|   romantic, sexual, complicated, former, desired_unrequited,
|   ritualistic, bonded
|
| Power & Control:
|   controls, serves, created, owns, commands,
|   manipulates, protects, hunts
|
| Conflict:
|   opposes, enemies, rivals, betrayed, at_war_with
|
| Organizational:
|   member_of, leads, founded, allied_with,
|   neutral_with, diplomat_for
|
| Knowledge & Magic:
|   trained, mentored, taught_by, knows_secret_of,
|   bound_to, cursed_by, prophesied_about
|
| Possession & Objects:
|   possesses, wielded_by, created_by,
|   destroyed_by, seeks
|
| Crossover Specific:
|   crossover_counterpart, convergence_linked,
|   power_interaction_with, canon_origin_of
|
| Narrative:
|   catalyzed, parallel_to, foil_of,
|   successor_of, legacy_of
|
| Escape hatch:
|   other — requires other_type_notes to be populated
|
|--------------------------------------------------------------------------
| THE UNIQUE CONSTRAINT
|--------------------------------------------------------------------------
|
| The unique constraint on [from_entity_id, to_entity_id,
| relationship_type, direction] prevents exact duplicates.
|
| This means Seraphine → controls → Johnny can only exist once.
| But Seraphine → manipulates → Johnny can also exist simultaneously.
| And Johnny → opposes → Seraphine can exist as a separate record
| because the direction (from/to) is reversed.
|
| Two characters can have multiple relationship types between them.
| Each type is a separate record. This is correct — Seraphine and
| Silas have sibling_of, complicated, ritualistic, and controls
| all simultaneously.
|
|--------------------------------------------------------------------------
| JOHNNY AND SERAPHINE — example records
|--------------------------------------------------------------------------
|
| Record 1:
|   from: Seraphine, to: Johnny
|   type: controls
|   direction: one_way
|   current_tension_charge: complex
|   strength_from_a: peripheral → significant (see charge_history)
|   strength_from_b: absolute
|   perceived_type: enemies
|   true_type: controls
|   perception_divergence: complete
|   perceived_by: [all entities except Seraphine and Harry v69]
|
| Record 2:
|   from: Johnny, to: Seraphine
|   type: desired_unrequited
|   direction: one_way
|   current_tension_charge: complex
|   strength_from_a: absolute
|   strength_from_b: peripheral
|
*/
