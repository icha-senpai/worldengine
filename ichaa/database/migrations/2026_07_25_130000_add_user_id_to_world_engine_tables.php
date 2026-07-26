<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->tables() as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'user_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $table): void {
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            });
        }

        $ownerId = DB::table('users')
            ->where('email', 'footmouthkick@pm.me')
            ->orWhere('name', 'footmouthkick')
            ->orderBy('id')
            ->value('id');

        if ($ownerId === null) {
            return;
        }

        foreach ($this->tables() as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_id')) {
                continue;
            }

            DB::table($table)
                ->whereNull('user_id')
                ->update(['user_id' => $ownerId]);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables()) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('user_id');
            });
        }
    }

    /**
     * @return array<int, string>
     */
    private function tables(): array
    {
        return [
            'entities',
            'entity_aliases',
            'entity_notes',
            'entity_questions',
            'media_references',
            'versions_and_canon_states',
            'relationships',
            'group_relationships',
            'group_relationship_entities',
            'faction_memberships',
            'collections',
            'collection_entities',
            'collection_documents',
            'glossary',
            'documents',
            'document_entities',
            'source_canon_reference',
            'canon_reference_entities',
            'crossover_entry_points',
            'timeline',
            'timeline_entities',
            'character_state_tracker',
            'state_relationships',
            'concurrency_groups',
            'power_interactions',
            'power_interaction_instances',
            'location_containment',
            'travel_routes',
            'location_control_history',
            'location_control_resistance_entities',
            'galactic_regions',
            'knowledge_states',
            'secrets',
            'perception_states',
            'meta',
            'meta_entities',
            'meta_group_relationships',
            'writing_pipeline',
            'pipeline_entities',
            'pipeline_group_relationships',
            'pipeline_documents',
            'session_log',
            'contradictions_and_conflicts',
        ];
    }
};
