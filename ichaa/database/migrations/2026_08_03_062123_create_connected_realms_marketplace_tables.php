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
        Schema::create('connected_realms_market_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_player_id')
                ->constrained('connected_realms_players')
                ->cascadeOnDelete();
            $table->string('item_key');
            $table->string('item_name');
            $table->string('rarity')->default('common');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price');
            $table->string('status')->default('active');
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'item_key']);
            $table->index(['seller_player_id', 'status']);
        });

        Schema::create('connected_realms_market_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')
                ->constrained('connected_realms_market_listings')
                ->cascadeOnDelete();
            $table->foreignId('seller_player_id')
                ->constrained('connected_realms_players')
                ->cascadeOnDelete();
            $table->foreignId('buyer_player_id')
                ->constrained('connected_realms_players')
                ->cascadeOnDelete();
            $table->string('item_key');
            $table->string('item_name');
            $table->string('rarity')->default('common');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price');
            $table->unsignedInteger('total_price');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connected_realms_market_transactions');
        Schema::dropIfExists('connected_realms_market_listings');
    }
};
