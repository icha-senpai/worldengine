<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('collection_entities', 'matched_rule_snapshot')) {
            Schema::table('collection_entities', function (Blueprint $table) {
                $table->jsonb('matched_rule_snapshot')->nullable()->after('added_by_rule');
            });
        }

        if (Schema::hasColumn('collection_entities', 'added_by_rule')) {
            DB::statement('
                UPDATE collection_entities
                SET matched_rule_snapshot = COALESCE(matched_rule_snapshot, added_by_rule)
                WHERE added_by_rule IS NOT NULL
            ');

            Schema::table('collection_entities', function (Blueprint $table) {
                $table->dropColumn('added_by_rule');
            });

            Schema::table('collection_entities', function (Blueprint $table) {
                $table->boolean('added_by_rule')->default(false)->after('added_manually');
            });

            DB::statement('
                UPDATE collection_entities
                SET added_by_rule = true
                WHERE matched_rule_snapshot IS NOT NULL
            ');
        }

        Schema::table('collection_entities', function (Blueprint $table) {
            if (! Schema::hasColumn('collection_entities', 'sort_order')) {
                $table->integer('sort_order')->nullable()->after('role_in_collection');
            }
        });

        Schema::table('collection_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('collection_documents', 'sort_order')) {
                $table->integer('sort_order')->nullable()->after('role_in_collection');
            }
        });
    }

    public function down(): void
    {
        Schema::table('collection_entities', function (Blueprint $table) {
            if (Schema::hasColumn('collection_entities', 'sort_order')) {
                $table->dropColumn('sort_order');
            }

            if (Schema::hasColumn('collection_entities', 'added_by_rule')) {
                $table->dropColumn('added_by_rule');
            }
        });

        Schema::table('collection_entities', function (Blueprint $table) {
            if (! Schema::hasColumn('collection_entities', 'added_by_rule')) {
                $table->jsonb('added_by_rule')->nullable()->after('added_manually');
            }

            if (Schema::hasColumn('collection_entities', 'matched_rule_snapshot')) {
                $table->dropColumn('matched_rule_snapshot');
            }
        });

        Schema::table('collection_documents', function (Blueprint $table) {
            if (Schema::hasColumn('collection_documents', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
        });
    }
};
