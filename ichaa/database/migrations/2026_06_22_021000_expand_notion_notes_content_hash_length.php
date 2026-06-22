<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notion_notes ALTER COLUMN content_hash TYPE VARCHAR(80)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notion_notes ALTER COLUMN content_hash TYPE VARCHAR(64)');
    }
};
