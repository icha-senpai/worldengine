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
        $now = now();

        DB::table('roles')->insertOrIgnore([
            [
                'name' => 'connected-realms',
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Schema::create('connected_realms_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('display_name');
            $table->string('species')->default('human');
            $table->unsignedInteger('gold')->default(0);
            $table->timestamp('last_action_at')->nullable();
            $table->timestamp('next_action_at')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });

        Schema::create('connected_realms_player_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')
                ->constrained('connected_realms_players')
                ->cascadeOnDelete();
            $table->string('skill');
            $table->unsignedSmallInteger('level')->default(1);
            $table->unsignedInteger('experience')->default(0);
            $table->timestamps();

            $table->unique(['player_id', 'skill']);
        });

        Schema::create('connected_realms_inventory_stacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')
                ->constrained('connected_realms_players')
                ->cascadeOnDelete();
            $table->string('item_key');
            $table->string('item_name');
            $table->string('rarity')->default('common');
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();

            $table->unique(['player_id', 'item_key']);
        });

        Schema::create('connected_realms_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')
                ->constrained('connected_realms_players')
                ->cascadeOnDelete();
            $table->string('action');
            $table->string('skill');
            $table->string('platform')->default('website');
            $table->string('result_label');
            $table->jsonb('items_awarded')->default(json_encode([]));
            $table->unsignedInteger('experience_awarded')->default(0);
            $table->unsignedInteger('gold_awarded')->default(0);
            $table->timestamp('available_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connected_realms_action_logs');
        Schema::dropIfExists('connected_realms_inventory_stacks');
        Schema::dropIfExists('connected_realms_player_skills');
        Schema::dropIfExists('connected_realms_players');

        $roleIds = DB::table('roles')
            ->where('name', 'connected-realms')
            ->where('guard_name', 'web')
            ->pluck('id');

        if ($roleIds->isNotEmpty()) {
            DB::table('model_has_roles')
                ->whereIn('role_id', $roleIds)
                ->delete();

            DB::table('roles')
                ->whereIn('id', $roleIds)
                ->delete();
        }
    }
};
