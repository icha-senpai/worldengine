<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE power_interactions ALTER COLUMN canon_certainty DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE power_interactions SET canon_certainty = COALESCE(NULLIF(canon_certainty, ''), 'unknown')");
        DB::statement('ALTER TABLE power_interactions ALTER COLUMN canon_certainty SET NOT NULL');
    }
};
