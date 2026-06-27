<?php

namespace App\Support\Web;

use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Connections\Models\GroupRelationshipEntity;
use App\Domain\Connections\Models\Relationship;
use App\Domain\Identity\Models\Entity;
use App\Domain\Lore\Models\CanonReferenceEntity;
use App\Domain\Lore\Models\Document;
use App\Domain\Lore\Models\DocumentEntity;
use App\Domain\Lore\Models\SourceCanonReference;
use App\Domain\Organization\Models\Collection;
use App\Domain\Organization\Models\CollectionDocument;
use App\Domain\System\Models\NotionNote;
use App\Domain\System\Models\NotionSyncMapping;
use App\Domain\Temporal\Models\CharacterStateTracker;
use App\Domain\Temporal\Models\StateRelationship;
use App\Domain\Temporal\Models\TimelineEntity;
use App\Domain\World\Models\GalacticRegion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ResourcePageBuilder
{
    public function indexProps(string $resource, Request $request, array $props = []): array
    {
        $records = $this->queryFor($resource)
            ->when($request->filled('q'), fn (Builder $query) => $this->applySearch($resource, $query, trim((string) $request->q)))
            ->paginate(40)
            ->withQueryString();

        return array_merge([
            'title' => $this->pluralLabel($resource),
            'countLabel' => Str::of($this->singularLabel($resource))->plural()->lower()->toString(),
            'indexHref' => route("{$resource}.index"),
            'records' => $records,
            'items' => collect($records->items())->map(fn (Model $record) => $this->indexItem($resource, $record))->values()->all(),
            'filters' => $request->only(['q']),
            'filterFields' => [
                ['key' => 'q', 'type' => 'text', 'placeholder' => "Search {$this->pluralLabel($resource)}..."],
            ],
            'filterRoute' => "{$resource}.index",
            'createHref' => $this->readOnly($resource) ? null : route("{$resource}.create"),
            'createLabel' => "New {$this->singularLabel($resource)}",
            'emptyTitle' => "No {$this->pluralLabel($resource)} yet",
            'emptyCtaLabel' => "Create the first {$this->singularLabel($resource)} ->",
        ], $props);
    }

    public function showProps(string $resource, Model $record, array $props = []): array
    {
        $record->load($this->showRelations($resource));

        return array_merge([
            'title' => $this->showTitle($resource, $record),
            'subtitle' => $this->showSubtitle($resource, $record),
            'backHref' => route("{$resource}.index"),
            'backLabel' => $this->pluralLabel($resource),
            'showHref' => route("{$resource}.show", $record),
            'editHref' => $this->readOnly($resource) ? null : route("{$resource}.edit", $record),
            'destroyHref' => $this->readOnly($resource) ? null : route("{$resource}.destroy", $record),
            'badge' => $this->showBadge($resource, $record),
            'heroMeta' => $this->heroMeta($resource, $record),
            'sections' => $this->sections($resource, $record),
        ], $props);
    }

    public function createDrawerProps(string $resource): array
    {
        return [
            'title' => "New {$this->singularLabel($resource)}",
            'backHref' => route("{$resource}.index"),
            'backLabel' => $this->pluralLabel($resource),
            'cancelHref' => route("{$resource}.index"),
            'submitHref' => route("{$resource}.store"),
            'submitMethod' => 'post',
            'submitLabel' => "Create {$this->singularLabel($resource)}",
            'processingLabel' => 'Creating...',
            'formData' => $this->defaultFormData($resource),
            'sections' => $this->formSections($resource),
        ];
    }

    public function editDrawerProps(string $resource, Model $record): array
    {
        $record->loadMissing($this->showRelations($resource));

        return [
            'title' => "Edit {$this->singularLabel($resource)}",
            'backHref' => route("{$resource}.show", $record),
            'backLabel' => $this->showTitle($resource, $record),
            'cancelHref' => route("{$resource}.show", $record),
            'submitHref' => route("{$resource}.update", $record),
            'submitMethod' => 'put',
            'submitLabel' => "Save {$this->singularLabel($resource)}",
            'processingLabel' => 'Saving...',
            'destroyHref' => route("{$resource}.destroy", $record),
            'formData' => $this->recordFormData($resource, $record),
            'sections' => $this->formSections($resource),
        ];
    }

    public function normalizePayload(array $validated): array
    {
        return array_filter(
            $validated,
            fn (mixed $value) => ! ($value === '' || $value === null) || is_array($value) || is_bool($value),
        );
    }

    public function readOnly(string $resource): bool
    {
        return in_array($resource, ['notion-notes', 'notion-sync-mappings'], true);
    }

    private function modelClass(string $resource): string
    {
        return match ($resource) {
            'group-relationship-memberships' => GroupRelationshipEntity::class,
            'collection-documents' => CollectionDocument::class,
            'document-entities' => DocumentEntity::class,
            'canon-reference-entities' => CanonReferenceEntity::class,
            'timeline-placements' => TimelineEntity::class,
            'state-relationships' => StateRelationship::class,
            'galactic-regions' => GalacticRegion::class,
            'notion-notes' => NotionNote::class,
            'notion-sync-mappings' => NotionSyncMapping::class,
            default => abort(404),
        };
    }

    private function queryFor(string $resource): Builder
    {
        $modelClass = $this->modelClass($resource);

        return match ($resource) {
            'group-relationship-memberships' => $modelClass::query()
                ->with(['groupRelationship:id,name,relationship_type', 'entity:id,name,entity_type'])
                ->orderByDesc('updated_at'),
            'collection-documents' => $modelClass::query()
                ->with(['collection:id,name,collection_type', 'document:id,title,document_type'])
                ->orderBy('sort_order')
                ->orderByDesc('updated_at'),
            'document-entities' => $modelClass::query()
                ->with(['document:id,title,document_type', 'entity:id,name,entity_type'])
                ->orderByDesc('updated_at'),
            'canon-reference-entities' => $modelClass::query()
                ->with(['canonReference:id,title,universe', 'entity:id,name,entity_type'])
                ->orderByDesc('updated_at'),
            'timeline-placements' => $modelClass::query()
                ->with(['timeline:id,name,entity_type', 'eventEntity:id,name,entity_type'])
                ->orderBy('position')
                ->orderByDesc('updated_at'),
            'state-relationships' => $modelClass::query()
                ->with([
                    'characterState:id,entity_id,snapshot_label,au_date',
                    'characterState.entity:id,name,entity_type',
                    'relationship:id,from_entity_id,to_entity_id,relationship_type',
                    'relationship.fromEntity:id,name',
                    'relationship.toEntity:id,name',
                ])
                ->orderByDesc('updated_at'),
            'galactic-regions' => $modelClass::query()
                ->with(['parentRegion:id,region_name', 'controllingEntity:id,name,entity_type'])
                ->orderBy('region_name'),
            'notion-notes' => $modelClass::query()
                ->orderByDesc('last_synced_at')
                ->orderByDesc('updated_at'),
            'notion-sync-mappings' => $modelClass::query()
                ->orderByDesc('last_synced_at')
                ->orderByDesc('updated_at'),
            default => $modelClass::query(),
        };
    }

    private function applySearch(string $resource, Builder $query, string $term): void
    {
        $query->where(function (Builder $inner) use ($resource, $term) {
            match ($resource) {
                'group-relationship-memberships' => $inner
                    ->where('role_in_group', 'like', "%{$term}%")
                    ->orWhere('joined_era', 'like', "%{$term}%")
                    ->orWhere('left_era', 'like', "%{$term}%")
                    ->orWhereHas('groupRelationship', fn (Builder $group) => $group->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('entity', fn (Builder $entity) => $entity->where('name', 'like', "%{$term}%")),
                'collection-documents' => $inner
                    ->where('role_in_collection', 'like', "%{$term}%")
                    ->orWhereHas('collection', fn (Builder $collection) => $collection->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('document', fn (Builder $document) => $document->where('title', 'like', "%{$term}%")),
                'document-entities' => $inner
                    ->where('relationship_type', 'like', "%{$term}%")
                    ->orWhereHas('document', fn (Builder $document) => $document->where('title', 'like', "%{$term}%"))
                    ->orWhereHas('entity', fn (Builder $entity) => $entity->where('name', 'like', "%{$term}%")),
                'canon-reference-entities' => $inner
                    ->where('relationship_type', 'like', "%{$term}%")
                    ->orWhere('divergence_level', 'like', "%{$term}%")
                    ->orWhereHas('canonReference', fn (Builder $reference) => $reference->where('title', 'like', "%{$term}%"))
                    ->orWhereHas('entity', fn (Builder $entity) => $entity->where('name', 'like', "%{$term}%")),
                'timeline-placements' => $inner
                    ->where('perspective_label', 'like', "%{$term}%")
                    ->orWhereHas('timeline', fn (Builder $timeline) => $timeline->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('eventEntity', fn (Builder $entity) => $entity->where('name', 'like', "%{$term}%")),
                'state-relationships' => $inner
                    ->whereHas('characterState', fn (Builder $state) => $state->where('snapshot_label', 'like', "%{$term}%"))
                    ->orWhereHas('characterState.entity', fn (Builder $entity) => $entity->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('relationship.fromEntity', fn (Builder $entity) => $entity->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('relationship.toEntity', fn (Builder $entity) => $entity->where('name', 'like', "%{$term}%")),
                'galactic-regions' => $inner
                    ->where('region_name', 'like', "%{$term}%")
                    ->orWhere('region_type', 'like', "%{$term}%")
                    ->orWhere('source_universe', 'like', "%{$term}%"),
                'notion-notes' => $inner
                    ->where('sync_resource', 'like', "%{$term}%")
                    ->orWhere('notion_page_id', 'like', "%{$term}%")
                    ->orWhere('content', 'like', "%{$term}%"),
                'notion-sync-mappings' => $inner
                    ->where('sync_resource', 'like', "%{$term}%")
                    ->orWhere('notion_page_id', 'like', "%{$term}%")
                    ->orWhere('local_model_type', 'like', "%{$term}%"),
                default => null,
            };
        });
    }

    private function indexItem(string $resource, Model $record): array
    {
        return match ($resource) {
            'group-relationship-memberships' => [
                'id' => $record->getKey(),
                'href' => route("{$resource}.show", $record),
                'title' => $record->entity?->name ?? "Membership #{$record->getKey()}",
                'badges' => $this->badges([
                    ['label' => 'Role', 'value' => $record->role_in_group],
                    ['label' => 'Status', 'value' => $record->is_active_member ? 'active' : 'inactive'],
                ]),
                'meta' => $this->meta([
                    ['label' => 'Group', 'value' => $record->groupRelationship?->name],
                    ['label' => 'Joined', 'value' => $record->joined_era],
                    ['label' => 'Left', 'value' => $record->left_era],
                ]),
            ],
            'collection-documents' => [
                'id' => $record->getKey(),
                'href' => route("{$resource}.show", $record),
                'title' => $record->document?->title ?? "Collection Document #{$record->getKey()}",
                'badges' => $this->badges([
                    ['label' => 'Role', 'value' => $record->role_in_collection],
                ]),
                'meta' => $this->meta([
                    ['label' => 'Collection', 'value' => $record->collection?->name],
                    ['label' => 'Sort', 'value' => $record->sort_order],
                ]),
            ],
            'document-entities' => [
                'id' => $record->getKey(),
                'href' => route("{$resource}.show", $record),
                'title' => $record->entity?->name ?? "Document Entity #{$record->getKey()}",
                'badges' => $this->badges([
                    ['label' => 'Role', 'value' => $record->relationship_type],
                ]),
                'meta' => $this->meta([
                    ['label' => 'Document', 'value' => $record->document?->title],
                ]),
            ],
            'canon-reference-entities' => [
                'id' => $record->getKey(),
                'href' => route("{$resource}.show", $record),
                'title' => $record->entity?->name ?? "Reference Link #{$record->getKey()}",
                'badges' => $this->badges([
                    ['label' => 'Relationship', 'value' => $record->relationship_type],
                    ['label' => 'Divergence', 'value' => $record->divergence_level],
                ]),
                'meta' => $this->meta([
                    ['label' => 'Reference', 'value' => $record->canonReference?->title],
                ]),
            ],
            'timeline-placements' => [
                'id' => $record->getKey(),
                'href' => route("{$resource}.show", $record),
                'title' => $record->eventEntity?->name ?? "Placement #{$record->getKey()}",
                'badges' => $this->badges([
                    ['label' => 'Perspective', 'value' => $record->perspective_label],
                ]),
                'meta' => $this->meta([
                    ['label' => 'Timeline', 'value' => $record->timeline?->name],
                    ['label' => 'Position', 'value' => $record->position],
                ]),
            ],
            'state-relationships' => [
                'id' => $record->getKey(),
                'href' => route("{$resource}.show", $record),
                'title' => $this->relationshipLabel($record->relationship),
                'badges' => $this->badges([
                    ['label' => 'Active', 'value' => $record->is_active_at_snapshot ? 'yes' : 'no'],
                ]),
                'meta' => $this->meta([
                    ['label' => 'Snapshot', 'value' => $record->characterState?->snapshot_label ?: $record->characterState?->entity?->name],
                    ['label' => 'AU Date', 'value' => $record->characterState?->au_date],
                ]),
            ],
            'galactic-regions' => [
                'id' => $record->getKey(),
                'href' => route("{$resource}.show", $record),
                'title' => $record->region_name,
                'badges' => $this->badges([
                    ['label' => 'Type', 'value' => $record->region_type],
                    ['label' => 'Mapped', 'value' => $record->is_fully_mapped ? 'yes' : 'no'],
                ]),
                'meta' => $this->meta([
                    ['label' => 'Parent', 'value' => $record->parentRegion?->region_name],
                    ['label' => 'Control', 'value' => $record->controllingEntity?->name],
                    ['label' => 'Universe', 'value' => $record->source_universe],
                ]),
            ],
            'notion-notes' => [
                'id' => $record->getKey(),
                'href' => route("{$resource}.show", $record),
                'title' => $record->sync_resource ?: "Notion Note #{$record->getKey()}",
                'subtitle' => Str::limit(strip_tags((string) $record->content), 120),
                'meta' => $this->meta([
                    ['label' => 'Page', 'value' => $record->notion_page_id],
                    ['label' => 'Linked Model', 'value' => class_basename((string) $record->noteable_type).' #'.$record->noteable_id],
                    ['label' => 'Synced', 'value' => optional($record->last_synced_at)?->toDateTimeString()],
                ]),
            ],
            'notion-sync-mappings' => [
                'id' => $record->getKey(),
                'href' => route("{$resource}.show", $record),
                'title' => $record->sync_resource ?: "Sync Mapping #{$record->getKey()}",
                'meta' => $this->meta([
                    ['label' => 'Page', 'value' => $record->notion_page_id],
                    ['label' => 'Model', 'value' => class_basename((string) $record->local_model_type).' #'.$record->local_model_id],
                    ['label' => 'Synced', 'value' => optional($record->last_synced_at)?->toDateTimeString()],
                ]),
            ],
            default => [],
        };
    }

    private function showRelations(string $resource): array
    {
        return match ($resource) {
            'group-relationship-memberships' => ['groupRelationship', 'entity'],
            'collection-documents' => ['collection', 'document'],
            'document-entities' => ['document', 'entity'],
            'canon-reference-entities' => ['canonReference', 'entity'],
            'timeline-placements' => ['timeline', 'eventEntity'],
            'state-relationships' => ['characterState.entity', 'relationship.fromEntity', 'relationship.toEntity'],
            'galactic-regions' => ['parentRegion', 'childRegions', 'controllingEntity'],
            default => [],
        };
    }

    private function sections(string $resource, Model $record): array
    {
        return match ($resource) {
            'group-relationship-memberships' => [
                [
                    'title' => 'Links',
                    'entries' => [
                        $this->entry('Group Relationship', $record->groupRelationship?->name, ['href' => $record->groupRelationship ? route('group-relationships.show', $record->groupRelationship) : null]),
                        $this->entry('Entity', $record->entity?->name, ['href' => $record->entity ? route('entities.show', $record->entity) : null]),
                    ],
                ],
                [
                    'title' => 'Membership',
                    'entries' => [
                        $this->entry('Role In Group', $record->role_in_group),
                        $this->entry('Active Member', $record->is_active_member),
                        $this->entry('Joined Era', $record->joined_era),
                        $this->entry('Left Era', $record->left_era),
                    ],
                ],
                [
                    'title' => 'Notes',
                    'entries' => [
                        $this->entry('Participation Notes', $record->participation_notes, ['kind' => 'json']),
                        $this->entry('Departure Notes', $record->departure_notes, ['kind' => 'json']),
                    ],
                    'fullWidth' => true,
                ],
            ],
            'collection-documents' => [
                [
                    'title' => 'Links',
                    'entries' => [
                        $this->entry('Collection', $record->collection?->name, ['href' => $record->collection ? route('collections.show', $record->collection) : null]),
                        $this->entry('Document', $record->document?->title, ['href' => $record->document ? route('documents.show', $record->document) : null]),
                    ],
                ],
                [
                    'title' => 'Membership',
                    'entries' => [
                        $this->entry('Role In Collection', $record->role_in_collection),
                        $this->entry('Sort Order', $record->sort_order),
                        $this->entry('Notes', $record->notes),
                    ],
                ],
            ],
            'document-entities' => [
                [
                    'title' => 'Links',
                    'entries' => [
                        $this->entry('Document', $record->document?->title, ['href' => $record->document ? route('documents.show', $record->document) : null]),
                        $this->entry('Entity', $record->entity?->name, ['href' => $record->entity ? route('entities.show', $record->entity) : null]),
                    ],
                ],
                [
                    'title' => 'Relationship',
                    'entries' => [
                        $this->entry('Relationship Type', $record->relationship_type),
                        $this->entry('Notes', $record->notes, ['kind' => 'json']),
                    ],
                    'fullWidth' => true,
                ],
            ],
            'canon-reference-entities' => [
                [
                    'title' => 'Links',
                    'entries' => [
                        $this->entry('Canon Reference', $record->canonReference?->title, ['href' => $record->canonReference ? route('canon-references.show', $record->canonReference) : null]),
                        $this->entry('Entity', $record->entity?->name, ['href' => $record->entity ? route('entities.show', $record->entity) : null]),
                    ],
                ],
                [
                    'title' => 'Mapping',
                    'entries' => [
                        $this->entry('Relationship Type', $record->relationship_type),
                        $this->entry('Divergence Level', $record->divergence_level),
                        $this->entry('Divergence Notes', $record->divergence_notes, ['kind' => 'json']),
                    ],
                    'fullWidth' => true,
                ],
            ],
            'timeline-placements' => [
                [
                    'title' => 'Links',
                    'entries' => [
                        $this->entry('Timeline', $record->timeline?->name, ['href' => $record->timeline ? route('timelines.show', $record->timeline) : null]),
                        $this->entry('Event Entity', $record->eventEntity?->name, ['href' => $record->eventEntity ? route('entities.show', $record->eventEntity) : null]),
                    ],
                ],
                [
                    'title' => 'Placement',
                    'entries' => [
                        $this->entry('Position', $record->position),
                        $this->entry('Perspective Label', $record->perspective_label),
                        $this->entry('Perspective Notes', $record->perspective_notes, ['kind' => 'json']),
                    ],
                    'fullWidth' => true,
                ],
            ],
            'state-relationships' => [
                [
                    'title' => 'Links',
                    'entries' => [
                        $this->entry('Character State', $record->characterState?->snapshot_label ?: $record->characterState?->entity?->name, ['href' => $record->characterState ? route('character-states.show', $record->characterState) : null]),
                        $this->entry('Relationship', $this->relationshipLabel($record->relationship), ['href' => $record->relationship ? route('relationships.show', $record->relationship) : null]),
                    ],
                ],
                [
                    'title' => 'Snapshot Relationship',
                    'entries' => [
                        $this->entry('Active At Snapshot', $record->is_active_at_snapshot),
                        $this->entry('Relationship State', $record->relationship_state_at_snapshot, ['kind' => 'json']),
                    ],
                    'fullWidth' => true,
                ],
            ],
            'galactic-regions' => [
                [
                    'title' => 'Overview',
                    'entries' => [
                        $this->entry('Region Type', $record->region_type),
                        $this->entry('Approximate Scale', $record->approximate_scale),
                        $this->entry('Universe', $record->source_universe),
                        $this->entry('Visibility', $record->visibility),
                        $this->entry('Classification', $record->content_classification),
                        $this->entry('Fully Mapped', $record->is_fully_mapped),
                    ],
                ],
                [
                    'title' => 'Control',
                    'entries' => [
                        $this->entry('Parent Region', $record->parentRegion?->region_name, ['href' => $record->parentRegion ? route('galactic-regions.show', $record->parentRegion) : null]),
                        $this->entry('Controlling Entity', $record->controllingEntity?->name, ['href' => $record->controllingEntity ? route('entities.show', $record->controllingEntity) : null]),
                        $this->entry('Control Era Start', $record->control_era_start),
                        $this->entry('Control Era End', $record->control_era_end),
                    ],
                ],
                [
                    'title' => 'Details',
                    'entries' => [
                        $this->entry('Notable Features', $record->notable_features, ['kind' => 'json']),
                        $this->entry('Known Inhabited Systems', $record->known_inhabited_systems, ['kind' => 'json']),
                        $this->entry('Strategic Significance', $record->strategic_significance, ['kind' => 'json']),
                        $this->entry('Mapping Notes', $record->mapping_notes, ['kind' => 'json']),
                        $this->entry('Connected Locations', collect($record->connected_location_entity_ids ?? [])->map(fn ($id) => ['label' => "Entity #{$id}", 'href' => route('entities.show', $id)])->values()->all(), ['kind' => 'list']),
                    ],
                    'fullWidth' => true,
                ],
                [
                    'title' => 'Hierarchy',
                    'entries' => [
                        $this->entry('Child Regions', $record->childRegions->map(fn (GalacticRegion $child) => [
                            'label' => $child->region_name,
                            'href' => route('galactic-regions.show', $child),
                        ])->values()->all(), ['kind' => 'list']),
                    ],
                ],
            ],
            'notion-notes' => [
                [
                    'title' => 'Overview',
                    'entries' => [
                        $this->entry('Sync Resource', $record->sync_resource),
                        $this->entry('Notion Page ID', $record->notion_page_id),
                        $this->entry('Linked Model', class_basename((string) $record->noteable_type).' #'.$record->noteable_id),
                        $this->entry('Last Synced At', optional($record->last_synced_at)?->toDateTimeString()),
                        $this->entry('Last Edited In Notion', optional($record->notion_last_edited_at)?->toDateTimeString()),
                        $this->linkedLocalEntry($record->noteable_type, $record->noteable_id),
                    ],
                ],
                [
                    'title' => 'Content',
                    'entries' => [
                        $this->entry('Content', $record->content),
                    ],
                    'fullWidth' => true,
                ],
            ],
            'notion-sync-mappings' => [
                [
                    'title' => 'Overview',
                    'entries' => [
                        $this->entry('Sync Resource', $record->sync_resource),
                        $this->entry('Notion Page ID', $record->notion_page_id),
                        $this->entry('Parent Database ID', $record->notion_parent_database_id),
                        $this->entry('Local Model', class_basename((string) $record->local_model_type).' #'.$record->local_model_id),
                        $this->entry('Last Synced At', optional($record->last_synced_at)?->toDateTimeString()),
                        $this->entry('Last Edited In Notion', optional($record->notion_last_edited_at)?->toDateTimeString()),
                        $this->entry('Payload Hash', $record->last_payload_hash),
                        $this->linkedLocalEntry($record->local_model_type, $record->local_model_id),
                    ],
                ],
            ],
            default => [],
        };
    }

    private function showTitle(string $resource, Model $record): string
    {
        return match ($resource) {
            'group-relationship-memberships' => $record->entity?->name ?? "Membership #{$record->getKey()}",
            'collection-documents' => $record->document?->title ?? "Collection Document #{$record->getKey()}",
            'document-entities' => $record->entity?->name ?? "Document Entity #{$record->getKey()}",
            'canon-reference-entities' => $record->entity?->name ?? "Reference Link #{$record->getKey()}",
            'timeline-placements' => $record->eventEntity?->name ?? "Placement #{$record->getKey()}",
            'state-relationships' => $this->relationshipLabel($record->relationship),
            'galactic-regions' => $record->region_name,
            'notion-notes' => "Notion Note #{$record->getKey()}",
            'notion-sync-mappings' => "Sync Mapping #{$record->getKey()}",
            default => "{$this->singularLabel($resource)} #{$record->getKey()}",
        };
    }

    private function showSubtitle(string $resource, Model $record): string
    {
        return match ($resource) {
            'group-relationship-memberships' => $record->groupRelationship?->name ?? '',
            'collection-documents' => $record->collection?->name ?? '',
            'document-entities' => $record->document?->title ?? '',
            'canon-reference-entities' => $record->canonReference?->title ?? '',
            'timeline-placements' => $record->timeline?->name ?? '',
            'state-relationships' => $record->characterState?->snapshot_label ?: ($record->characterState?->entity?->name ?? ''),
            'galactic-regions' => $record->parentRegion?->region_name ? "Child of {$record->parentRegion->region_name}" : '',
            default => '',
        };
    }

    private function showBadge(string $resource, Model $record): string
    {
        return match ($resource) {
            'group-relationship-memberships' => $record->role_in_group ?: ($record->is_active_member ? 'active' : 'inactive'),
            'collection-documents' => $record->role_in_collection ?: 'document link',
            'document-entities' => $record->relationship_type ?: 'document link',
            'canon-reference-entities' => $record->relationship_type ?: 'reference link',
            'timeline-placements' => $record->perspective_label ?: 'timeline placement',
            'state-relationships' => $record->is_active_at_snapshot ? 'active' : 'inactive',
            'galactic-regions' => $record->region_type ?: 'region',
            default => '',
        };
    }

    private function heroMeta(string $resource, Model $record): array
    {
        return match ($resource) {
            'galactic-regions' => $this->meta([
                ['label' => 'Universe', 'value' => $record->source_universe],
                ['label' => 'Mapped', 'value' => $record->is_fully_mapped ? 'Yes' : 'No'],
                ['label' => 'Control', 'value' => $record->controllingEntity?->name],
            ]),
            default => [],
        };
    }

    private function defaultFormData(string $resource): array
    {
        return match ($resource) {
            'group-relationship-memberships' => [
                'group_relationship_id' => '',
                'entity_id' => '',
                'role_in_group' => '',
                'participation_notes' => null,
                'is_active_member' => true,
                'joined_era' => '',
                'left_era' => '',
                'departure_notes' => null,
            ],
            'collection-documents' => [
                'collection_id' => '',
                'document_id' => '',
                'role_in_collection' => '',
                'sort_order' => '',
                'notes' => '',
            ],
            'document-entities' => [
                'document_id' => '',
                'entity_id' => '',
                'relationship_type' => '',
                'notes' => null,
            ],
            'canon-reference-entities' => [
                'canon_reference_id' => '',
                'entity_id' => '',
                'divergence_level' => '',
                'relationship_type' => '',
                'divergence_notes' => null,
            ],
            'timeline-placements' => [
                'timeline_id' => '',
                'event_entity_id' => '',
                'position' => '',
                'perspective_label' => '',
                'perspective_notes' => null,
            ],
            'state-relationships' => [
                'character_state_id' => '',
                'relationship_id' => '',
                'is_active_at_snapshot' => true,
                'relationship_state_at_snapshot' => null,
            ],
            'galactic-regions' => [
                'region_name' => '',
                'region_type' => '',
                'parent_region_id' => '',
                'approximate_scale' => '',
                'notable_features' => null,
                'known_inhabited_systems' => [],
                'strategic_significance' => null,
                'controlling_entity_id' => '',
                'control_era_start' => '',
                'control_era_end' => '',
                'is_fully_mapped' => false,
                'mapping_notes' => null,
                'connected_location_entity_ids' => [],
                'source_universe' => '',
                'visibility' => '',
                'content_classification' => '',
            ],
            default => [],
        };
    }

    private function recordFormData(string $resource, Model $record): array
    {
        return match ($resource) {
            'group-relationship-memberships' => [
                'group_relationship_id' => $record->group_relationship_id,
                'entity_id' => $record->entity_id,
                'role_in_group' => $record->role_in_group,
                'participation_notes' => $record->participation_notes,
                'is_active_member' => (bool) $record->is_active_member,
                'joined_era' => $record->joined_era,
                'left_era' => $record->left_era,
                'departure_notes' => $record->departure_notes,
            ],
            'collection-documents' => [
                'collection_id' => $record->collection_id,
                'document_id' => $record->document_id,
                'role_in_collection' => $record->role_in_collection,
                'sort_order' => $record->sort_order,
                'notes' => $record->notes,
            ],
            'document-entities' => [
                'document_id' => $record->document_id,
                'entity_id' => $record->entity_id,
                'relationship_type' => $record->relationship_type,
                'notes' => $record->notes,
            ],
            'canon-reference-entities' => [
                'canon_reference_id' => $record->canon_reference_id,
                'entity_id' => $record->entity_id,
                'divergence_level' => $record->divergence_level,
                'relationship_type' => $record->relationship_type,
                'divergence_notes' => $record->divergence_notes,
            ],
            'timeline-placements' => [
                'timeline_id' => $record->timeline_id,
                'event_entity_id' => $record->event_entity_id,
                'position' => $record->position,
                'perspective_label' => $record->perspective_label,
                'perspective_notes' => $record->perspective_notes,
            ],
            'state-relationships' => [
                'character_state_id' => $record->character_state_id,
                'relationship_id' => $record->relationship_id,
                'is_active_at_snapshot' => (bool) $record->is_active_at_snapshot,
                'relationship_state_at_snapshot' => $record->relationship_state_at_snapshot,
            ],
            'galactic-regions' => [
                'region_name' => $record->region_name,
                'region_type' => $record->region_type,
                'parent_region_id' => $record->parent_region_id,
                'approximate_scale' => $record->approximate_scale,
                'notable_features' => $record->notable_features,
                'known_inhabited_systems' => $record->known_inhabited_systems ?? [],
                'strategic_significance' => $record->strategic_significance,
                'controlling_entity_id' => $record->controlling_entity_id,
                'control_era_start' => $record->control_era_start,
                'control_era_end' => $record->control_era_end,
                'is_fully_mapped' => (bool) $record->is_fully_mapped,
                'mapping_notes' => $record->mapping_notes,
                'connected_location_entity_ids' => $record->connected_location_entity_ids ?? [],
                'source_universe' => $record->source_universe,
                'visibility' => $record->visibility,
                'content_classification' => $record->content_classification,
            ],
            default => [],
        };
    }

    private function formSections(string $resource): array
    {
        return match ($resource) {
            'group-relationship-memberships' => [
                [
                    'title' => 'Membership',
                    'fields' => [
                        $this->field('group_relationship_id', 'Group Relationship', 'select', ['options' => $this->groupRelationshipOptions(), 'required' => true, 'placeholder' => 'Select a group relationship...']),
                        $this->field('entity_id', 'Entity', 'select', ['options' => $this->entityOptions(), 'required' => true, 'placeholder' => 'Select an entity...']),
                        $this->field('role_in_group', 'Role In Group'),
                        $this->field('is_active_member', 'Active Member', 'checkbox'),
                        $this->field('joined_era', 'Joined Era'),
                        $this->field('left_era', 'Left Era'),
                    ],
                ],
                [
                    'title' => 'Notes',
                    'fields' => [
                        $this->field('participation_notes', 'Participation Notes', 'json'),
                        $this->field('departure_notes', 'Departure Notes', 'json'),
                    ],
                ],
            ],
            'collection-documents' => [
                [
                    'title' => 'Link',
                    'fields' => [
                        $this->field('collection_id', 'Collection', 'select', ['options' => $this->collectionOptions(), 'required' => true, 'placeholder' => 'Select a collection...']),
                        $this->field('document_id', 'Document', 'select', ['options' => $this->documentOptions(), 'required' => true, 'placeholder' => 'Select a document...']),
                        $this->field('role_in_collection', 'Role In Collection'),
                        $this->field('sort_order', 'Sort Order', 'number'),
                        $this->field('notes', 'Notes'),
                    ],
                ],
            ],
            'document-entities' => [
                [
                    'title' => 'Link',
                    'fields' => [
                        $this->field('document_id', 'Document', 'select', ['options' => $this->documentOptions(), 'required' => true, 'placeholder' => 'Select a document...']),
                        $this->field('entity_id', 'Entity', 'select', ['options' => $this->entityOptions(), 'required' => true, 'placeholder' => 'Select an entity...']),
                        $this->field('relationship_type', 'Relationship Type', 'select', ['options' => DocumentEntity::RELATIONSHIP_TYPES, 'required' => true]),
                        $this->field('notes', 'Notes', 'json'),
                    ],
                ],
            ],
            'canon-reference-entities' => [
                [
                    'title' => 'Link',
                    'fields' => [
                        $this->field('canon_reference_id', 'Canon Reference', 'select', ['options' => $this->canonReferenceOptions(), 'required' => true, 'placeholder' => 'Select a canon reference...']),
                        $this->field('entity_id', 'Entity', 'select', ['options' => $this->entityOptions(), 'required' => true, 'placeholder' => 'Select an entity...']),
                        $this->field('relationship_type', 'Relationship Type', 'select', ['options' => CanonReferenceEntity::RELATIONSHIP_TYPES, 'required' => true]),
                        $this->field('divergence_level', 'Divergence Level', 'select', ['options' => CanonReferenceEntity::DIVERGENCE_LEVELS]),
                        $this->field('divergence_notes', 'Divergence Notes', 'json'),
                    ],
                ],
            ],
            'timeline-placements' => [
                [
                    'title' => 'Placement',
                    'fields' => [
                        $this->field('timeline_id', 'Timeline', 'select', ['options' => $this->timelineOptions(), 'required' => true, 'placeholder' => 'Select a timeline...']),
                        $this->field('event_entity_id', 'Event Entity', 'select', ['options' => $this->eventEntityOptions(), 'required' => true, 'placeholder' => 'Select an event entity...']),
                        $this->field('position', 'Position', 'number'),
                        $this->field('perspective_label', 'Perspective Label'),
                        $this->field('perspective_notes', 'Perspective Notes', 'json'),
                    ],
                ],
            ],
            'state-relationships' => [
                [
                    'title' => 'Snapshot Link',
                    'fields' => [
                        $this->field('character_state_id', 'Character State', 'select', ['options' => $this->characterStateOptions(), 'required' => true, 'placeholder' => 'Select a character state...']),
                        $this->field('relationship_id', 'Relationship', 'select', ['options' => $this->relationshipOptions(), 'required' => true, 'placeholder' => 'Select a relationship...']),
                        $this->field('is_active_at_snapshot', 'Active At Snapshot', 'checkbox'),
                        $this->field('relationship_state_at_snapshot', 'Relationship State', 'json'),
                    ],
                ],
            ],
            'galactic-regions' => [
                [
                    'title' => 'Region',
                    'fields' => [
                        $this->field('region_name', 'Region Name', 'text', ['required' => true]),
                        $this->field('region_type', 'Region Type', 'select', ['options' => GalacticRegion::REGION_TYPES, 'required' => true]),
                        $this->field('parent_region_id', 'Parent Region', 'select', ['options' => $this->galacticRegionOptions(), 'placeholder' => 'No parent region']),
                        $this->field('approximate_scale', 'Approximate Scale'),
                        $this->field('source_universe', 'Source Universe'),
                        $this->field('visibility', 'Visibility'),
                        $this->field('content_classification', 'Content Classification'),
                        $this->field('is_fully_mapped', 'Fully Mapped', 'checkbox'),
                    ],
                ],
                [
                    'title' => 'Control',
                    'fields' => [
                        $this->field('controlling_entity_id', 'Controlling Entity', 'select', ['options' => $this->entityOptions(), 'placeholder' => 'No controlling entity']),
                        $this->field('control_era_start', 'Control Era Start'),
                        $this->field('control_era_end', 'Control Era End'),
                    ],
                ],
                [
                    'title' => 'Details',
                    'fields' => [
                        $this->field('notable_features', 'Notable Features', 'json'),
                        $this->field('known_inhabited_systems', 'Known Inhabited Systems', 'json', ['jsonMode' => 'list', 'emptyValue' => []]),
                        $this->field('strategic_significance', 'Strategic Significance', 'json'),
                        $this->field('mapping_notes', 'Mapping Notes', 'json'),
                        $this->field('connected_location_entity_ids', 'Connected Location Entity IDs', 'json', ['jsonMode' => 'ids', 'emptyValue' => []]),
                    ],
                ],
            ],
            default => [],
        };
    }

    private function entry(string $label, mixed $value, array $extra = []): array
    {
        return array_filter(
            array_merge(['label' => $label, 'value' => $value], $extra),
            fn (mixed $item, string $key) => $item !== null || $key === 'value',
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function linkedLocalEntry(?string $modelType, int|string|null $id): array
    {
        $link = $this->localResourceHref($modelType, $id);

        return $this->entry('Linked Record', $link['label'] ?? null, ['href' => $link['href'] ?? null]);
    }

    private function localResourceHref(?string $modelType, int|string|null $id): array
    {
        if (! $modelType || ! $id) {
            return [];
        }

        return match ($modelType) {
            Entity::class => ['label' => "Entity #{$id}", 'href' => route('entities.show', $id)],
            GroupRelationship::class => ['label' => "Group Relationship #{$id}", 'href' => route('group-relationships.show', $id)],
            Collection::class => ['label' => "Collection #{$id}", 'href' => route('collections.show', $id)],
            Document::class => ['label' => "Document #{$id}", 'href' => route('documents.show', $id)],
            SourceCanonReference::class => ['label' => "Canon Reference #{$id}", 'href' => route('canon-references.show', $id)],
            CharacterStateTracker::class => ['label' => "Character State #{$id}", 'href' => route('character-states.show', $id)],
            GalacticRegion::class => ['label' => "Galactic Region #{$id}", 'href' => route('galactic-regions.show', $id)],
            default => ['label' => class_basename($modelType)." #{$id}"],
        };
    }

    private function field(string $key, string $label, string $type = 'text', array $extra = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'type' => $type,
        ], $extra);
    }

    private function badges(array $items): array
    {
        return collect($items)
            ->filter(fn (array $item) => filled($item['value'] ?? null))
            ->values()
            ->all();
    }

    private function meta(array $items): array
    {
        return collect($items)
            ->filter(fn (array $item) => filled($item['value'] ?? null))
            ->values()
            ->all();
    }

    private function singularLabel(string $resource): string
    {
        return match ($resource) {
            'group-relationship-memberships' => 'Group Membership',
            'collection-documents' => 'Collection Document',
            'document-entities' => 'Document Entity Link',
            'canon-reference-entities' => 'Canon Reference Link',
            'timeline-placements' => 'Timeline Placement',
            'state-relationships' => 'State Relationship',
            'galactic-regions' => 'Galactic Region',
            'notion-notes' => 'Notion Note',
            'notion-sync-mappings' => 'Notion Sync Mapping',
            default => Str::of($resource)->replace('-', ' ')->singular()->title()->toString(),
        };
    }

    private function pluralLabel(string $resource): string
    {
        return match ($resource) {
            'group-relationship-memberships' => 'Group Memberships',
            'collection-documents' => 'Collection Documents',
            'document-entities' => 'Document Entity Links',
            'canon-reference-entities' => 'Canon Reference Links',
            'timeline-placements' => 'Timeline Placements',
            'state-relationships' => 'State Relationships',
            'galactic-regions' => 'Galactic Regions',
            'notion-notes' => 'Notion Notes',
            'notion-sync-mappings' => 'Notion Sync Mappings',
            default => Str::of($resource)->replace('-', ' ')->title()->toString(),
        };
    }

    private function entityOptions(): array
    {
        return Entity::query()
            ->select('id', 'name', 'entity_type')
            ->orderBy('name')
            ->get()
            ->map(fn (Entity $entity) => [
                'value' => $entity->id,
                'label' => sprintf('%s (#%d%s)', $entity->name, $entity->id, $entity->entity_type ? ' · '.$this->formatLabel($entity->entity_type) : ''),
            ])
            ->all();
    }

    private function groupRelationshipOptions(): array
    {
        return GroupRelationship::query()
            ->select('id', 'name', 'relationship_type')
            ->orderBy('name')
            ->get()
            ->map(fn (GroupRelationship $group) => [
                'value' => $group->id,
                'label' => sprintf('%s (#%d%s)', $group->name, $group->id, $group->relationship_type ? ' · '.$this->formatLabel($group->relationship_type) : ''),
            ])
            ->all();
    }

    private function collectionOptions(): array
    {
        return Collection::query()
            ->select('id', 'name', 'collection_type')
            ->orderBy('name')
            ->get()
            ->map(fn (Collection $collection) => [
                'value' => $collection->id,
                'label' => sprintf('%s (#%d%s)', $collection->name, $collection->id, $collection->collection_type ? ' · '.$this->formatLabel($collection->collection_type) : ''),
            ])
            ->all();
    }

    private function documentOptions(): array
    {
        return Document::query()
            ->select('id', 'title', 'document_type')
            ->orderBy('title')
            ->get()
            ->map(fn (Document $document) => [
                'value' => $document->id,
                'label' => sprintf('%s (#%d%s)', $document->title, $document->id, $document->document_type ? ' · '.$this->formatLabel($document->document_type) : ''),
            ])
            ->all();
    }

    private function canonReferenceOptions(): array
    {
        return SourceCanonReference::query()
            ->select('id', 'title', 'universe')
            ->orderBy('title')
            ->get()
            ->map(fn (SourceCanonReference $reference) => [
                'value' => $reference->id,
                'label' => sprintf('%s (#%d%s)', $reference->title, $reference->id, $reference->universe ? ' · '.$reference->universe : ''),
            ])
            ->all();
    }

    private function timelineOptions(): array
    {
        return Entity::query()
            ->select('id', 'name')
            ->where('entity_type', 'timeline')
            ->orderBy('name')
            ->get()
            ->map(fn (Entity $timeline) => [
                'value' => $timeline->id,
                'label' => sprintf('%s (#%d)', $timeline->name, $timeline->id),
            ])
            ->all();
    }

    private function eventEntityOptions(): array
    {
        return Entity::events()
            ->select('id', 'name', 'entity_type')
            ->orderBy('name')
            ->get()
            ->map(fn (Entity $entity) => [
                'value' => $entity->id,
                'label' => sprintf('%s (#%d%s)', $entity->name, $entity->id, $entity->entity_type ? ' · '.$this->formatLabel($entity->entity_type) : ''),
            ])
            ->all();
    }

    private function relationshipOptions(): array
    {
        return Relationship::query()
            ->with(['fromEntity:id,name', 'toEntity:id,name'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Relationship $relationship) => [
                'value' => $relationship->id,
                'label' => $this->relationshipLabel($relationship)." (#{$relationship->id})",
            ])
            ->all();
    }

    private function characterStateOptions(): array
    {
        return CharacterStateTracker::query()
            ->with('entity:id,name')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (CharacterStateTracker $state) => [
                'value' => $state->id,
                'label' => sprintf('%s (#%d%s)', $state->snapshot_label ?: ($state->entity?->name ?: 'Character State'), $state->id, $state->au_date ? ' · '.$state->au_date : ''),
            ])
            ->all();
    }

    private function galacticRegionOptions(): array
    {
        return GalacticRegion::query()
            ->select('id', 'region_name', 'region_type')
            ->orderBy('region_name')
            ->get()
            ->map(fn (GalacticRegion $region) => [
                'value' => $region->id,
                'label' => sprintf('%s (#%d%s)', $region->region_name, $region->id, $region->region_type ? ' · '.$this->formatLabel($region->region_type) : ''),
            ])
            ->all();
    }

    private function relationshipLabel(?Relationship $relationship): string
    {
        if (! $relationship) {
            return 'Relationship';
        }

        $from = $relationship->fromEntity?->name ?? 'Unknown';
        $to = $relationship->toEntity?->name ?? 'Unknown';
        $type = $relationship->relationship_type ? ' · '.$this->formatLabel($relationship->relationship_type) : '';

        return "{$from} -> {$to}{$type}";
    }

    private function formatLabel(?string $value): string
    {
        if (! filled($value)) {
            return '—';
        }

        return Str::of($value)->replace('_', ' ')->title()->toString();
    }
}
