<?php

namespace App\Http\Controllers\Intelligence;

use App\Domain\Identity\Models\Entity;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Intelligence\Services\IntelligenceService;
use App\Http\Controllers\Controller;
use App\Support\Validation\DataverseRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Response;

class SecretController extends Controller
{
    public function __construct(
        private readonly IntelligenceService $service,
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
        $validated = $request->validate(DataverseRules::web('secrets', 'store'));

        $secret = $this->service->createSecret($validated);

        return $this->to('secrets.show', [$secret], "Secret '{$secret->title}' created.");
    }

    public function show(Secret $secret): Response
    {
        return $this->showPage($secret);
    }

    public function edit(Secret $secret): Response
    {
        return $this->showPage($secret, [
            'editDrawer' => $this->createFormProps(),
        ]);
    }

    public function update(Request $request, Secret $secret): RedirectResponse
    {
        $this->service->updateSecret($secret, $request->validate(DataverseRules::web('secrets', 'update')));

        return $this->to('secrets.show', [$secret], 'Secret updated.');
    }

    public function destroy(Secret $secret): RedirectResponse
    {
        $secret->delete();

        return $this->to('secrets.index', [], 'Secret deleted.');
    }

    public function expose(Request $request, Secret $secret): RedirectResponse
    {
        $validated = $request->validate(DataverseRules::webAction('secret-expose'));

        $this->service->exposeSecret($secret, $validated['era'], $validated['exposure_level'] ?? 'partially_exposed');

        return $this->back('Secret exposure recorded.');
    }

    public function addKnownBy(Secret $secret, Entity $entity): RedirectResponse
    {
        $this->service->addToKnownBy($secret, $entity->id);

        return $this->back("{$entity->name} added to known-by.");
    }

    private function entityListItems(array $ids, Collection $entities): array
    {
        return collect($ids)
            ->map(function ($id) use ($entities) {
                $entity = $entities->get($id);

                if (! $entity) {
                    return [
                        'label' => "Unknown entity #{$id}",
                    ];
                }

                $type = $entity->entity_type ? " ({$entity->entity_type})" : '';

                return [
                    'label' => "{$entity->name}{$type}",
                    'href' => route('entities.show', [$entity]),
                ];
            })
            ->values()
            ->all();
    }

    private function indexPage(Request $request, array $props = []): Response
    {
        $query = Secret::active()->latest();

        if ($request->boolean('high_risk')) {
            $query->highRisk();
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            $query->where(function ($inner) use ($term) {
                $inner->where('title', 'like', "%{$term}%")
                    ->orWhere('secret_type', 'like', "%{$term}%")
                    ->orWhere('status', 'like', "%{$term}%");
            });
        }

        if ($request->boolean('leaking')) {
            $query->leaking();
        }

        return $this->page('Intelligence/Secrets/Index', array_merge([
            'secrets' => $query->paginate(40)->withQueryString(),
            'filters' => $request->only(['q', 'high_risk', 'leaking']),
        ], $props));

    }

    private function createFormProps(): array
    {
        return [
            'entities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'secretTypes' => Secret::SECRET_TYPES,
            'exposureRisks' => Secret::EXPOSURE_RISKS,

        ];
    }

    private function showPage(Secret $secret, array $props = []): Response
    {
        $entityIds = array_values(array_unique(array_merge(
            $secret->subject_entity_ids ?? [],
            $secret->holder_entity_ids ?? [],
            $secret->known_by_entity_ids ?? [],
        )));

        $entities = Entity::query()
            ->select('id', 'name', 'entity_type')
            ->whereIn('id', $entityIds)
            ->get()
            ->keyBy('id');

        return $this->pageWithNotionNote('Intelligence/Secrets/Show', $secret, 'secrets', array_merge([
            'secret' => $secret,
            'subjectEntities' => $this->entityListItems($secret->subject_entity_ids ?? [], $entities),
            'holderEntities' => $this->entityListItems($secret->holder_entity_ids ?? [], $entities),
            'knownByEntities' => $this->entityListItems($secret->known_by_entity_ids ?? [], $entities),
            'entities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
        ], $props));
    }
}
