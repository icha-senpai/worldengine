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
        Schema::create('connected_realms_achievement_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')
                ->constrained('connected_realms_players')
                ->cascadeOnDelete();
            $table->string('achievement_key');
            $table->string('achievement_label');
            $table->string('category');
            $table->jsonb('reward');
            $table->timestamp('claimed_at');
            $table->timestamps();

            $table->unique(['player_id', 'achievement_key'], 'connected_realms_achievement_claim_unique');
            $table->index(['player_id', 'claimed_at']);
            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connected_realms_achievement_claims');
    }
};
