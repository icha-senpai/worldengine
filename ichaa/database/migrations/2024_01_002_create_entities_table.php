<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table) {
            $table->id();

            // --- CORE IDENTITY ---

            $table->string('name');
            $table->string('public_title')->nullable(); // Separate display title if published

            // Entity type — locked enum, 39 types across 9 categories
            $table->string('entity_type'); // See full enum below in comment block

            // Entity sub-type — narrows the type further
            $table->string('entity_sub_type')->nullable();

            // Summary — manually written 2-3 sentence description
            // This is what shows in search results and entity browser cards
            $table->text('summary')->nullable();
            $table->text('public_summary')->nullable(); // Separate summary for published entities

            // --- SOURCE UNIVERSE ---

            // Multi-select — array of source universe tags
            // e.g. ["Harry Potter", "Native"] or ["Cosmere", "Harry Potter"]
            $table->jsonb('source_universes')->default(json_encode([]));

            // Origin type — how this entity relates to its source universe(s)
            $table->string('origin_type')->default('native'); // native, canonical, hybrid

            // Canon deviation — quick rating plus freeform note
            $table->string('canon_deviation')->nullable(); // low, medium, high
            $table->text('origin_notes')->nullable(); // Freeform notes on what was borrowed and changed

            // --- POWER TIERS ---
            // Three separate tiers as designed
            // All nullable — not everything has a power rating

            $table->string('power_tier_ceiling')->nullable();
            // street_level, regional, national, continental, planetary, cosmic, multiversal

            $table->string('power_tier_operating')->nullable();
            // Same scale — where they actually function day to day

            $table->string('power_tier_influence')->nullable();
            // personal, local, factional, regional, national, global, civilizational, universal

            // --- STATUS ---

            // Universal status
            $table->string('status')->default('concept');
            // concept, developing, draft, active, dormant, archived, deprecated

            // Type-specific secondary status — populated based on entity_type
            $table->string('type_status')->nullable();
            // Characters: alive, dead, undead, transformed, unknown
            // Events: planned, occurring, concluded, unresolved
            // Factions/orgs: active, dissolved, dormant, absorbed
            // Documents: draft, ratified, repealed, lost
            // Governments/nations: see control_state below

            // --- VISIBILITY AND CLASSIFICATION ---

            $table->string('visibility')->default('private'); // private, unlisted, public
            $table->string('content_classification')->default('restricted'); // general, mature, explicit, restricted
            $table->timestamp('published_at')->nullable(); // When this was made public

            // --- PERCEPTION LAYER ---
            // The duality between what the world sees and what actually is
            // Used for characters, factions, organizations, governments

            $table->jsonb('public_persona')->nullable();   // What the world sees
            $table->jsonb('true_nature')->nullable();      // What they actually are
            $table->string('persona_divergence')->nullable(); // none, surface, significant, complete
            $table->jsonb('known_by')->nullable();         // Entity IDs that know the true nature

            // --- GOVERNMENT AND NATION SPECIFIC ---
            // Control state for governments and nations
            // Used to model Seraphine's puppet apparatus system

            $table->string('control_state')->nullable();
            // sovereign, infiltrated, captured, puppet, collapsed

            $table->unsignedBigInteger('controlling_entity_id')->nullable();
            // Points to the entity that actually controls this government or nation

            // --- SPATIAL ---
            // Location-specific fields
            // Only populated for entity_type: location

            $table->string('space_type')->nullable();
            // physical, planar, dimensional, conceptual, transient, atemporal

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedBigInteger('planet_entity_id')->nullable();
            $table->unsignedBigInteger('plane_entity_id')->nullable();
            $table->text('position_relative_to_anchor')->nullable();
            $table->text('entry_conditions')->nullable();
            $table->text('boundary_description')->nullable();
            $table->text('existence_conditions')->nullable();
            $table->jsonb('known_manifestation_points')->nullable();
            $table->text('movement_pattern')->nullable();
            $table->jsonb('position_history')->nullable();
            $table->text('nature_description')->nullable();
            $table->text('access_method')->nullable();
            $table->unsignedBigInteger('galactic_region_id')->nullable();

            // --- CONSTRUCTED INTELLIGENCE SPECIFIC ---
            // For Harry and other magically created synthetic beings
            // Stored in attributes JSONB but key fields surfaced here for querying

            $table->integer('iteration_number')->nullable();
            $table->integer('previous_iterations_count')->nullable();
            $table->unsignedBigInteger('source_entity_id')->nullable();
            // Points to conceptual parent entity for hard iteration tracking
            // Harry v1 through v69 all point back to the abstract "Harry" entity

            // --- FLEXIBLE ATTRIBUTES ---
            // Everything type-specific lives here
            // Pre-populated from entity_type_templates in settings
            // Fully editable and extensible per entity

            $table->jsonb('attributes')->default(json_encode([]));

            // --- COMPLETION INDICATOR ---
            // Calculated fields showing how developed this entity is
            // Updated automatically when related records are created

            $table->boolean('has_attributes')->default(false);
            $table->boolean('has_relationships')->default(false);
            $table->boolean('has_timeline_entries')->default(false);
            $table->boolean('has_documents')->default(false);
            $table->boolean('has_state_snapshots')->default(false);
            $table->boolean('has_aliases')->default(false);
            $table->boolean('has_media')->default(false);

            // Completion score — calculated 0-100 based on above booleans and type
            $table->integer('completion_score')->default(0);

            // --- SOFT DELETE AND TIMESTAMPS ---

            $table->softDeletes(); // deleted_at
            $table->timestamps();  // created_at, updated_at
        });

        // --- INDEXES ---
        // Chosen for the queries you'll actually run most often

        // Full text search index across name and summary
        // PostgreSQL GIN index for JSONB and tsvector search
        \DB::statement("
            ALTER TABLE entities
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(name, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(summary, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(public_summary, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(origin_notes, '')), 'C')
            ) STORED
        ");

        \DB::statement('CREATE INDEX entities_search_vector_idx ON entities USING GIN (search_vector)');

        // JSONB indexes for source_universes and attributes filtering
        \DB::statement('CREATE INDEX entities_source_universes_idx ON entities USING GIN (source_universes)');
        \DB::statement('CREATE INDEX entities_attributes_idx ON entities USING GIN (attributes)');
        \DB::statement('CREATE INDEX entities_known_by_idx ON entities USING GIN (known_by)');

        // Standard indexes for common filter queries
        Schema::table('entities', function (Blueprint $table) {
            $table->index('entity_type');
            $table->index('entity_sub_type');
            $table->index('status');
            $table->index('visibility');
            $table->index('content_classification');
            $table->index('origin_type');
            $table->index('control_state');
            $table->index('controlling_entity_id');
            $table->index('source_entity_id');
            $table->index('completion_score');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entities');
    }
};

/*
|--------------------------------------------------------------------------
| ENTITY TYPE ENUM — 39 types across 9 categories
|--------------------------------------------------------------------------
|
| People & Identity:
|   character, species, bloodline, family, role, title
|
| World & Geography:
|   location, era, convergence_point, timeline
|
| Nature & Ecology:
|   flora, fauna, creature, material, resource, substance
|
| Systems & Forces:
|   system, technology, ritual, condition, concept
|
| Society & Civilization:
|   culture, religion, language, social_structure, philosophy, organization
|
| Governance & Power:
|   nation, government
|
| History & Time:
|   event
|
| Objects & Artifacts:
|   artifact, currency, industry
|
| Created Beings:
|   constructed_intelligence, golem, homunculus, resurrection, hybrid_created
|
| Crossover Specific:
|   convergence_artifact, crossover_rule
|
|--------------------------------------------------------------------------
| ENTITY SUB-TYPES — selected examples
|--------------------------------------------------------------------------
|
| location sub-types:
|   region, city, building, plane, biome, trade_route, sanctuary
|
| organization sub-types:
|   military, intelligence, guild, movement, humanitarian, shadow_structure
|
| system sub-types:
|   magic, technology, afterlife, education, calendar, psychological, social
|
| event sub-types:
|   conflict, atrocity, turning_point
|
| timeline sub-types: (timeline is an entity type)
|   main_au, source_canon_reference, parallel, personal, meta_observational
|
|--------------------------------------------------------------------------
| POWER TIER ENUMS
|--------------------------------------------------------------------------
|
| Ceiling and Operating tiers:
|   street_level, regional, national, continental,
|   planetary, cosmic, multiversal, transcendent
|
| Influence tier:
|   personal, local, factional, regional, national,
|   global, civilizational, universal
|
|--------------------------------------------------------------------------
| SOURCE UNIVERSE ENUM — 21 values
|--------------------------------------------------------------------------
|
|   Native, Harry Potter, Lord of the Rings, Tolkien Extended,
|   Warhammer 40K, Twilight, Fifty Shades, Stormlight Archive,
|   Cosmere, Wheel of Time, Dune, Narnia, Tom Clancy,
|   From Blood and Ash, Kushiels Legacy, Broken Earth,
|   Malazan, Kingkiller Chronicle, The Witcher,
|   Game of Thrones, Other
|
*/
