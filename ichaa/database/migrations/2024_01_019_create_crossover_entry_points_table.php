<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crossover_entry_points', function (Blueprint $table) {
            $table->id();

            // Which source universe these entry rules apply to
            $table->string('source_universe')->unique();
            // Same enum as source_universes on entities
            // One record per universe — enforced by unique constraint

            // --- ENTRY MECHANISM ---

            $table->jsonb('entry_mechanism')->nullable(); // Tiptap JSON
            // How entities from this universe arrive in your AU
            // e.g. "Cosmere entities can cross via Perpendicularities
            //        when the cognitive and physical realms align near
            //        a convergence point in your AU"
            // e.g. "Warhammer 40K entities arrive through Warp rifts
            //        that open near areas of extreme psychic disturbance"

            // --- TRANSITION RULES ---
            // What changes when an entity crosses over
            // Each field addresses a different aspect of the entity

            $table->jsonb('power_transition_rules')->nullable(); // Tiptap JSON
            // What happens to their abilities on arrival
            // Does Investiture still work? At full strength or diminished?
            // Does the Warp still respond to a psyker?

            $table->jsonb('physical_transition_rules')->nullable(); // Tiptap JSON
            // What happens to their body
            // Do they arrive in their original form?
            // Do they age differently? Does biology change?

            $table->jsonb('memory_and_identity_rules')->nullable(); // Tiptap JSON
            // Do they remember their origin universe?
            // Is their sense of self intact?
            // Do they know they have crossed over?

            $table->jsonb('psychological_transition_rules')->nullable(); // Tiptap JSON
            // What happens to their mind and sense of self
            // A WoT channeler losing the One Power may experience
            // profound psychological destabilization
            // A 40K Space Marine in a universe without the Emperor's
            // light may experience existential crisis

            // --- CANON DEVIATION ---

            $table->text('canon_deviation_notes')->nullable();
            // How your AU crossover rules differ from anything
            // the source universe says about dimensional travel

            // --- KNOWN EXAMPLES ---

            $table->jsonb('known_examples')->nullable();
            // Array of entity IDs — confirmed crossover arrivals
            // from this universe in your AU

            // --- KNOWN ENTRY POINTS ---

            $table->jsonb('known_entry_points')->nullable();
            // Array of location or convergence point entity IDs
            // where crossings from this universe have occurred or
            // are known to be possible

            // --- STATUS ---

            $table->string('status')->default('theorized');
            // theorized  — rules predicted, no confirmed crossings yet
            // established — at least one confirmed crossing documented
            // documented  — multiple crossings, rules well understood

            $table->unsignedBigInteger('first_documented_crossing_event_id')->nullable();
            // The event entity that first documented a crossing
            // from this universe

            // --- RESTRICTIONS AND LIMITS ---

            $table->jsonb('restrictions')->nullable(); // Tiptap JSON
            // Are there limits on how many entities can cross?
            // Are certain entity types unable to cross?
            // Are there costs or consequences to crossing?

            $table->jsonb('return_rules')->nullable(); // Tiptap JSON
            // Can entities return to their origin universe?
            // Under what conditions?
            // What happens to them if they do?

            // --- VISIBILITY ---

            $table->string('visibility')->default('private');
            $table->string('content_classification')->default('restricted');

            // --- SOFT DELETE AND TIMESTAMPS ---

            $table->softDeletes();
            $table->timestamps();
        });

        // --- ADD DEFERRED FOREIGN KEY ---
        // Now that crossover_entry_points exists we can constrain
        // the crossover_entry_point_id on source_canon_reference

        Schema::table('source_canon_reference', function (Blueprint $table) {
            $table->foreign('crossover_entry_point_id')
                ->references('id')
                ->on('crossover_entry_points')
                ->nullOnDelete();
        });

        // --- INDEXES ---

        \DB::statement("
            ALTER TABLE crossover_entry_points
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(source_universe, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(canon_deviation_notes, '')), 'C')
            ) STORED
        ");

        \DB::statement('CREATE INDEX crossover_entry_points_search_vector_idx ON crossover_entry_points USING GIN (search_vector)');

        \DB::statement('CREATE INDEX crossover_entry_points_known_examples_idx ON crossover_entry_points USING GIN (known_examples)');
        \DB::statement('CREATE INDEX crossover_entry_points_known_entry_points_idx ON crossover_entry_points USING GIN (known_entry_points)');

        Schema::table('crossover_entry_points', function (Blueprint $table) {
            $table->index('source_universe');
            $table->index('status');
            $table->index('first_documented_crossing_event_id');
            $table->index('visibility');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('source_canon_reference', function (Blueprint $table) {
            $table->dropForeign(['crossover_entry_point_id']);
        });

        Schema::dropIfExists('crossover_entry_points');
    }
};

/*
|--------------------------------------------------------------------------
| STATUS ENUM
|--------------------------------------------------------------------------
|
|   theorized   — rules predicted by logic or existing canon knowledge
|                  no confirmed crossing from this universe yet
|   established — at least one confirmed crossing documented
|   documented  — multiple crossings, rules well understood
|
|--------------------------------------------------------------------------
| ONE RECORD PER UNIVERSE
|--------------------------------------------------------------------------
|
| The unique constraint on source_universe enforces one entry rules
| record per universe. This is intentional — the rules for how
| Cosmere entities cross over should be consistent regardless of
| which specific Cosmere entity is crossing.
|
| Individual entity variations from the standard rules are documented
| in the entity's own attributes or in canon_reference_entities
| divergence notes.
|
|--------------------------------------------------------------------------
| EXAMPLE — COSMERE CROSSOVER ENTRY POINT
|--------------------------------------------------------------------------
|
|   source_universe: "Cosmere"
|   status: established
|
|   entry_mechanism:
|     "Cosmere entities cross via Perpendicularities — points where
|      the Physical, Cognitive, and Spiritual Realms align. In your AU,
|      Perpendicularities near convergence points create corridors to
|      your universe. The Mirror Library is the primary stable corridor.
|      Unstable crossings occur near areas of extreme Investiture
|      concentration that resonate with your AU's convergence mechanics."
|
|   power_transition_rules:
|     "Investiture functions in your AU but draws from a diminished
|      pool — the Shards are not present to replenish it. A Mistborn
|      arrives with their existing metal reserves but cannot resupply
|      through normal Cosmere mechanisms. Stormlight does not renew
|      without Highstorms. Entities must find alternative power sources
|      or operate at reduced capacity."
|
|   physical_transition_rules:
|     "Physical form is preserved on crossing. Rosharans arrive in
|      their standard form including Shardblades and Shardplate if
|      bonded. Spren that have crossed the Perpendicularity with a
|      Radiant arrive with them but may behave unpredictably in a
|      universe outside the Cosmere's investiture framework."
|
|   memory_and_identity_rules:
|     "Memory is fully preserved. Entities know they have crossed
|      over and retain complete awareness of their origin universe.
|      Their oaths and Nahel bonds remain intact."
|
|   psychological_transition_rules:
|     "The absence of Stormlight renewal creates psychological strain
|      for Radiants who have become dependent on it. The silence of
|      a universe without Shards — no Rhythm of War, no Stormfather —
|      creates profound disorientation for entities attuned to Cosmere
|      metaphysics. A Bondsmith without access to the Stormfather
|      may experience their bond weakening over time."
|
|   restrictions:
|     "Shards themselves cannot cross — only their Vessels and
|      Invested entities. The Dor (Sel's combined Investiture)
|      cannot function outside Sel's Cognitive Realm and entities
|      dependent on it arrive powerless."
|
|   return_rules:
|     "Return crossing is possible via the same Perpendicularity
|      corridors. Entities who have been significantly changed by
|      your AU — particularly those who have interacted with Death
|      or the Mirror Library — may find their return crossing
|      unpredictable or impossible."
|
|--------------------------------------------------------------------------
| DEFERRED FK RESOLUTION
|--------------------------------------------------------------------------
|
| Migration 18 created source_canon_reference with crossover_entry_point_id
| as an unconstrained integer because this table did not exist yet.
| This migration adds the foreign key constraint now that the table exists.
|
| The down() method drops the constraint before dropping this table
| to avoid foreign key violation during rollback.
|
*/
