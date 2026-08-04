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
        Schema::table('connected_realms_players', function (Blueprint $table) {
            $table->jsonb('reward_loadout')->default(json_encode([]))->after('appearance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connected_realms_players', function (Blueprint $table) {
            $table->dropColumn('reward_loadout');
        });
    }
};
