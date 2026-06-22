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

    public function index(): Response
    {
        return $this->page('Temporal/ConcurrencyGroups/Index', [
            'groups' => ConcurrencyGroup::withCount('timelineEntries')->orderBy('au_date')->get(),
        ]);
    }

    public function create(): Response
    {
        return $this->page('Temporal/ConcurrencyGroups/Create', [
            'significanceLevels' => ConcurrencyGroup::SIGNIFICANCE_LEVELS,
        ]);
    }

    public function show(ConcurrencyGroup $concurrencyGroup): Response
    {
        return $this->pageWithNotionNote('Temporal/ConcurrencyGroups/Show', $concurrencyGroup, 'concurrency_groups', [
            'group' => $concurrencyGroup->load([
                'timelineEntries.timeline:id,name',
                'timelineEntries.eventEntity:id,name,entity_type',
                'timelineEntries.era:id,name',
            ]),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('concurrency-groups', 'store'));

        $group = $this->service->createConcurrencyGroup($validated);

        return $this->back("Concurrency group '{$group->name}' created.");
    }

    public function edit(ConcurrencyGroup $concurrencyGroup): Response
    {
        return $this->page('Temporal/ConcurrencyGroups/Edit', [
            'group'              => $concurrencyGroup,
            'significanceLevels' => ConcurrencyGroup::SIGNIFICANCE_LEVELS,
        ]);
    }

    public function update(Request $request, ConcurrencyGroup $concurrencyGroup): \Illuminate\Http\RedirectResponse
    {
        $concurrencyGroup->update($request->validate(
            DataverseRules::web('concurrency-groups', 'update')
        ));

        return $this->back('Concurrency group updated.');
    }

    public function destroy(ConcurrencyGroup $concurrencyGroup): \Illuminate\Http\RedirectResponse
    {
        $concurrencyGroup->delete();

        return $this->back('Concurrency group deleted.');
    }
}
