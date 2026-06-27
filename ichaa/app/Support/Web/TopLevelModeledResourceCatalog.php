<?php

namespace App\Support\Web;

use App\Domain\Connections\Models\FactionMembership;
use App\Domain\Connections\Models\GroupRelationshipEntity;
use App\Domain\Identity\Models\Entity;
use App\Domain\Lore\Models\CanonReferenceEntity;
use App\Domain\Lore\Models\DocumentEntity;
use App\Domain\Organization\Models\CollectionDocument;
use App\Domain\Organization\Models\CollectionEntity;
use App\Domain\Temporal\Models\StateRelationship;
use App\Domain\Temporal\Models\TimelineEntity;
use App\Domain\World\Models\PowerInteractionInstance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class TopLevelModeledResourceCatalog
{
    public function __construct(
        private readonly DataverseWebResourceRegistry $registry,
    ) {}

    public function definition(string $resource): array
    {
        $definition = $this->definitions()[$resource] ?? null;

        abort_unless($definition, 404);

        return $definition;
    }

    public function paginate(string $resource, Request $request, int $perPage = 40): LengthAwarePaginator
    {
        $definition = $this->definition($resource);
        $query = $definition['query']();

        $definition['apply_filters']($query, $request);

        return $query->paginate($perPage)->withQueryString();
    }

    public function findOrFail(string $resource, int|string $id): Model
    {
        $definition = $this->definition($resource);

        return $definition['show_query']()->findOrFail($id);
    }

    public function indexProps(string $resource, LengthAwarePaginator $records, array $filters): array
    {
        $definition = $this->definition($resource);

        return [
            'resource' => [
                'key' => $resource,
                'title' => $definition['title'],
                'countLabel' => $definition['count_label'],
                'routeName' => $definition['route_name'],
                'emptyTitle' => $definition['empty_title'],
            ],
            'records' => $records,
            'items' => collect($records->items())
                ->map(fn (Model $record) => $definition['item']($record))
                ->values(),
            'filters' => $filters,
            'filterFields' => $definition['filter_fields'],
        ];
    }

    public function showProps(string $resource, Model $record): array
    {
        $definition = $this->definition($resource);

        return array_merge([
            'resource' => [
                'key' => $resource,
                'title' => $definition['title'],
                'backLabel' => $definition['title'],
                'backHref' => route($definition['route_name']),
            ],
        ], $definition['show']($record));
    }

    private function definitions(): array
    {
        return [
            'faction-memberships' => [
                'title' => 'Faction Memberships',
                'count_label' => 'memberships',
                'route_name' => 'faction-memberships.index',
                'empty_title' => 'No faction memberships found',
                'filter_fields' => [
                    ['key' => 'q', 'type' => 'text', 'placeholder' => 'Search memberships...'],
                    ['key' => 'status', 'type' => 'select', 'placeholder' => 'All statuses', 'options' => ['active', 'former', 'suspended', 'unclear']],
                ],
                'query' => fn (): Builder => FactionMembership::query()
                    ->with([
                        'faction:id,name,entity_type',
                        'member:id,name,entity_type',
                        'trueLoyalty:id,name,entity_type',
                        'recruitedBy:id,name,entity_type',
                    ])
                    ->latest('id'),
                'show_query' => fn (): Builder => FactionMembership::query()
                    ->with([
                        'faction:id,name,entity_type',
                        'member:id,name,entity_type',
                        'trueLoyalty:id,name,entity_type',
                        'recruitedBy:id,name,entity_type',
                    ]),
                'apply_filters' => function (Builder $query, Request $request): void {
                    if ($request->filled('status')) {
                        $query->where('membership_status', $request->string('status')->value());
                    }

                    $this->applyTermFilter($query, (string) $request->string('q')->trim(), [
                        'rank_or_role',
                        'membership_status',
                        'joined_era',
                        'left_era',
                    ], [
                        'faction' => 'name',
                        'member' => 'name',
                    ]);
                },
                'item' => fn (FactionMembership $record) => [
                    'id' => $record->id,
                    'href' => route('faction-memberships.show', $record->id),
                    'title' => ($record->member?->name ?: 'Unknown').' -> '.($record->faction?->name ?: 'Unknown'),
                    'badges' => [$this->badge('Status', $record->membership_status)],
                    'meta' => $this->meta([
                        ['label' => 'Role', 'value' => $record->rank_or_role],
                        ['label' => 'Joined', 'value' => $record->joined_era],
                        ['label' => 'Left', 'value' => $record->left_era],
                        ['label' => 'Undercover', 'value' => $record->is_undercover],
                    ]),
                ],
                'show' => fn (FactionMembership $record) => [
                    'title' => ($record->member?->name ?: 'Unknown').' -> '.($record->faction?->name ?: 'Unknown'),
                    'badge' => $this->formatLabel($record->membership_status ?: 'membership'),
                    'sections' => [
                        [
                            'title' => 'Membership',
                            'entries' => [
                                $this->entry('Faction', $record->faction?->name, $this->linkEntry($this->registry->linkForResourceType('entities', $record->faction_entity_id))),
                                $this->entry('Member', $record->member?->name, $this->linkEntry($this->registry->linkForResourceType('entities', $record->member_entity_id))),
                                $this->entry('Role', $record->rank_or_role),
                                $this->entry('Status', $this->formatLabel($record->membership_status)),
                                $this->entry('Joined Era', $record->joined_era),
                                $this->entry('Left Era', $record->left_era),
                                $this->entry('Undercover', $record->is_undercover),
                                $this->entry('Publicly Known', $record->public_membership_known),
                                $this->entry('True Loyalty', $record->trueLoyalty?->name, $this->linkEntry($record->true_loyalty_entity_id ? $this->registry->linkForResourceType('entities', $record->true_loyalty_entity_id) : null)),
                                $this->entry('Recruited By', $record->recruitedBy?->name, $this->linkEntry($record->recruited_by_entity_id ? $this->registry->linkForResourceType('entities', $record->recruited_by_entity_id) : null)),
                                $this->entry('Visibility', $this->formatLabel($record->visibility)),
                                $this->entry('Content Classification', $this->formatLabel($record->content_classification)),
                            ],
                        ],
                        [
                            'title' => 'Narrative',
                            'entries' => [
                                $this->entry('Departure Reason', $record->departure_reason, ['kind' => 'json']),
                                $this->entry('Notes', $record->notes, ['kind' => 'json']),
                            ],
                            'fullWidth' => true,
                        ],
                    ],
                ],
            ],
            'group-relationship-memberships' => [
                'title' => 'Group Relationship Memberships',
                'count_label' => 'membership records',
                'route_name' => 'group-relationship-memberships.index',
                'empty_title' => 'No group relationship memberships found',
                'filter_fields' => [
                    ['key' => 'q', 'type' => 'text', 'placeholder' => 'Search memberships...'],
                    ['key' => 'active', 'type' => 'select', 'placeholder' => 'All states', 'options' => [
                        ['value' => 'current', 'label' => 'Current only'],
                        ['value' => 'former', 'label' => 'Former only'],
                    ]],
                ],
                'query' => fn (): Builder => GroupRelationshipEntity::query()
                    ->with(['groupRelationship:id,name,relationship_type', 'entity:id,name,entity_type'])
                    ->latest('id'),
                'show_query' => fn (): Builder => GroupRelationshipEntity::query()
                    ->with(['groupRelationship:id,name,relationship_type', 'entity:id,name,entity_type']),
                'apply_filters' => function (Builder $query, Request $request): void {
                    match ($request->string('active')->value()) {
                        'current' => $query->where('is_active_member', true),
                        'former' => $query->where('is_active_member', false),
                        default => null,
                    };

                    $this->applyTermFilter($query, (string) $request->string('q')->trim(), [
                        'role_in_group',
                        'joined_era',
                        'left_era',
                    ], [
                        'groupRelationship' => 'name',
                        'entity' => 'name',
                    ]);
                },
                'item' => fn (GroupRelationshipEntity $record) => [
                    'id' => $record->id,
                    'href' => route('group-relationship-memberships.show', $record->id),
                    'title' => ($record->entity?->name ?: 'Unknown').' in '.($record->groupRelationship?->name ?: 'Unknown'),
                    'badges' => [$this->badge('Role', $record->role_in_group ?: 'member')],
                    'meta' => $this->meta([
                        ['label' => 'Status', 'value' => $record->is_active_member ? 'Current' : 'Former'],
                        ['label' => 'Joined', 'value' => $record->joined_era],
                        ['label' => 'Left', 'value' => $record->left_era],
                    ]),
                ],
                'show' => fn (GroupRelationshipEntity $record) => [
                    'title' => ($record->entity?->name ?: 'Unknown').' in '.($record->groupRelationship?->name ?: 'Unknown'),
                    'badge' => $record->is_active_member ? 'Current' : 'Former',
                    'sections' => [
                        [
                            'title' => 'Membership',
                            'entries' => [
                                $this->entry('Group Relationship', $record->groupRelationship?->name, $this->linkEntry($this->registry->linkForResourceType('group-relationships', $record->group_relationship_id))),
                                $this->entry('Entity', $record->entity?->name, $this->linkEntry($this->registry->linkForResourceType('entities', $record->entity_id))),
                                $this->entry('Role In Group', $record->role_in_group),
                                $this->entry('Active Member', $record->is_active_member),
                                $this->entry('Joined Era', $record->joined_era),
                                $this->entry('Left Era', $record->left_era),
                            ],
                        ],
                        [
                            'title' => 'Narrative',
                            'entries' => [
                                $this->entry('Participation Notes', $record->participation_notes, ['kind' => 'json']),
                                $this->entry('Departure Notes', $record->departure_notes, ['kind' => 'json']),
                            ],
                            'fullWidth' => true,
                        ],
                    ],
                ],
            ],
            'collection-entities' => [
                'title' => 'Collection Entities',
                'count_label' => 'collection links',
                'route_name' => 'collection-entities.index',
                'empty_title' => 'No collection entity links found',
                'filter_fields' => [
                    ['key' => 'q', 'type' => 'text', 'placeholder' => 'Search collection links...'],
                    ['key' => 'source', 'type' => 'select', 'placeholder' => 'All sources', 'options' => [
                        ['value' => 'manual', 'label' => 'Manual only'],
                        ['value' => 'rule', 'label' => 'Rule only'],
                    ]],
                ],
                'query' => fn (): Builder => CollectionEntity::query()
                    ->with(['collection:id,name,collection_type', 'entity:id,name,entity_type'])
                    ->latest('id'),
                'show_query' => fn (): Builder => CollectionEntity::query()
                    ->with(['collection:id,name,collection_type', 'entity:id,name,entity_type']),
                'apply_filters' => function (Builder $query, Request $request): void {
                    match ($request->string('source')->value()) {
                        'manual' => $query->where('added_manually', true),
                        'rule' => $query->where('added_by_rule', true),
                        default => null,
                    };

                    $this->applyTermFilter($query, (string) $request->string('q')->trim(), [
                        'role_in_collection',
                        'notes',
                    ], [
                        'collection' => 'name',
                        'entity' => 'name',
                    ]);
                },
                'item' => fn (CollectionEntity $record) => [
                    'id' => $record->id,
                    'href' => route('collection-entities.show', $record->id),
                    'title' => ($record->entity?->name ?: 'Unknown').' in '.($record->collection?->name ?: 'Unknown'),
                    'badges' => [$this->badge('Role', $record->role_in_collection ?: 'member')],
                    'meta' => $this->meta([
                        ['label' => 'Sort', 'value' => $record->sort_order],
                        ['label' => 'Manual', 'value' => $record->added_manually],
                        ['label' => 'Rule', 'value' => $record->added_by_rule],
                    ]),
                ],
                'show' => fn (CollectionEntity $record) => [
                    'title' => ($record->entity?->name ?: 'Unknown').' in '.($record->collection?->name ?: 'Unknown'),
                    'badge' => $this->formatLabel($record->role_in_collection ?: 'member'),
                    'sections' => [
                        [
                            'title' => 'Link',
                            'entries' => [
                                $this->entry('Collection', $record->collection?->name, $this->linkEntry($this->registry->linkForResourceType('collections', $record->collection_id))),
                                $this->entry('Entity', $record->entity?->name, $this->linkEntry($this->registry->linkForResourceType('entities', $record->entity_id))),
                                $this->entry('Role In Collection', $record->role_in_collection),
                                $this->entry('Sort Order', $record->sort_order),
                                $this->entry('Added Manually', $record->added_manually),
                                $this->entry('Added By Rule', $record->added_by_rule),
                            ],
                        ],
                        [
                            'title' => 'Rule Context',
                            'entries' => [
                                $this->entry('Matched Rule Snapshot', $record->matched_rule_snapshot, ['kind' => 'json']),
                                $this->entry('Notes', $record->notes),
                            ],
                            'fullWidth' => true,
                        ],
                    ],
                ],
            ],
            'collection-documents' => [
                'title' => 'Collection Documents',
                'count_label' => 'document links',
                'route_name' => 'collection-documents.index',
                'empty_title' => 'No collection document links found',
                'filter_fields' => [
                    ['key' => 'q', 'type' => 'text', 'placeholder' => 'Search document links...'],
                ],
                'query' => fn (): Builder => CollectionDocument::query()
                    ->with(['collection:id,name,collection_type', 'document:id,title,document_type'])
                    ->latest('id'),
                'show_query' => fn (): Builder => CollectionDocument::query()
                    ->with(['collection:id,name,collection_type', 'document:id,title,document_type']),
                'apply_filters' => function (Builder $query, Request $request): void {
                    $this->applyTermFilter($query, (string) $request->string('q')->trim(), [
                        'role_in_collection',
                        'notes',
                    ], [
                        'collection' => 'name',
                        'document' => 'title',
                    ]);
                },
                'item' => fn (CollectionDocument $record) => [
                    'id' => $record->id,
                    'href' => route('collection-documents.show', $record->id),
                    'title' => ($record->document?->title ?: 'Unknown').' in '.($record->collection?->name ?: 'Unknown'),
                    'badges' => [$this->badge('Role', $record->role_in_collection ?: 'document')],
                    'meta' => $this->meta([
                        ['label' => 'Sort', 'value' => $record->sort_order],
                        ['label' => 'Document Type', 'value' => $record->document?->document_type],
                    ]),
                ],
                'show' => fn (CollectionDocument $record) => [
                    'title' => ($record->document?->title ?: 'Unknown').' in '.($record->collection?->name ?: 'Unknown'),
                    'badge' => $this->formatLabel($record->role_in_collection ?: 'document'),
                    'sections' => [
                        [
                            'title' => 'Link',
                            'entries' => [
                                $this->entry('Collection', $record->collection?->name, $this->linkEntry($this->registry->linkForResourceType('collections', $record->collection_id))),
                                $this->entry('Document', $record->document?->title, $this->linkEntry($this->registry->linkForResourceType('documents', $record->document_id))),
                                $this->entry('Role In Collection', $record->role_in_collection),
                                $this->entry('Sort Order', $record->sort_order),
                                $this->entry('Notes', $record->notes),
                            ],
                        ],
                    ],
                ],
            ],
            'document-entities' => [
                'title' => 'Document Entities',
                'count_label' => 'document links',
                'route_name' => 'document-entities.index',
                'empty_title' => 'No document entity links found',
                'filter_fields' => [
                    ['key' => 'q', 'type' => 'text', 'placeholder' => 'Search document links...'],
                    ['key' => 'relationship_type', 'type' => 'select', 'placeholder' => 'All relationship types', 'options' => DocumentEntity::RELATIONSHIP_TYPES],
                ],
                'query' => fn (): Builder => DocumentEntity::query()
                    ->with(['document:id,title,document_type', 'entity:id,name,entity_type'])
                    ->latest('id'),
                'show_query' => fn (): Builder => DocumentEntity::query()
                    ->with(['document:id,title,document_type', 'entity:id,name,entity_type']),
                'apply_filters' => function (Builder $query, Request $request): void {
                    if ($request->filled('relationship_type')) {
                        $query->where('relationship_type', $request->string('relationship_type')->value());
                    }

                    $this->applyTermFilter($query, (string) $request->string('q')->trim(), [
                        'relationship_type',
                    ], [
                        'document' => 'title',
                        'entity' => 'name',
                    ]);
                },
                'item' => fn (DocumentEntity $record) => [
                    'id' => $record->id,
                    'href' => route('document-entities.show', $record->id),
                    'title' => ($record->entity?->name ?: 'Unknown').' in '.($record->document?->title ?: 'Unknown'),
                    'badges' => [$this->badge('Relationship', $record->relationship_type)],
                    'meta' => $this->meta([
                        ['label' => 'Entity Type', 'value' => $record->entity?->entity_type],
                        ['label' => 'Document Type', 'value' => $record->document?->document_type],
                    ]),
                ],
                'show' => fn (DocumentEntity $record) => [
                    'title' => ($record->entity?->name ?: 'Unknown').' in '.($record->document?->title ?: 'Unknown'),
                    'badge' => $this->formatLabel($record->relationship_type ?: 'link'),
                    'sections' => [
                        [
                            'title' => 'Link',
                            'entries' => [
                                $this->entry('Document', $record->document?->title, $this->linkEntry($this->registry->linkForResourceType('documents', $record->document_id))),
                                $this->entry('Entity', $record->entity?->name, $this->linkEntry($this->registry->linkForResourceType('entities', $record->entity_id))),
                                $this->entry('Relationship Type', $this->formatLabel($record->relationship_type)),
                            ],
                        ],
                        [
                            'title' => 'Narrative',
                            'entries' => [
                                $this->entry('Notes', $record->notes, ['kind' => 'json']),
                            ],
                            'fullWidth' => true,
                        ],
                    ],
                ],
            ],
            'canon-reference-entities' => [
                'title' => 'Canon Reference Entities',
                'count_label' => 'canon links',
                'route_name' => 'canon-reference-entities.index',
                'empty_title' => 'No canon reference entity links found',
                'filter_fields' => [
                    ['key' => 'q', 'type' => 'text', 'placeholder' => 'Search canon links...'],
                    ['key' => 'relationship_type', 'type' => 'select', 'placeholder' => 'All relationship types', 'options' => CanonReferenceEntity::RELATIONSHIP_TYPES],
                ],
                'query' => fn (): Builder => CanonReferenceEntity::query()
                    ->with(['canonReference:id,title,universe', 'entity:id,name,entity_type'])
                    ->latest('id'),
                'show_query' => fn (): Builder => CanonReferenceEntity::query()
                    ->with(['canonReference:id,title,universe', 'entity:id,name,entity_type']),
                'apply_filters' => function (Builder $query, Request $request): void {
                    if ($request->filled('relationship_type')) {
                        $query->where('relationship_type', $request->string('relationship_type')->value());
                    }

                    $this->applyTermFilter($query, (string) $request->string('q')->trim(), [
                        'relationship_type',
                        'divergence_level',
                    ], [
                        'canonReference' => 'title',
                        'entity' => 'name',
                    ]);
                },
                'item' => fn (CanonReferenceEntity $record) => [
                    'id' => $record->id,
                    'href' => route('canon-reference-entities.show', $record->id),
                    'title' => ($record->entity?->name ?: 'Unknown').' in '.($record->canonReference?->title ?: 'Unknown'),
                    'badges' => [$this->badge('Relationship', $record->relationship_type)],
                    'meta' => $this->meta([
                        ['label' => 'Divergence', 'value' => $record->divergence_level],
                        ['label' => 'Universe', 'value' => $record->canonReference?->universe],
                    ]),
                ],
                'show' => fn (CanonReferenceEntity $record) => [
                    'title' => ($record->entity?->name ?: 'Unknown').' in '.($record->canonReference?->title ?: 'Unknown'),
                    'badge' => $this->formatLabel($record->relationship_type ?: 'link'),
                    'sections' => [
                        [
                            'title' => 'Link',
                            'entries' => [
                                $this->entry('Canon Reference', $record->canonReference?->title, $this->linkEntry($this->registry->linkForResourceType('canon-references', $record->canon_reference_id))),
                                $this->entry('Entity', $record->entity?->name, $this->linkEntry($this->registry->linkForResourceType('entities', $record->entity_id))),
                                $this->entry('Relationship Type', $this->formatLabel($record->relationship_type)),
                                $this->entry('Divergence Level', $this->formatLabel($record->divergence_level)),
                            ],
                        ],
                        [
                            'title' => 'Narrative',
                            'entries' => [
                                $this->entry('Divergence Notes', $record->divergence_notes, ['kind' => 'json']),
                            ],
                            'fullWidth' => true,
                        ],
                    ],
                ],
            ],
            'timeline-placements' => [
                'title' => 'Timeline Placements',
                'count_label' => 'placements',
                'route_name' => 'timeline-placements.index',
                'empty_title' => 'No timeline placements found',
                'filter_fields' => [
                    ['key' => 'q', 'type' => 'text', 'placeholder' => 'Search placements...'],
                ],
                'query' => fn (): Builder => TimelineEntity::query()
                    ->with(['timeline:id,name,entity_type', 'eventEntity:id,name,entity_type'])
                    ->latest('id'),
                'show_query' => fn (): Builder => TimelineEntity::query()
                    ->with(['timeline:id,name,entity_type', 'eventEntity:id,name,entity_type']),
                'apply_filters' => function (Builder $query, Request $request): void {
                    $this->applyTermFilter($query, (string) $request->string('q')->trim(), [
                        'perspective_label',
                    ], [
                        'timeline' => 'name',
                        'eventEntity' => 'name',
                    ]);
                },
                'item' => fn (TimelineEntity $record) => [
                    'id' => $record->id,
                    'href' => route('timeline-placements.show', $record->id),
                    'title' => ($record->eventEntity?->name ?: 'Unknown').' on '.($record->timeline?->name ?: 'Unknown'),
                    'badges' => [$this->badge('Position', $record->position)],
                    'meta' => $this->meta([
                        ['label' => 'Perspective', 'value' => $record->perspective_label],
                        ['label' => 'Event Type', 'value' => $record->eventEntity?->entity_type],
                    ]),
                ],
                'show' => fn (TimelineEntity $record) => [
                    'title' => ($record->eventEntity?->name ?: 'Unknown').' on '.($record->timeline?->name ?: 'Unknown'),
                    'badge' => 'Placement',
                    'sections' => [
                        [
                            'title' => 'Placement',
                            'entries' => [
                                $this->entry('Timeline', $record->timeline?->name, $this->linkEntry($this->registry->linkForResourceType('timelines', $record->timeline_id))),
                                $this->entry('Event', $record->eventEntity?->name, $this->linkEntry($this->registry->linkForResourceType('entities', $record->event_entity_id))),
                                $this->entry('Position', $record->position),
                                $this->entry('Perspective Label', $record->perspective_label),
                            ],
                        ],
                        [
                            'title' => 'Perspective',
                            'entries' => [
                                $this->entry('Perspective Notes', $record->perspective_notes, ['kind' => 'json']),
                            ],
                            'fullWidth' => true,
                        ],
                    ],
                ],
            ],
            'state-relationships' => [
                'title' => 'State Relationships',
                'count_label' => 'snapshot links',
                'route_name' => 'state-relationships.index',
                'empty_title' => 'No state relationship links found',
                'filter_fields' => [
                    ['key' => 'q', 'type' => 'text', 'placeholder' => 'Search state links...'],
                    ['key' => 'active', 'type' => 'select', 'placeholder' => 'All states', 'options' => [
                        ['value' => 'active', 'label' => 'Active only'],
                        ['value' => 'inactive', 'label' => 'Inactive only'],
                    ]],
                ],
                'query' => fn (): Builder => StateRelationship::query()
                    ->with([
                        'characterState:id,snapshot_label,entity_id',
                        'relationship.fromEntity:id,name',
                        'relationship.toEntity:id,name',
                    ])
                    ->latest('id'),
                'show_query' => fn (): Builder => StateRelationship::query()
                    ->with([
                        'characterState:id,snapshot_label,entity_id',
                        'relationship.fromEntity:id,name,entity_type',
                        'relationship.toEntity:id,name,entity_type',
                    ]),
                'apply_filters' => function (Builder $query, Request $request): void {
                    match ($request->string('active')->value()) {
                        'active' => $query->where('is_active_at_snapshot', true),
                        'inactive' => $query->where('is_active_at_snapshot', false),
                        default => null,
                    };

                    $term = (string) $request->string('q')->trim();

                    if ($term === '') {
                        return;
                    }

                    $query->where(function (Builder $inner) use ($term) {
                        $inner
                            ->whereHas('characterState', fn (Builder $state) => $state->where('snapshot_label', 'like', "%{$term}%"))
                            ->orWhereHas('relationship.fromEntity', fn (Builder $entity) => $entity->where('name', 'like', "%{$term}%"))
                            ->orWhereHas('relationship.toEntity', fn (Builder $entity) => $entity->where('name', 'like', "%{$term}%"));
                    });
                },
                'item' => fn (StateRelationship $record) => [
                    'id' => $record->id,
                    'href' => route('state-relationships.show', $record->id),
                    'title' => ($record->characterState?->snapshot_label ?: 'State').' -> '.($record->relationship?->fromEntity?->name ?: 'Unknown').' / '.($record->relationship?->toEntity?->name ?: 'Unknown'),
                    'badges' => [$this->badge('Active', $record->is_active_at_snapshot ? 'Yes' : 'No')],
                    'meta' => $this->meta([
                        ['label' => 'Relationship', 'value' => $record->relationship?->relationship_type],
                    ]),
                ],
                'show' => fn (StateRelationship $record) => [
                    'title' => ($record->characterState?->snapshot_label ?: 'State').' -> Relationship #'.$record->relationship_id,
                    'badge' => $record->is_active_at_snapshot ? 'Active' : 'Inactive',
                    'sections' => [
                        [
                            'title' => 'Snapshot',
                            'entries' => [
                                $this->entry('Character State', $record->characterState?->snapshot_label, $this->linkEntry($this->registry->linkForResourceType('character-states', $record->character_state_id))),
                                $this->entry('Relationship', ($record->relationship?->fromEntity?->name ?: 'Unknown').' -> '.($record->relationship?->toEntity?->name ?: 'Unknown'), $this->linkEntry($this->registry->linkForResourceType('relationships', $record->relationship_id))),
                                $this->entry('Active At Snapshot', $record->is_active_at_snapshot),
                            ],
                        ],
                        [
                            'title' => 'State Payload',
                            'entries' => [
                                $this->entry('Relationship State At Snapshot', $record->relationship_state_at_snapshot, ['kind' => 'json']),
                            ],
                            'fullWidth' => true,
                        ],
                    ],
                ],
            ],
            'power-interaction-instances' => [
                'title' => 'Power Interaction Instances',
                'count_label' => 'instances',
                'route_name' => 'power-interaction-instances.index',
                'empty_title' => 'No power interaction instances found',
                'filter_fields' => [
                    ['key' => 'q', 'type' => 'text', 'placeholder' => 'Search instances...'],
                    ['key' => 'outcome', 'type' => 'select', 'placeholder' => 'All outcomes', 'options' => PowerInteractionInstance::OUTCOME_MATCHES],
                ],
                'query' => fn (): Builder => PowerInteractionInstance::query()
                    ->with(['powerInteraction:id,interaction_name', 'eventEntity:id,name,entity_type'])
                    ->latest('id'),
                'show_query' => fn (): Builder => PowerInteractionInstance::query()
                    ->with(['powerInteraction:id,interaction_name', 'eventEntity:id,name,entity_type']),
                'apply_filters' => function (Builder $query, Request $request): void {
                    if ($request->filled('outcome')) {
                        $query->where('outcome_match', $request->string('outcome')->value());
                    }

                    $this->applyTermFilter($query, (string) $request->string('q')->trim(), [
                        'outcome_match',
                        'observed_at_era',
                    ], [
                        'powerInteraction' => 'interaction_name',
                        'eventEntity' => 'name',
                    ]);
                },
                'item' => fn (PowerInteractionInstance $record) => [
                    'id' => $record->id,
                    'href' => route('power-interaction-instances.show', $record->id),
                    'title' => ($record->eventEntity?->name ?: 'Unknown').' for '.($record->powerInteraction?->interaction_name ?: 'Unknown'),
                    'badges' => [$this->badge('Outcome', $record->outcome_match)],
                    'meta' => $this->meta([
                        ['label' => 'Observed Era', 'value' => $record->observed_at_era],
                        ['label' => 'Position', 'value' => $record->observed_at_position],
                    ]),
                ],
                'show' => function (PowerInteractionInstance $record) {
                    $involved = Entity::query()
                        ->select('id', 'name', 'entity_type')
                        ->whereIn('id', collect($record->involved_entity_ids ?? [])->filter()->values())
                        ->orderBy('name')
                        ->get()
                        ->map(fn (Entity $entity) => [
                            'label' => $entity->name,
                            'href' => route('entities.show', $entity),
                        ])
                        ->values()
                        ->all();

                    return [
                        'title' => ($record->eventEntity?->name ?: 'Unknown').' for '.($record->powerInteraction?->interaction_name ?: 'Unknown'),
                        'badge' => $this->formatLabel($record->outcome_match ?: 'instance'),
                        'sections' => [
                            [
                                'title' => 'Observation',
                                'entries' => [
                                    $this->entry('Power Interaction', $record->powerInteraction?->interaction_name, $this->linkEntry($this->registry->linkForResourceType('power-interactions', $record->power_interaction_id))),
                                    $this->entry('Event Entity', $record->eventEntity?->name, $this->linkEntry($this->registry->linkForResourceType('entities', $record->event_entity_id))),
                                    $this->entry('Outcome Match', $this->formatLabel($record->outcome_match)),
                                    $this->entry('Observed Era', $record->observed_at_era),
                                    $this->entry('Observed Position', $record->observed_at_position),
                                    $this->entry('Involved Entities', $involved, ['kind' => 'list']),
                                ],
                            ],
                            [
                                'title' => 'Narrative',
                                'entries' => [
                                    $this->entry('Outcome Notes', $record->outcome_notes, ['kind' => 'json']),
                                ],
                                'fullWidth' => true,
                            ],
                        ],
                    ];
                },
            ],
        ];
    }

    private function applyTermFilter(Builder $query, string $term, array $columns, array $relations = []): void
    {
        if ($term === '') {
            return;
        }

        $query->where(function (Builder $inner) use ($term, $columns, $relations) {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $inner->{$method}($column, 'like', "%{$term}%");
            }

            foreach ($relations as $relation => $column) {
                $inner->orWhereHas($relation, fn (Builder $related) => $related->where($column, 'like', "%{$term}%"));
            }
        });
    }

    private function badge(string $label, mixed $value): array
    {
        return [
            'label' => $label,
            'value' => $this->displayValue($value),
        ];
    }

    private function meta(array $pairs): array
    {
        return collect($pairs)
            ->filter(fn (array $pair) => $pair['value'] !== null && $pair['value'] !== '')
            ->map(fn (array $pair) => [
                'label' => $pair['label'],
                'value' => $this->displayValue($pair['value']),
            ])
            ->values()
            ->all();
    }

    private function entry(string $label, mixed $value, array $extra = []): array
    {
        return array_merge([
            'label' => $label,
            'value' => $value,
        ], $extra);
    }

    private function linkEntry(?array $link): array
    {
        return $link && ! empty($link['href'])
            ? ['href' => $link['href']]
            : [];
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return is_string($value) ? $value : (string) $value;
    }

    private function formatLabel(?string $value): string
    {
        return $value
            ? Str::of($value)->replace('_', ' ')->title()->value()
            : '—';
    }
}
