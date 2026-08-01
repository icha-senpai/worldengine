<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('notion_notes');
        Schema::dropIfExists('notion_sync_mappings');
    }

    public function down(): void
    {
        //
    }
};
