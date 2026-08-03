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
        Schema::create('connected_realms_job_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')
                ->constrained('connected_realms_players')
                ->cascadeOnDelete();
            $table->string('job_key');
            $table->string('job_name');
            $table->string('category');
            $table->jsonb('items_delivered');
            $table->jsonb('rewards')->default(json_encode([]));
            $table->unsignedInteger('experience_awarded')->default(0);
            $table->unsignedInteger('gold_awarded')->default(0);
            $table->timestamps();

            $table->index(['player_id', 'job_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connected_realms_job_completions');
    }
};
