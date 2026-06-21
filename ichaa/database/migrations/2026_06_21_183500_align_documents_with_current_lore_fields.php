<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('documents', 'official_narrative')) {
            DB::statement('ALTER TABLE documents ALTER COLUMN official_narrative DROP DEFAULT');
            DB::statement('ALTER TABLE documents ALTER COLUMN official_narrative DROP NOT NULL');
            DB::statement("
                ALTER TABLE documents
                ALTER COLUMN official_narrative TYPE jsonb
                USING CASE
                    WHEN official_narrative IS TRUE THEN jsonb_build_object('type', 'doc', 'content', jsonb_build_array())
                    ELSE NULL
                END
            ");
        }

        Schema::table('documents', function (Blueprint $table) {
            if (! Schema::hasColumn('documents', 'true_content')) {
                $table->jsonb('true_content')->nullable()->after('official_narrative');
            }

            if (! Schema::hasColumn('documents', 'era_created')) {
                $table->string('era_created')->nullable()->after('document_authenticity');
            }

            if (! Schema::hasColumn('documents', 'suppressed_by_entity_id')) {
                $table->unsignedBigInteger('suppressed_by_entity_id')->nullable()->after('superseded_by_document_id');
            }
        });

        if (Schema::hasColumn('documents', 'time_period_created')) {
            DB::statement('
                UPDATE documents
                SET era_created = COALESCE(era_created, time_period_created)
                WHERE time_period_created IS NOT NULL
            ');
        }
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (Schema::hasColumn('documents', 'suppressed_by_entity_id')) {
                $table->dropColumn('suppressed_by_entity_id');
            }

            if (Schema::hasColumn('documents', 'era_created')) {
                $table->dropColumn('era_created');
            }

            if (Schema::hasColumn('documents', 'true_content')) {
                $table->dropColumn('true_content');
            }
        });

        if (Schema::hasColumn('documents', 'official_narrative')) {
            DB::statement('
                ALTER TABLE documents
                ALTER COLUMN official_narrative TYPE boolean
                USING CASE
                    WHEN official_narrative IS NULL THEN false
                    ELSE true
                END
            ');
            DB::statement('ALTER TABLE documents ALTER COLUMN official_narrative SET NOT NULL');
            DB::statement('ALTER TABLE documents ALTER COLUMN official_narrative SET DEFAULT false');
        }
    }
};
