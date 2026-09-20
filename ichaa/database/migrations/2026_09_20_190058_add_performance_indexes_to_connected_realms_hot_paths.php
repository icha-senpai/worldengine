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
            $table->index(['player_id', 'created_at'], 'cr_action_logs_player_created_idx');
        });

        Schema::table('connected_realms_crafting_logs', function (Blueprint $table) {
            $table->index(['player_id', 'created_at'], 'cr_crafting_logs_player_created_idx');
        });

        Schema::table('connected_realms_job_completions', function (Blueprint $table) {
            $table->index(['player_id', 'created_at'], 'cr_job_completions_player_created_idx');
        });

        Schema::table('connected_realms_expedition_runs', function (Blueprint $table) {
            $table->index(['player_id', 'created_at'], 'cr_expedition_runs_player_created_idx');
        });

        Schema::table('connected_realms_market_listings', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'cr_market_listings_status_created_idx');
            $table->index(['seller_player_id', 'created_at'], 'cr_market_listings_seller_created_idx');
        });

        Schema::table('connected_realms_market_transactions', function (Blueprint $table) {
            $table->index('created_at', 'cr_market_transactions_created_idx');
            $table->index(['seller_player_id', 'created_at'], 'cr_market_transactions_seller_created_idx');
            $table->index(['buyer_player_id', 'created_at'], 'cr_market_transactions_buyer_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connected_realms_market_transactions', function (Blueprint $table) {
            $table->dropIndex('cr_market_transactions_buyer_created_idx');
            $table->dropIndex('cr_market_transactions_seller_created_idx');
            $table->dropIndex('cr_market_transactions_created_idx');
        });

        Schema::table('connected_realms_market_listings', function (Blueprint $table) {
            $table->dropIndex('cr_market_listings_seller_created_idx');
            $table->dropIndex('cr_market_listings_status_created_idx');
        });

        Schema::table('connected_realms_expedition_runs', function (Blueprint $table) {
            $table->dropIndex('cr_expedition_runs_player_created_idx');
        });

        Schema::table('connected_realms_job_completions', function (Blueprint $table) {
            $table->dropIndex('cr_job_completions_player_created_idx');
        });

        Schema::table('connected_realms_crafting_logs', function (Blueprint $table) {
            $table->dropIndex('cr_crafting_logs_player_created_idx');
        });

        Schema::table('connected_realms_action_logs', function (Blueprint $table) {
            $table->dropIndex('cr_action_logs_player_created_idx');
        });
    }
};
