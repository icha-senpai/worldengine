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
        Schema::create('connected_realms_vendor_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')
                ->constrained('connected_realms_players')
                ->cascadeOnDelete();
            $table->string('vendor_key')->default('ledger_steward');
            $table->string('vendor_name')->default('Ledger Steward');
            $table->string('item_key');
            $table->string('item_name');
            $table->string('rarity')->default('common');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price');
            $table->unsignedInteger('total_price');
            $table->json('item_snapshot')->nullable();
            $table->timestamps();

            $table->index(['player_id', 'created_at']);
            $table->index(['item_key', 'rarity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connected_realms_vendor_sales');
    }
};
