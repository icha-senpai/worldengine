<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('power_interactions', function (Blueprint $table) {
            if (! Schema::hasColumn('power_interactions', 'resolution_notes')) {
                $table->jsonb('resolution_notes')->nullable()->after('unresolved_flag');
            }
        });
    }

    public function down(): void
    {
        Schema::table('power_interactions', function (Blueprint $table) {
            if (Schema::hasColumn('power_interactions', 'resolution_notes')) {
                $table->dropColumn('resolution_notes');
            }
        });
    }
};
