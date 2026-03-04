<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop generated search_vector which depends on context and resolution
        DB::statement('DROP INDEX IF EXISTS entity_questions_search_vector_idx');
        DB::statement('ALTER TABLE entity_questions DROP COLUMN IF EXISTS search_vector');

        DB::statement('ALTER TABLE entity_questions ALTER COLUMN context    TYPE TEXT USING context::text');
        DB::statement('ALTER TABLE entity_questions ALTER COLUMN resolution TYPE TEXT USING resolution::text');

        DB::statement("
            ALTER TABLE entity_questions
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                to_tsvector('english',
                    coalesce(question, '') || ' ' ||
                    coalesce(context, '') || ' ' ||
                    coalesce(resolution, '')
                )
            ) STORED
        ");
        DB::statement('CREATE INDEX entity_questions_search_vector_idx ON entity_questions USING gin(search_vector)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS entity_questions_search_vector_idx');
        DB::statement('ALTER TABLE entity_questions DROP COLUMN IF EXISTS search_vector');

        DB::statement('ALTER TABLE entity_questions ALTER COLUMN context    TYPE JSONB USING context::jsonb');
        DB::statement('ALTER TABLE entity_questions ALTER COLUMN resolution TYPE JSONB USING resolution::jsonb');

        DB::statement("
            ALTER TABLE entity_questions
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                to_tsvector('english',
                    coalesce(question, '') || ' ' ||
                    coalesce(context::text, '') || ' ' ||
                    coalesce(resolution::text, '')
                )
            ) STORED
        ");
        DB::statement('CREATE INDEX entity_questions_search_vector_idx ON entity_questions USING gin(search_vector)');
    }
};