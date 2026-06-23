<?php

namespace App\Mcp\Support;

use App\Domain\System\Services\NotionDataverseSyncService;
use App\Support\Api\ApiResourceRegistry;

class DataverseMcpCatalog
{
    public static function resources(): array
    {
        return ApiResourceRegistry::slugs();
    }

    public static function actions(): array
    {
        return [
            'entity.publish' => [
                'method' => 'POST',
                'path' => 'entities/{record}/publish',
                'resource' => 'entities',
                'description' => 'Publish an entity that already satisfies completion rules.',
            ],
            'entity.unpublish' => [
                'method' => 'POST',
                'path' => 'entities/{record}/unpublish',
                'resource' => 'entities',
                'description' => 'Return a published entity to a draft state.',
            ],
            'entity.archive' => [
                'method' => 'POST',
                'path' => 'entities/{record}/archive',
                'resource' => 'entities',
                'description' => 'Archive an entity without force deleting it.',
            ],
            'entity.save-version' => [
                'method' => 'POST',
                'path' => 'entities/{record}/versions',
                'resource' => 'entities',
                'description' => 'Save a new canon/version snapshot for an entity.',
            ],
            'relationship.tension-charge' => [
                'method' => 'POST',
                'path' => 'relationships/{record}/tension-charge',
                'resource' => 'relationships',
                'description' => 'Update a relationship tension charge and reason.',
            ],
            'group-relationship.tension-charge' => [
                'method' => 'POST',
                'path' => 'group-relationships/{record}/tension-charge',
                'resource' => 'group-relationships',
                'description' => 'Update a group relationship tension charge and reason.',
            ],
            'group-relationship.add-member' => [
                'method' => 'POST',
                'path' => 'group-relationships/{record}/members',
                'resource' => 'group-relationships',
                'description' => 'Attach an entity to a group relationship membership.',
            ],
            'group-relationship.remove-member' => [
                'method' => 'DELETE',
                'path' => 'group-relationships/{record}/members/{secondary}',
                'resource' => 'group-relationships',
                'secondary_key' => 'membership_id',
                'description' => 'Deactivate a group relationship membership record.',
            ],
            'faction-membership.terminate' => [
                'method' => 'POST',
                'path' => 'faction-memberships/{record}/terminate',
                'resource' => 'faction-memberships',
                'description' => 'Terminate a faction membership with closure metadata.',
            ],
            'collection.sync' => [
                'method' => 'POST',
                'path' => 'collections/{record}/sync',
                'resource' => 'collections',
                'description' => 'Re-run smart collection membership sync.',
            ],
            'timeline.place-event' => [
                'method' => 'POST',
                'path' => 'timelines/{record}/events',
                'resource' => 'timelines',
                'description' => 'Place an event entity on a timeline.',
            ],
            'timeline.update-event' => [
                'method' => 'PATCH',
                'path' => 'timelines/{record}/events/{secondary}',
                'resource' => 'timelines',
                'secondary_key' => 'entry_id',
                'description' => 'Edit an existing timeline entry on a timeline.',
            ],
            'timeline.remove-event' => [
                'method' => 'DELETE',
                'path' => 'timelines/{record}/events/{secondary}',
                'resource' => 'timelines',
                'secondary_key' => 'entry_id',
                'description' => 'Soft delete a timeline entry from a timeline.',
            ],
            'power-interaction.resolve' => [
                'method' => 'POST',
                'path' => 'power-interactions/{record}/resolve',
                'resource' => 'power-interactions',
                'description' => 'Resolve a power interaction with outcome data.',
            ],
            'power-interaction.record-instance' => [
                'method' => 'POST',
                'path' => 'power-interactions/{record}/instances',
                'resource' => 'power-interactions',
                'description' => 'Create a power interaction instance tied to an event.',
            ],
            'knowledge-state.act-on' => [
                'method' => 'POST',
                'path' => 'knowledge-states/{record}/act-on',
                'resource' => 'knowledge-states',
                'description' => 'Mark a knowledge state as acted on.',
            ],
            'secret.expose' => [
                'method' => 'POST',
                'path' => 'secrets/{record}/expose',
                'resource' => 'secrets',
                'description' => 'Expose a secret and advance its exposure state.',
            ],
            'secret.add-known-by' => [
                'method' => 'POST',
                'path' => 'secrets/{record}/known-by',
                'resource' => 'secrets',
                'description' => 'Add an entity to the known-by list for a secret.',
            ],
            'secret.remove-known-by' => [
                'method' => 'DELETE',
                'path' => 'secrets/{record}/known-by/{secondary}',
                'resource' => 'secrets',
                'secondary_key' => 'entity_id',
                'description' => 'Remove an entity from the known-by list for a secret.',
            ],
            'secret.add-holder' => [
                'method' => 'POST',
                'path' => 'secrets/{record}/holders',
                'resource' => 'secrets',
                'description' => 'Add an entity to the holder list for a secret.',
            ],
            'secret.remove-holder' => [
                'method' => 'DELETE',
                'path' => 'secrets/{record}/holders/{secondary}',
                'resource' => 'secrets',
                'secondary_key' => 'entity_id',
                'description' => 'Remove an entity from the holder list for a secret.',
            ],
            'perception-state.add-immune' => [
                'method' => 'POST',
                'path' => 'perception-states/{record}/immune',
                'resource' => 'perception-states',
                'description' => 'Add an immune entity to a perception state.',
            ],
            'perception-state.remove-immune' => [
                'method' => 'DELETE',
                'path' => 'perception-states/{record}/immune/{secondary}',
                'resource' => 'perception-states',
                'secondary_key' => 'entity_id',
                'description' => 'Remove an immune entity from a perception state.',
            ],
            'perception-state.collapse' => [
                'method' => 'POST',
                'path' => 'perception-states/{record}/collapse',
                'resource' => 'perception-states',
                'description' => 'Collapse a perception state into a revealed state.',
            ],
            'meta.resolve' => [
                'method' => 'POST',
                'path' => 'meta/{record}/resolve',
                'resource' => 'meta',
                'description' => 'Resolve a meta note.',
            ],
            'meta.supersede' => [
                'method' => 'POST',
                'path' => 'meta/{record}/supersede',
                'resource' => 'meta',
                'description' => 'Supersede a meta note with another one.',
            ],
            'meta.link-entity' => [
                'method' => 'POST',
                'path' => 'meta/{record}/entities',
                'resource' => 'meta',
                'description' => 'Link an entity to a meta note.',
            ],
            'meta.unlink-entity' => [
                'method' => 'DELETE',
                'path' => 'meta/{record}/entities/{secondary}',
                'resource' => 'meta',
                'secondary_key' => 'entity_id',
                'description' => 'Unlink an entity from a meta note.',
            ],
            'pipeline-item.advance' => [
                'method' => 'POST',
                'path' => 'pipeline-items/{record}/advance',
                'resource' => 'pipeline-items',
                'description' => 'Advance a pipeline item to the next stage.',
            ],
            'pipeline-item.resolve' => [
                'method' => 'POST',
                'path' => 'pipeline-items/{record}/resolve',
                'resource' => 'pipeline-items',
                'description' => 'Resolve a pipeline item.',
            ],
        ];
    }

    public static function actionNames(): array
    {
        return array_keys(self::actions());
    }

    public static function action(string $name): array
    {
        $action = self::actions()[$name] ?? null;

        abort_if($action === null, 404, "Unknown Dataverse MCP action [{$name}].");

        return $action;
    }

    public static function notionSyncResources(): array
    {
        return NotionDataverseSyncService::supportedResources();
    }

    public static function catalog(): array
    {
        return [
            'server' => [
                'name' => 'Dataverse Authoring MCP',
                'api_base' => (string) config('services.dataverse_mcp.api_base', '/api/v1'),
                'local_handle' => 'dataverse',
                'web_endpoint' => '/mcp/dataverse',
            ],
            'resources' => collect(self::resources())
                ->map(fn (string $resource) => [
                    'slug' => $resource,
                    'includes' => ApiResourceRegistry::definition($resource)['includes'] ?? [],
                    'search_fields' => ApiResourceRegistry::searchableFields($resource),
                    'filter_fields' => ApiResourceRegistry::filterableFields($resource),
                    'sort_fields' => ApiResourceRegistry::sortableFields($resource),
                    'supports_soft_deletes' => ApiResourceRegistry::supportsSoftDeletes(ApiResourceRegistry::modelClass($resource)),
                ])
                ->values()
                ->all(),
            'actions' => array_map(
                fn (string $name, array $definition) => array_merge(['name' => $name], $definition),
                array_keys(self::actions()),
                array_values(self::actions()),
            ),
            'notion_sync_resources' => self::notionSyncResources(),
            'notes' => [
                'Mutating tools should send base_revision_id for every non-create write.',
                'The API returns canonical envelopes with data, included, and meta.',
                'Use the catalog resource first when you need the exact action or resource slug.',
                'Use upload_dataverse_media when you need to create a media-reference from actual file bytes rather than a URL or local path.',
                'Use replace_dataverse_media_file when you need to swap the stored bytes for an existing media-reference.',
            ],
        ];
    }
}
