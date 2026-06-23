<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->createRichDocumentFunctions();

        DB::statement('DROP INDEX IF EXISTS entities_search_vector_idx');
        DB::statement('ALTER TABLE entities DROP COLUMN IF EXISTS search_vector');
        DB::statement("ALTER TABLE entities ALTER COLUMN summary TYPE JSONB USING dataverse_text_to_rich_document(summary)");
        DB::statement("ALTER TABLE entities ALTER COLUMN public_summary TYPE JSONB USING dataverse_text_to_rich_document(public_summary)");
        DB::statement("ALTER TABLE entities ALTER COLUMN origin_notes TYPE JSONB USING dataverse_text_to_rich_document(origin_notes)");
        DB::statement("
            ALTER TABLE entities
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(name, '')), 'A') ||
                setweight(to_tsvector('english', dataverse_rich_document_text(summary)), 'B') ||
                setweight(to_tsvector('english', dataverse_rich_document_text(public_summary)), 'B') ||
                setweight(to_tsvector('english', dataverse_rich_document_text(origin_notes)), 'C')
            ) STORED
        ");
        DB::statement('CREATE INDEX entities_search_vector_idx ON entities USING GIN (search_vector)');

        DB::statement('DROP INDEX IF EXISTS character_state_search_vector_idx');
        DB::statement('ALTER TABLE character_state_tracker DROP COLUMN IF EXISTS search_vector');
        DB::statement("ALTER TABLE character_state_tracker ALTER COLUMN current_trauma_profile TYPE JSONB USING dataverse_text_to_rich_document(current_trauma_profile)");
        DB::statement("ALTER TABLE character_state_tracker ALTER COLUMN active_psychological_patterns TYPE JSONB USING dataverse_text_to_rich_document(active_psychological_patterns)");
        DB::statement("
            ALTER TABLE character_state_tracker
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(snapshot_label, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(significance_reason, '')), 'B') ||
                setweight(to_tsvector('english', dataverse_rich_document_text(current_trauma_profile)), 'C') ||
                setweight(to_tsvector('english', coalesce(core_wound, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(current_desire, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(current_fear, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(performed_self, '')), 'D') ||
                setweight(to_tsvector('english', coalesce(true_self, '')), 'D') ||
                setweight(to_tsvector('english', dataverse_rich_document_text(active_psychological_patterns)), 'D')
            ) STORED
        ");
        DB::statement('CREATE INDEX character_state_search_vector_idx ON character_state_tracker USING GIN (search_vector)');

        DB::statement('DROP INDEX IF EXISTS writing_pipeline_search_vector_idx');
        DB::statement('ALTER TABLE writing_pipeline DROP COLUMN IF EXISTS search_vector');
        DB::statement("ALTER TABLE writing_pipeline ALTER COLUMN content TYPE JSONB USING dataverse_text_to_rich_document(content)");
        DB::statement("ALTER TABLE writing_pipeline ALTER COLUMN narrative_purpose TYPE JSONB USING dataverse_text_to_rich_document(narrative_purpose)");
        DB::statement("ALTER TABLE writing_pipeline ALTER COLUMN arc_notes TYPE JSONB USING dataverse_text_to_rich_document(arc_notes)");
        DB::statement("ALTER TABLE writing_pipeline ALTER COLUMN notes TYPE JSONB USING dataverse_text_to_rich_document(notes)");
        DB::statement("
            ALTER TABLE writing_pipeline
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(pipeline_type, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(pipeline_stage, '')), 'B') ||
                setweight(to_tsvector('english', dataverse_rich_document_text(narrative_purpose)), 'C') ||
                setweight(to_tsvector('english', dataverse_rich_document_text(arc_notes)), 'C') ||
                setweight(to_tsvector('english', coalesce(how_used, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(how_changed, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(why_it_fits, '')), 'D')
            ) STORED
        ");
        DB::statement('CREATE INDEX writing_pipeline_search_vector_idx ON writing_pipeline USING GIN (search_vector)');
    }

    public function down(): void
    {
        $this->createRichDocumentFunctions();

        DB::statement('DROP INDEX IF EXISTS entities_search_vector_idx');
        DB::statement('ALTER TABLE entities DROP COLUMN IF EXISTS search_vector');
        DB::statement("ALTER TABLE entities ALTER COLUMN summary TYPE TEXT USING dataverse_rich_document_text(summary)");
        DB::statement("ALTER TABLE entities ALTER COLUMN public_summary TYPE TEXT USING dataverse_rich_document_text(public_summary)");
        DB::statement("ALTER TABLE entities ALTER COLUMN origin_notes TYPE TEXT USING dataverse_rich_document_text(origin_notes)");
        DB::statement("
            ALTER TABLE entities
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(name, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(summary, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(public_summary, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(origin_notes, '')), 'C')
            ) STORED
        ");
        DB::statement('CREATE INDEX entities_search_vector_idx ON entities USING GIN (search_vector)');

        DB::statement('DROP INDEX IF EXISTS character_state_search_vector_idx');
        DB::statement('ALTER TABLE character_state_tracker DROP COLUMN IF EXISTS search_vector');
        DB::statement("ALTER TABLE character_state_tracker ALTER COLUMN current_trauma_profile TYPE TEXT USING dataverse_rich_document_text(current_trauma_profile)");
        DB::statement("ALTER TABLE character_state_tracker ALTER COLUMN active_psychological_patterns TYPE TEXT USING dataverse_rich_document_text(active_psychological_patterns)");
        DB::statement("
            ALTER TABLE character_state_tracker
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(snapshot_label, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(significance_reason, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(current_trauma_profile, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(core_wound, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(current_desire, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(current_fear, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(performed_self, '')), 'D') ||
                setweight(to_tsvector('english', coalesce(true_self, '')), 'D')
            ) STORED
        ");
        DB::statement('CREATE INDEX character_state_search_vector_idx ON character_state_tracker USING GIN (search_vector)');

        DB::statement('DROP INDEX IF EXISTS writing_pipeline_search_vector_idx');
        DB::statement('ALTER TABLE writing_pipeline DROP COLUMN IF EXISTS search_vector');
        DB::statement("ALTER TABLE writing_pipeline ALTER COLUMN content TYPE TEXT USING dataverse_rich_document_text(content)");
        DB::statement("ALTER TABLE writing_pipeline ALTER COLUMN narrative_purpose TYPE TEXT USING dataverse_rich_document_text(narrative_purpose)");
        DB::statement("ALTER TABLE writing_pipeline ALTER COLUMN arc_notes TYPE TEXT USING dataverse_rich_document_text(arc_notes)");
        DB::statement("ALTER TABLE writing_pipeline ALTER COLUMN notes TYPE TEXT USING dataverse_rich_document_text(notes)");
        DB::statement("
            ALTER TABLE writing_pipeline
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(pipeline_type, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(pipeline_stage, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(narrative_purpose, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(arc_notes, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(how_used, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(how_changed, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(why_it_fits, '')), 'D')
            ) STORED
        ");
        DB::statement('CREATE INDEX writing_pipeline_search_vector_idx ON writing_pipeline USING GIN (search_vector)');

        DB::statement('DROP FUNCTION IF EXISTS dataverse_rich_document_text(JSONB)');
        DB::statement('DROP FUNCTION IF EXISTS dataverse_text_to_rich_document(TEXT)');
    }

    private function createRichDocumentFunctions(): void
    {
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION dataverse_text_to_rich_document(raw TEXT)
RETURNS JSONB
LANGUAGE plpgsql
IMMUTABLE
AS $$
DECLARE
    parsed JSONB;
    paragraphs TEXT[];
    paragraph TEXT;
    content JSONB := '[]'::jsonb;
BEGIN
    IF raw IS NULL OR btrim(raw) = '' THEN
        RETURN NULL;
    END IF;

    BEGIN
        parsed := raw::jsonb;

        IF jsonb_typeof(parsed) = 'object' AND parsed->>'type' = 'doc' THEN
            RETURN parsed;
        END IF;
    EXCEPTION WHEN others THEN
        parsed := NULL;
    END;

    paragraphs := regexp_split_to_array(raw, E'\\r?\\n\\r?\\n+');

    FOREACH paragraph IN ARRAY paragraphs LOOP
        paragraph := btrim(paragraph);

        IF paragraph = '' THEN
            CONTINUE;
        END IF;

        content := content || jsonb_build_array(
            jsonb_build_object(
                'type', 'paragraph',
                'content', jsonb_build_array(
                    jsonb_build_object(
                        'type', 'text',
                        'text', paragraph
                    )
                )
            )
        );
    END LOOP;

    RETURN jsonb_build_object('type', 'doc', 'content', content);
END;
$$;
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION dataverse_rich_document_text(doc JSONB)
RETURNS TEXT
LANGUAGE sql
IMMUTABLE
AS $$
    SELECT CASE
        WHEN doc IS NULL THEN ''
        WHEN jsonb_typeof(doc) = 'string' THEN trim(both '"' from doc::text)
        WHEN jsonb_typeof(doc) NOT IN ('object', 'array') THEN doc::text
        ELSE coalesce(
            array_to_string(
                ARRAY(
                    SELECT value #>> '{}'
                    FROM jsonb_path_query(doc, '$.**.text') AS value
                ),
                ' '
            ),
            ''
        )
    END;
$$;
SQL);
    }
};
