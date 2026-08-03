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
        Schema::create('connected_realms_leaderboard_seasons', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['active', 'starts_at']);
        });

        Schema::create('connected_realms_leaderboard_boards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')
                ->constrained('connected_realms_leaderboard_seasons')
                ->cascadeOnDelete();
            $table->string('key');
            $table->string('group_key');
            $table->string('group_label');
            $table->string('label');
            $table->text('description');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['season_id', 'key']);
            $table->index(['season_id', 'group_key', 'sort_order']);
        });

        Schema::create('connected_realms_leaderboard_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')
                ->constrained('connected_realms_leaderboard_boards')
                ->cascadeOnDelete();
            $table->foreignId('player_id')
                ->nullable()
                ->constrained('connected_realms_players')
                ->nullOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->string('display_name');
            $table->string('species_label')->nullable();
            $table->string('skill')->nullable();
            $table->string('skill_label')->nullable();
            $table->integer('score')->default(0);
            $table->string('score_label');
            $table->string('detail')->nullable();
            $table->jsonb('metrics')->default(json_encode([]));
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['board_id', 'rank']);
            $table->index(['board_id', 'score']);
            $table->index(['player_id', 'board_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connected_realms_leaderboard_entries');
        Schema::dropIfExists('connected_realms_leaderboard_boards');
        Schema::dropIfExists('connected_realms_leaderboard_seasons');
    }
};
