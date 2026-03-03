<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            // --- IDENTITY ---

            $table->string('title');
            $table->string('public_title')->nullable();

            // What kind of document this is
            $table->string('document_type');
            // legal_code, constitution, treaty, decree, prophecy,
            // scripture, in_world_document, in_world_creative_work,
            // correspondence, historical_record

            // --- OWNERSHIP ---
            // The entity that owns or is responsible for this document
            // The United Earth Empire owns its constitution
            // The Spider's Chapel owns its scripture
            // Nullable — some documents have no clear institutional owner

            $table->unsignedBigInteger('owner_entity_id')->nullable();

            // --- AUTHORSHIP ---
            // Three separate authorship fields because they often differ
            // The puppet writes the decree, Harry v69 composed it,
            // Seraphine directed its creation

            $table->unsignedBigInteger('official_author_entity_id')->nullable();
            // Who is publicly credited as author

            $table->unsignedBigInteger('true_author_entity_id')->nullable();
            // Who actually wrote it — null if same as official

            $table->unsignedBigInteger('authored_under_direction_of_entity_id')->nullable();
            // Who commissioned or directed its creation — null if none

            // --- CONTENT ---

            $table->jsonb('content')->nullable();
            // Tiptap JSON — the full document content
            // Null when document_status is lost, destroyed, or legendary

            $table->jsonb('suppressed_content')->nullable();
            // Tiptap JSON — portions that were removed or hidden
            // The redacted sections of an official history
            // The banned verses of a prophecy

            // --- DOCUMENT STATUS ---

            $table->string('document_status')->default('extant');
            // extant     — exists and content is known
            // lost       — existed but content unknown
            // destroyed  — deliberately destroyed, may have surviving copies
            // suppressed — exists but access controlled
            // legendary  — may or may not have ever existed, status uncertain

            // --- AUTHENTICITY ---

            $table->string('document_authenticity')->default('genuine');
            // genuine, falsified, propaganda, redacted, lost_original

            $table->boolean('official_narrative')->default(false);
            // Is this an official record that shapes public understanding of events

            // --- TEMPORAL ---

            $table->string('time_period_created')->nullable();
            // Freeform era reference — when this was created in-world

            // --- VERSION TRACKING ---
            // Documents evolve — constitutions get amended, laws get repealed
            // Each version is a separate record linked to the original

            $table->unsignedBigInteger('parent_document_id')->nullable();
            // Points to the original document if this is a revision
            // Null for the original

            $table->integer('version_number')->default(1);
            // 1 for original, increments for each revision

            $table->text('version_notes')->nullable();
            // What changed in this version

            $table->unsignedBigInteger('superseded_by_document_id')->nullable();
            // Points to the newer version if this one has been replaced
            // Null means this is the current version

            // --- IN-WORLD CREATIVE WORK FIELDS ---
            // Only populated for document_type: in_world_creative_work

            $table->string('medium')->nullable();
            // written, musical, visual, performance, oral

            $table->string('cultural_significance')->nullable();
            // low, moderate, high, revolutionary

            $table->jsonb('banned_by')->nullable();
            // Array of entity IDs — which governments or factions suppressed it

            $table->text('survived_via')->nullable();
            // How it was preserved — underground copies, oral tradition, hidden archive

            // --- PUBLIC FACING ---

            $table->text('public_summary')->nullable();
            $table->timestamp('published_at')->nullable();

            // --- VISIBILITY AND CLASSIFICATION ---
            // Classification here is about access within the world
            // Visibility is about whether it surfaces on your public site

            $table->string('classification')->default('secret');
            // public, restricted, secret, author_only

            $table->string('visibility')->default('private');
            $table->string('content_classification')->default('restricted');

            // --- SOFT DELETE AND TIMESTAMPS ---

            $table->softDeletes();
            $table->timestamps();
        });

        // --- DOCUMENT ENTITIES PIVOT ---
        // A document can reference multiple entities beyond its owner
        // The constitution references Seraphine as author, Johnny's
        // erasure as context, the puppet cycles as historical basis

        Schema::create('document_entities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();

            $table->foreignId('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            // How this entity relates to this document
            $table->string('relationship_type');
            // author      — created or wrote this document
            // subject     — the document is about this entity
            // referenced  — mentioned or cited within the document
            // signatory   — signed or ratified this document
            // contradicts — this entity's existence contradicts this document
            // sealed_by   — this entity's authority validates the document
            // other

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['document_id', 'entity_id', 'relationship_type'],
                'document_entities_unique'
            );
        });

        // --- INDEXES ---

        // Full text search across title and content
        \DB::statement("
            ALTER TABLE documents
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(public_title, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(version_notes, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(survived_via, '')), 'C')
            ) STORED
        ");

        \DB::statement('CREATE INDEX documents_search_vector_idx ON documents USING GIN (search_vector)');

        // JSONB indexes
        \DB::statement('CREATE INDEX documents_content_idx ON documents USING GIN (content)');
        \DB::statement('CREATE INDEX documents_banned_by_idx ON documents USING GIN (banned_by)');

        Schema::table('documents', function (Blueprint $table) {
            $table->index('document_type');
            $table->index('owner_entity_id');
            $table->index('official_author_entity_id');
            $table->index('true_author_entity_id');
            $table->index('authored_under_direction_of_entity_id');
            $table->index('document_status');
            $table->index('document_authenticity');
            $table->index('official_narrative');
            $table->index('parent_document_id');
            $table->index('superseded_by_document_id');
            $table->index('classification');
            $table->index('visibility');
            $table->index('content_classification');
            $table->index('deleted_at');

            // Compound index for version chain queries:
            // "give me all versions of this document in order"
            $table->index(['parent_document_id', 'version_number']);

            // Compound index for owner document queries:
            // "give me all documents owned by this entity"
            $table->index(['owner_entity_id', 'document_type']);

            // Compound index for official narrative queries:
            // "show me all official propaganda documents"
            $table->index(['official_narrative', 'document_authenticity']);
        });

        Schema::table('document_entities', function (Blueprint $table) {
            $table->index('document_id');
            $table->index('entity_id');
            $table->index('relationship_type');

            // Compound index for entity document queries:
            // "show me all documents this entity is subject of"
            $table->index(['entity_id', 'relationship_type']);
        });

        // Now that documents table exists, add the foreign key constraint
        // to collection_documents that we deferred in migration 10
        Schema::table('collection_documents', function (Blueprint $table) {
            $table->foreign('document_id')
                ->references('id')
                ->on('documents')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('collection_documents', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
        });

        Schema::dropIfExists('document_entities');
        Schema::dropIfExists('documents');
    }
};

/*
|--------------------------------------------------------------------------
| DOCUMENT TYPE ENUM
|--------------------------------------------------------------------------
|
|   legal_code            — body of laws for a nation or government
|   constitution          — founding document of a government or nation
|   treaty                — agreement between two or more parties
|   decree                — official order from a ruling authority
|   prophecy              — foretelling of future events
|   scripture             — religious or spiritual text
|   in_world_document     — general purpose in-world written record
|   in_world_creative_work — art, literature, music existing within the world
|   correspondence        — letters, messages, communications
|   historical_record     — chronicles, histories, official records
|
|--------------------------------------------------------------------------
| VERSION CHAIN EXAMPLE
|--------------------------------------------------------------------------
|
| Original Constitution of the United Earth Empire:
|   id: 1, version_number: 1, parent_document_id: null,
|   superseded_by_document_id: 2
|
| First Amendment — post cycle 12 reforms:
|   id: 2, version_number: 2, parent_document_id: 1,
|   superseded_by_document_id: 3
|
| Second Amendment — post Revelation restructure:
|   id: 3, version_number: 3, parent_document_id: 1,
|   superseded_by_document_id: null  ← current version
|
| To get the full version chain:
|   query where parent_document_id = 1 OR id = 1, order by version_number
|
|--------------------------------------------------------------------------
| DEFERRED FOREIGN KEY NOTE
|--------------------------------------------------------------------------
|
| The collection_documents pivot was created in migration 10 with
| document_id as an unconstrained integer because documents did not
| exist yet. This migration adds the foreign key constraint now that
| the documents table exists.
|
| The down() method removes the constraint before dropping documents
| to avoid foreign key violations during rollback.
|
*/
