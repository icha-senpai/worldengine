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
        Schema::table('connected_realms_market_listings', function (Blueprint $table) {
            $table->string('listing_type')->default('item')->after('seller_player_id');
            $table->foreignId('tool_id')
                ->nullable()
                ->after('listing_type')
                ->constrained('connected_realms_tools')
                ->nullOnDelete();
            $table->jsonb('tool_snapshot')->nullable()->after('unit_price');

            $table->index(['listing_type', 'status']);
        });

        Schema::table('connected_realms_market_transactions', function (Blueprint $table) {
            $table->string('listing_type')->default('item')->after('buyer_player_id');
            $table->foreignId('tool_id')
                ->nullable()
                ->after('listing_type')
                ->constrained('connected_realms_tools')
                ->nullOnDelete();
            $table->jsonb('tool_snapshot')->nullable()->after('total_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connected_realms_market_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tool_id');
            $table->dropColumn(['listing_type', 'tool_snapshot']);
        });

        Schema::table('connected_realms_market_listings', function (Blueprint $table) {
            $table->dropIndex(['listing_type', 'status']);
            $table->dropConstrainedForeignId('tool_id');
            $table->dropColumn(['listing_type', 'tool_snapshot']);
        });
    }
};
