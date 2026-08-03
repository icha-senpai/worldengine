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
            $table->string('title')->nullable()->after('display_name');
            $table->string('pronouns')->nullable()->after('species');
            $table->string('home_region')->default('moonwake_coast')->after('pronouns');
            $table->jsonb('appearance')->default(json_encode([
                'body_style' => 'balanced',
                'palette' => 'moonlit',
                'hair_style' => 'short',
                'outfit' => 'traveler',
            ]))->after('home_region');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connected_realms_players', function (Blueprint $table) {
            $table->dropColumn([
                'title',
                'pronouns',
                'home_region',
                'appearance',
            ]);
        });
    }
};
