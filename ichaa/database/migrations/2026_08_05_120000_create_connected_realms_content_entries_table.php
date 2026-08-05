<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_realms_content_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('surface');
            $table->string('entry_key');
            $table->string('label')->nullable();
            $table->string('category')->nullable();
            $table->unsignedSmallInteger('required_level')->nullable();
            $table->string('rarity')->nullable();
            $table->boolean('enabled')->default(true);
            $table->integer('sort_order')->default(0);
            $table->jsonb('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['surface', 'entry_key']);
            $table->index(['surface', 'enabled', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_realms_content_entries');
    }
};
