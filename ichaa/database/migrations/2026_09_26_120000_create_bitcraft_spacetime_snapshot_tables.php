<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitcraft_spacetime_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('source')->default('bitcraft-spacetimedb');
            $table->string('host')->nullable();
            $table->string('database')->nullable();
            $table->timestampTz('generated_at')->nullable();
            $table->jsonb('table_counts')->default('{}');
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->index('is_current');
            $table->index('generated_at');
        });

        Schema::create('bitcraft_spacetime_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('bitcraft_spacetime_snapshots')->cascadeOnDelete();
            $table->string('table_name');
            $table->string('row_key');
            $table->jsonb('row_data');
            $table->timestamps();

            $table->unique(['snapshot_id', 'table_name', 'row_key'], 'bc_spacetime_rows_snapshot_table_key_unique');
            $table->index(['snapshot_id', 'table_name'], 'bc_spacetime_rows_snapshot_table_idx');
            $table->index('table_name', 'bc_spacetime_rows_table_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitcraft_spacetime_rows');
        Schema::dropIfExists('bitcraft_spacetime_snapshots');
    }
};
