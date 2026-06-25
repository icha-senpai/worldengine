<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_control_resistance_entities', function (Blueprint $table) {
            $table->foreignId('location_control_history_id')
                ->constrained('location_control_history')
                ->cascadeOnDelete();
            $table->foreignId('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            $table->primary(['location_control_history_id', 'entity_id'], 'location_control_resistance_entities_primary');
            $table->index('entity_id');
        });

        $rows = DB::table('location_control_history')
            ->whereNotNull('resistance_entity_id')
            ->get(['id', 'resistance_entity_id']);

        foreach ($rows as $row) {
            DB::table('location_control_resistance_entities')->insert([
                'location_control_history_id' => $row->id,
                'entity_id' => $row->resistance_entity_id,
            ]);
        }

        Schema::table('location_control_history', function (Blueprint $table) {
            $table->dropForeign(['resistance_entity_id']);
            $table->dropIndex(['resistance_entity_id']);
            $table->dropColumn('resistance_entity_id');
        });
    }

    public function down(): void
    {
        Schema::table('location_control_history', function (Blueprint $table) {
            $table->unsignedBigInteger('resistance_entity_id')->nullable();
        });

        $rows = DB::table('location_control_resistance_entities')
            ->orderBy('entity_id')
            ->get(['location_control_history_id', 'entity_id'])
            ->groupBy('location_control_history_id');

        foreach ($rows as $locationControlHistoryId => $entities) {
            DB::table('location_control_history')
                ->where('id', $locationControlHistoryId)
                ->update([
                    'resistance_entity_id' => $entities->first()->entity_id,
                ]);
        }

        Schema::table('location_control_history', function (Blueprint $table) {
            $table->foreign('resistance_entity_id')
                ->references('id')
                ->on('entities')
                ->nullOnDelete();
            $table->index('resistance_entity_id');
        });

        Schema::dropIfExists('location_control_resistance_entities');
    }
};
