<?php

use App\Http\Controllers\Admin\NotionNoteController as AdminNotionNoteController;
use App\Http\Controllers\Admin\NotionSyncMappingController as AdminNotionSyncMappingController;
use App\Http\Controllers\Admin\RevisionController as AdminRevisionController;
use App\Http\Controllers\Bitcraft\BitcraftActivityController;
use App\Http\Controllers\Bitcraft\BitcraftInventoryTrackerController;
use App\Http\Controllers\Bitcraft\BitcraftTaskTrackerController;
use App\Http\Controllers\Bitcraft\BitcraftToolController;
use App\Http\Controllers\Connections\FactionMembershipController;
use App\Http\Controllers\Connections\GroupRelationshipController;
// Breeze
use App\Http\Controllers\Connections\GroupRelationshipMembershipController;
// App
use App\Http\Controllers\Connections\RelationshipController;
// Identity
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Identity\EntityAliasController;
use App\Http\Controllers\Identity\EntityController;
use App\Http\Controllers\Identity\EntityNoteController;
use App\Http\Controllers\Identity\EntityQuestionController;
use App\Http\Controllers\Identity\MediaReferenceController;
// Connections
use App\Http\Controllers\Identity\VersionController;
use App\Http\Controllers\Intelligence\KnowledgeStateController;
use App\Http\Controllers\Intelligence\PerceptionStateController;
// Organization
use App\Http\Controllers\Intelligence\SecretController;
use App\Http\Controllers\Lore\CanonReferenceEntityController;
use App\Http\Controllers\Lore\CrossoverEntryPointController;
// Lore
use App\Http\Controllers\Lore\DocumentController;
use App\Http\Controllers\Lore\DocumentEntityController;
use App\Http\Controllers\Lore\SourceCanonReferenceController;
use App\Http\Controllers\Organization\CollectionController;
use App\Http\Controllers\Organization\CollectionDocumentController;
// Temporal
use App\Http\Controllers\Organization\GlossaryController;
use App\Http\Controllers\Production\MetaController;
use App\Http\Controllers\Production\PipelineItemController;
// World
use App\Http\Controllers\Production\SessionLogController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Scaffold\TopLevelModeledResourceController;
use App\Http\Controllers\System\MediaLibraryController;
use App\Http\Controllers\System\NotionSyncController;
use App\Http\Controllers\System\SearchController;
use App\Http\Controllers\System\TrashController;
// Intelligence
use App\Http\Controllers\Temporal\CharacterStateController;
use App\Http\Controllers\Temporal\ConcurrencyGroupController;
use App\Http\Controllers\Temporal\StateRelationshipController;
use App\Http\Controllers\Temporal\TimelineController;
use App\Http\Controllers\Temporal\TimelinePlacementController;
// Production
use App\Http\Controllers\World\GalacticRegionController;
use App\Http\Controllers\World\LocationContainmentController;
use App\Http\Controllers\World\LocationControlController;
use App\Http\Controllers\World\PowerInteractionController;
// System
use App\Http\Controllers\World\TravelRouteController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ---------------------------------------------------------------------------
// Breeze — auth pages (unauthenticated)
// ---------------------------------------------------------------------------

require __DIR__.'/auth.php';

Route::get('/', fn () => Inertia::render('Welcome', [
    'canLogin' => Route::has('login'),
    'canRegister' => Route::has('register'),
]))->name('home');

Route::prefix('datacrypt/bitcraft')->name('bitcraft.')->group(function () {
    Route::get('activity', [BitcraftActivityController::class, 'show'])->name('activity');
    Route::get('activity/snapshot', [BitcraftActivityController::class, 'snapshot'])->name('activity.snapshot');
    Route::get('inventory-tracker', [BitcraftInventoryTrackerController::class, 'show'])->name('inventory-tracker');
    Route::get('inventory-tracker/snapshot', [BitcraftInventoryTrackerController::class, 'snapshot'])->name('inventory-tracker.snapshot');
    Route::get('task-tracker', [BitcraftTaskTrackerController::class, 'show'])->name('task-tracker');
});

// ---------------------------------------------------------------------------
// All app routes require auth
// ---------------------------------------------------------------------------

Route::prefix('datacrypt')->middleware(['auth', 'verified', EnsureAdmin::class])->group(function () {
    Route::redirect('/', '/datacrypt/worldengine');

    Route::prefix('bitcraft')->name('bitcraft.')->group(function () {
        Route::redirect('/', '/datacrypt/bitcraft/market');
        Route::get('market', [BitcraftToolController::class, 'market'])->name('market');
        Route::get('barter-stalls', [BitcraftToolController::class, 'barterStalls'])->name('barter-stalls');
        Route::get('crafting', [BitcraftToolController::class, 'crafting'])->name('crafting');
    });

    Route::prefix('worldengine')->group(function () {

        // Dashboard
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // Breeze profile
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

        // -----------------------------------------------------------------------
        // Identity — Entities
        // -----------------------------------------------------------------------

        Route::resource('entities', EntityController::class);
        Route::resource('media-references', MediaReferenceController::class);

        Route::prefix('entities/{entity}')->name('entities.')->group(function () {

            Route::resource('aliases', EntityAliasController::class)->except(['index', 'show']);
            Route::resource('notes', EntityNoteController::class)->except(['index', 'show']);
            Route::resource('questions', EntityQuestionController::class)->except(['index', 'show']);
            Route::resource('versions', VersionController::class)->only(['index', 'store', 'show']);

            Route::post('publish', [EntityController::class, 'publish'])->name('publish');
            Route::post('unpublish', [EntityController::class, 'unpublish'])->name('unpublish');
            Route::post('archive', [EntityController::class, 'archive'])->name('archive');
        });

        // -----------------------------------------------------------------------
        // Connections
        // -----------------------------------------------------------------------

        Route::resource('relationships', RelationshipController::class);
        Route::resource('group-relationships', GroupRelationshipController::class);
        Route::resource('group-relationship-memberships', GroupRelationshipMembershipController::class);

        Route::prefix('group-relationships/{groupRelationship}')->name('group-relationships.')->group(function () {
            Route::post('members', [GroupRelationshipController::class, 'addMember'])->name('members.add');
            Route::delete('members/{entry}', [GroupRelationshipController::class, 'removeMember'])->name('members.remove');
        });
        Route::get('faction-memberships', [TopLevelModeledResourceController::class, 'index'])
            ->defaults('resource', 'faction-memberships')
            ->name('faction-memberships.index');
        Route::get('faction-memberships/{record}', [TopLevelModeledResourceController::class, 'show'])
            ->defaults('resource', 'faction-memberships')
            ->name('faction-memberships.show');
        Route::resource('faction-memberships', FactionMembershipController::class)->except(['index', 'show']);

        // -----------------------------------------------------------------------
        // Organization
        // -----------------------------------------------------------------------

        Route::resource('collections', CollectionController::class);
        Route::get('collection-entities', [TopLevelModeledResourceController::class, 'index'])
            ->defaults('resource', 'collection-entities')
            ->name('collection-entities.index');
        Route::get('collection-entities/{record}', [TopLevelModeledResourceController::class, 'show'])
            ->defaults('resource', 'collection-entities')
            ->name('collection-entities.show');
        Route::resource('collection-documents', CollectionDocumentController::class);

        Route::prefix('collections/{collection}')->name('collections.')->group(function () {
            Route::post('entities/{entity}', [CollectionController::class, 'addEntity'])->name('entities.add');
            Route::delete('entities/{entity}', [CollectionController::class, 'removeEntity'])->name('entities.remove');
            Route::post('sync', [CollectionController::class, 'sync'])->name('sync');
        });

        Route::resource('glossary', GlossaryController::class);

        // -----------------------------------------------------------------------
        // Lore
        // -----------------------------------------------------------------------

        Route::resource('documents', DocumentController::class);
        Route::resource('document-entities', DocumentEntityController::class);
        Route::resource('canon-references', SourceCanonReferenceController::class);
        Route::resource('canon-reference-entities', CanonReferenceEntityController::class);
        Route::resource('crossover-entry-points', CrossoverEntryPointController::class);

        // -----------------------------------------------------------------------
        // Temporal
        // -----------------------------------------------------------------------

        Route::resource('timelines', TimelineController::class);
        Route::resource('timeline-placements', TimelinePlacementController::class);
        Route::resource('character-states', CharacterStateController::class);
        Route::resource('state-relationships', StateRelationshipController::class);
        Route::resource('concurrency-groups', ConcurrencyGroupController::class);

        Route::prefix('timelines/{timeline}')->name('timelines.')->group(function () {
            Route::post('events/{event}', [TimelineController::class, 'placeEvent'])->name('events.place');
            Route::get('events/{entry}/edit', [TimelineController::class, 'editEvent'])->name('events.edit');
            Route::put('events/{entry}', [TimelineController::class, 'updateEvent'])->name('events.update');
            Route::delete('events/{entry}', [TimelineController::class, 'removeEvent'])->name('events.remove');
        });

        // -----------------------------------------------------------------------
        // World
        // -----------------------------------------------------------------------

        Route::resource('power-interactions', PowerInteractionController::class);
        Route::get('power-interaction-instances', [TopLevelModeledResourceController::class, 'index'])
            ->defaults('resource', 'power-interaction-instances')
            ->name('power-interaction-instances.index');
        Route::get('power-interaction-instances/{record}', [TopLevelModeledResourceController::class, 'show'])
            ->defaults('resource', 'power-interaction-instances')
            ->name('power-interaction-instances.show');
        Route::resource('location-containment', LocationContainmentController::class);
        Route::resource('travel-routes', TravelRouteController::class);
        Route::resource('location-control', LocationControlController::class);
        Route::resource('galactic-regions', GalacticRegionController::class);

        Route::prefix('power-interactions/{powerInteraction}')->name('power-interactions.')->group(function () {
            Route::post('resolve', [PowerInteractionController::class, 'resolve'])->name('resolve');
            Route::post('instances', [PowerInteractionController::class, 'recordInstance'])->name('instances.store');
        });

        // -----------------------------------------------------------------------
        // Intelligence
        // -----------------------------------------------------------------------

        Route::resource('knowledge-states', KnowledgeStateController::class);
        Route::resource('secrets', SecretController::class);
        Route::resource('perception-states', PerceptionStateController::class);

        Route::prefix('knowledge-states/{knowledgeState}')->name('knowledge-states.')->group(function () {
            Route::post('act-on', [KnowledgeStateController::class, 'markActedOn'])->name('act-on');
        });

        Route::prefix('secrets/{secret}')->name('secrets.')->group(function () {
            Route::post('expose', [SecretController::class, 'expose'])->name('expose');
            Route::post('known-by/{entity}', [SecretController::class, 'addKnownBy'])->name('known-by.add');
            Route::delete('known-by/{entity}', [SecretController::class, 'removeKnownBy'])->name('known-by.remove');
            Route::post('holders/{entity}', [SecretController::class, 'addHolder'])->name('holders.add');
            Route::delete('holders/{entity}', [SecretController::class, 'removeHolder'])->name('holders.remove');
        });

        Route::prefix('perception-states/{perceptionState}')->name('perception-states.')->group(function () {
            Route::post('immune/{entity}', [PerceptionStateController::class, 'addImmune'])->name('immune.add');
            Route::delete('immune/{entity}', [PerceptionStateController::class, 'removeImmune'])->name('immune.remove');
            Route::post('collapse', [PerceptionStateController::class, 'collapse'])->name('collapse');
        });

        // -----------------------------------------------------------------------
        // Production
        // -----------------------------------------------------------------------

        // Force param name to {meta} — without this Laravel inflects it to {metum}
        Route::resource('meta', MetaController::class)
            ->parameters(['meta' => 'meta']);

        // param name {pipeline} throughout — matches controller method signatures
        Route::resource('pipeline', PipelineItemController::class)
            ->parameters(['pipeline' => 'pipeline']);

        Route::resource('session-logs', SessionLogController::class);

        Route::prefix('meta/{meta}')->name('meta.')->group(function () {
            // Marks action_status = resolved, sets resolved_at
            Route::post('resolve', [MetaController::class, 'resolve'])->name('resolve');
            // Links this note to a newer one via superseded_by_meta_id
            Route::post('supersede', [MetaController::class, 'supersede'])->name('supersede');
            Route::post('entities/{entity}', [MetaController::class, 'linkEntity'])->name('entities.link');
            Route::delete('entities/{entity}', [MetaController::class, 'unlinkEntity'])->name('entities.unlink');
        });

        Route::prefix('pipeline/{pipeline}')->name('pipeline.')->group(function () {
            // Moves pipeline_stage forward one step
            Route::post('advance', [PipelineItemController::class, 'advance'])->name('advance');
            Route::post('resolve', [PipelineItemController::class, 'resolve'])->name('resolve');
        });

        // -----------------------------------------------------------------------
        // System
        // -----------------------------------------------------------------------

        Route::get('search', [SearchController::class, 'index'])->name('search');
        Route::get('media-library/items', [MediaLibraryController::class, 'index'])->name('media-library.index');
        Route::get('media-library/{mediaReference}/asset', [MediaLibraryController::class, 'asset'])->name('media-library.asset');
        Route::post('notion/sync/{resource}', [NotionSyncController::class, 'store'])->name('notion.sync');
        Route::prefix('admin')->name('admin.')->group(function () {
            Route::get('revisions', [AdminRevisionController::class, 'index'])->name('revisions.index');
            Route::get('revisions/compare', [AdminRevisionController::class, 'compare'])->name('revisions.compare');
            Route::get('revisions/{revision}', [AdminRevisionController::class, 'show'])->name('revisions.show');
            Route::post('revisions/{revision}/restore', [AdminRevisionController::class, 'restore'])->name('revisions.restore');

            Route::get('notion-notes', [AdminNotionNoteController::class, 'index'])->name('notion-notes.index');
            Route::get('notion-notes/{notionNote}', [AdminNotionNoteController::class, 'show'])->name('notion-notes.show');

            Route::get('notion-sync-mappings', [AdminNotionSyncMappingController::class, 'index'])->name('notion-sync-mappings.index');
            Route::get('notion-sync-mappings/{notionSyncMapping}', [AdminNotionSyncMappingController::class, 'show'])->name('notion-sync-mappings.show');
        });
        Route::get('trash', [TrashController::class, 'index'])->name('trash.index');
        Route::post('trash/{type}/{record}/restore', [TrashController::class, 'restore'])->name('trash.restore');

    });
});
