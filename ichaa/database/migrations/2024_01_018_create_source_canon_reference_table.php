<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_canon_reference', function (Blueprint $table) {
            $table->id();

            // --- HIERARCHY ---

            // Which source universe this reference belongs to
            $table->string('universe');
            // Same enum as source_universes on entities

            // Which level of the hierarchy this record sits at
            $table->string('level');
            // universe  — one record per source universe, broad overview
            // category  — multiple per universe, organized by domain
            // element   — granular records for specific canonical elements

            // Parent record in the hierarchy
            // Null for universe-level records
            // Category records point to their universe record
            // Element records point to their category record
            $table->unsignedBigInteger('parent_reference_id')->nullable();

            // --- IDENTITY ---

            $table->string('title');
            // e.g. "Harry Potter Universe"
            // e.g. "HP Magic Systems"
            // e.g. "Legilimency"

            $table->jsonb('content')->nullable(); // Tiptap JSON
            // The actual canon information at this level

            // --- UNIVERSE LEVEL FIELDS ---
            // Only populated for level: universe

            $table->text('universe_overview')->nullable();

            $table->string('universe_priority')->nullable();
            // peripheral, moderate, significant, primary

            $table->string('universe_depth_rating')->nullable();
            // surface, developing, solid, comprehensive

            $table->text('overall_divergence_summary')->nullable();
            // High level description of how your AU relates to
            // and diverges from this universe overall

            $table->jsonb('primary_elements_borrowed')->nullable();
            // Array of text — most significant things taken from this universe

            $table->jsonb('primary_divergences')->nullable();
            // Array of text — most significant ways your AU differs

            $table->unsignedBigInteger('crossover_entry_point_id')->nullable();
            // Links to crossover_entry_points table
            // How entities from this universe arrive in your AU
            // FK added after crossover_entry_points table exists

            // --- CATEGORY LEVEL FIELDS ---
            // Only populated for level: category

            $table->string('category_type')->nullable();
            // magic_system, political_structure, history, geography,
            // species, artifacts, characters, cosmology,
            // technology, culture, other

            $table->text('category_overview')->nullable();

            // --- ELEMENT LEVEL FIELDS ---
            // Only populated for level: element

            $table->string('element_type')->nullable();
            // character, artifact, location, magic_ability,
            // political_entity, species, event, concept, other

            $table->jsonb('canonical_properties')->nullable();
            // The actual documented properties of this element
            // in source canon as you understand them

            $table->text('first_appearance')->nullable();
            // Where this element first appears in the source material
            // e.g. "Harry Potter and the Philosopher's Stone, Chapter 1"

            $table->jsonb('source_material_references')->nullable();
            // Array of specific books, episodes, editions
            // where this is documented

            $table->unsignedBigInteger('au_entity_id')->nullable();
            // Which entity in your AU corresponds to this canonical element
            // Your Hermione's au_entity_id points to your Hermione entity
            // FK to entities table

            // --- CANON DISPUTE FIELDS ---

            $table->boolean('canon_disputed')->default(false);

            $table->text('dispute_description')->nullable();
            // What specifically is disputed in the source material

            $table->jsonb('dispute_sources')->nullable();
            // Array of source materials that contradict each other

            $table->text('your_ruling')->nullable();
            // Which version you're treating as canon for your AU

            // --- RESEARCH STATUS ---

            $table->string('research_status')->default('unstarted');
            // unstarted, rough, developing, solid, comprehensive

            $table->text('research_notes')->nullable();
            // What you know you're missing or uncertain about

            $table->date('last_researched_at')->nullable();

            $table->string('research_confidence')->nullable();
            // low, medium, high

            // --- VISIBILITY ---

            $table->string('visibility')->default('private');
            $table->string('content_classification')->default('restricted');

            // --- SOFT DELETE AND TIMESTAMPS ---

            $table->softDeletes();
            $table->timestamps();
        });

        // --- CANON REFERENCE ENTITIES ---
        // Connects source canon reference entries to AU entities
        // Your Hermione entity links back to the HP canon Hermione entry
        // The divergence notes document what specifically changed

        Schema::create('canon_reference_entities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('canon_reference_id')
                ->constrained('source_canon_reference')
                ->cascadeOnDelete();

            $table->foreignId('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            // What specifically is different in your AU version
            // from the source canon element
            $table->jsonb('divergence_notes')->nullable(); // Tiptap JSON

            $table->string('divergence_level')->nullable();
            // minimal        — borrowed almost intact
            // moderate       — significant changes but recognizable
            // significant    — heavily modified
            // complete_reimagining — only the name or concept remains

            // Whether this entity is your AU version of the canonical element
            // or merely references or is inspired by it
            $table->string('relationship_type')->default('au_version');
            // au_version   — this entity IS your version of the canonical element
            // inspired_by  — inspired by but not a direct version
            // references   — your entity references this canonical element
            //                without being a version of it

            $table->timestamps();

            $table->unique(
                ['canon_reference_id', 'entity_id'],
                'canon_reference_entities_unique'
            );
        });

        // --- ADD DEFERRED FOREIGN KEYS ---

        Schema::table('source_canon_reference', function (Blueprint $table) {
            $table->foreign('parent_reference_id')
                ->references('id')
                ->on('source_canon_reference')
                ->nullOnDelete();

            $table->foreign('au_entity_id')
                ->references('id')
                ->on('entities')
                ->nullOnDelete();
        });

        // --- INDEXES ---

        \DB::statement("
            ALTER TABLE source_canon_reference
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(universe, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(universe_overview, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(category_overview, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(first_appearance, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(your_ruling, '')), 'D') ||
                setweight(to_tsvector('english', coalesce(research_notes, '')), 'D')
            ) STORED
        ");

        \DB::statement('CREATE INDEX source_canon_search_vector_idx ON source_canon_reference USING GIN (search_vector)');

        \DB::statement('CREATE INDEX source_canon_canonical_properties_idx ON source_canon_reference USING GIN (canonical_properties)');
        \DB::statement('CREATE INDEX source_canon_borrowed_idx ON source_canon_reference USING GIN (primary_elements_borrowed)');
        \DB::statement('CREATE INDEX source_canon_divergences_idx ON source_canon_reference USING GIN (primary_divergences)');
        \DB::statement('CREATE INDEX source_canon_dispute_sources_idx ON source_canon_reference USING GIN (dispute_sources)');

        Schema::table('source_canon_reference', function (Blueprint $table) {
            $table->index('universe');
            $table->index('level');
            $table->index('parent_reference_id');
            $table->index('universe_priority');
            $table->index('universe_depth_rating');
            $table->index('category_type');
            $table->index('element_type');
            $table->index('au_entity_id');
            $table->index('canon_disputed');
            $table->index('research_status');
            $table->index('research_confidence');
            $table->index('crossover_entry_point_id');
            $table->index('visibility');
            $table->index('deleted_at');

            // Compound index for hierarchy traversal:
            // "give me all categories under this universe"
            $table->index(['parent_reference_id', 'level']);

            // Compound index for universe research overview:
            // "show me all elements in this universe that need research"
            $table->index(['universe', 'research_status']);

            // Compound index for disputed canon queries:
            // "show me all disputed elements in HP"
            $table->index(['universe', 'canon_disputed']);

            // Compound index for priority universe queries:
            // "show me all primary universes and their depth"
            $table->index(['universe_priority', 'universe_depth_rating']);

            // Compound index for element type queries:
            // "show me all character elements across all universes"
            $table->index(['level', 'element_type']);
        });

        Schema::table('canon_reference_entities', function (Blueprint $table) {
            $table->index('canon_reference_id');
            $table->index('entity_id');
            $table->index('divergence_level');
            $table->index('relationship_type');

            // Compound index for AU entity divergence queries:
            // "show me all source canon entries this entity diverges from"
            $table->index(['entity_id', 'divergence_level']);

            // Compound index for complete reimagining queries:
            // "show me everything we've completely reinvented"
            $table->index(['relationship_type', 'divergence_level']);
        });
    }

    public function down(): void
    {
        Schema::table('source_canon_reference', function (Blueprint $table) {
            $table->dropForeign(['parent_reference_id']);
            $table->dropForeign(['au_entity_id']);
        });

        Schema::dropIfExists('canon_reference_entities');
        Schema::dropIfExists('source_canon_reference');
    }
};

/*
|--------------------------------------------------------------------------
| HIERARCHY EXAMPLE — HARRY POTTER
|--------------------------------------------------------------------------
|
| Universe level (id: 1):
|   universe: "Harry Potter"
|   level: universe
|   title: "Harry Potter Universe"
|   universe_priority: primary
|   universe_depth_rating: developing
|   overall_divergence_summary: "AU begins at the Battle of Hogwarts.
|     Seraphine replaces canon Voldemort as the primary antagonist-turned-
|     architect. Most canon characters exist but are significantly altered
|     by 20 years of post-war trauma and Seraphine's operations."
|
| Category level (id: 2):
|   universe: "Harry Potter"
|   level: category
|   parent_reference_id: 1
|   title: "HP Magic Systems"
|   category_type: magic_system
|   category_overview: "Wand-based magic, Legilimency, Parseltongue,
|     Animagus transformation, Horcrux mechanics, Hallow properties"
|
| Category level (id: 3):
|   universe: "Harry Potter"
|   level: category
|   parent_reference_id: 1
|   title: "HP Key Artifacts"
|   category_type: artifacts
|
| Element level (id: 4):
|   universe: "Harry Potter"
|   level: element
|   parent_reference_id: 2  ← under HP Magic Systems
|   title: "Legilimency"
|   element_type: magic_ability
|   canonical_properties: {
|     "definition": "The ability to navigate through the layers of
|       a person's mind and extract thoughts, feelings, memories",
|     "requirements": "Typically requires wand and eye contact",
|     "known_practitioners": ["Voldemort", "Snape", "Dumbledore"],
|     "counter": "Occlumency"
|   }
|   au_entity_id: [your AU Legilimency system entity id]
|   research_status: solid
|
| Element level (id: 5):
|   universe: "Harry Potter"
|   level: element
|   parent_reference_id: 3  ← under HP Key Artifacts
|   title: "The Elder Wand"
|   element_type: artifact
|   au_entity_id: [your AU Elder Wand entity id]
|   canon_disputed: false
|   research_status: comprehensive
|
|--------------------------------------------------------------------------
| SELF-REFERENCING FK NOTE
|--------------------------------------------------------------------------
|
| parent_reference_id is a self-referencing foreign key.
| It points to another row in the same table.
| This is how the three-level hierarchy is implemented without
| needing three separate tables.
|
| The FK uses nullOnDelete — if a parent record is deleted,
| child records have their parent_reference_id set to null
| rather than being cascade deleted. This prevents accidentally
| deleting an entire universe's worth of research because you
| deleted the universe-level record.
|
| Rebuilding the hierarchy after a null parent is straightforward:
| query for records where universe matches and parent_reference_id is null
| to find orphaned records, then reassign parents.
|
*/
