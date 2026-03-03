<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_states', function (Blueprint $table) {
            $table->id();

            // The entity who holds this knowledge or belief
            $table->foreignId('knower_entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            // --- SUBJECT ---
            // What this knowledge is about
            // Exactly one of these should be populated per record
            // Enforced at application layer

            $table->unsignedBigInteger('subject_entity_id')->nullable();
            // Knowledge about an entity
            // "Johnny knows Seraphine is Master of Death"

            $table->unsignedBigInteger('subject_relationship_id')->nullable();
            // Knowledge about a pairwise relationship
            // "Harry v69 knows the true nature of Seraphine-Johnny dynamic"

            $table->unsignedBigInteger('subject_group_relationship_id')->nullable();
            // Knowledge about a group dynamic
            // "Ginny suspects the triangle dynamic with Hermione and Johnny"

            $table->unsignedBigInteger('subject_event_id')->nullable();
            // Knowledge about a specific event
            // "Hermione knows about the Battle of the Mirror Library"

            $table->unsignedBigInteger('subject_secret_id')->nullable();
            // Direct link to a secret record
            // "Seraphine knows the cosmological secret of the Veil"

            // --- KNOWLEDGE CONTENT ---

            $table->string('knowledge_type');
            // true_nature      — knowledge of what something really is
            // secret           — knowledge of a hidden fact
            // public_fact      — knowledge of commonly known information
            // rumor            — unverified information believed to some degree
            // suspicion        — partial pattern recognition without confirmation
            // false_belief     — genuinely believes something untrue
            // prophecy_fragment — partial prophetic knowledge

            // The specific content of what they know or believe
            $table->jsonb('knowledge_content')->nullable(); // Tiptap JSON

            // How accurate is this knowledge
            $table->string('accuracy');
            // true             — they know the truth
            // partial          — they know part of the truth
            // false            — they believe something incorrect
            // distorted        — truth corrupted by perspective or manipulation
            // unknown_to_knower — they don't know how accurate it is

            // --- ACQUISITION ---

            $table->string('acquired_at_era')->nullable();
            // When they learned this

            $table->string('acquired_through');
            // observation       — witnessed directly
            // told_by           — informed by another entity
            // legilimency       — extracted via mind reading
            // prophecy          — revealed through prophetic vision
            // deduction         — reasoned out from available evidence
            // torture           — extracted under duress
            // ritual            — revealed through magical or ritual process
            // accidental        — discovered without intent
            // other

            $table->unsignedBigInteger('acquired_from_entity_id')->nullable();
            // Who told them or from whom they extracted this knowledge
            // Null for observation, deduction, or accidental discovery

            // --- CURRENT BELIEF STATE ---

            $table->string('current_belief_state');
            // believes          — accepts this as true
            // suspects          — partial confidence, not fully committed
            // doubts            — leans toward it being false
            // disbelieves       — actively rejects it
            // compartmentalizing — knows it is true but psychologically
            //                      refuses to integrate it
            //
            // compartmentalizing is distinct from doubts —
            // Johnny knows Seraphine chose this path deliberately
            // He cannot bring himself to fully accept it
            // He is compartmentalizing, not doubting

            // --- ACTION ---
            // Has this entity taken any action based on this knowledge
            // Johnny knows Seraphine killed Hermione
            // He acts on it by pursuing immortality — acted_on: true

            $table->boolean('acted_on')->default(false);

            $table->jsonb('action_notes')->nullable(); // Tiptap JSON
            // What action they took and when
            // Only populated when acted_on is true

            // --- ERA SCOPING ---
            // Knowledge states can change over time
            // Johnny's suspicion becomes certainty at the Revelation

            $table->string('valid_from_era')->nullable();
            $table->string('valid_until_era')->nullable();
            $table->boolean('is_current')->default(true);
            // False means this belief has been updated or superseded

            $table->unsignedBigInteger('superseded_by_knowledge_id')->nullable();
            // Points to the updated knowledge state record
            // Johnny's suspicion record is superseded by his certainty record

            // --- VISIBILITY ---

            $table->string('visibility')->default('private');
            $table->string('content_classification')->default('restricted');

            // --- SOFT DELETE AND TIMESTAMPS ---

            $table->softDeletes();
            $table->timestamps();
        });

        // --- INDEXES ---

        \DB::statement("
            ALTER TABLE knowledge_states
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(knowledge_type, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(acquired_through, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(acquired_at_era, '')), 'D')
            ) STORED
        ");

        \DB::statement('CREATE INDEX knowledge_states_search_vector_idx ON knowledge_states USING GIN (search_vector)');

        Schema::table('knowledge_states', function (Blueprint $table) {
            $table->index('knower_entity_id');
            $table->index('subject_entity_id');
            $table->index('subject_relationship_id');
            $table->index('subject_group_relationship_id');
            $table->index('subject_event_id');
            $table->index('subject_secret_id');
            $table->index('knowledge_type');
            $table->index('accuracy');
            $table->index('acquired_at_era');
            $table->index('acquired_through');
            $table->index('acquired_from_entity_id');
            $table->index('current_belief_state');
            $table->index('acted_on');
            $table->index('is_current');
            $table->index('superseded_by_knowledge_id');
            $table->index('visibility');
            $table->index('deleted_at');

            // Compound index for the core knowledge query:
            // "what does Johnny currently know or believe"
            $table->index(['knower_entity_id', 'is_current']);

            // Compound index for subject knowledge queries:
            // "who knows anything about Seraphine's true nature"
            $table->index(['subject_entity_id', 'knowledge_type', 'is_current']);

            // Compound index for false belief queries:
            // "who believes something false about this entity"
            $table->index(['subject_entity_id', 'accuracy']);

            // Compound index for acted-on queries:
            // "which entities have acted on what they know"
            $table->index(['knower_entity_id', 'acted_on']);

            // Compound index for compartmentalization queries:
            // "who knows something true but cannot integrate it"
            $table->index(['current_belief_state', 'accuracy']);

            // Compound index for information source queries:
            // "what has Seraphine caused others to know via Legilimency"
            $table->index(['acquired_from_entity_id', 'acquired_through']);

            // Compound index for era-scoped knowledge audit:
            // "what did any entity know during cycle 12"
            $table->index(['is_current', 'valid_from_era', 'valid_until_era']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_states');
    }
};

/*
|--------------------------------------------------------------------------
| KNOWLEDGE TYPE ENUM
|--------------------------------------------------------------------------
|
|   true_nature        — knowledge of what something really is
|   secret             — knowledge of a hidden fact
|   public_fact        — commonly known information
|   rumor              — unverified, believed to some degree
|   suspicion          — pattern recognition without confirmation
|   false_belief       — genuinely believes something untrue
|   prophecy_fragment  — partial prophetic knowledge
|
|--------------------------------------------------------------------------
| ACCURACY ENUM
|--------------------------------------------------------------------------
|
|   true               — they know the actual truth
|   partial            — they have part of the picture
|   false              — they believe something incorrect
|   distorted          — truth warped by perspective or manipulation
|   unknown_to_knower  — they cannot evaluate their own accuracy
|
|--------------------------------------------------------------------------
| CURRENT BELIEF STATE — COMPARTMENTALIZING
|--------------------------------------------------------------------------
|
| The compartmentalizing state deserves elaboration because it is
| the most psychologically specific value in this system.
|
| Doubting means: "I am not sure this is true"
| Compartmentalizing means: "I know this is true and I cannot face it"
|
| The difference is critical for your characters:
|
| Johnny — Seraphine's true motive:
|   accuracy: true
|   current_belief_state: compartmentalizing
|   — He knows she chose this path deliberately
|   — He cannot integrate that with his image of who she was
|   — He behaves as though it might still be otherwise
|
| Hermione — Harry's eventual fate:
|   accuracy: true
|   current_belief_state: compartmentalizing
|   acted_on: false
|   — She knows Harry will die
|   — She does not act on this knowledge
|   — That failure to act defines her relationship with grief
|
|--------------------------------------------------------------------------
| KNOWLEDGE SUPERSESSION CHAIN
|--------------------------------------------------------------------------
|
| When Johnny's suspicion about Seraphine becomes certainty:
|
|   Old record:
|     knower: Johnny
|     subject_entity: Seraphine
|     knowledge_type: suspicion
|     accuracy: partial
|     current_belief_state: suspects
|     is_current: false  ← marked false when superseded
|     superseded_by_knowledge_id: [new record id]
|     valid_until_era: "Year 2000 — The Revelation"
|
|   New record:
|     knower: Johnny
|     subject_entity: Seraphine
|     knowledge_type: true_nature
|     accuracy: true
|     current_belief_state: compartmentalizing
|     is_current: true
|     valid_from_era: "Year 2000 — The Revelation"
|
| The chain is preserved. His epistemic journey is fully traceable.
|
|--------------------------------------------------------------------------
| ACTED_ON — NARRATIVE WEIGHT
|--------------------------------------------------------------------------
|
| The acted_on field captures one of the most narratively significant
| questions in any story: what do people do with what they know?
|
| Hermione knows → does not act → defines her arc for 20 years
| Johnny knows   → acts        → drives his pursuit of immortality
| Harry v69 knows → acts with precision → enables Seraphine's operations
|
| Querying acted_on = false where accuracy = true surfaces all the
| characters who know something true and have not yet responded.
| That query is a map of your world's latent narrative tension.
|
*/
