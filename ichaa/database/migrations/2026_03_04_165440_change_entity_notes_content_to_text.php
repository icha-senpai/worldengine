<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // search_vector is a generated column that depends on content.
        // Postgres requires dropping it before changing content's type.
        DB::statement('DROP INDEX IF EXISTS entity_notes_search_vector_idx');
        DB::statement('ALTER TABLE entity_notes DROP COLUMN IF EXISTS search_vector');
        DB::statement('ALTER TABLE entity_notes ALTER COLUMN content TYPE TEXT USING content::text');
        DB::statement("
            ALTER TABLE entity_notes
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                to_tsvector('english',
                    coalesce(note_label, '') || ' ' ||
                    coalesce(content, '')
                )
            ) STORED
        ");
        DB::statement('CREATE INDEX entity_notes_search_vector_idx ON entity_notes USING gin(search_vector)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS entity_notes_search_vector_idx');
        DB::statement('ALTER TABLE entity_notes DROP COLUMN IF EXISTS search_vector');
        DB::statement('ALTER TABLE entity_notes ALTER COLUMN content TYPE JSONB USING content::jsonb');
        DB::statement("
            ALTER TABLE entity_notes
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                to_tsvector('english',
                    coalesce(note_label, '') || ' ' ||
                    coalesce(content::text, '')
                )
            ) STORED
        ");
        DB::statement('CREATE INDEX entity_notes_search_vector_idx ON entity_notes USING gin(search_vector)');
    }
};