<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revisions', function (Blueprint $table) {
            $table->id();
            $table->string('resource_type');
            $table->string('resource_id');
            $table->string('action');
            $table->json('before_payload')->nullable();
            $table->json('after_payload')->nullable();
            $table->json('diff_payload')->nullable();
            $table->text('reason')->nullable();
            $table->string('source')->default('mcp');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token_name')->nullable();
            $table->unsignedBigInteger('base_revision_id')->nullable();
            $table->unsignedBigInteger('restored_from_revision_id')->nullable();
            $table->timestamps();

            $table->index(['resource_type', 'resource_id']);
            $table->foreign('restored_from_revision_id')->references('id')->on('revisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revisions');
    }
};
