<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Exceptions\CannotPublishIncompleteEntityException;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Services\EntityService;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Identity\ValueObjects\SourceUniverse;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Domain\Intelligence\Models\PerceptionState;
use App\Domain\Intelligence\Models\Secret;
use App\Http\Controllers\Concerns\RendersEntityShowPage;
use App\Http\Controllers\Controller;
use App\Support\Validation\DataverseRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class EntityController extends Controller
{
    use RendersEntityShowPage;

    public function __construct(
        private readonly EntityService $entityService,
    ) {}

    // GET /entities
    // Index page — filterable list of all entities
    public function index(Request $request): Response
    {
        return $this->indexPage($request);
    }

    // GET /entities/create
    public function create(): Response
    {
        return $this->indexPage(request(), [
            'createDrawer' => $this->createFormProps(),
        ]);
    }

    // POST /entities
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('entities', 'store'));

        // Strip empty strings so database column defaults apply
        $validated = array_filter($validated, fn ($v) => ! ($v === '' || $v === null) || is_array($v) || is_bool($v));

        $entity = $this->entityService->create($validated);

        return $this->to('entities.show', [$entity], "Entity '{$entity->name}' created.");
    }

    // GET /entities/{entity}
    public function show(Entity $entity): Response
    {
        return $this->showPage($entity);
    }

    // GET /entities/{entity}/edit
    public function edit(Entity $entity): Response
    {
        return $this->showPage($entity, [
            'editDrawer' => [
                'entityTypes' => EntityType::CATEGORIES,
            ],
        ]);
    }

    // PUT /entities/{entity}
    public function update(Request $request, Entity $entity): RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('entities', 'update'));

        // Strip empty strings — treat as null so existing values aren't overwritten with ""
        $validated = array_filter($validated, fn ($v) => ! ($v === '' || $v === null) || is_array($v) || is_bool($v));

        $this->entityService->update($entity, $validated);

        return $this->to('entities.show', [$entity], "Entity '{$entity->name}' updated.");
    }

    // DELETE /entities/{entity}
    public function destroy(Entity $entity): RedirectResponse
    {
        $name = $entity->name;

        $this->entityService->delete($entity);

        return $this->to('entities.index', [], "Entity '{$name}' deleted.");
    }

    // POST /entities/{entity}/publish
    public function publish(Entity $entity): RedirectResponse
    {
        try {
            $this->entityService->publish($entity);

            return $this->back("'{$entity->name}' published.");
        } catch (CannotPublishIncompleteEntityException $e) {
            return redirect()->back()->withErrors([
                'publish' => $e->getMessage(),
            ]);
        }
    }

    // POST /entities/{entity}/unpublish
    public function unpublish(Entity $entity): RedirectResponse
    {
        $this->entityService->unpublish($entity);

        return $this->back("'{$entity->name}' unpublished.");
    }

    // POST /entities/{entity}/archive
    public function archive(Entity $entity): RedirectResponse
    {
        $this->entityService->archive($entity);

        return $this->back("'{$entity->name}' archived.");
    }

    private function indexPage(Request $request, array $props = []): Response
    {
        $query = Entity::query()
            ->select([
                'id', 'name', 'public_title', 'entity_type', 'status',
                'source_universes', 'summary', 'completion_score',
                'visibility', 'power_tier_ceiling', 'published_at',
            ])
            ->orderBy('name');

        if ($request->filled('type')) {
            $typeFilter = (string) $request->type;

            if (str_starts_with($typeFilter, 'category:')) {
                $category = substr($typeFilter, strlen('category:'));
                $types = EntityType::CATEGORIES[$category] ?? null;

                if ($types) {
                    $query->whereIn('entity_type', $types);
                }
            } else {
                $query->ofType($typeFilter);
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('universe')) {
            $query->fromUniverse($request->universe);
        }

        if ($request->filled('visibility')) {
            $query->where('visibility', $request->visibility);
        }

        if ($request->filled('q')) {
            $query->search($request->q);
        }

        if ($request->boolean('incomplete')) {
            $query->incomplete();
        }

        return $this->page('Entities/Index', array_merge([
            'entities' => $query->paginate(40)->withQueryString(),
            'filters' => $request->only(['type', 'status', 'universe', 'visibility', 'q', 'incomplete']),
            'entityTypes' => EntityType::CATEGORIES,
            'statuses' => Entity::STATUSES,
            'universes' => SourceUniverse::ALL ?? [],
            'visibilityLevels' => VisibilityLevel::ALL,
        ], $props));
    }

    private function createFormProps(): array
    {
        return [
            'entityTypes' => EntityType::CATEGORIES,
        ];
    }

    private function showPage(Entity $entity, array $props = []): Response
    {
        return $this->showEntityPage($entity, array_merge([
            'intelligenceSummary' => $this->intelligenceSummary($entity),
        ], $props));
    }

    private function intelligenceSummary(Entity $entity): array
    {
        $knowledgeHeld = KnowledgeState::query()
            ->current()
            ->where('knower_entity_id', $entity->id)
            ->with([
                'subjectEntity:id,name',
                'subjectRelationship:id,from_entity_id,to_entity_id',
                'subjectRelationship.fromEntity:id,name',
                'subjectRelationship.toEntity:id,name',
                'subjectGroupRelationship:id,name',
                'subjectEvent:id,timeline_id,event_entity_id,entry_label',
                'subjectEvent.eventEntity:id,name',
                'subjectEvent.timeline:id,name',
                'subjectSecret:id,title',
            ])
            ->latest('id')
            ->take(4)
            ->get();

        $knowledgeAbout = KnowledgeState::query()
            ->current()
            ->where('subject_entity_id', $entity->id)
            ->with('knower:id,name')
            ->latest('id')
            ->take(4)
            ->get();

        $secretsAbout = Secret::query()
            ->select('id', 'title', 'secret_type', 'status', 'exposure_risk')
            ->whereJsonContains('subject_entity_ids', $entity->id)
            ->latest('id')
            ->take(4)
            ->get();

        $secretsHeld = Secret::query()
            ->select('id', 'title', 'secret_type', 'status', 'exposure_risk')
            ->whereJsonContains('holder_entity_ids', $entity->id)
            ->latest('id')
            ->take(4)
            ->get();

        $secretsKnown = Secret::query()
            ->select('id', 'title', 'secret_type', 'status', 'exposure_risk')
            ->whereJsonContains('known_by_entity_ids', $entity->id)
            ->latest('id')
            ->take(4)
            ->get();

        $perceptionStates = PerceptionState::query()
            ->current()
            ->select('id', 'divergence_level', 'maintenance_method', 'revelation_risk', 'subject_type', 'subject_id')
            ->where('subject_type', 'entity')
            ->where('subject_id', $entity->id)
            ->latest('id')
            ->take(4)
            ->get();

        return [
            'counts' => [
                'knowledge_held' => $knowledgeHeld->count(),
                'knowledge_about' => $knowledgeAbout->count(),
                'secrets_about' => $secretsAbout->count(),
                'secrets_held' => $secretsHeld->count(),
                'secrets_known' => $secretsKnown->count(),
                'perception_states' => $perceptionStates->count(),
            ],
            'knowledgeHeld' => $knowledgeHeld
                ->map(fn (KnowledgeState $state) => [
                    'id' => $state->id,
                    'label' => $this->knowledgeSubjectLabel($state),
                    'meta' => $state->knowledge_type,
                    'href' => route('knowledge-states.show', [$state]),
                ])
                ->values()
                ->all(),
            'knowledgeAbout' => $knowledgeAbout
                ->map(fn (KnowledgeState $state) => [
                    'id' => $state->id,
                    'label' => $state->knower?->name ?? "Knowledge state #{$state->id}",
                    'meta' => $state->knowledge_type,
                    'href' => route('knowledge-states.show', [$state]),
                ])
                ->values()
                ->all(),
            'secretsAbout' => $secretsAbout
                ->map(fn (Secret $secret) => [
                    'id' => $secret->id,
                    'label' => $secret->title,
                    'meta' => $secret->secret_type,
                    'href' => route('secrets.show', [$secret]),
                ])
                ->values()
                ->all(),
            'secretsHeld' => $secretsHeld
                ->map(fn (Secret $secret) => [
                    'id' => $secret->id,
                    'label' => $secret->title,
                    'meta' => $secret->status,
                    'href' => route('secrets.show', [$secret]),
                ])
                ->values()
                ->all(),
            'secretsKnown' => $secretsKnown
                ->map(fn (Secret $secret) => [
                    'id' => $secret->id,
                    'label' => $secret->title,
                    'meta' => $secret->exposure_risk,
                    'href' => route('secrets.show', [$secret]),
                ])
                ->values()
                ->all(),
            'perceptionStates' => $perceptionStates
                ->map(fn (PerceptionState $state) => [
                    'id' => $state->id,
                    'label' => "{$entity->name} perception gap",
                    'meta' => $state->divergence_level,
                    'href' => route('perception-states.show', [$state]),
                ])
                ->values()
                ->all(),
        ];
    }

    private function knowledgeSubjectLabel(KnowledgeState $state): string
    {
        return match ($state->subjectType()) {
            'entity' => $state->subjectEntity?->name ?? "Entity #{$state->subjectId()}",
            'relationship' => sprintf(
                '%s -> %s',
                $state->subjectRelationship?->fromEntity?->name ?? 'Unknown',
                $state->subjectRelationship?->toEntity?->name ?? 'Unknown',
            ),
            'group_relationship' => $state->subjectGroupRelationship?->name ?? "Group relationship #{$state->subjectId()}",
            'event' => $state->subjectEvent?->entry_label
                ?: $state->subjectEvent?->eventEntity?->name
                ?: "Timeline entry #{$state->subjectId()}",
            'secret' => $state->subjectSecret?->title ?? "Secret #{$state->subjectId()}",
            default => "Subject #{$state->subjectId()}",
        };
    }
}
