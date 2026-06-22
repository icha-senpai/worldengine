<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notion_notes', function (Blueprint $table) {
            $table->id();
            $table->string('sync_resource')->nullable()->index();
            $table->string('notion_page_id')->unique();
            $table->morphs('noteable');
            $table->longText('content');
            $table->string('content_hash', 64)->index();
            $table->timestamp('notion_last_edited_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notion_notes');
    }
};
