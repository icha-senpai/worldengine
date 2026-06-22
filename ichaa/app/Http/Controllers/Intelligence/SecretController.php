<?php

namespace App\Http\Controllers\Intelligence;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Intelligence\Services\IntelligenceService;

class SecretController extends Controller
{
    public function __construct(
        private readonly IntelligenceService $service,
    ) {}

    public function index(Request $request): Response
    {
        $query = Secret::active()->latest();

        if ($request->boolean('high_risk')) {
            $query->highRisk();
        }

        if ($request->boolean('leaking')) {
            $query->leaking();
        }

        return $this->page('Intelligence/Secrets/Index', [
            'secrets' => $query->paginate(40)->withQueryString(),
            'filters' => $request->only(['high_risk', 'leaking']),
        ]);
    }

    public function create(): Response
    {
        return $this->page('Intelligence/Secrets/Create', [
            'entities'      => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'secretTypes'   => Secret::SECRET_TYPES,
            'exposureRisks' => Secret::EXPOSURE_RISKS,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'title'               => ['required', 'string', 'max:255'],
            'secret_content'      => ['required', 'array'],
            'secret_type'         => ['required', 'string', 'in:' . implode(',', Secret::SECRET_TYPES)],
            'subject_entity_ids'  => ['nullable', 'array'],
            'holder_entity_ids'   => ['nullable', 'array'],
            'known_by_entity_ids' => ['nullable', 'array'],
            'exposure_risk'       => ['nullable', 'string', 'in:' . implode(',', Secret::EXPOSURE_RISKS)],
            'status'              => ['nullable', 'string'],
        ]);

        $secret = $this->service->createSecret($validated);

        return $this->to('secrets.show', [$secret], "Secret '{$secret->title}' created.");
    }

    public function show(Secret $secret): Response
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

        return $this->pageWithNotionNote('Intelligence/Secrets/Show', $secret, 'secrets', [
            'secret'          => $secret,
            'subjectEntities' => $this->entityListItems($secret->subject_entity_ids ?? [], $entities),
            'holderEntities'  => $this->entityListItems($secret->holder_entity_ids ?? [], $entities),
            'knownByEntities' => $this->entityListItems($secret->known_by_entity_ids ?? [], $entities),
        ]);
    }

    public function edit(Secret $secret): Response
    {
        return $this->page('Intelligence/Secrets/Edit', [
            'secret'        => $secret,
            'secretTypes'   => Secret::SECRET_TYPES,
            'exposureRisks' => Secret::EXPOSURE_RISKS,
        ]);
    }

    public function update(Request $request, Secret $secret): \Illuminate\Http\RedirectResponse
    {
        $this->service->updateSecret($secret, $request->validate([
            'title'              => ['sometimes', 'string'],
            'secret_content'     => ['nullable', 'array'],
            'exposure_risk'      => ['nullable', 'string'],
            'revelation_trigger' => ['nullable', 'string'],
            'status'             => ['nullable', 'string'],
        ]));

        return $this->to('secrets.show', [$secret], 'Secret updated.');
    }

    public function destroy(Secret $secret): \Illuminate\Http\RedirectResponse
    {
        $secret->delete();

        return $this->to('secrets.index', [], 'Secret deleted.');
    }

    public function expose(Request $request, Secret $secret): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'era'            => ['required', 'string'],
            'exposure_level' => ['nullable', 'string', 'in:partially_exposed,fully_exposed'],
        ]);

        $this->service->exposeSecret($secret, $validated['era'], $validated['exposure_level'] ?? 'partially_exposed');

        return $this->back('Secret exposure recorded.');
    }

    public function addKnownBy(Secret $secret, Entity $entity): \Illuminate\Http\RedirectResponse
    {
        $this->service->addToKnownBy($secret, $entity->id);

        return $this->back("{$entity->name} added to known-by.");
    }

    private function entityListItems(array $ids, \Illuminate\Support\Collection $entities): array
    {
        return collect($ids)
            ->map(function ($id) use ($entities) {
                $entity = $entities->get($id);

                if (!$entity) {
                    return [
                        'label' => "Unknown entity #{$id}",
                    ];
                }

                $type = $entity->entity_type ? " ({$entity->entity_type})" : '';

                return [
                    'label' => "{$entity->name}{$type}",
                    'href'  => route('entities.show', [$entity]),
                ];
            })
            ->values()
            ->all();
    }
}
