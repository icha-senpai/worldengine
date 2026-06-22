<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notion_sync_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('sync_resource');
            $table->string('notion_page_id');
            $table->string('notion_parent_database_id')->nullable();
            $table->string('local_model_type');
            $table->unsignedBigInteger('local_model_id');
            $table->timestamp('notion_last_edited_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_payload_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['sync_resource', 'notion_page_id']);
            $table->index(['local_model_type', 'local_model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notion_sync_mappings');
    }
};
