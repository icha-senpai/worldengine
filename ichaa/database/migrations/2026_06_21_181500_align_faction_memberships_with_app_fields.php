<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faction_memberships', function (Blueprint $table) {
            if (! Schema::hasColumn('faction_memberships', 'joined_era')) {
                $table->string('joined_era')->nullable()->after('membership_status');
            }

            if (! Schema::hasColumn('faction_memberships', 'left_era')) {
                $table->string('left_era')->nullable()->after('joined_era');
            }

            if (! Schema::hasColumn('faction_memberships', 'is_undercover')) {
                $table->boolean('is_undercover')->default(false)->after('true_loyalty_entity_id');
            }
        });

        if (Schema::hasColumn('faction_memberships', 'joined_at_era')) {
            DB::statement('
                UPDATE faction_memberships
                SET joined_era = COALESCE(joined_era, joined_at_era)
                WHERE joined_at_era IS NOT NULL
            ');
        }

        if (Schema::hasColumn('faction_memberships', 'left_at_era')) {
            DB::statement('
                UPDATE faction_memberships
                SET left_era = COALESCE(left_era, left_at_era)
                WHERE left_at_era IS NOT NULL
            ');
        }

        DB::statement("
            UPDATE faction_memberships
            SET is_undercover = true
            WHERE membership_status = 'undercover'
        ");
    }

    public function down(): void
    {
        Schema::table('faction_memberships', function (Blueprint $table) {
            if (Schema::hasColumn('faction_memberships', 'is_undercover')) {
                $table->dropColumn('is_undercover');
            }

            if (Schema::hasColumn('faction_memberships', 'left_era')) {
                $table->dropColumn('left_era');
            }

            if (Schema::hasColumn('faction_memberships', 'joined_era')) {
                $table->dropColumn('joined_era');
            }
        });
    }
};
