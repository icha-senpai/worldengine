<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versions_and_canon_states', function (Blueprint $table) {
            $table->id();

            // The entity this version belongs to
            $table->foreignId('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            // --- VERSION IDENTITY ---

            $table->string('version_type');
            // soft           — same continuous entity evolved naturally
            //                  Seraphine at year 0 vs year 2000
            // hard_iteration — deliberate instantiation, previous versions
            //                  terminated. Harry v1 through v69.

            $table->integer('version_number')->default(1);
            // Sequential per entity — not global

            $table->string('version_label')->nullable();
            // Human readable label for this version
            // "Year 0 — Pre-Transformation Seraphine"
            // "Harry v23 — Ethical Drift Iteration"
            // "Pre-AU Hermione — Version Zero"

            // --- VERSION STATE ---

            $table->string('version_state');
            // current          — the active canonical version
            // archived         — was valid at a point in time, entity evolved
            // deprecated       — you changed your mind, no longer canon
            // iteration_failed — deliberately terminated (hard iterations only)

            $table->boolean('is_current')->default(false);
            // Only one version per entity should have this true
            // Enforced at application layer

            $table->boolean('is_version_zero')->default(false);
            // True for source canon reference versions
            // The HP Hermione before your AU divergence begins

            // Version zero confidence — how accurately this captures source canon
            $table->string('version_zero_confidence')->nullable();
            // rough, developing, solid, verified
            // Only relevant when is_version_zero is true

            $table->text('version_zero_notes')->nullable();
            // What you know you're missing or uncertain about in this
            // source canon capture

            // --- CONTENT ---

            // Complete snapshot of the entity's fields at this version
            // Stored as a full JSON copy of the entity record
            $table->jsonb('entity_snapshot');

            // What is different from the previous version
            $table->jsonb('what_changed')->nullable(); // Tiptap JSON

            // What caused this change — in-world event or authorial decision
            $table->jsonb('why_changed')->nullable(); // Tiptap JSON

            // --- TRIGGER ---

            $table->string('trigger_type')->default('manual');
            // manual    — you explicitly saved this canon state
            // automatic — triggered by a critical field change

            $table->string('triggered_by_field')->nullable();
            // Which field change triggered automatic versioning
            // e.g. "power_tier_ceiling", "true_nature", "status"

            // When this version was the current canonical state
            $table->string('valid_from_era')->nullable();
            $table->string('valid_until_era')->nullable();

            // --- HARD ITERATION SPECIFIC FIELDS ---
            // Only populated for version_type: hard_iteration

            $table->integer('iteration_number')->nullable();
            // The iteration number — Harry v23 has iteration_number: 23

            $table->unsignedBigInteger('source_entity_id')->nullable();
            // The conceptual parent entity all iterations instantiate
            // Harry v1 through v69 all point to the abstract "Harry" entity

            // What knowledge and capabilities carried over from the previous
            // iteration despite the reset
            $table->jsonb('retained_from_previous')->nullable(); // Tiptap JSON

            // Why this iteration was terminated
            $table->jsonb('what_failed')->nullable(); // Tiptap JSON
            // Only populated for version_state: iteration_failed

            $table->string('failure_era')->nullable();
            // When in the timeline this iteration was terminated

            $table->unsignedBigInteger('terminated_by_entity_id')->nullable();
            // Who made the decision to terminate this iteration
            // For Harry iterations this is always Seraphine

            // --- DEPRECATION FIELDS ---
            // Only populated for version_state: deprecated

            $table->timestamp('deprecated_at')->nullable();
            $table->jsonb('deprecation_reason')->nullable(); // Tiptap JSON
            $table->unsignedBigInteger('superseded_by_version_id')->nullable();
            // Points to the version that replaced this deprecated one

            // --- VISIBILITY ---
            // Version zero records are often private even for public entities
            // Your working notes on source canon don't need to be public

            $table->string('visibility')->default('private');
            $table->string('content_classification')->default('restricted');

            // --- SOFT DELETE AND TIMESTAMPS ---

            $table->softDeletes();
            $table->timestamps();
        });

        // --- INDEXES ---

        \DB::statement("
            ALTER TABLE versions_and_canon_states
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(version_label, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(triggered_by_field, '')), 'C')
            ) STORED
        ");

        \DB::statement('CREATE INDEX versions_search_vector_idx ON versions_and_canon_states USING GIN (search_vector)');

        Schema::table('versions_and_canon_states', function (Blueprint $table) {
            $table->index('entity_id');
            $table->index('version_type');
            $table->index('version_number');
            $table->index('version_state');
            $table->index('is_current');
            $table->index('is_version_zero');
            $table->index('version_zero_confidence');
            $table->index('trigger_type');
            $table->index('triggered_by_field');
            $table->index('iteration_number');
            $table->index('source_entity_id');
            $table->index('terminated_by_entity_id');
            $table->index('superseded_by_version_id');
            $table->index('visibility');
            $table->index('deleted_at');

            // Compound index for the primary version history query:
            // "give me all versions of this entity in order"
            $table->index(['entity_id', 'version_number']);

            // Compound index for current version lookup:
            // "give me the current canonical version of this entity"
            $table->index(['entity_id', 'is_current']);

            // Compound index for version zero lookup:
            // "give me the source canon reference for this entity"
            $table->index(['entity_id', 'is_version_zero']);

            // Compound index for hard iteration queries:
            // "give me all iterations of the Harry construct in order"
            $table->index(['source_entity_id', 'iteration_number']);

            // Compound index for failed iteration queries:
            // "show me all iterations that failed and why"
            $table->index(['version_type', 'version_state']);

            // Compound index for terminator queries:
            // "show me all iterations Seraphine has terminated"
            $table->index(['terminated_by_entity_id', 'version_state']);

            // Compound index for deprecated version audit:
            // "show me everything I've retconned and why"
            $table->index(['version_state', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versions_and_canon_states');
    }
};

/*
|--------------------------------------------------------------------------
| VERSION TYPE ENUM
|--------------------------------------------------------------------------
|
|   soft           — same continuous entity evolved
|                    Seraphine is one entity with many soft versions
|   hard_iteration — distinct instantiation, previous versions terminated
|                    Harry v1-v69 are hard iterations of one construct
|
|--------------------------------------------------------------------------
| VERSION STATE ENUM
|--------------------------------------------------------------------------
|
|   current          — active canonical version right now
|   archived         — was true at a point in time, entity moved on
|   deprecated       — retconned, no longer canon in any context
|   iteration_failed — deliberately terminated in-world
|
| In the UI:
|   current          → standard display
|   archived         → muted, marked with era range
|   deprecated       → strikethrough style, marked as retconned
|   iteration_failed → marked with termination era and reason
|
|--------------------------------------------------------------------------
| AUTOMATIC TRIGGER FIELDS
|--------------------------------------------------------------------------
|
| These field changes on the entity record trigger automatic
| canon state creation (controlled by notification_preferences
| in the settings table):
|
|   power_tier_ceiling    — Seraphine's ceiling changes at transformation
|   true_nature           — fundamental identity shift
|   status                — alive → dead → undead → transformed
|   control_state         — government captured or liberated
|
| When triggered automatically the system:
|   1. Copies the entire current entity record into entity_snapshot
|   2. Sets trigger_type: automatic
|   3. Sets triggered_by_field: the field that changed
|   4. Marks the previous current version as archived
|   5. Creates a dashboard notification
|
|--------------------------------------------------------------------------
| HARRY ITERATIONS — EXAMPLE RECORDS
|--------------------------------------------------------------------------
|
| Abstract parent entity: "The Harry Construct"
|   entity_type: constructed_intelligence
|   iteration_number: null (the concept, not an iteration)
|
| Harry v1 (failed):
|   entity_id: harry_v1_entity_id
|   version_type: hard_iteration
|   version_state: iteration_failed
|   iteration_number: 1
|   source_entity_id: harry_construct_entity_id
|   what_failed: "Developed independent moral framework
|                 incompatible with Seraphine's operational requirements.
|                 Began questioning the puppet cycle mechanism
|                 on ethical grounds."
|   retained_from_previous: null (first iteration)
|   failure_era: "Year 2 — Early Infrastructure"
|   terminated_by_entity_id: seraphine_entity_id
|
| Harry v69 (current):
|   entity_id: harry_v69_entity_id
|   version_type: hard_iteration
|   version_state: current
|   is_current: true
|   iteration_number: 69
|   source_entity_id: harry_construct_entity_id
|   retained_from_previous: "700 years of accumulated strategic
|                             knowledge. Pattern recognition across
|                             68 previous iterations. Knows he has
|                             been reset and why. Experiences current
|                             alignment as genuinely his own."
|   what_failed: null (has not failed)
|
|--------------------------------------------------------------------------
| VERSION ZERO — SOURCE CANON REFERENCE
|--------------------------------------------------------------------------
|
| Your Hermione diverges from canon Hermione at a specific point.
| Version zero is the source canon state before your AU begins.
|
| Hermione version zero:
|   is_version_zero: true
|   version_zero_confidence: developing
|   version_zero_notes: "Have captured physical profile and key
|                         canonical relationships. Psychological
|                         profile needs more depth. War trauma
|                         arc not fully researched yet."
|   entity_snapshot: { ...HP canon Hermione as you understand her }
|
| This record is editable — you refine it as your HP research deepens.
| The confidence field tracks how complete your source canon capture is.
|
*/
