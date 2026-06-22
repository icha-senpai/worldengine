<?php

namespace App\Http\Controllers\Intelligence;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Connections\Models\Relationship;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Lore\Models\Document;
use App\Domain\Intelligence\Models\PerceptionState;
use App\Domain\Temporal\Models\Timeline;
use App\Domain\Intelligence\Services\IntelligenceService;

class PerceptionStateController extends Controller
{
    public function __construct(
        private readonly IntelligenceService $service,
    ) {}

    public function index(Request $request): Response
    {
        $query = PerceptionState::current()->latest();

        if ($request->boolean('high_risk')) {
            $query->highRisk();
        }

        if ($request->boolean('critical_maintenance')) {
            $query->criticalMaintenance();
        }

        return $this->page('Intelligence/PerceptionStates/Index', [
            'states'  => $query->paginate(40)->withQueryString(),
            'filters' => $request->only(['high_risk', 'critical_maintenance']),
        ]);
    }

    public function create(): Response
    {
        return $this->page('Intelligence/PerceptionStates/Create', [
            'entities'           => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'factionEntities'    => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->whereIn('entity_type', EntityType::FACTION_TYPES)
                ->orderBy('name')
                ->get(),
            'locationEntities'   => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->whereIn('entity_type', EntityType::SPATIAL_TYPES)
                ->orderBy('name')
                ->get(),
            'relationships'      => Relationship::query()
                ->with(['fromEntity:id,name', 'toEntity:id,name'])
                ->orderByDesc('id')
                ->get(['id', 'from_entity_id', 'to_entity_id', 'relationship_type']),
            'groupRelationships' => GroupRelationship::query()
                ->select('id', 'name', 'relationship_type')
                ->orderBy('name')
                ->get(),
            'eventEntries'       => Timeline::query()
                ->with(['eventEntity:id,name,entity_type', 'timeline:id,name'])
                ->orderByDesc('id')
                ->get(['id', 'timeline_id', 'event_entity_id', 'entry_label', 'au_date']),
            'documents'          => Document::query()
                ->select('id', 'title', 'document_type')
                ->orderBy('title')
                ->get(),
            'subjectTypes'       => PerceptionState::SUBJECT_TYPES,
            'divergenceLevels'   => PerceptionState::DIVERGENCE_LEVELS,
            'maintenanceMethods' => PerceptionState::MAINTENANCE_METHODS,
            'maintenanceEfforts' => PerceptionState::MAINTENANCE_EFFORTS,
            'revelationRisks'    => PerceptionState::REVELATION_RISKS,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'subject_type'             => ['required', 'string', 'in:' . implode(',', PerceptionState::SUBJECT_TYPES)],
            'subject_id'               => ['required', 'integer'],
            'true_state'               => ['required', 'array'],
            'perceived_state'          => ['required', 'array'],
            'divergence_level'         => ['required', 'string'],
            'maintained_by_entity_ids' => ['nullable', 'array'],
            'maintenance_method'       => ['nullable', 'string'],
            'maintenance_effort'       => ['nullable', 'string'],
            'revelation_risk'          => ['nullable', 'string'],
        ]);

        $state = $this->service->createPerceptionGap($validated);

        return $this->to('perception-states.show', [$state], 'Perception gap created.');
    }

    public function show(PerceptionState $perceptionState): Response
    {
        $maintainerIds = $perceptionState->maintained_by_entity_ids ?? [];
        $maintainers = Entity::query()
            ->select('id', 'name', 'entity_type')
            ->whereIn('id', $maintainerIds)
            ->get()
            ->keyBy('id');

        return $this->pageWithNotionNote('Intelligence/PerceptionStates/Show', $perceptionState, 'perception_states', [
            'state'               => $perceptionState,
            'subjectDisplay'      => $this->resolveSubjectDisplay($perceptionState),
            'maintainedByEntities'=> collect($maintainerIds)
                ->map(function ($id) use ($maintainers) {
                    $entity = $maintainers->get($id);

                    if (!$entity) {
                        return ['label' => "Unknown entity #{$id}"];
                    }

                    return [
                        'label' => "{$entity->name}" . ($entity->entity_type ? " ({$entity->entity_type})" : ''),
                        'href'  => route('entities.show', [$entity]),
                    ];
                })
                ->values()
                ->all(),
        ]);
    }

    public function edit(PerceptionState $perceptionState): Response
    {
        return $this->page('Intelligence/PerceptionStates/Edit', [
            'state'              => $perceptionState,
            'maintenanceMethods' => PerceptionState::MAINTENANCE_METHODS,
            'maintenanceEfforts' => PerceptionState::MAINTENANCE_EFFORTS,
            'revelationRisks'    => PerceptionState::REVELATION_RISKS,
        ]);
    }

    public function update(Request $request, PerceptionState $perceptionState): \Illuminate\Http\RedirectResponse
    {
        $perceptionState->update($request->validate([
            'true_state'         => ['nullable', 'array'],
            'perceived_state'    => ['nullable', 'array'],
            'divergence_level'   => ['nullable', 'string'],
            'maintenance_effort' => ['nullable', 'string'],
            'revelation_risk'    => ['nullable', 'string'],
        ]));

        return $this->to('perception-states.show', [$perceptionState], 'Perception state updated.');
    }

    public function destroy(PerceptionState $perceptionState): \Illuminate\Http\RedirectResponse
    {
        $perceptionState->delete();

        return $this->to('perception-states.index', [], 'Perception state deleted.');
    }

    public function addImmune(PerceptionState $perceptionState, Entity $entity): \Illuminate\Http\RedirectResponse
    {
        $this->service->addImmuneEntity($perceptionState, $entity->id);

        return $this->back("{$entity->name} added to immune list.");
    }

    public function collapse(Request $request, PerceptionState $perceptionState): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'era' => ['required', 'string'],
        ]);

        $this->service->collapsePerceptionGap($perceptionState, $validated['era']);

        return $this->back('Perception gap collapsed.');
    }

    private function resolveSubjectDisplay(PerceptionState $perceptionState): array
    {
        return match ($perceptionState->subject_type) {
            'entity', 'faction', 'location' => $this->resolveEntitySubject($perceptionState->subject_id),
            'relationship' => $this->resolveRelationshipSubject($perceptionState->subject_id),
            'group_relationship' => $this->resolveGroupRelationshipSubject($perceptionState->subject_id),
            'event' => $this->resolveEventSubject($perceptionState->subject_id),
            'document' => $this->resolveDocumentSubject($perceptionState->subject_id),
            default => [
                'label' => "Unknown subject #{$perceptionState->subject_id}",
            ],
        };
    }

    private function resolveEntitySubject(?int $id): array
    {
        $entity = $id ? Entity::query()->select('id', 'name', 'entity_type')->find($id) : null;

        if (!$entity) {
            return ['label' => $id ? "Unknown entity #{$id}" : 'Unknown entity'];
        }

        return [
            'label' => "{$entity->name}" . ($entity->entity_type ? " ({$entity->entity_type})" : ''),
            'href'  => route('entities.show', [$entity]),
        ];
    }

    private function resolveRelationshipSubject(?int $id): array
    {
        $relationship = $id
            ? Relationship::query()->with(['fromEntity:id,name', 'toEntity:id,name'])->find($id)
            : null;

        if (!$relationship) {
            return ['label' => $id ? "Unknown relationship #{$id}" : 'Unknown relationship'];
        }

        return [
            'label' => "{$relationship->fromEntity?->name} -> {$relationship->toEntity?->name}",
            'href'  => route('relationships.show', [$relationship]),
        ];
    }

    private function resolveGroupRelationshipSubject(?int $id): array
    {
        $group = $id ? GroupRelationship::query()->select('id', 'name', 'relationship_type')->find($id) : null;

        if (!$group) {
            return ['label' => $id ? "Unknown group relationship #{$id}" : 'Unknown group relationship'];
        }

        return [
            'label' => "{$group->name}" . ($group->relationship_type ? " ({$group->relationship_type})" : ''),
            'href'  => route('group-relationships.show', [$group]),
        ];
    }

    private function resolveEventSubject(?int $id): array
    {
        $entry = $id
            ? Timeline::query()
                ->with(['eventEntity:id,name', 'timeline:id,name'])
                ->find($id)
            : null;

        if (!$entry) {
            return ['label' => $id ? "Unknown event entry #{$id}" : 'Unknown event entry'];
        }

        $label = $entry->entry_label ?: $entry->eventEntity?->name ?: "Timeline entry #{$entry->id}";
        $timelineName = $entry->timeline?->name ? " on {$entry->timeline->name}" : '';

        return [
            'label' => "{$label}{$timelineName}",
        ];
    }

    private function resolveDocumentSubject(?int $id): array
    {
        $document = $id ? Document::query()->select('id', 'title', 'document_type')->find($id) : null;

        if (!$document) {
            return ['label' => $id ? "Unknown document #{$id}" : 'Unknown document'];
        }

        return [
            'label' => "{$document->title}" . ($document->document_type ? " ({$document->document_type})" : ''),
            'href'  => route('documents.show', [$document]),
        ];
    }
}
