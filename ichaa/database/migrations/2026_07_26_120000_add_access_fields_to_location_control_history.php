<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('location_control_history')) {
            return;
        }

        $needsVisibility = ! Schema::hasColumn('location_control_history', 'visibility');
        $needsClassification = ! Schema::hasColumn('location_control_history', 'content_classification');

        if (! $needsVisibility && ! $needsClassification) {
            return;
        }

        Schema::table('location_control_history', function (Blueprint $table) use ($needsVisibility, $needsClassification): void {
            if ($needsVisibility) {
                $table->string('visibility')->default('private');
            }

            if ($needsClassification) {
                $table->string('content_classification')->default('restricted');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('location_control_history')) {
            return;
        }

        $drops = collect(['visibility', 'content_classification'])
            ->filter(fn (string $column): bool => Schema::hasColumn('location_control_history', $column))
            ->values()
            ->all();

        if ($drops === []) {
            return;
        }

        Schema::table('location_control_history', function (Blueprint $table) use ($drops): void {
            $table->dropColumn($drops);
        });
    }
};
