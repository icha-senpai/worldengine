<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- LOCATION CONTAINMENT ---
        // Flexible graph containment between locations
        // Any location can be contained within multiple parent locations
        // simultaneously and through different containment types
        // London is physically in England, politically in the UK,
        // and during a puppet cycle politically in Seraphine's apparatus

        Schema::create('location_containment', function (Blueprint $table) {
            $table->id();

            $table->foreignId('child_location_entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            $table->foreignId('parent_location_entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            $table->string('containment_type');
            // physical    — geographically inside
            // planar      — exists within a cosmological plane
            // dimensional — exists within a pocket dimension
            // conceptual  — conceptually contained, not physically
            // political   — politically governed by
            // cultural    — culturally part of

            $table->text('containment_notes')->nullable();

            // Era scoping — containment relationships can change
            // England contains London always
            // Seraphine's apparatus contains the UK government only during cycles
            $table->string('era_start')->nullable();
            $table->string('era_end')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['child_location_entity_id', 'parent_location_entity_id', 'containment_type'],
                'location_containment_unique'
            );
        });

        // --- TRAVEL ROUTES ---
        // Method-aware travel between locations
        // Walking, apparition, Floo, broomstick, planar crossing,
        // time jump, convergence corridor — all on the same route record

        Schema::create('travel_routes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('origin_location_entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            $table->foreignId('destination_location_entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            $table->string('route_type');
            // overland, maritime, aerial, magical, temporal,
            // planar, dimensional, conceptual, unknown

            // Baseline travel duration for a standard traveler
            // with no special abilities
            $table->text('standard_duration')->nullable();

            $table->text('duration_notes')->nullable();
            // The caveats — "Apparition is instant but requires magical
            // ability and knowledge of destination. Muggle travel takes
            // 3 hours by car. Broomstick takes 45 minutes in clear weather."

            // All known travel methods for this route
            $table->jsonb('method_variants')->default(json_encode([]));
            // Array of method variant objects:
            // [{
            //   method:                    walking|apparition|floo|portkey|
            //                              broomstick|time_jump|planar_crossing|
            //                              convergence_corridor|other
            //   required_ability_or_artifact: text — what you need to use this method
            //   duration:                  text — how long this method takes
            //   conditions:                text — when this method works
            //   notes:                     text — additional details
            // }]

            // Hazards along this route
            $table->jsonb('hazards')->nullable();
            // Array of hazard objects:
            // [{ hazard_type, description, era_active, severity }]

            // How travel between these points changes across eras
            $table->jsonb('era_specific_variants')->nullable();
            // Array of era variant objects:
            // [{
            //   era:         text — which era this applies to
            //   description: text — how travel changed in this era
            //   new_methods: array — methods that became available
            //   lost_methods: array — methods that ceased to work
            // }]

            // Who knows this route exists
            // Empty array means publicly known
            $table->jsonb('known_by_entity_ids')->default(json_encode([]));

            // Who controls access to this route
            $table->unsignedBigInteger('controlled_by_entity_id')->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('inactive_reason')->nullable();

            $table->string('visibility')->default('private');
            $table->string('content_classification')->default('restricted');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['origin_location_entity_id', 'destination_location_entity_id', 'route_type'],
                'travel_routes_unique'
            );
        });

        // --- LOCATION CONTROL HISTORY ---
        // Political and military control over locations across time
        // Who controlled London during cycle 12
        // When did Earth transition to fully puppet-controlled
        // What was the resistance level during each cycle

        Schema::create('location_control_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('location_entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            // The entity exercising control
            $table->unsignedBigInteger('controlling_entity_id')->nullable();

            $table->string('control_type');
            // sovereign   — legitimate recognized control
            // occupied    — military control without legitimacy
            // contested   — multiple parties claim it
            // puppet      — nominal control by installed leader,
            //               real control held elsewhere
            // abandoned   — no active control
            // neutral     — deliberately neutral territory
            // protected   — under protection of an external power

            // Era when control began and ended
            $table->string('control_start_era')->nullable();
            $table->string('control_end_era')->nullable();
            // Null end era means currently controlled

            $table->boolean('is_current')->default(false);

            // How this control was established
            $table->text('how_control_was_established')->nullable();
            // conquest, inheritance, treaty, puppet_installation,
            // democratic, coup, other

            // How this control ended (if it has)
            $table->text('how_control_ended')->nullable();

            // Level of resistance to this control
            $table->string('resistance_level')->default('none');
            // none, minor, significant, active_conflict

            // The resistance organization if resistance_level is significant
            // or active_conflict — points to an organization entity
            $table->unsignedBigInteger('resistance_entity_id')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        // --- GALACTIC REGIONS ---
        // Bulk spatial data for galactic scale geography
        // Named significant locations within get full entity records
        // Everything else lives here as a region record

        Schema::create('galactic_regions', function (Blueprint $table) {
            $table->id();

            $table->string('region_name');

            $table->string('region_type');
            // star_system, sector, quadrant, arm, galaxy, void, other

            // Parent region for nested galactic geography
            // A star system belongs to a sector
            // A sector belongs to a quadrant
            $table->unsignedBigInteger('parent_region_id')->nullable();

            $table->text('approximate_scale')->nullable();
            // How large is this region — descriptive text
            // "Approximately 200 star systems spanning 3000 light years"

            $table->text('notable_features')->nullable();
            // What makes this region significant

            $table->integer('known_inhabited_systems')->nullable();
            // Rough count — not exact

            $table->string('strategic_significance')->default('none');
            // none, low, moderate, high, critical

            // Political control — who controls this region
            $table->unsignedBigInteger('controlling_entity_id')->nullable();
            $table->string('control_era_start')->nullable();
            $table->string('control_era_end')->nullable();

            // Whether your world has complete knowledge of this region
            $table->boolean('is_fully_mapped')->default(false);
            $table->text('mapping_notes')->nullable();
            // What is and isn't known about this region

            // Named significant locations within this region
            // that have full entity records
            $table->jsonb('connected_location_entity_ids')->default(json_encode([]));

            // Source universe this region belongs to
            // e.g. Warhammer 40K regions, Dune Imperium regions
            $table->string('source_universe')->nullable();

            $table->string('visibility')->default('private');
            $table->string('content_classification')->default('restricted');

            $table->timestamps();
            $table->softDeletes();
        });

        // --- ADD DEFERRED FOREIGN KEYS ---

        Schema::table('location_control_history', function (Blueprint $table) {
            $table->foreign('controlling_entity_id')
                ->references('id')
                ->on('entities')
                ->nullOnDelete();

            $table->foreign('resistance_entity_id')
                ->references('id')
                ->on('entities')
                ->nullOnDelete();
        });

        Schema::table('galactic_regions', function (Blueprint $table) {
            $table->foreign('parent_region_id')
                ->references('id')
                ->on('galactic_regions')
                ->nullOnDelete();

            $table->foreign('controlling_entity_id')
                ->references('id')
                ->on('entities')
                ->nullOnDelete();
        });

        Schema::table('travel_routes', function (Blueprint $table) {
            $table->foreign('controlled_by_entity_id')
                ->references('id')
                ->on('entities')
                ->nullOnDelete();
        });

        // --- INDEXES ---

        // Location containment
        Schema::table('location_containment', function (Blueprint $table) {
            $table->index('child_location_entity_id');
            $table->index('parent_location_entity_id');
            $table->index('containment_type');
            $table->index('is_active');
            $table->index('deleted_at');

            // Compound index for the most common containment query:
            // "what does this location physically contain"
            $table->index(['parent_location_entity_id', 'containment_type', 'is_active']);

            // Compound index for reverse containment query:
            // "what contains this location"
            $table->index(['child_location_entity_id', 'containment_type', 'is_active']);
        });

        // Travel routes
        \DB::statement('CREATE INDEX travel_routes_method_variants_idx ON travel_routes USING GIN (method_variants)');
        \DB::statement('CREATE INDEX travel_routes_known_by_idx ON travel_routes USING GIN (known_by_entity_ids)');

        Schema::table('travel_routes', function (Blueprint $table) {
            $table->index('origin_location_entity_id');
            $table->index('destination_location_entity_id');
            $table->index('route_type');
            $table->index('controlled_by_entity_id');
            $table->index('is_active');
            $table->index('deleted_at');

            // Compound index for route queries from a location:
            // "what routes leave from this location"
            $table->index(['origin_location_entity_id', 'is_active']);

            // Compound index for route queries to a location:
            // "what routes arrive at this location"
            $table->index(['destination_location_entity_id', 'is_active']);
        });

        // Location control history
        Schema::table('location_control_history', function (Blueprint $table) {
            $table->index('location_entity_id');
            $table->index('controlling_entity_id');
            $table->index('control_type');
            $table->index('is_current');
            $table->index('resistance_level');
            $table->index('resistance_entity_id');
            $table->index('deleted_at');

            // Compound index for current control queries:
            // "who currently controls this location"
            $table->index(['location_entity_id', 'is_current']);

            // Compound index for puppet control queries:
            // "show me all puppet-controlled locations right now"
            $table->index(['control_type', 'is_current']);

            // Compound index for controller queries:
            // "show me everything Seraphine controls"
            $table->index(['controlling_entity_id', 'is_current']);

            // Compound index for resistance queries:
            // "show me all locations with active conflict"
            $table->index(['resistance_level', 'control_type']);
        });

        // Galactic regions
        \DB::statement('CREATE INDEX galactic_regions_connected_locations_idx ON galactic_regions USING GIN (connected_location_entity_ids)');

        \DB::statement("
            ALTER TABLE galactic_regions
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(region_name, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(notable_features, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(mapping_notes, '')), 'C')
            ) STORED
        ");

        \DB::statement('CREATE INDEX galactic_regions_search_vector_idx ON galactic_regions USING GIN (search_vector)');

        Schema::table('galactic_regions', function (Blueprint $table) {
            $table->index('region_type');
            $table->index('parent_region_id');
            $table->index('strategic_significance');
            $table->index('controlling_entity_id');
            $table->index('is_fully_mapped');
            $table->index('source_universe');
            $table->index('deleted_at');

            // Compound index for nested galactic hierarchy queries:
            // "give me all sectors within this quadrant"
            $table->index(['parent_region_id', 'region_type']);

            // Compound index for strategic priority queries:
            // "show me all critical regions Seraphine controls"
            $table->index(['controlling_entity_id', 'strategic_significance']);
        });
    }

    public function down(): void
    {
        Schema::table('travel_routes', function (Blueprint $table) {
            $table->dropForeign(['controlled_by_entity_id']);
        });

        Schema::table('location_control_history', function (Blueprint $table) {
            $table->dropForeign(['controlling_entity_id']);
            $table->dropForeign(['resistance_entity_id']);
        });

        Schema::table('galactic_regions', function (Blueprint $table) {
            $table->dropForeign(['parent_region_id']);
            $table->dropForeign(['controlling_entity_id']);
        });

        Schema::dropIfExists('galactic_regions');
        Schema::dropIfExists('location_control_history');
        Schema::dropIfExists('travel_routes');
        Schema::dropIfExists('location_containment');
    }
};

/*
|--------------------------------------------------------------------------
| CONTAINMENT VERSUS CONTROL
|--------------------------------------------------------------------------
|
| These are different relationships and live in different tables.
|
| Containment (location_containment):
|   Physical, planar, dimensional, or conceptual nesting.
|   London is physically inside England. Always.
|   The Mirror Library is conceptually inside every universe. Always.
|   These are structural facts about how space is organized.
|
| Control (location_control_history):
|   Political or military authority over a location.
|   England is controlled by the UK government. Usually.
|   England is controlled by Seraphine's apparatus. During cycles.
|   These are temporal facts about who has power over a space.
|
| A location's containment rarely changes.
| A location's control changes constantly in your world.
|
|--------------------------------------------------------------------------
| TRAVEL ROUTE DIRECTIONALITY
|--------------------------------------------------------------------------
|
| Routes are stored as directed — origin to destination.
| If travel works both ways, create two records.
| Most mundane routes are bidirectional.
| Some routes in your world are one-way only:
|   - Certain convergence corridors may only allow one-direction crossing
|   - Temporal jumps may be unidirectional
|   - Some planar crossings collapse after use
|
| The unique constraint is on [origin, destination, route_type]
| so London → Hogwarts by broomstick and
| Hogwarts → London by broomstick are separate records.
|
|--------------------------------------------------------------------------
| GALACTIC SCALE GRADUATION
|--------------------------------------------------------------------------
|
| As your galactic campaign develops, specific star systems
| that become narratively significant graduate from galactic
| region entries to full location entity records.
|
| The graduation process:
|   1. Create a location entity for the specific location
|   2. Add its ID to the connected_location_entity_ids array
|      on its parent galactic_region record
|   3. Add spatial fields to the entity (space_type, coordinates, etc.)
|   4. The galactic_region retains the broader context
|      The location entity holds the specific details
|
*/
