<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bitcraft_widget_profiles', function (Blueprint $table) {
            $table->dropUnique('bitcraft_widget_profiles_widget_source_unique');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        });

        $ownerId = DB::table('users')->orderBy('id')->value('id');

        if ($ownerId !== null) {
            DB::table('bitcraft_widget_profiles')
                ->whereNull('user_id')
                ->update(['user_id' => $ownerId]);
        }

        Schema::table('bitcraft_widget_profiles', function (Blueprint $table) {
            $table->unique(['user_id', 'widget', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('bitcraft_widget_profiles', function (Blueprint $table) {
            $table->dropUnique('bitcraft_widget_profiles_user_id_widget_source_unique');
            $table->dropConstrainedForeignId('user_id');
            $table->unique(['widget', 'source']);
        });
    }
};
