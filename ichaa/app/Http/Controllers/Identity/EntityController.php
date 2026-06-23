<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Models\EntityAlias;
use App\Domain\Identity\Models\EntityNote;
use App\Domain\Identity\Models\EntityQuestion;
use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Services\EntityService;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\System\Models\NotionNote;
use App\Support\Validation\DataverseRules;

class EntityController extends Controller
{
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
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('entities', 'store'));

        // Strip empty strings so database column defaults apply
        $validated = array_filter($validated, fn($v) => !($v === '' || $v === null) || is_array($v) || is_bool($v));

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
    public function update(Request $request, Entity $entity): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('entities', 'update'));

        // Strip empty strings — treat as null so existing values aren't overwritten with ""
        $validated = array_filter($validated, fn($v) => !($v === '' || $v === null) || is_array($v) || is_bool($v));

        $this->entityService->update($entity, $validated);

        return $this->to('entities.show', [$entity], "Entity '{$entity->name}' updated.");
    }

    // DELETE /entities/{entity}
    public function destroy(Entity $entity): \Illuminate\Http\RedirectResponse
    {
        $name = $entity->name;

        $this->entityService->delete($entity);

        return $this->to('entities.index', [], "Entity '{$name}' deleted.");
    }

    // POST /entities/{entity}/publish
    public function publish(Entity $entity): \Illuminate\Http\RedirectResponse
    {
        try {
            $this->entityService->publish($entity);

            return $this->back("'{$entity->name}' published.");
        } catch (\App\Domain\Identity\Exceptions\CannotPublishIncompleteEntityException $e) {
            return redirect()->back()->withErrors([
                'publish' => $e->getMessage(),
            ]);
        }
    }

    // POST /entities/{entity}/unpublish
    public function unpublish(Entity $entity): \Illuminate\Http\RedirectResponse
    {
        $this->entityService->unpublish($entity);

        return $this->back("'{$entity->name}' unpublished.");
    }

    // POST /entities/{entity}/archive
    public function archive(Entity $entity): \Illuminate\Http\RedirectResponse
    {
        $this->entityService->archive($entity);

        return $this->back("'{$entity->name}' archived.");
    }

    private function attachEmbeddedNotionNotes(Entity $entity): void
    {
        $entity->setRelation('aliases', $this->attachNotionNotesToRecords(
            $entity->aliases,
            EntityAlias::class,
            'entity_aliases',
        ));

        $entity->setRelation('notes', $this->attachNotionNotesToRecords(
            $entity->notes,
            EntityNote::class,
            'entity_notes',
        ));

        $entity->setRelation('questions', $this->attachNotionNotesToRecords(
            $entity->questions,
            EntityQuestion::class,
            'entity_questions',
        ));
    }

    private function attachNotionNotesToRecords($records, string $modelClass, string $resource)
    {
        if ($records->isEmpty()) {
            return $records;
        }

        $noteMap = NotionNote::query()
            ->where('noteable_type', $modelClass)
            ->where('sync_resource', $resource)
            ->whereIn('noteable_id', $records->pluck('id'))
            ->get()
            ->keyBy('noteable_id');

        return $records->map(function ($record) use ($noteMap) {
            $record->setAttribute('notion_note', $this->formatNotionNote($noteMap->get($record->getKey())));

            return $record;
        })->values();
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

        if ($request->filled('q')) {
            $query->search($request->q);
        }

        if ($request->boolean('incomplete')) {
            $query->incomplete();
        }

        return $this->page('Entities/Index', array_merge([
            'entities' => $query->paginate(40)->withQueryString(),
            'filters' => $request->only(['type', 'status', 'universe', 'q', 'incomplete']),
            'entityTypes' => EntityType::CATEGORIES,
            'statuses' => [
                'concept', 'active', 'archived',
                'deceased', 'destroyed', 'dormant', 'unknown',
            ],
            'universes' => \App\Domain\Identity\ValueObjects\SourceUniverse::ALL ?? [],
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
        $entity->load([
            'aliases',
            'notes' => fn ($q) => $q->orderBy('sort_order')->orderBy('created_at'),
            'questions' => fn ($q) => $q->orderByRaw("CASE priority WHEN 'blocking' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")->orderBy('created_at'),
        ]);
        $this->attachEmbeddedNotionNotes($entity);

        return $this->pageWithNotionNote('Entities/Show', $entity, 'entities', array_merge([
            'entity' => $entity,
        ], $props));
    }
}
