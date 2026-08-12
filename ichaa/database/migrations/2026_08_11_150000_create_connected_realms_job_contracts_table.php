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
        Schema::create('connected_realms_job_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')
                ->constrained('connected_realms_players')
                ->cascadeOnDelete();
            $table->string('job_key');
            $table->string('job_name');
            $table->string('category');
            $table->string('skill');
            $table->string('objective_type');
            $table->jsonb('objective')->default(json_encode([]));
            $table->unsignedInteger('required_quantity')->default(1);
            $table->unsignedInteger('progress_quantity')->default(0);
            $table->string('status')->default('active');
            $table->timestamp('accepted_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['player_id', 'job_key', 'status']);
            $table->index(['player_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connected_realms_job_contracts');
    }
};
