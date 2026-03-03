<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_notes', function (Blueprint $table) {
            $table->id();

            // The entity this note belongs to
            $table->foreignId('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            // --- NOTE CONTENT ---

            // Optional short label to distinguish multiple notes on one entity
            // e.g. "Transformation thoughts", "Johnny connection ideas", "Draft arc notes"
            $table->string('note_label')->nullable();

            // Rich text content stored as Tiptap JSON
            // This is freeform — no structure enforced
            $table->jsonb('content')->default(json_encode([]));

            // --- NOTE ORDER ---
            // Allows manual ordering of multiple notes on one entity
            // Lower numbers surface first
            $table->integer('sort_order')->default(0);

            // --- SOFT DELETE AND TIMESTAMPS ---
            // No visibility field — scratch pad notes are always private by definition
            // They are never exposed publicly under any circumstance

            $table->softDeletes();
            $table->timestamps();
        });

        // --- INDEXES ---

        // Full text search on note content
        // Allows searching your own scratch pad notes across all entities
        \DB::statement("
            ALTER TABLE entity_notes
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(note_label, '')), 'B') ||
                setweight(to_tsvector('english',
                    coalesce(
                        (content->>'text'),
                        ''
                    )
                ), 'C')
            ) STORED
        ");

        \DB::statement('CREATE INDEX entity_notes_search_vector_idx ON entity_notes USING GIN (search_vector)');

        Schema::table('entity_notes', function (Blueprint $table) {
            $table->index('entity_id');
            $table->index('deleted_at');

            // Compound index for the standard query:
            // "give me all notes for this entity in order"
            $table->index(['entity_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_notes');
    }
};

/*
|--------------------------------------------------------------------------
| DESIGN NOTES
|--------------------------------------------------------------------------
|
| No visibility field.
| Scratch pad notes are always and only yours.
| They never surface in public views, search results shown to others,
| or AI context. They are margin scribbles, not canon.
|
| No content_classification field.
| Same reason. These are private by architecture, not by setting.
|
| Multiple notes per entity are supported.
| Use note_label to distinguish them and sort_order to sequence them.
| Example for Seraphine:
|   Note 1 — label: "Transformation arc thoughts",    sort_order: 0
|   Note 2 — label: "Johnny dynamic ideas",           sort_order: 1
|   Note 3 — label: "Post-2000 era questions",        sort_order: 2
|
| Tiptap JSON structure note:
| The search vector attempts to extract raw text from content->>'text'
| This works if Tiptap stores a top-level text field.
| For production, consider a more robust text extraction approach
| once you see the actual Tiptap JSON shape your editor produces.
|
*/
