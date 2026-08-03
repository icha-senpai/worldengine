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
        Schema::create('connected_realms_tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')
                ->constrained('connected_realms_players')
                ->cascadeOnDelete();
            $table->string('slot');
            $table->string('skill');
            $table->string('item_key');
            $table->string('item_name');
            $table->string('rarity')->default('common');
            $table->unsignedSmallInteger('durability')->default(100);
            $table->jsonb('bonuses')->default(json_encode([]));
            $table->unsignedTinyInteger('rarity_progress')->default(0);
            $table->string('origin')->default('crafted');
            $table->string('status')->default('inventory');
            $table->string('maker_name')->nullable();
            $table->unsignedSmallInteger('tier_level')->default(0);
            $table->unsignedInteger('upgrade_count')->default(0);
            $table->unsignedInteger('tier_upgrade_count')->default(0);
            $table->unsignedInteger('rarity_upgrade_attempts')->default(0);
            $table->timestamps();

            $table->index(['player_id', 'status']);
            $table->index(['slot', 'status']);
            $table->index(['item_key', 'rarity']);
        });

        Schema::table('connected_realms_equipment_slots', function (Blueprint $table) {
            $table->foreignId('tool_id')
                ->nullable()
                ->after('id')
                ->constrained('connected_realms_tools')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connected_realms_equipment_slots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tool_id');
        });

        Schema::dropIfExists('connected_realms_tools');
    }
};
