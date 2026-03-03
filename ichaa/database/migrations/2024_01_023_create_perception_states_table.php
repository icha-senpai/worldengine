<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perception_states', function (Blueprint $table) {
            $table->id();

            // --- SUBJECT ---
            // What this perception gap is about
            // Exactly one of these should be populated per record
            // Enforced at application layer

            $table->string('subject_type');
            // entity, relationship, group_relationship,
            // event, document, faction, location

            // The ID of the subject record
            // Combined with subject_type to identify the subject
            $table->unsignedBigInteger('subject_id');

            // --- THE GAP ---

            // What the subject actually is
            $table->jsonb('true_state')->nullable(); // Tiptap JSON

            // What most entities perceive it to be
            $table->jsonb('perceived_state')->nullable(); // Tiptap JSON

            // How large is the gap between true and perceived
            $table->string('divergence_level');
            // none       — perception matches reality
            // surface    — minor differences, essentially accurate
            // significant — meaningful divergence, misleading
            // complete   — perceived state bears no resemblance to true state

            // --- MAINTENANCE ---
            // Who is actively maintaining the false perception
            // and how they are doing it

            $table->jsonb('maintained_by_entity_ids')->default(json_encode([]));
            // Array of entity IDs actively sustaining this false perception
            // Seraphine maintains most of the false perceptions in your world
            // Harry v69 maintains others on her behalf

            $table->text('maintenance_method')->nullable();
            // How the false perception is actively sustained
            // propaganda, memory_modification, strategic_information_control,
            // legilimency_based_manipulation, puppet_narrative_control,
            // deliberate_misdirection, social_pressure, other

            $table->string('maintenance_effort')->nullable();
            // passive  — self-sustaining, requires no active work
            // active   — requires ongoing deliberate maintenance
            // critical — would collapse immediately without constant effort

            // --- PERCEIVERS ---
            // Which entities hold the false perception
            // Empty array means universally perceived this way

            $table->jsonb('perceiving_entity_ids')->default(json_encode([]));
            // Array of entity IDs that hold the false perception
            // When this is empty it means the false perception is universal —
            // everyone except those who know the truth holds it

            $table->jsonb('immune_entity_ids')->default(json_encode([]));
            // Array of entity IDs that see through the false perception
            // Seraphine's collaborators and Harry v69
            // These are the only entities who hold the true perception

            // --- REVELATION ---

            $table->text('revelation_condition')->nullable();
            // What would cause the true state to become known
            // "Johnny confronting Seraphine with direct evidence"
            // "A Cosmere entity with sufficient Spiritual awareness
            //  examining the Mirror Library's true structure"

            $table->jsonb('revelation_consequence')->nullable(); // Tiptap JSON
            // What changes when the truth surfaces
            // Not just that it is revealed but what it means for the world
            // "When Seraphine's true nature is revealed at Year 2000
            //  the puppet cycles end. The world restructures around
            //  the knowledge that Death has been walking among them."

            $table->string('revelation_risk')->nullable();
            // low, medium, high, critical, inevitable

            $table->string('revealed_at_era')->nullable();
            // When the true state became known if it has

            $table->boolean('is_current')->default(true);
            // False means this perception gap no longer exists —
            // truth has been revealed or the false perception collapsed

            // --- CONNECTIONS ---
            // Link to related records for cross-referencing

            $table->unsignedBigInteger('related_secret_id')->nullable();
            // The secret that this perception state conceals or maintains

            $table->jsonb('related_knowledge_state_ids')->default(json_encode([]));
            // Knowledge state records for entities who hold the false perception

            // --- VISIBILITY ---

            $table->string('visibility')->default('private');
            $table->string('content_classification')->default('restricted');

            // --- SOFT DELETE AND TIMESTAMPS ---

            $table->softDeletes();
            $table->timestamps();
        });

        // --- INDEXES ---

        \DB::statement("
            ALTER TABLE perception_states
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(subject_type, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(maintenance_method, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(revelation_condition, '')), 'C')
            ) STORED
        ");

        \DB::statement('CREATE INDEX perception_states_search_vector_idx ON perception_states USING GIN (search_vector)');

        \DB::statement('CREATE INDEX perception_states_maintained_by_idx ON perception_states USING GIN (maintained_by_entity_ids)');
        \DB::statement('CREATE INDEX perception_states_perceiving_idx ON perception_states USING GIN (perceiving_entity_ids)');
        \DB::statement('CREATE INDEX perception_states_immune_idx ON perception_states USING GIN (immune_entity_ids)');
        \DB::statement('CREATE INDEX perception_states_knowledge_states_idx ON perception_states USING GIN (related_knowledge_state_ids)');

        Schema::table('perception_states', function (Blueprint $table) {
            $table->index('subject_type');
            $table->index('subject_id');
            $table->index('divergence_level');
            $table->index('maintenance_effort');
            $table->index('revelation_risk');
            $table->index('revealed_at_era');
            $table->index('is_current');
            $table->index('related_secret_id');
            $table->index('visibility');
            $table->index('deleted_at');

            // Compound index for the core subject query:
            // "what is the current perception gap for this entity"
            $table->index(['subject_type', 'subject_id', 'is_current']);

            // Compound index for critical maintenance queries:
            // "show me all perception gaps requiring critical active maintenance"
            $table->index(['maintenance_effort', 'is_current']);

            // Compound index for revelation risk dashboard:
            // "show me all current critical-risk perception gaps"
            $table->index(['revelation_risk', 'is_current']);

            // Compound index for divergence level queries:
            // "show me all complete divergence gaps — total false perceptions"
            $table->index(['divergence_level', 'is_current']);

            // Compound index for revelation timeline:
            // "show me all perception gaps revealed during the Revelation era"
            $table->index(['is_current', 'revealed_at_era']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perception_states');
    }
};

/*
|--------------------------------------------------------------------------
| SUBJECT TYPE ENUM
|--------------------------------------------------------------------------
|
|   entity            — false perception of what an entity is
|   relationship      — false perception of a pairwise connection
|   group_relationship — false perception of a group dynamic
|   event             — false perception of what happened
|   document          — false perception of a document's authenticity
|                        or meaning
|   faction           — false perception of an organization's nature
|                        or purpose
|   location          — false perception of a location's significance
|                        or what it contains
|
|--------------------------------------------------------------------------
| DIVERGENCE LEVEL ENUM
|--------------------------------------------------------------------------
|
|   none        — perception matches reality, record may not be needed
|   surface     — minor differences, observer is essentially accurate
|   significant — meaningful divergence, observer has wrong conclusions
|   complete    — perceived state has no resemblance to true state
|
|--------------------------------------------------------------------------
| MAINTENANCE EFFORT ENUM
|--------------------------------------------------------------------------
|
|   passive  — self-sustaining without active intervention
|              the world simply does not have access to the truth
|              no one is actively lying — truth is just hidden
|   active   — requires ongoing deliberate maintenance
|              someone must continue managing information flow
|   critical — would collapse immediately without constant effort
|              the weight of evidence against the false perception
|              is so great that only constant pressure maintains it
|
|--------------------------------------------------------------------------
| SERAPHINE'S APPARATUS — EXAMPLE RECORDS
|--------------------------------------------------------------------------
|
| Record 1 — Seraphine as political abstraction:
|   subject_type: entity
|   subject_id: seraphine_entity_id
|   true_state: "Death incarnate. Master of all three Hallows.
|                2000-year architect of a galactic alignment project.
|                The most powerful entity in the known convergence."
|   perceived_state: "Historical legend. May or may not be a real
|                      individual. Associated with catastrophic events
|                      separated by generations. Not understood as
|                      a continuous presence."
|   divergence_level: complete
|   maintained_by_entity_ids: [seraphine_id, harry_v69_id]
|   maintenance_method: puppet_narrative_control
|   maintenance_effort: active
|   perceiving_entity_ids: []  ← universal false perception
|   immune_entity_ids: [harry_v69_id, grey_line_inner_circle_ids]
|   revelation_condition: "The Revelation at Year 2000 — Seraphine
|                           reveals herself deliberately"
|   revelation_risk: inevitable
|   related_secret_id: puppet_cycles_secret_id
|
| Record 2 — The Seraphine-Johnny dynamic:
|   subject_type: relationship
|   subject_id: seraphine_johnny_relationship_id
|   true_state: "Seraphine controls Johnny completely. He is her
|                most important piece and her most carefully managed
|                liability. She tracks every move he makes."
|   perceived_state: "Ancient enemies. The greatest personal conflict
|                      in the post-war era. Two survivors of Year 0
|                      locked in permanent opposition."
|   divergence_level: complete
|   maintained_by_entity_ids: [seraphine_id]
|   maintenance_method: deliberate_misdirection
|   maintenance_effort: passive
|   — Johnny himself maintains this false perception by
|     behaving as though it is true
|   immune_entity_ids: [harry_v69_id]
|   revelation_condition: "Johnny arriving at full understanding
|                           of his own role in Seraphine's design"
|   revelation_consequence: "Johnny's entire framework for understanding
|                              himself collapses. His resistance was always
|                              part of the design. His agency was always
|                              permitted, never real."
|   revelation_risk: critical
|
| Record 3 — The United Earth Government during cycle 12:
|   subject_type: faction
|   subject_id: united_earth_government_id
|   true_state: "A puppet government. All major decisions
|                are routed through Harry v69 to Seraphine.
|                The elected leaders believe they govern.
|                They do not."
|   perceived_state: "A legitimate democratic government
|                      with genuine autonomous authority."
|   divergence_level: complete
|   maintained_by_entity_ids: [seraphine_id, harry_v69_id]
|   maintenance_method: puppet_narrative_control
|   maintenance_effort: active
|   immune_entity_ids: [seraphine_id, harry_v69_id]
|
|--------------------------------------------------------------------------
| THE IMMUNE LIST AS A NARRATIVE TOOL
|--------------------------------------------------------------------------
|
| immune_entity_ids is the list of who sees clearly.
| In most of Seraphine's perception gaps this list is very short:
|   - Seraphine herself
|   - Harry v69
|   - Possibly one or two Grey Line inner circle members
|
| The immune list growing — more entities seeing through the false
| perception — is a structural measure of how close any given
| false perception is to collapse.
|
| The revelation_risk field is partly a function of immune list size.
| When immune_entity_ids expands beyond those Seraphine controls,
| the revelation becomes inevitable rather than merely possible.
|
*/
