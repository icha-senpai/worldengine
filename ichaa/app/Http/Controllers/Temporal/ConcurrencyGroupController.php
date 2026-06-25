<?php

namespace App\Http\Controllers\Temporal;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Temporal\Models\ConcurrencyGroup;
use App\Domain\Temporal\Services\TemporalService;
use App\Support\Validation\DataverseRules;

class ConcurrencyGroupController extends Controller
{
    public function __construct(
        private readonly TemporalService $service,
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

    public function show(ConcurrencyGroup $concurrencyGroup): Response
    {
        return $this->showPage($concurrencyGroup);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $this->normalizePayload(
            $request->validate(DataverseRules::web('concurrency-groups', 'store'))
        );

        $group = $this->service->createConcurrencyGroup($validated);

        return $this->to('concurrency-groups.show', [$group], "Concurrency group '{$group->name}' created.");
    }

    public function edit(ConcurrencyGroup $concurrencyGroup): Response
    {
        return $this->showPage($concurrencyGroup, [
            'editDrawer' => [
                'significanceLevels' => ConcurrencyGroup::SIGNIFICANCE_LEVELS,
            ],
        ]);
    }

    public function update(Request $request, ConcurrencyGroup $concurrencyGroup): \Illuminate\Http\RedirectResponse
    {
        $concurrencyGroup->update($this->normalizePayload(
            $request->validate(DataverseRules::web('concurrency-groups', 'update'))
        ));

        return $this->to('concurrency-groups.show', [$concurrencyGroup], 'Concurrency group updated.');
    }

    public function destroy(ConcurrencyGroup $concurrencyGroup): \Illuminate\Http\RedirectResponse
    {
        $concurrencyGroup->delete();

        return $this->to('concurrency-groups.index', [], 'Concurrency group deleted.');
    }



    private function indexPage(Request $request, array $props = []): Response
    {
        $query = ConcurrencyGroup::query()
            ->withCount('timelineEntries')
            ->orderBy('au_date');

        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            $query->where(function ($inner) use ($term) {
                $inner->where('name', 'like', "%{$term}%")
                    ->orWhere('au_date', 'like', "%{$term}%");
            });
        }

        if ($request->filled('significance')) {
            $query->where('narrative_significance', $request->string('significance')->toString());
        }

        return $this->page('Temporal/ConcurrencyGroups/Index', array_merge([
            'groups' => $query->get(),
            'filters' => $request->only(['q', 'significance']),
            'significanceLevels' => ConcurrencyGroup::SIGNIFICANCE_LEVELS,
        ], $props));
    }

    private function createFormProps(): array
    {
        return [
            'significanceLevels' => ConcurrencyGroup::SIGNIFICANCE_LEVELS,
        
        ];
    }

    private function showPage(ConcurrencyGroup $concurrencyGroup, array $props = []): Response
    {
        return $this->pageWithNotionNote('Temporal/ConcurrencyGroups/Show', $concurrencyGroup, 'concurrency_groups', array_merge([
            'group' => $concurrencyGroup->load([
                'timelineEntries.timeline:id,name',
                'timelineEntries.eventEntity:id,name,entity_type',
                'timelineEntries.era:id,name',
            ]),
        ], $props));
    }

    private function normalizePayload(array $payload): array
    {
        if (($payload['narrative_significance'] ?? null) === null || $payload['narrative_significance'] === '') {
            $payload['narrative_significance'] = 'moderate';
        }

        return $payload;
    }
}
