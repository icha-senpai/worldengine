<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // search_vector on writing_pipeline does NOT reference content or notes
        // (it references title, pipeline_type, pipeline_stage, narrative_purpose,
        // arc_notes, how_used, how_changed, why_it_fits) so no need to drop it.
        // We can alter content and notes directly.
        DB::statement('ALTER TABLE writing_pipeline ALTER COLUMN content TYPE TEXT USING content::text');
        DB::statement('ALTER TABLE writing_pipeline ALTER COLUMN notes   TYPE TEXT USING notes::text');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE writing_pipeline ALTER COLUMN content TYPE JSONB USING content::jsonb');
        DB::statement('ALTER TABLE writing_pipeline ALTER COLUMN notes   TYPE JSONB USING notes::jsonb');
    }
};