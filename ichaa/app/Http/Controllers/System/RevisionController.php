<?php

namespace App\Http\Controllers\System;

use App\Domain\Connections\Models\FactionMembership;
use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Connections\Models\GroupRelationshipEntity;
use App\Domain\Connections\Models\Relationship;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\EntityAlias;
use App\Domain\Identity\Models\EntityNote;
use App\Domain\Identity\Models\EntityQuestion;
use App\Domain\Identity\Models\MediaReference;
use App\Domain\Identity\Models\VersionAndCanonState;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Domain\Intelligence\Models\PerceptionState;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Lore\Models\CanonReferenceEntity;
use App\Domain\Lore\Models\CrossoverEntryPoint;
use App\Domain\Lore\Models\Document;
use App\Domain\Lore\Models\DocumentEntity;
use App\Domain\Lore\Models\SourceCanonReference;
use App\Domain\Organization\Models\Collection;
use App\Domain\Organization\Models\CollectionDocument;
use App\Domain\Organization\Models\CollectionEntity;
use App\Domain\Organization\Models\Glossary;
use App\Domain\Production\Models\Meta;
use App\Domain\Production\Models\PipelineItem;
use App\Domain\Production\Models\SessionLog;
use App\Domain\System\Models\NotionNote;
use App\Domain\System\Models\NotionSyncMapping;
use App\Domain\System\Models\Revision;
use App\Domain\System\Services\RevisionService;
use App\Domain\Temporal\Models\CharacterStateTracker;
use App\Domain\Temporal\Models\ConcurrencyGroup;
use App\Domain\Temporal\Models\StateRelationship;
use App\Domain\Temporal\Models\Timeline;
use App\Domain\Temporal\Models\TimelineEntity;
use App\Domain\World\Models\GalacticRegion;
use App\Domain\World\Models\LocationContainment;
use App\Domain\World\Models\LocationControlHistory;
use App\Domain\World\Models\PowerInteraction;
use App\Domain\World\Models\PowerInteractionInstance;
use App\Domain\World\Models\TravelRoute;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response;

class RevisionController extends Controller
{
    public function __construct(
        private readonly RevisionService $revisions,
    ) {}

    public function index(Request $request): Response
    {
        $query = Revision::query()->with(['actor:id,name', 'restoredFrom:id']);

        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            $query->where(function ($inner) use ($term) {
                $inner->where('resource_type', 'like', "%{$term}%")
                    ->orWhere('resource_id', 'like', "%{$term}%")
                    ->orWhere('action', 'like', "%{$term}%")
                    ->orWhere('reason', 'like', "%{$term}%")
                    ->orWhere('source', 'like', "%{$term}%");
            });
        }

        if ($request->filled('resource_type')) {
            $query->where('resource_type', $request->string('resource_type')->toString());
        }

        if ($request->filled('action')) {
            $query->where('action', $request->string('action')->toString());
        }

        $revisions = $query->orderByDesc('id')->paginate(50)->withQueryString();

        return $this->page('System/Revisions/Index', [
            'revisions' => $revisions,
            'items' => collect($revisions->items())->map(fn (Revision $revision) => $this->revisionIndexItem($revision))->values()->all(),
            'filters' => $request->only(['q', 'resource_type', 'action']),
            'resourceTypes' => Revision::query()->distinct()->orderBy('resource_type')->pluck('resource_type')->values()->all(),
            'actions' => Revision::query()->distinct()->orderBy('action')->pluck('action')->values()->all(),
        ]);
    }

    public function show(Revision $revision): Response
    {
        $revision->load(['actor:id,name', 'restoredFrom:id,action,resource_type,resource_id']);

        return $this->page('System/Revisions/Show', [
            'revision' => $revision,
            'resourceLink' => $this->resourceLink($revision->resource_type, $revision->resource_id),
            'compareCandidates' => Revision::query()
                ->where('resource_type', $revision->resource_type)
                ->where('resource_id', $revision->resource_id)
                ->whereKeyNot($revision->getKey())
                ->orderByDesc('id')
                ->limit(15)
                ->get(['id', 'action', 'created_at'])
                ->map(fn (Revision $candidate) => [
                    'id' => $candidate->id,
                    'label' => sprintf('#%d · %s · %s', $candidate->id, $candidate->action, optional($candidate->created_at)?->toDateTimeString()),
                ])
                ->values()
                ->all(),
            'currentRevisionId' => $this->revisions->currentRevisionId($revision->resource_type, $revision->resource_id),
            'diffRows' => $this->diffRows($revision->diff_payload ?? []),
        ]);
    }

    public function compare(Request $request): Response
    {
        $validated = $request->validate([
            'left' => ['required', 'integer', 'exists:revisions,id'],
            'right' => ['required', 'integer', 'exists:revisions,id', 'different:left'],
        ]);

        $left = Revision::query()->with('actor:id,name')->findOrFail($validated['left']);
        $right = Revision::query()->with('actor:id,name')->findOrFail($validated['right']);

        abort_unless(
            $left->resource_type === $right->resource_type
            && (string) $left->resource_id === (string) $right->resource_id,
            422,
            'Revision compare requires two revisions from the same resource record.',
        );

        return $this->page('System/Revisions/Compare', [
            'left' => $left,
            'right' => $right,
            'resourceLink' => $this->resourceLink($left->resource_type, $left->resource_id),
            'currentRevisionId' => $this->revisions->currentRevisionId($left->resource_type, $left->resource_id),
            'rows' => $this->comparisonRows($left, $right),
        ]);
    }

    public function restore(Request $request, Revision $revision): RedirectResponse
    {
        $validated = $request->validate([
            'base_revision_id' => ['nullable', 'integer'],
        ]);

        $currentRevisionId = $this->revisions->currentRevisionId($revision->resource_type, $revision->resource_id);
        $submittedBase = (int) ($validated['base_revision_id'] ?? 0);

        if ($submittedBase !== 0 && $submittedBase !== $currentRevisionId) {
            return back()->with('error', 'Revision history changed before restore completed. Refresh and compare again first.');
        }

        $record = $this->resolveRevisionTarget($revision);
        $before = $record->attributesToArray();
        $restored = $this->revisions->restoreModel($record, $revision);
        $this->revisions->record(
            $revision->resource_type,
            $restored,
            'restore_revision',
            $before,
            $restored->attributesToArray(),
            $request,
            $revision->id,
        );

        return back()->with('success', sprintf(
            'Restored %s #%s from revision #%d.',
            Str::headline($revision->resource_type),
            $revision->resource_id,
            $revision->id,
        ));
    }

    private function revisionIndexItem(Revision $revision): array
    {
        return [
            'id' => $revision->id,
            'href' => route('revisions.show', $revision),
            'title' => sprintf('%s #%s', Str::headline($revision->resource_type), $revision->resource_id),
            'badges' => array_values(array_filter([
                ['label' => 'Action', 'value' => $revision->action],
                $revision->actor?->name ? ['label' => 'Actor', 'value' => $revision->actor->name] : null,
            ])),
            'meta' => array_values(array_filter([
                ['label' => 'Revision', 'value' => $revision->id],
                ['label' => 'Source', 'value' => $revision->source],
                ['label' => 'Reason', 'value' => $revision->reason],
                ['label' => 'Created', 'value' => optional($revision->created_at)?->toDateTimeString()],
            ], fn (?array $item) => filled($item['value'] ?? null))),
        ];
    }

    private function diffRows(array $diff): array
    {
        return collect($diff)
            ->map(fn (array $change, string $field) => [
                'field' => $field,
                'before' => $change['before'] ?? null,
                'after' => $change['after'] ?? null,
            ])
            ->values()
            ->all();
    }

    private function comparisonRows(Revision $left, Revision $right): array
    {
        $before = $left->after_payload ?? [];
        $after = $right->after_payload ?? [];
        $keys = collect(array_keys($before))
            ->merge(array_keys($after))
            ->unique()
            ->values();

        return $keys->map(fn (string $field) => [
            'field' => $field,
            'left' => $before[$field] ?? null,
            'right' => $after[$field] ?? null,
            'changed' => ($before[$field] ?? null) !== ($after[$field] ?? null),
        ])->all();
    }

    private function resolveRevisionTarget(Revision $revision): Model
    {
        $meta = $this->resourceMeta()[$revision->resource_type] ?? null;
        abort_unless($meta !== null, 404, "Unknown revision resource [{$revision->resource_type}].");

        /** @var class-string<Model> $modelClass */
        $modelClass = $meta['model'];
        $query = $modelClass::query();

        if ($meta['soft_deletes'] ?? false) {
            $query->withTrashed();
        }

        return $query->findOrFail($revision->resource_id);
    }

    private function resourceLink(string $resourceType, int|string $resourceId): ?array
    {
        $meta = $this->resourceMeta()[$resourceType] ?? null;

        if ($meta === null || blank($meta['route'] ?? null)) {
            return null;
        }

        return [
            'label' => sprintf('%s #%s', $meta['label'], $resourceId),
            'href' => route($meta['route'], $resourceId),
        ];
    }

    private function resourceMeta(): array
    {
        return [
            'entities' => ['model' => Entity::class, 'route' => 'entities.show', 'label' => 'Entity', 'soft_deletes' => true],
            'entity-aliases' => ['model' => EntityAlias::class, 'route' => null, 'label' => 'Entity Alias'],
            'entity-notes' => ['model' => EntityNote::class, 'route' => null, 'label' => 'Entity Note'],
            'entity-questions' => ['model' => EntityQuestion::class, 'route' => null, 'label' => 'Entity Question'],
            'media-references' => ['model' => MediaReference::class, 'route' => 'media-references.show', 'label' => 'Media Reference'],
            'entity-versions' => ['model' => VersionAndCanonState::class, 'route' => null, 'label' => 'Entity Version'],
            'relationships' => ['model' => Relationship::class, 'route' => 'relationships.show', 'label' => 'Relationship'],
            'group-relationships' => ['model' => GroupRelationship::class, 'route' => 'group-relationships.show', 'label' => 'Group Relationship'],
            'group-relationship-memberships' => ['model' => GroupRelationshipEntity::class, 'route' => 'group-relationship-memberships.show', 'label' => 'Group Membership'],
            'faction-memberships' => ['model' => FactionMembership::class, 'route' => null, 'label' => 'Faction Membership'],
            'collections' => ['model' => Collection::class, 'route' => 'collections.show', 'label' => 'Collection'],
            'collection-entities' => ['model' => CollectionEntity::class, 'route' => null, 'label' => 'Collection Entity'],
            'collection-documents' => ['model' => CollectionDocument::class, 'route' => 'collection-documents.show', 'label' => 'Collection Document'],
            'glossary' => ['model' => Glossary::class, 'route' => 'glossary.show', 'label' => 'Glossary Term'],
            'documents' => ['model' => Document::class, 'route' => 'documents.show', 'label' => 'Document'],
            'document-entities' => ['model' => DocumentEntity::class, 'route' => 'document-entities.show', 'label' => 'Document Entity Link'],
            'canon-references' => ['model' => SourceCanonReference::class, 'route' => 'canon-references.show', 'label' => 'Canon Reference'],
            'canon-reference-entities' => ['model' => CanonReferenceEntity::class, 'route' => 'canon-reference-entities.show', 'label' => 'Canon Reference Link'],
            'crossover-entry-points' => ['model' => CrossoverEntryPoint::class, 'route' => 'crossover-entry-points.show', 'label' => 'Crossover Entry Point'],
            'timelines' => ['model' => Entity::class, 'route' => 'timelines.show', 'label' => 'Timeline', 'soft_deletes' => true],
            'timeline-entries' => ['model' => Timeline::class, 'route' => null, 'label' => 'Timeline Entry'],
            'timeline-placements' => ['model' => TimelineEntity::class, 'route' => 'timeline-placements.show', 'label' => 'Timeline Placement'],
            'character-states' => ['model' => CharacterStateTracker::class, 'route' => 'character-states.show', 'label' => 'Character State'],
            'state-relationships' => ['model' => StateRelationship::class, 'route' => 'state-relationships.show', 'label' => 'State Relationship'],
            'concurrency-groups' => ['model' => ConcurrencyGroup::class, 'route' => 'concurrency-groups.show', 'label' => 'Concurrency Group'],
            'power-interactions' => ['model' => PowerInteraction::class, 'route' => 'power-interactions.show', 'label' => 'Power Interaction'],
            'power-interaction-instances' => ['model' => PowerInteractionInstance::class, 'route' => null, 'label' => 'Power Interaction Instance'],
            'location-containment' => ['model' => LocationContainment::class, 'route' => 'location-containment.show', 'label' => 'Location Containment'],
            'location-control-records' => ['model' => LocationControlHistory::class, 'route' => 'location-control.show', 'label' => 'Location Control Record'],
            'travel-routes' => ['model' => TravelRoute::class, 'route' => 'travel-routes.show', 'label' => 'Travel Route'],
            'galactic-regions' => ['model' => GalacticRegion::class, 'route' => 'galactic-regions.show', 'label' => 'Galactic Region', 'soft_deletes' => true],
            'knowledge-states' => ['model' => KnowledgeState::class, 'route' => 'knowledge-states.show', 'label' => 'Knowledge State'],
            'secrets' => ['model' => Secret::class, 'route' => 'secrets.show', 'label' => 'Secret'],
            'perception-states' => ['model' => PerceptionState::class, 'route' => 'perception-states.show', 'label' => 'Perception State'],
            'meta' => ['model' => Meta::class, 'route' => 'meta.show', 'label' => 'Meta'],
            'pipeline-items' => ['model' => PipelineItem::class, 'route' => 'pipeline.show', 'label' => 'Pipeline Item'],
            'session-logs' => ['model' => SessionLog::class, 'route' => 'session-logs.show', 'label' => 'Session Log'],
            'notion-notes' => ['model' => NotionNote::class, 'route' => 'notion-notes.show', 'label' => 'Notion Note'],
            'notion-sync-mappings' => ['model' => NotionSyncMapping::class, 'route' => 'notion-sync-mappings.show', 'label' => 'Notion Sync Mapping'],
        ];
    }
}
