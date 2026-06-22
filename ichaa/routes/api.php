<?php

use App\Http\Controllers\Api\V1\ActionController;
use App\Http\Controllers\Api\V1\ResourceController;
use App\Http\Controllers\Api\V1\SystemController;
use App\Support\Api\ApiResourceRegistry;
use Illuminate\Support\Facades\Route;

$resourcePattern = implode('|', array_map(
    static fn (string $slug) => preg_quote($slug, '/'),
    ApiResourceRegistry::slugs(),
));

Route::prefix('v1')
    ->middleware(['auth:sanctum'])
    ->group(function () use ($resourcePattern) {
        Route::get('search', [SystemController::class, 'search']);
        Route::get('trash', [SystemController::class, 'trash']);
        Route::post('trash/{resource}/{record}/restore', [SystemController::class, 'restoreTrash'])
            ->where('resource', $resourcePattern);
        Route::post('notion-sync/{resource}', [SystemController::class, 'syncNotion']);
        Route::get('revisions/compare', [SystemController::class, 'compareRevisions']);
        Route::get('revisions/{revision}', [SystemController::class, 'showRevision']);
        Route::post('revisions/{revision}/restore', [SystemController::class, 'restoreRevision']);

        Route::post('entities/{record}/publish', [ActionController::class, 'publishEntity']);
        Route::post('entities/{record}/unpublish', [ActionController::class, 'unpublishEntity']);
        Route::post('entities/{record}/archive', [ActionController::class, 'archiveEntity']);
        Route::get('entities/{record}/versions', [ActionController::class, 'entityVersionsIndex']);
        Route::get('entities/{record}/versions/{version}', [ActionController::class, 'entityVersionsShow']);
        Route::post('entities/{record}/versions', [ActionController::class, 'entityVersionsStore']);

        Route::post('relationships/{record}/tension-charge', [ActionController::class, 'relationshipTensionCharge']);
        Route::post('group-relationships/{record}/tension-charge', [ActionController::class, 'groupRelationshipTensionCharge']);
        Route::post('group-relationships/{record}/members', [ActionController::class, 'groupRelationshipAddMember']);
        Route::delete('group-relationships/{record}/members/{membership}', [ActionController::class, 'groupRelationshipRemoveMember']);
        Route::post('faction-memberships/{record}/terminate', [ActionController::class, 'terminateFactionMembership']);

        Route::post('collections/{record}/sync', [ActionController::class, 'syncCollection']);

        Route::post('timelines/{record}/events', [ActionController::class, 'timelinePlaceEvent']);
        Route::patch('timelines/{record}/events/{entry}', [ActionController::class, 'timelineUpdateEvent']);
        Route::delete('timelines/{record}/events/{entry}', [ActionController::class, 'timelineRemoveEvent']);

        Route::post('power-interactions/{record}/resolve', [ActionController::class, 'resolvePowerInteraction']);
        Route::post('power-interactions/{record}/instances', [ActionController::class, 'recordPowerInteractionInstance']);

        Route::post('knowledge-states/{record}/act-on', [ActionController::class, 'markKnowledgeStateActedOn']);
        Route::post('secrets/{record}/expose', [ActionController::class, 'exposeSecret']);
        Route::post('secrets/{record}/known-by', [ActionController::class, 'addSecretKnownBy']);
        Route::delete('secrets/{record}/known-by/{entity}', [ActionController::class, 'removeSecretKnownBy']);
        Route::post('secrets/{record}/holders', [ActionController::class, 'addSecretHolder']);
        Route::delete('secrets/{record}/holders/{entity}', [ActionController::class, 'removeSecretHolder']);

        Route::post('perception-states/{record}/immune', [ActionController::class, 'addPerceptionImmune']);
        Route::delete('perception-states/{record}/immune/{entity}', [ActionController::class, 'removePerceptionImmune']);
        Route::post('perception-states/{record}/collapse', [ActionController::class, 'collapsePerceptionState']);

        Route::post('meta/{record}/resolve', [ActionController::class, 'resolveMeta']);
        Route::post('meta/{record}/supersede', [ActionController::class, 'supersedeMeta']);
        Route::post('meta/{record}/entities', [ActionController::class, 'linkMetaEntity']);
        Route::delete('meta/{record}/entities/{entity}', [ActionController::class, 'unlinkMetaEntity']);

        Route::post('pipeline-items/{record}/advance', [ActionController::class, 'advancePipelineItem']);
        Route::post('pipeline-items/{record}/resolve', [ActionController::class, 'resolvePipelineItem']);

        Route::get('{resource}', [ResourceController::class, 'index'])->where('resource', $resourcePattern);
        Route::post('{resource}', [ResourceController::class, 'store'])->where('resource', $resourcePattern);
        Route::get('{resource}/{record}', [ResourceController::class, 'show'])->where('resource', $resourcePattern);
        Route::patch('{resource}/{record}', [ResourceController::class, 'update'])->where('resource', $resourcePattern);
        Route::delete('{resource}/{record}', [ResourceController::class, 'destroy'])->where('resource', $resourcePattern);
    });
