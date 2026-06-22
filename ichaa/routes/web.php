<?php

use App\Http\Controllers\Connections\FactionMembershipController;
// Breeze
use App\Http\Controllers\Connections\GroupRelationshipController;
// App
use App\Http\Controllers\Connections\RelationshipController;
// Identity
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Identity\EntityAliasController;
use App\Http\Controllers\Identity\EntityController;
use App\Http\Controllers\Identity\EntityNoteController;
use App\Http\Controllers\Identity\EntityQuestionController;
// Connections
use App\Http\Controllers\Identity\VersionController;
use App\Http\Controllers\Intelligence\KnowledgeStateController;
use App\Http\Controllers\Intelligence\PerceptionStateController;
// Organization
use App\Http\Controllers\Intelligence\SecretController;
use App\Http\Controllers\Lore\CrossoverEntryPointController;
// Lore
use App\Http\Controllers\Lore\DocumentController;
use App\Http\Controllers\Lore\SourceCanonReferenceController;
use App\Http\Controllers\Organization\CollectionController;
// Temporal
use App\Http\Controllers\Organization\GlossaryController;
use App\Http\Controllers\Production\MetaController;
use App\Http\Controllers\Production\PipelineItemController;
// World
use App\Http\Controllers\Production\SessionLogController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\System\NotionSyncController;
use App\Http\Controllers\System\SearchController;
use App\Http\Controllers\System\TrashController;
// Intelligence
use App\Http\Controllers\Temporal\CharacterStateController;
use App\Http\Controllers\Temporal\ConcurrencyGroupController;
use App\Http\Controllers\Temporal\TimelineController;
// Production
use App\Http\Controllers\World\LocationContainmentController;
use App\Http\Controllers\World\LocationControlController;
use App\Http\Controllers\World\PowerInteractionController;
// System
use App\Http\Controllers\World\TravelRouteController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Breeze — auth pages (unauthenticated)
// ---------------------------------------------------------------------------

require __DIR__.'/auth.php';

// ---------------------------------------------------------------------------
// All app routes require auth
// ---------------------------------------------------------------------------

Route::middleware(['auth', 'verified'])->group(function () {

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

    Route::prefix('group-relationships/{groupRelationship}')->name('group-relationships.')->group(function () {
        Route::post('members', [GroupRelationshipController::class, 'addMember'])->name('members.add');
        Route::delete('members/{entry}', [GroupRelationshipController::class, 'removeMember'])->name('members.remove');
    });

    Route::resource('faction-memberships', FactionMembershipController::class)->except(['index', 'show']);

    // -----------------------------------------------------------------------
    // Organization
    // -----------------------------------------------------------------------

    Route::resource('collections', CollectionController::class);

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
    Route::resource('canon-references', SourceCanonReferenceController::class);
    Route::resource('crossover-entry-points', CrossoverEntryPointController::class);

    // -----------------------------------------------------------------------
    // Temporal
    // -----------------------------------------------------------------------

    Route::resource('timelines', TimelineController::class);
    Route::resource('character-states', CharacterStateController::class);
    Route::resource('concurrency-groups', ConcurrencyGroupController::class);

    Route::prefix('timelines/{timeline}')->name('timelines.')->group(function () {
        Route::post('events/{event}', [TimelineController::class, 'placeEvent'])->name('events.place');
        Route::delete('events/{entry}', [TimelineController::class, 'removeEvent'])->name('events.remove');
    });

    // -----------------------------------------------------------------------
    // World
    // -----------------------------------------------------------------------

    Route::resource('power-interactions', PowerInteractionController::class);
    Route::resource('location-containment', LocationContainmentController::class)->except(['show']);
    Route::resource('travel-routes', TravelRouteController::class);
    Route::resource('location-control', LocationControlController::class)->except(['show']);

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
    });

    Route::prefix('perception-states/{perceptionState}')->name('perception-states.')->group(function () {
        Route::post('immune/{entity}', [PerceptionStateController::class, 'addImmune'])->name('immune.add');
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
    });

    // -----------------------------------------------------------------------
    // System
    // -----------------------------------------------------------------------

    Route::get('search', [SearchController::class, 'index'])->name('search');
    Route::post('notion/sync/{resource}', [NotionSyncController::class, 'store'])->name('notion.sync');
    Route::get('trash', [TrashController::class, 'index'])->name('trash.index');
    Route::post('trash/{type}/{record}/restore', [TrashController::class, 'restore'])->name('trash.restore');

});
