<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('connected_realms_market_transactions', function (Blueprint $table): void {
            $table->unsignedInteger('market_fee')->default(0);
            $table->unsignedInteger('seller_payout')->default(0);
        });

        DB::table('connected_realms_market_transactions')->update([
            'seller_payout' => DB::raw('total_price'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connected_realms_market_transactions', function (Blueprint $table): void {
            $table->dropColumn(['market_fee', 'seller_payout']);
        });
    }
};
