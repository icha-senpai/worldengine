<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE power_interactions ALTER COLUMN directionality DROP NOT NULL');
        DB::statement('ALTER TABLE power_interactions ALTER COLUMN trigger_type DROP NOT NULL');
        DB::statement('ALTER TABLE power_interactions ALTER COLUMN trigger_frequency DROP NOT NULL');
        DB::statement('ALTER TABLE power_interactions ALTER COLUMN interaction_scale DROP NOT NULL');
        DB::statement('ALTER TABLE power_interactions ALTER COLUMN scale_variance DROP NOT NULL');
        DB::statement('ALTER TABLE power_interactions ALTER COLUMN knowledge_state DROP NOT NULL');
        DB::statement('ALTER TABLE power_interactions ALTER COLUMN danger_rating DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE power_interactions SET directionality = COALESCE(NULLIF(directionality, ''), 'contextual')");
        DB::statement("UPDATE power_interactions SET trigger_type = COALESCE(NULLIF(trigger_type, ''), 'other')");
        DB::statement("UPDATE power_interactions SET trigger_frequency = COALESCE(NULLIF(trigger_frequency, ''), 'rare')");
        DB::statement("UPDATE power_interactions SET interaction_scale = COALESCE(NULLIF(interaction_scale, ''), 'local')");
        DB::statement("UPDATE power_interactions SET scale_variance = COALESCE(NULLIF(scale_variance, ''), 'uniform')");
        DB::statement("UPDATE power_interactions SET knowledge_state = COALESCE(NULLIF(knowledge_state, ''), 'unknown')");
        DB::statement("UPDATE power_interactions SET danger_rating = COALESCE(NULLIF(danger_rating, ''), 'unknown_risk')");

        DB::statement('ALTER TABLE power_interactions ALTER COLUMN directionality SET NOT NULL');
        DB::statement('ALTER TABLE power_interactions ALTER COLUMN trigger_type SET NOT NULL');
        DB::statement('ALTER TABLE power_interactions ALTER COLUMN trigger_frequency SET NOT NULL');
        DB::statement('ALTER TABLE power_interactions ALTER COLUMN interaction_scale SET NOT NULL');
        DB::statement('ALTER TABLE power_interactions ALTER COLUMN scale_variance SET NOT NULL');
        DB::statement('ALTER TABLE power_interactions ALTER COLUMN knowledge_state SET NOT NULL');
        DB::statement('ALTER TABLE power_interactions ALTER COLUMN danger_rating SET NOT NULL');
    }
};
