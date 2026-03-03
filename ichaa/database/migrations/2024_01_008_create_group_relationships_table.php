<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- GROUP RELATIONSHIPS ---
        // The dynamic that exists between three or more entities as a unit
        // Distinct from the sum of their pairwise relationships

        Schema::create('group_relationships', function (Blueprint $table) {
            $table->id();

            // --- IDENTITY ---

            // Optional human-readable label for this group dynamic
            // e.g. "The Ash-Born Triangle", "Spider's Chapel Inner Circle"
            // "Morbraith Family Power Structure"
            $table->string('name')->nullable();

            // What kind of dynamic this group has
            $table->string('relationship_type');
            // Same enum as pairwise relationships
            // A group can be: complicated, allied_with, at_war_with,
            // member_of, controls, ritualistic, etc.
            // Use other + notes for dynamics that don't fit

            $table->text('other_type_notes')->nullable();

            // What makes this group dynamic distinct from the sum of its pairs
            // This is the core field — the reason this record exists
            $table->jsonb('dynamic_description')->nullable(); // Tiptap JSON

            // --- TENSION CHARGE ---
            // Same pattern as pairwise relationships
            // Current charge reflects the latest state of the group dynamic

            $table->string('current_tension_charge');
            // positive, neutral, negative, complex, volatile

            // Full charge history — how the group dynamic's tone has shifted
            // Each entry: { era, charge, caused_by, notes }
            $table->jsonb('charge_history')->default(json_encode([]));

            // --- TEMPORAL SCOPE ---

            $table->string('time_period_start')->nullable();
            $table->string('time_period_end')->nullable();
            $table->boolean('is_active')->default(true);

            // --- GROUP HISTORY ---
            // How the group dynamic itself has evolved
            // Key moments that changed the nature of the group
            // Each entry: { era, description, significant_shift (bool), notes }

            $table->jsonb('group_history')->default(json_encode([]));

            // --- PERCEPTION LAYER ---
            // What the world thinks this group is versus what it actually is
            // The Spider's Chapel inner circle appears to be religious leaders
            // They are actually something else entirely

            $table->string('perceived_type')->nullable();
            $table->string('true_type')->nullable();
            $table->string('perception_divergence')->nullable();
            // none, partial, complete
            $table->jsonb('perceived_by')->nullable();
            // Array of entity IDs that hold the false perception of this group

            // --- NOTES ---

            $table->jsonb('notes')->nullable(); // Tiptap JSON

            // --- VISIBILITY AND CLASSIFICATION ---

            $table->string('visibility')->default('private');
            $table->string('content_classification')->default('restricted');

            // --- SOFT DELETE AND TIMESTAMPS ---

            $table->softDeletes();
            $table->timestamps();
        });

        // --- GROUP RELATIONSHIP ENTITIES ---
        // Pivot connecting entities to group relationships
        // Each entity in the group gets a role describing their function
        // in the dynamic — not just that they're a member

        Schema::create('group_relationship_entities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('group_relationship_id')
                ->constrained('group_relationships')
                ->cascadeOnDelete();

            $table->foreignId('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            // What function this entity serves in the group dynamic
            $table->string('role_in_group');
            // axis        — the central figure the others orbit
            //               Johnny is axis in the Ash-Born triangle
            // member      — standard participant in the dynamic
            // catalyst    — their presence or actions drive the dynamic
            //               without being central themselves
            // peripheral  — involved but not core to the dynamic
            // antagonist  — their opposition defines the group
            // anchor      — stabilizing force that holds the group together
            // disruptor   — their unpredictability destabilizes the group
            // unknown     — role unclear or deliberately ambiguous

            // Notes specific to this entity's participation in this group
            $table->text('participation_notes')->nullable();

            // Whether this entity is currently active in this group dynamic
            // An entity can have left a group while the group continues
            $table->boolean('is_active_member')->default(true);
            $table->string('joined_era')->nullable();
            $table->string('left_era')->nullable();
            $table->text('departure_notes')->nullable();

            // Timestamps — no soft delete on pivot
            // Removing an entity from a group uses is_active_member = false
            // with a left_era, preserving the historical record
            $table->timestamps();

            // An entity can only have one role per group relationship
            $table->unique(['group_relationship_id', 'entity_id'],
                'group_relationship_entities_unique');
        });

        // --- INDEXES ---

        // Full text search on group relationship name and dynamic description
        \DB::statement("
            ALTER TABLE group_relationships
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(name, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(relationship_type, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(other_type_notes, '')), 'C')
            ) STORED
        ");

        \DB::statement('CREATE INDEX group_relationships_search_vector_idx ON group_relationships USING GIN (search_vector)');

        // JSONB indexes
        \DB::statement('CREATE INDEX group_relationships_charge_history_idx ON group_relationships USING GIN (charge_history)');
        \DB::statement('CREATE INDEX group_relationships_perceived_by_idx ON group_relationships USING GIN (perceived_by)');

        Schema::table('group_relationships', function (Blueprint $table) {
            $table->index('relationship_type');
            $table->index('current_tension_charge');
            $table->index('is_active');
            $table->index('visibility');
            $table->index('content_classification');
            $table->index('deleted_at');
        });

        Schema::table('group_relationship_entities', function (Blueprint $table) {
            $table->index('group_relationship_id');
            $table->index('entity_id');
            $table->index('role_in_group');
            $table->index('is_active_member');

            // Compound index for the most common query:
            // "give me all active group dynamics this entity is part of"
            $table->index(['entity_id', 'is_active_member']);

            // Compound index for role-based queries:
            // "show me all entities who serve as axis in any group dynamic"
            $table->index(['role_in_group', 'is_active_member']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_relationship_entities');
        Schema::dropIfExists('group_relationships');
    }
};

/*
|--------------------------------------------------------------------------
| THE DISTINCTION FROM PAIRWISE RELATIONSHIPS
|--------------------------------------------------------------------------
|
| Pairwise relationships answer: what is the connection between A and B?
| Group relationships answer: what is the dynamic when A, B, and C
| exist together as a unit?
|
| The Hermione-Ginny-Johnny triangle is not captured by:
|   Hermione → complicated → Johnny
|   Ginny → complicated → Johnny
|   Hermione → complicated → Ginny
|
| Those three records exist AND should exist.
| But the triangle as a unit has its own charge, its own history,
| and its own emergent dynamic that belongs to none of the pairs.
| Johnny is the axis. Hermione and Ginny are both connected to him
| in ways that create a three-way tension none of the pairs fully express.
|
|--------------------------------------------------------------------------
| ROLE IN GROUP ENUM
|--------------------------------------------------------------------------
|
|   axis       — central figure others orbit (Johnny in Ash-Born triangle)
|   member     — standard participant
|   catalyst   — drives the dynamic without being central
|   peripheral — involved but not core
|   antagonist — opposition that defines the group
|   anchor     — stabilizing force
|   disruptor  — destabilizing presence
|   unknown    — deliberately ambiguous
|
|--------------------------------------------------------------------------
| EXAMPLE — THE ASH-BORN TRIANGLE
|--------------------------------------------------------------------------
|
| group_relationships record:
|   name: "The Ash-Born Triangle"
|   relationship_type: complicated
|   dynamic_description: "Johnny is the axis around which both Hermione
|     and Ginny orbit. Hermione's strategic bond with him is intellectual
|     and deeply personal. Ginny's connection carries grief, rivalry,
|     and a shared devotion to Harry's memory. The triangle's charge
|     is defined by what none of them say directly."
|   current_tension_charge: complex
|   is_active: false (dissolved at Hermione's death)
|   time_period_end: "Year 0 — Hermione's Death"
|
| group_relationship_entities records:
|   Johnny  — role: axis,   is_active_member: false
|   Hermione — role: member, is_active_member: false, left_era: "Year 0"
|   Ginny   — role: member, is_active_member: false
|
|--------------------------------------------------------------------------
| SOFT DELETE NOTE
|--------------------------------------------------------------------------
|
| The pivot table (group_relationship_entities) does not use soft deletes.
| Removing a member uses is_active_member = false with a left_era.
| This preserves the historical record of who was in the group and when
| they left without needing a deleted_at column on the pivot.
|
*/
