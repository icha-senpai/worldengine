<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timeline', function (Blueprint $table) {
            if (! Schema::hasColumn('timeline', 'au_date_end')) {
                $table->string('au_date_end')->nullable()->after('au_date');
            }

            if (! Schema::hasColumn('timeline', 'dating_notes')) {
                $table->jsonb('dating_notes')->nullable()->after('temporal_certainty');
            }

            if (! Schema::hasColumn('timeline', 'is_atemporal')) {
                $table->boolean('is_atemporal')->default(false)->after('concurrency_group_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('timeline', function (Blueprint $table) {
            foreach (['au_date_end', 'dating_notes', 'is_atemporal'] as $column) {
                if (Schema::hasColumn('timeline', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
