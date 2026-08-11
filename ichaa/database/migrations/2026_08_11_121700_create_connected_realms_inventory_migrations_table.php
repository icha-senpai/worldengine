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
        Schema::create('connected_realms_inventory_migrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')
                ->constrained('connected_realms_players')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('old_stack_id');
            $table->string('item_key');
            $table->string('item_name');
            $table->string('rarity');
            $table->unsignedInteger('old_quantity');
            $table->string('new_item_key')->nullable();
            $table->string('new_item_name')->nullable();
            $table->unsignedInteger('new_quantity')->default(0);
            $table->decimal('conversion_ratio', 10, 4)->default(1);
            $table->string('rounding')->default('floor');
            $table->decimal('quantity_remainder', 10, 4)->default(0);
            $table->unsignedInteger('gold_compensation')->default(0);
            $table->integer('value_before')->default(0);
            $table->integer('value_after')->default(0);
            $table->integer('value_delta')->default(0);
            $table->string('action');
            $table->string('migration_version');
            $table->text('reason')->nullable();
            $table->timestamp('applied_at');
            $table->timestamps();

            $table->unique(['migration_version', 'old_stack_id']);
            $table->index(['player_id', 'item_key']);
            $table->index(['action', 'migration_version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connected_realms_inventory_migrations');
    }
};
