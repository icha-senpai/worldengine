<?php

namespace App\Http\Controllers\Identity;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Services\EntityService;
use App\Domain\Identity\ValueObjects\EntityType;

class EntityController extends Controller
{
    public function __construct(
        private readonly EntityService $entityService,
    ) {}

    // GET /entities
    // Index page — filterable list of all entities
    public function index(Request $request): Response
    {
        $query = Entity::query()
            ->select([
                'id', 'name', 'public_title', 'entity_type', 'status',
                'source_universes', 'summary', 'completion_score',
                'visibility', 'power_tier_ceiling', 'published_at',
            ])
            ->orderBy('name');

        if ($request->filled('type')) {
            $query->ofType($request->type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('universe')) {
            $query->fromUniverse($request->universe);
        }

        // Search uses 'q' to match the Vue filter form
        if ($request->filled('q')) {
            $query->search($request->q);
        }

        if ($request->boolean('incomplete')) {
            $query->incomplete();
        }

        return $this->page('Entities/Index', [
            'entities'    => $query->paginate(40)->withQueryString(),
            'filters'     => $request->only(['type', 'status', 'universe', 'q', 'incomplete']),
            // Grouped by category for optgroup rendering in Vue
            'entityTypes' => EntityType::CATEGORIES,
            'statuses'    => [
                'concept', 'active', 'archived',
                'deceased', 'destroyed', 'dormant', 'unknown',
            ],
            'universes'   => \App\Domain\Identity\ValueObjects\SourceUniverse::ALL ?? [],
        ]);
    }

    // GET /entities/create
    public function create(): Response
    {
        return $this->page('Entities/Create', [
            'entityTypes' => EntityType::ALL,
        ]);
    }

    // POST /entities
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name'                 => ['required', 'string', 'max:255'],
            'entity_type'          => ['required', 'string', 'in:' . implode(',', EntityType::ALL)],
            'alternate_name'       => ['nullable', 'string', 'max:255'],
            'brief_description'    => ['nullable', 'string'],
            'source_universes'     => ['nullable', 'array'],
            'source_universes.*'   => ['string'],
            'origin_type'          => ['nullable', 'string'],
            'visibility'           => ['nullable', 'string'],
            'content_classification'=> ['nullable', 'string'],
        ]);

        $entity = $this->entityService->create($validated);

        return $this->to('entities.show', [$entity], "Entity '{$entity->name}' created.");
    }

    // GET /entities/{entity}
    public function show(Entity $entity): Response
    {
        return $this->page('Entities/Show', [
            'entity' => $entity,
        ]);
    }

    // GET /entities/{entity}/edit
    public function edit(Entity $entity): Response
    {
        return $this->page('Entities/Edit', [
            'entity'      => $entity->load(['aliases', 'notes']),
            'entityTypes' => EntityType::ALL,
        ]);
    }

    // PUT /entities/{entity}
    public function update(Request $request, Entity $entity): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name'                  => ['sometimes', 'string', 'max:255'],
            'alternate_name'        => ['nullable', 'string', 'max:255'],
            'brief_description'     => ['nullable', 'string'],
            'summary'               => ['nullable', 'array'],   // Tiptap JSON
            'true_nature'           => ['nullable', 'string'],
            'entity_type'           => ['sometimes', 'string', 'in:' . implode(',', EntityType::ALL)],
            'status'                => ['nullable', 'string'],
            'power_tier_ceiling'    => ['nullable', 'string'],
            'power_tier_operating'  => ['nullable', 'string'],
            'power_tier_influence'  => ['nullable', 'string'],
            'source_universes'      => ['nullable', 'array'],
            'source_universes.*'    => ['string'],
            'origin_type'           => ['nullable', 'string'],
            'space_type'            => ['nullable', 'string'],
            'control_state'         => ['nullable', 'string'],
            'visibility'            => ['nullable', 'string'],
            'content_classification'=> ['nullable', 'string'],
        ]);

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
}