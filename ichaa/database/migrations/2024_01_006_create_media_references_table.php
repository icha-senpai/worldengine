<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_references', function (Blueprint $table) {
            $table->id();

            // --- ATTACHMENT TARGETS ---
            // A media reference can attach to multiple parent types
            // All nullable — a reference attaches to exactly one target
            // Enforced at the application layer, not database layer
            // This avoids a polymorphic relationship which is harder to index

            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('group_relationship_id')->nullable();
            $table->unsignedBigInteger('collection_id')->nullable();
            $table->unsignedBigInteger('meta_id')->nullable();
            $table->unsignedBigInteger('timeline_entry_id')->nullable();
            $table->unsignedBigInteger('concurrency_group_id')->nullable();
            $table->unsignedBigInteger('source_canon_reference_id')->nullable();

            // --- MEDIA IDENTITY ---

            $table->string('title');
            $table->text('description')->nullable();

            // What kind of media this is
            $table->string('media_type');
            // image, video, audio, document, link

            // What function this media serves
            $table->string('purpose');
            // reference         — general reference material
            // inspiration       — mood or concept inspiration
            // portrait          — visual representation of a character or creature
            // map               — geographic or spatial reference
            // mood              — atmospheric or tonal reference
            // symbol            — icon or sigil reference
            // era_visual        — visual reference for a specific era's aesthetic
            // galactic_map      — spatial reference for galactic regions
            // power_system_diagram — visual explanation of a magic or power system
            // other

            // --- FILE LOCATION ---
            // One of these two will be populated, not both
            // file_path for local files on your machine
            // url for external web references

            // Local file path — absolute path on your local machine
            // e.g. /Users/icha/worldbuilding/references/seraphine-portrait.jpg
            $table->text('file_path')->nullable();

            // External URL for web references
            $table->text('url')->nullable();

            // File metadata — populated for local files
            $table->string('file_name')->nullable();
            $table->string('file_extension')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('mime_type')->nullable();

            // Image dimensions — populated for image type
            $table->integer('width_px')->nullable();
            $table->integer('height_px')->nullable();

            // --- ORDERING ---
            // Multiple media references per entity — order controls display sequence
            // Portrait images surface before mood references, etc.
            $table->integer('sort_order')->default(0);

            // Whether this is the primary/featured media for its parent
            // Only one record per parent should have this true
            // Enforced at application layer
            $table->boolean('is_primary')->default(false);

            // --- VISIBILITY AND CLASSIFICATION ---
            // A portrait of Seraphine marked explicit should not surface
            // in a general public view of her entity even if she is public

            $table->string('visibility')->default('private');
            $table->string('content_classification')->default('restricted');

            // --- SOFT DELETE AND TIMESTAMPS ---

            $table->softDeletes();
            $table->timestamps();
        });

        // --- INDEXES ---

        Schema::table('media_references', function (Blueprint $table) {
            $table->index('entity_id');
            $table->index('group_relationship_id');
            $table->index('collection_id');
            $table->index('meta_id');
            $table->index('timeline_entry_id');
            $table->index('concurrency_group_id');
            $table->index('source_canon_reference_id');
            $table->index('media_type');
            $table->index('purpose');
            $table->index('is_primary');
            $table->index('visibility');
            $table->index('content_classification');
            $table->index('deleted_at');

            // Compound index for the most common query:
            // "give me all media for this entity in order"
            $table->index(['entity_id', 'sort_order']);

            // Compound index for filtered media queries:
            // "give me all portraits for this entity"
            $table->index(['entity_id', 'purpose']);

            // Compound index for primary media lookup:
            // "give me the primary image for this entity"
            $table->index(['entity_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_references');
    }
};

/*
|--------------------------------------------------------------------------
| ATTACHMENT TARGET RULES
|--------------------------------------------------------------------------
|
| Exactly one attachment target should be populated per record.
| The application layer enforces this — a validation rule checks that
| exactly one of the nullable foreign key fields is non-null.
|
| We use explicit nullable columns rather than a polymorphic morph
| because it produces cleaner queries and cleaner indexes.
| With morphs you query on type + id. With explicit columns you
| query on a specific indexed column. Faster and more readable.
|
|--------------------------------------------------------------------------
| FILE PATH CONVENTION
|--------------------------------------------------------------------------
|
| For local files, use absolute paths.
| Recommended directory structure on your machine:
|
|   ~/worldbuilding/
|     references/
|       characters/
|         seraphine/
|       locations/
|         mirror-library/
|       eras/
|         puppet-cycles/
|       mood/
|       symbols/
|
| This keeps your reference files organized to match
| the structure of the database.
|
|--------------------------------------------------------------------------
| PURPOSE ENUM — when each type is used
|--------------------------------------------------------------------------
|
|   portrait          — attach to character, creature, or species entities
|   map               — attach to location entities or galactic regions
|   symbol            — attach to symbol meta notes or faction entities
|   era_visual        — attach to era entities or timeline entries
|   mood              — attach to any entity, collection, or meta note
|   inspiration       — attach to any entity or meta note
|   power_system_diagram — attach to system or magic entities
|   galactic_map      — attach to galactic_regions or location entities
|   reference         — general purpose, attach anywhere
|   other             — when nothing else fits
|
*/
