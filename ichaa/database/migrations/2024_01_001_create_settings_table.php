<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            // Display
            $table->string('dashboard_theme')->default('dark'); // light, dark, system

            // Privacy defaults — everything private and restricted unless you explicitly change it
            $table->string('default_visibility')->default('private'); // private, unlisted, public
            $table->string('default_content_classification')->default('restricted'); // general, mature, explicit, restricted

            // Quick create menu — which entity types appear in the floating create button
            $table->jsonb('quick_create_entity_types')->default(json_encode([
                'character',
                'location',
                'event',
                'faction',
                'artifact',
            ]));

            // Per-section display preferences
            $table->jsonb('search_preferences')->default(json_encode([
                'results_per_page' => 20,
                'default_sort' => 'relevance',
                'show_content_classification_badge' => true,
                'show_source_universe_badge' => true,
            ]));

            $table->jsonb('timeline_display_preferences')->default(json_encode([
                'default_timeline' => 'main_au',
                'show_secret_events' => true,
                'show_author_only_events' => true,
                'expand_pivotal_concurrency_groups' => true,
            ]));

            $table->jsonb('pipeline_display_preferences')->default(json_encode([
                'default_view' => 'kanban',
                'show_word_count' => true,
                'show_reading_time' => true,
                'default_stage_filter' => null,
            ]));

            // Entity type attribute templates
            // Stores your custom JSONB skeleton per entity type
            // These pre-populate the attributes field when creating a new entity
            $table->jsonb('entity_type_templates')->default(json_encode([]));

            // Notification and flag preferences
            $table->jsonb('notification_preferences')->default(json_encode([
                'flag_unresolved_power_interactions' => true,
                'flag_blocking_entity_questions' => true,
                'flag_blocking_contradictions' => true,
                'flag_deprecated_canon_states' => true,
                'auto_save_canon_state_on_power_tier_change' => true,
                'auto_save_canon_state_on_true_nature_change' => true,
                'auto_save_canon_state_on_status_change' => false,
            ]));

            $table->timestamp('updated_at')->nullable();
        });

        // Insert the single settings row with all defaults
        DB::table('settings')->insert([
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
