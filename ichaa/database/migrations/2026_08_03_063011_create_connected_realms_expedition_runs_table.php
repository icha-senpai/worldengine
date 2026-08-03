<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('connected_realms_expedition_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')
                ->constrained('connected_realms_players')
                ->cascadeOnDelete();
            $table->string('expedition_key');
            $table->string('expedition_name');
            $table->string('status')->default('resolved');
            $table->jsonb('supplies_consumed')->default(json_encode([]));
            $table->jsonb('items_awarded')->default(json_encode([]));
            $table->unsignedInteger('experience_awarded')->default(0);
            $table->unsignedInteger('gold_awarded')->default(0);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['player_id', 'expedition_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connected_realms_expedition_runs');
    }
};
