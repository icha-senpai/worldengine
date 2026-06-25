<?php

namespace App\Http\Controllers\Organization;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\ContentClassification;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
use App\Domain\Organization\Models\Collection;
use App\Domain\Organization\Services\CollectionService;
use App\Http\Controllers\Controller;
use App\Support\Validation\DataverseRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class CollectionController extends Controller
{
    public function __construct(
        private readonly CollectionService $service,
    ) {}

    public function index(Request $request): Response
    {
        return $this->indexPage($request);
    }

    public function create(): Response
    {
        return $this->indexPage(request(), [
            'createDrawer' => $this->createFormProps(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('collections', 'store'));

        $collection = $this->service->create($validated);

        return $this->to('collections.show', [$collection], "Collection '{$collection->name}' created.");
    }

    public function show(Collection $collection): Response
    {
        return $this->showPage($collection);
    }

    public function edit(Collection $collection): Response
    {
        return $this->showPage($collection, [
            'editDrawer' => $this->createFormProps(),
        ]);
    }

    public function update(Request $request, Collection $collection): RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('collections', 'update'));

        $this->service->update($collection, $validated);

        return $this->to('collections.show', [$collection], 'Collection updated.');
    }

    public function destroy(Collection $collection): RedirectResponse
    {
        $collection->delete();

        return $this->to('collections.index', [], 'Collection deleted.');
    }

    public function addEntity(Request $request, Collection $collection, Entity $entity): RedirectResponse
    {
        $this->service->addEntity($collection, $entity, $request->only(['role_in_collection', 'sort_order']));

        return $this->back('Entity added to collection.');
    }

    public function removeEntity(Collection $collection, Entity $entity): RedirectResponse
    {
        $this->service->removeEntity($collection, $entity);

        return $this->back('Entity removed from collection.');
    }

    public function sync(Collection $collection): RedirectResponse
    {
        $count = $this->service->syncSmartMembers($collection);

        return $this->back("{$count} entities synced.");
    }

    private function indexPage(Request $request, array $props = []): Response
    {
        $query = Collection::topLevel()->withCount('entities')->orderBy('name');

        if ($request->filled('type')) {
            $query->ofType($request->type);
        }

        return $this->page('Collections/Index', array_merge([
            'collections' => $query->get(),
            'filters' => $request->only(['type']),
            'types' => Collection::TYPES,
        ], $props));
    }

    private function createFormProps(): array
    {
        return [
            'collections' => Collection::query()
                ->select('id', 'name', 'collection_type')
                ->orderBy('name')
                ->get(),
            'types' => Collection::TYPES,
            'modes' => Collection::MODES,
            'completionStates' => Collection::COMPLETION_STATES,
            'visibilityLevels' => VisibilityLevel::ALL,
            'contentClassifications' => ContentClassification::ALL,
        ];
    }

    private function showPage(Collection $collection, array $props = []): Response
    {
        $collection->load([
            'entities:id,name,entity_type,completion_score',
            'entityEntries.entity:id,name,entity_type,completion_score',
            'childCollections:id,parent_collection_id,name,collection_type',
        ]);

        return $this->pageWithNotionNote('Collections/Show', $collection, 'collections', array_merge([
            'collection' => $collection,
            'entities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
        ], $props));
    }
}
