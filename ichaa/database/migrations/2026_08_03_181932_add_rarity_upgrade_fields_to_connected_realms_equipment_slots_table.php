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
        Schema::table('connected_realms_equipment_slots', function (Blueprint $table) {
            $table->unsignedTinyInteger('rarity_progress')->default(0);
            $table->string('origin')->default('starter');
            $table->string('maker_name')->nullable();
            $table->unsignedSmallInteger('tier_level')->default(1);
            $table->unsignedInteger('upgrade_count')->default(0);
            $table->unsignedInteger('rarity_upgrade_attempts')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connected_realms_equipment_slots', function (Blueprint $table) {
            $table->dropColumn([
                'rarity_progress',
                'origin',
                'maker_name',
                'tier_level',
                'upgrade_count',
                'rarity_upgrade_attempts',
            ]);
        });
    }
};
