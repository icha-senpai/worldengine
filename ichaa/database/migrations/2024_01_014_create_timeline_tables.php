<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- CONCURRENCY GROUPS ---
        // Created first because timeline entries reference it
        // Groups events that happen simultaneously
        // "The Night of Hermione's Death" — multiple events, one moment

        Schema::create('concurrency_groups', function (Blueprint $table) {
            $table->id();

            // Optional human-readable label for this moment
            $table->string('name')->nullable();

            // The shared moment in your custom calendar
            $table->string('au_date')->nullable();

            // Description of what is happening across all concurrent events
            $table->jsonb('description')->nullable(); // Tiptap JSON

            // How narratively significant this moment is
            // Pivotal groups get expanded parallel view in the timeline UI
            $table->string('narrative_significance')->default('moderate');
            // low, moderate, high, pivotal

            $table->softDeletes();
            $table->timestamps();
        });

        // --- TIMELINE ---
        // Each row is one event's placement on one timeline
        // The same event can appear on multiple timelines
        // via the timeline_entities pivot

        Schema::create('timeline', function (Blueprint $table) {
            $table->id();

            // Which timeline this entry belongs to
            // Points to an entity of type: timeline
            // e.g. Main AU Timeline, Grey Line Observed Timeline
            $table->unsignedBigInteger('timeline_id');
            // FK constrained below after entities exists — it does

            // The event entity this entry places on the timeline
            // Points to an entity of type: event
            $table->foreignId('event_entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            // Optional short display label for the timeline view
            // Separate from the event entity's name
            // e.g. "The Night Everything Changed" vs the entity name
            // "Hermione Granger's Death — Year 0"
            $table->string('entry_label')->nullable();

            // --- DATING ---
            // Dual date system — your custom calendar and source equivalent

            $table->string('au_date')->nullable();
            // Your custom calendar date — freeform text until calendar
            // entity is fully designed, then validated against it

            $table->string('source_date')->nullable();
            // Equivalent date in the relevant source universe calendar
            // HP uses standard years, Cosmere uses in-world calendars

            $table->string('source_date_universe')->nullable();
            // Which universe's calendar the source_date uses
            // Same enum as source_universes on entities

            // How certain is the timing of this event
            $table->string('temporal_certainty')->default('documented');
            // documented  — specific date known, recorded in your world
            // estimated   — approximate era known, exact date uncertain
            // legendary   — existence known but timing entirely uncertain
            // primordial  — predates recorded history
            // atemporal   — exists outside time entirely

            $table->boolean('primordial_era')->default(false);
            // True for events that predate formal timeline documentation

            // --- POSITIONING ---
            // Numeric position for ordering when dates are uncertain
            // Lower numbers are earlier — use large gaps (1000, 2000, 3000)
            // to leave room for insertions between existing entries

            $table->integer('timeline_position')->default(0);

            // Which era this entry falls within
            // Points to an entity of type: era
            $table->unsignedBigInteger('era_entity_id')->nullable();

            // How dense this period of the timeline is
            // Controls visual compression in the timeline UI
            $table->string('time_density')->default('moderate');
            // sparse   — compress visually, little of note happening
            // moderate — standard display
            // dense    — expand visually, many significant events

            // --- CONCURRENCY ---
            // Links this entry to a group of simultaneous events

            $table->foreignId('concurrency_group_id')
                ->nullable()
                ->constrained('concurrency_groups')
                ->nullOnDelete();

            // --- CAUSALITY ---
            // Direct causal links between timeline entries
            // Separate from the general relationship system
            // These are specific narrative causality chains

            $table->jsonb('caused_by_event_ids')->default(json_encode([]));
            // Array of event entity IDs that directly caused this event

            $table->jsonb('caused_event_ids')->default(json_encode([]));
            // Array of event entity IDs this event directly caused

            $table->string('causality_type')->nullable();
            // direct        — would not have occurred without the cause
            // contributory  — made it more likely but not solely responsible
            // catalytic     — accelerated something already building
            // coincidental  — temporal proximity without direct causation

            $table->text('causality_notes')->nullable();
            // The specific mechanism of causation

            // --- NARRATIVE FIELDS ---

            // What the world believes happened
            $table->jsonb('public_narrative')->nullable(); // Tiptap JSON

            // What actually happened
            $table->jsonb('true_narrative')->nullable(); // Tiptap JSON

            $table->string('narrative_divergence')->nullable();
            // none, partial, complete

            $table->jsonb('truth_known_by')->nullable();
            // Array of entity IDs that know the true narrative

            $table->string('truth_revealed_at_era')->nullable();
            // When does this become public knowledge

            // --- VISIBILITY ---
            // Three-way visibility specific to timeline entries
            // An event can be public knowledge, secret, or author-only

            $table->string('visibility')->default('author_only');
            // public_knowledge — known within the world
            // secret           — exists but hidden from most characters
            // author_only      — only you know this happened

            $table->string('content_classification')->default('restricted');

            $table->softDeletes();
            $table->timestamps();
        });

        // --- TIMELINE ENTITIES ---
        // An event can belong to multiple timelines simultaneously
        // Hermione's death exists on the Main AU Timeline,
        // on Seraphine's personal arc timeline,
        // and on the Puppet Cycles meta timeline
        // Each with potentially different positioning and labels

        Schema::create('timeline_entities', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('timeline_id');
            // FK added below

            $table->foreignId('event_entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            // Position on this specific timeline
            // The same event may be positioned differently
            // on different timelines
            $table->integer('position')->default(0);

            // Optional label specific to this timeline's perspective
            $table->string('perspective_label')->nullable();

            // Notes about this event from this timeline's perspective
            $table->text('perspective_notes')->nullable();

            $table->timestamps();

            $table->unique(
                ['timeline_id', 'event_entity_id'],
                'timeline_entities_unique'
            );
        });

        // --- ADD FOREIGN KEY CONSTRAINTS ---
        // timeline_id on both tables points to entities
        // Added after both tables exist

        Schema::table('timeline', function (Blueprint $table) {
            $table->foreign('timeline_id')
                ->references('id')
                ->on('entities')
                ->cascadeOnDelete();

            $table->foreign('era_entity_id')
                ->references('id')
                ->on('entities')
                ->nullOnDelete();
        });

        Schema::table('timeline_entities', function (Blueprint $table) {
            $table->foreign('timeline_id')
                ->references('id')
                ->on('entities')
                ->cascadeOnDelete();
        });

        // --- INDEXES ---

        \DB::statement("
            ALTER TABLE concurrency_groups
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(name, '')), 'A')
            ) STORED
        ");

        \DB::statement('CREATE INDEX concurrency_groups_search_vector_idx ON concurrency_groups USING GIN (search_vector)');

        \DB::statement("
            ALTER TABLE timeline
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(entry_label, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(au_date, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(causality_notes, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(truth_revealed_at_era, '')), 'D')
            ) STORED
        ");

        \DB::statement('CREATE INDEX timeline_search_vector_idx ON timeline USING GIN (search_vector)');

        // JSONB indexes for causality chain queries
        \DB::statement('CREATE INDEX timeline_caused_by_idx ON timeline USING GIN (caused_by_event_ids)');
        \DB::statement('CREATE INDEX timeline_caused_events_idx ON timeline USING GIN (caused_event_ids)');
        \DB::statement('CREATE INDEX timeline_truth_known_by_idx ON timeline USING GIN (truth_known_by)');

        Schema::table('concurrency_groups', function (Blueprint $table) {
            $table->index('narrative_significance');
            $table->index('deleted_at');
        });

        Schema::table('timeline', function (Blueprint $table) {
            $table->index('timeline_id');
            $table->index('event_entity_id');
            $table->index('era_entity_id');
            $table->index('concurrency_group_id');
            $table->index('temporal_certainty');
            $table->index('primordial_era');
            $table->index('time_density');
            $table->index('causality_type');
            $table->index('narrative_divergence');
            $table->index('visibility');
            $table->index('content_classification');
            $table->index('deleted_at');

            // Compound index for the core timeline view query:
            // "give me all entries on this timeline in position order"
            $table->index(['timeline_id', 'timeline_position']);

            // Compound index for era-filtered timeline queries:
            // "show me all events in this era on this timeline"
            $table->index(['timeline_id', 'era_entity_id']);

            // Compound index for density-aware timeline rendering:
            // "give me all dense-period entries on this timeline"
            $table->index(['timeline_id', 'time_density', 'timeline_position']);

            // Compound index for secret event queries:
            // "show me all secret events the world doesn't know about"
            $table->index(['visibility', 'narrative_divergence']);

            // Compound index for atemporal event queries:
            // "show me all events that exist outside normal time"
            $table->index(['temporal_certainty', 'timeline_id']);
        });

        Schema::table('timeline_entities', function (Blueprint $table) {
            $table->index('timeline_id');
            $table->index('event_entity_id');

            // Compound index for event multi-timeline queries:
            // "which timelines does this event appear on"
            $table->index(['event_entity_id', 'timeline_id']);
        });
    }

    public function down(): void
    {
        Schema::table('timeline_entities', function (Blueprint $table) {
            $table->dropForeign(['timeline_id']);
        });

        Schema::table('timeline', function (Blueprint $table) {
            $table->dropForeign(['timeline_id']);
            $table->dropForeign(['era_entity_id']);
        });

        Schema::dropIfExists('timeline_entities');
        Schema::dropIfExists('timeline');
        Schema::dropIfExists('concurrency_groups');
    }
};

/*
|--------------------------------------------------------------------------
| TIMELINE POSITION CONVENTION
|--------------------------------------------------------------------------
|
| Use large gaps between positions to leave room for insertions.
| Recommended starting convention:
|
|   Primordial / Atemporal events:  position -999999 to -1
|   Pre-AU canon events:            position 0 to 999
|   Cycle 1:                        position 1000 to 1999
|   Cycle 2:                        position 2000 to 2999
|   ...and so on per cycle
|   The Revelation (Year 2000):     position 100000
|   Post-Revelation / Galactic:     position 100001 onward
|
| Within each range use increments of 10 initially.
| This leaves room to insert events between existing ones
| without renumbering the entire timeline.
|
|--------------------------------------------------------------------------
| DUAL DATE CONVENTION
|--------------------------------------------------------------------------
|
| au_date examples before custom calendar is designed:
|   "Year 0 — The Transformation"
|   "Cycle 12 — Opening"
|   "Cycle 12 — Year 3"
|   "Year 2000 — The Revelation"
|   "Post-Revelation Year 47"
|
| source_date examples:
|   "1998" with source_date_universe: "Harry Potter"
|   "Year 1173 of the Era of Solitude" with source_date_universe: "Cosmere"
|
|--------------------------------------------------------------------------
| ATEMPORAL EVENTS IN THE UI
|--------------------------------------------------------------------------
|
| Events with temporal_certainty: atemporal appear in a fixed header
| layer at the top of the timeline view — always present, not anchored
| to any specific point, visually distinct from temporally anchored events.
|
| The Mirror Library's existence is atemporal.
| Certain cosmological forces are atemporal.
| These are displayed permanently visible, not scrolled past.
|
|--------------------------------------------------------------------------
| CAUSALITY CHAIN EXAMPLE
|--------------------------------------------------------------------------
|
| Hermione's Death:
|   caused_by_event_ids: [seraphine_manipulation_event_id]
|   caused_event_ids:    [resurrection_stone_transfer_id,
|                         seraphine_transformation_id,
|                         johnny_flight_id]
|   causality_type: direct
|
| Seraphine's Transformation:
|   caused_by_event_ids: [hermiones_death_id,
|                         elder_wand_possession_id,
|                         cloak_possession_id]
|   caused_event_ids:    [edged_state_resolution_id,
|                         first_puppet_deployment_id]
|   causality_type: direct
|
| Following the chain forward from Hermione's death traces
| the entire causal architecture of Year 0 and beyond.
|
*/
