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
        Schema::create('connected_realms_crafting_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')
                ->constrained('connected_realms_players')
                ->cascadeOnDelete();
            $table->string('recipe_key');
            $table->string('recipe_name');
            $table->string('skill');
            $table->jsonb('items_consumed');
            $table->jsonb('items_created');
            $table->unsignedInteger('experience_awarded')->default(0);
            $table->unsignedInteger('gold_cost')->default(0);
            $table->timestamps();

            $table->index(['player_id', 'recipe_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connected_realms_crafting_logs');
    }
};
