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
        Schema::table('connected_realms_action_logs', function (Blueprint $table) {
            $table->string('event_key')->nullable()->after('tool_item_name');
            $table->string('event_label')->nullable()->after('event_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connected_realms_action_logs', function (Blueprint $table) {
            $table->dropColumn(['event_key', 'event_label']);
        });
    }
};
