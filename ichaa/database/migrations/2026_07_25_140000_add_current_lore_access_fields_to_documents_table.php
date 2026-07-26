<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (! Schema::hasColumn('documents', 'access_level')) {
                $table->string('access_level')->nullable()->after('suppressed_by_entity_id');
            }

            if (! Schema::hasColumn('documents', 'suppression_notes')) {
                $table->jsonb('suppression_notes')->nullable()->after('access_level');
            }

            if (! Schema::hasColumn('documents', 'known_by_entity_ids')) {
                $table->jsonb('known_by_entity_ids')->default(json_encode([]))->after('suppression_notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (Schema::hasColumn('documents', 'known_by_entity_ids')) {
                $table->dropColumn('known_by_entity_ids');
            }

            if (Schema::hasColumn('documents', 'suppression_notes')) {
                $table->dropColumn('suppression_notes');
            }

            if (Schema::hasColumn('documents', 'access_level')) {
                $table->dropColumn('access_level');
            }
        });
    }
};
