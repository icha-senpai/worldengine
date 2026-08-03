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
        Schema::create('connected_realms_equipment_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')
                ->constrained('connected_realms_players')
                ->cascadeOnDelete();
            $table->string('slot');
            $table->string('item_key');
            $table->string('item_name');
            $table->string('rarity')->default('common');
            $table->unsignedSmallInteger('durability')->default(100);
            $table->jsonb('bonuses')->default(json_encode([]));
            $table->timestamps();

            $table->unique(['player_id', 'slot']);
        });

        Schema::table('connected_realms_action_logs', function (Blueprint $table) {
            $table->string('tool_item_key')->nullable()->after('result_label');
            $table->string('tool_item_name')->nullable()->after('tool_item_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connected_realms_action_logs', function (Blueprint $table) {
            $table->dropColumn(['tool_item_key', 'tool_item_name']);
        });

        Schema::dropIfExists('connected_realms_equipment_slots');
    }
};
