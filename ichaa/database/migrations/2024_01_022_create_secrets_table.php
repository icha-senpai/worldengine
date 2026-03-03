<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secrets', function (Blueprint $table) {
            $table->id();

            // --- IDENTITY ---

            $table->string('title');
            // Short label for dashboard and list views
            // "Seraphine Killed Hermione"
            // "The True Nature of the Mirror Library"
            // "Johnny Witnessed Year 0"

            $table->jsonb('secret_content')->nullable(); // Tiptap JSON
            // The full truth of what is being concealed

            $table->string('secret_type');
            // identity     — what someone or something truly is
            // event        — what actually happened
            // relationship — the true nature of a connection
            // power        — hidden abilities or power level
            // origin       — where something truly comes from
            // plan         — a hidden agenda or scheme
            // location     — where something or someone truly is
            // cosmological — fundamental truths about how the world works
            // other

            // --- PARTIES ---

            $table->jsonb('subject_entity_ids')->default(json_encode([]));
            // The entities this secret is about
            // Seraphine and Hermione are subjects of "Seraphine Killed Hermione"

            $table->jsonb('holder_entity_ids')->default(json_encode([]));
            // The entities actively concealing this secret
            // Not just passive unknowing — actively holding it hidden
            // Seraphine holds the secret of Hermione's death

            $table->jsonb('known_by_entity_ids')->default(json_encode([]));
            // The entities who know this secret
            // May include holders and others
            // Johnny knows. Harry v69 knows. Nobody else does.

            // --- EXPOSURE ---

            $table->string('exposure_risk');
            // low, medium, high, critical

            $table->jsonb('exposure_consequences')->nullable(); // Tiptap JSON
            // What happens when this secret surfaces
            // Who is affected, how, and how immediately

            $table->text('revelation_trigger')->nullable();
            // What would or did cause this secret to be exposed
            // "Johnny confronting Seraphine directly at the Revelation"
            // "The Mirror Library revealing its true function"

            // --- STATUS ---

            $table->string('status')->default('active');
            // active            — still concealed
            // partially_exposed — some know, not all
            // fully_exposed     — generally known
            // irrelevant        — no longer matters regardless of revelation

            $table->string('revealed_at_era')->nullable();
            // When it was exposed if status is not active

            // --- CONNECTIONS ---
            // Link to related knowledge states
            // Each entity that knows this secret should have a
            // corresponding knowledge_states record
            // These arrays provide quick reverse lookup

            $table->jsonb('related_knowledge_state_ids')->default(json_encode([]));
            // Array of knowledge_states IDs for entities who know this secret

            // Link to perception states that maintain this secret
            $table->jsonb('related_perception_state_ids')->default(json_encode([]));
            // Array of perception_states IDs connected to this secret

            // --- VISIBILITY ---
            // Visibility here controls whether this surfaces on your public site
            // Most secrets should stay private even for public entities
            // author_only classification means only you know this exists

            $table->string('visibility')->default('private');
            $table->string('content_classification')->default('restricted');

            // --- SOFT DELETE AND TIMESTAMPS ---

            $table->softDeletes();
            $table->timestamps();
        });

        // --- INDEXES ---

        \DB::statement("
            ALTER TABLE secrets
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(secret_type, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(revelation_trigger, '')), 'C')
            ) STORED
        ");

        \DB::statement('CREATE INDEX secrets_search_vector_idx ON secrets USING GIN (search_vector)');

        \DB::statement('CREATE INDEX secrets_subject_entities_idx ON secrets USING GIN (subject_entity_ids)');
        \DB::statement('CREATE INDEX secrets_holder_entities_idx ON secrets USING GIN (holder_entity_ids)');
        \DB::statement('CREATE INDEX secrets_known_by_idx ON secrets USING GIN (known_by_entity_ids)');
        \DB::statement('CREATE INDEX secrets_knowledge_states_idx ON secrets USING GIN (related_knowledge_state_ids)');
        \DB::statement('CREATE INDEX secrets_perception_states_idx ON secrets USING GIN (related_perception_state_ids)');

        Schema::table('secrets', function (Blueprint $table) {
            $table->index('secret_type');
            $table->index('exposure_risk');
            $table->index('status');
            $table->index('revealed_at_era');
            $table->index('visibility');
            $table->index('content_classification');
            $table->index('deleted_at');

            // Compound index for exposure risk dashboard panel:
            // "show me all active high-exposure-risk secrets"
            $table->index(['status', 'exposure_risk']);

            // Compound index for secret type filtering:
            // "show me all active identity secrets"
            $table->index(['secret_type', 'status']);

            // Compound index for revelation timeline:
            // "show me all secrets revealed during the Revelation era"
            $table->index(['status', 'revealed_at_era']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secrets');
    }
};

/*
|--------------------------------------------------------------------------
| SECRET TYPE ENUM
|--------------------------------------------------------------------------
|
|   identity     — what someone or something truly is
|                  "Seraphine is Death incarnate, not merely a witch"
|   event        — what actually happened
|                  "Seraphine killed Hermione deliberately"
|   relationship — the true nature of a connection
|                  "The Seraphine-Johnny dynamic is control, not enmity"
|   power        — hidden abilities, ceiling, or resources
|                  "Seraphine's true power ceiling post-transformation"
|   origin       — where something truly comes from
|                  "The Mirror Library predates all known universes"
|   plan         — a hidden agenda or ongoing scheme
|                  "The puppet cycles are a 2000-year alignment project"
|   location     — where something or someone truly is
|                  "Where Seraphine actually operates from"
|   cosmological — fundamental truths about how reality works
|                  "What the Mirror Library actually is"
|                  "The true mechanics of Death in your convergence"
|   other
|
|--------------------------------------------------------------------------
| DISTINCTION: SECRETS VS META SECRETS
|--------------------------------------------------------------------------
|
| Secrets (this table):
|   In-world truths that exist to be discovered.
|   Characters can learn them. Revealing them changes the world.
|   "Seraphine killed Hermione" is a secret in the world —
|   Johnny could find out. The Revelation is partly about this.
|
| Meta secrets (meta table, category: secrets_and_hidden_truth):
|   Author-level design facts not yet expressed in the world.
|   Characters cannot discover them because they are not yet canon.
|   "The galactic campaign will fail via a Cosmere mechanism
|    I haven't designed yet" is a meta secret — it exists only
|    in your planning space.
|
| When a meta secret becomes canonically real in the world,
| you create a secrets record for it and update the meta record
| to mark it as expressed.
|
|--------------------------------------------------------------------------
| HOLDER VS KNOWN BY
|--------------------------------------------------------------------------
|
| holder_entity_ids — entities actively working to keep it hidden.
|   They know it AND they are concealing it.
|   Seraphine holds the secret of Hermione's death.
|   Harry v69 holds it with her.
|
| known_by_entity_ids — all entities aware of the truth.
|   Includes holders plus any others who know but may not be
|   actively concealing.
|   Johnny knows Seraphine killed Hermione.
|   He is not a holder — he cannot confront it fully.
|   He is in known_by only.
|
| The distinction matters for plotting exposure risk:
|   High holder count with few known_by = tightly controlled
|   High known_by count with few holders = poorly controlled,
|   leaking at the edges
|
|--------------------------------------------------------------------------
| EXAMPLE RECORDS
|--------------------------------------------------------------------------
|
| Secret: "Seraphine Killed Hermione"
|   secret_type: event
|   subject_entity_ids: [seraphine_id, hermione_id]
|   holder_entity_ids: [seraphine_id, harry_v69_id]
|   known_by_entity_ids: [seraphine_id, harry_v69_id, johnny_id]
|   exposure_risk: critical
|   revelation_trigger: "Johnny confronting Seraphine directly at Year 2000
|                         or Harry v69's loyalty fracturing"
|   status: partially_exposed
|   — Johnny knows. The world does not yet.
|
| Secret: "The True Function of the Mirror Library"
|   secret_type: cosmological
|   subject_entity_ids: [mirror_library_id]
|   holder_entity_ids: []  ← nobody is actively concealing it
|   known_by_entity_ids: [seraphine_id]
|   exposure_risk: high
|   revelation_trigger: "Any entity with sufficient metaphysical
|                         awareness spending significant time within it"
|   status: active
|
| Secret: "The Puppet Cycles Are a 2000-Year Alignment Project"
|   secret_type: plan
|   subject_entity_ids: [seraphine_id]
|   holder_entity_ids: [seraphine_id, harry_v69_id]
|   known_by_entity_ids: [seraphine_id, harry_v69_id]
|   exposure_risk: critical
|   revelation_trigger: "The Revelation itself — Seraphine reveals this
|                         deliberately at Year 2000"
|   status: active  ← changes to fully_exposed at Year 2000
|
*/
