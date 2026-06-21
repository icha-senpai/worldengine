<?php

namespace App\Http\Controllers\Temporal;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Services\EntityService;
use App\Domain\Temporal\Models\Timeline;
use App\Domain\Temporal\Services\TemporalService;

class TimelineController extends Controller
{
    public function __construct(
        private readonly TemporalService $service,
        private readonly EntityService   $entityService,
    ) {}

    public function index(): Response
    {
        $timelines = Entity::ofType('timeline')
            ->withCount('timelineEvents as entry_count')
            ->orderBy('name')
            ->get(['id', 'name', 'status']);

        return $this->page('Temporal/Timelines/Index', [
            'timelines' => $timelines,
        ]);
    }

    public function create(): Response
    {
        return $this->page('Temporal/Timelines/Create');
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $entity = $this->entityService->create(array_merge(
            $request->validate([
                'name'              => ['required', 'string', 'max:255'],
                'brief_description' => ['nullable', 'string'],
                'visibility'        => ['nullable', 'string'],
            ]),
            ['entity_type' => 'timeline']
        ));

        return $this->to('timelines.show', [$entity], "Timeline '{$entity->name}' created.");
    }

    public function show(Entity $timeline): Response
    {
        $this->assertTimelineEntity($timeline);

        $events = $this->service->getTimelineEvents($timeline->id);

        return $this->page('Temporal/Timelines/Show', [
            'timeline'  => $timeline,
            'atemporal' => $events['atemporal'],
            'events'    => $events['chronological'],
        ]);
    }

    public function edit(Entity $timeline): Response
    {
        $this->assertTimelineEntity($timeline);

        return $this->page('Temporal/Timelines/Edit', [
            'timeline' => $timeline,
        ]);
    }

    public function update(Request $request, Entity $timeline): \Illuminate\Http\RedirectResponse
    {
        $this->assertTimelineEntity($timeline);

        $timeline->update($request->validate([
            'name'              => ['sometimes', 'string'],
            'brief_description' => ['nullable', 'string'],
        ]));

        return $this->back('Timeline updated.');
    }

    public function destroy(Entity $timeline): \Illuminate\Http\RedirectResponse
    {
        $this->assertTimelineEntity($timeline);

        $timeline->delete();

        return $this->to('timelines.index', [], 'Timeline deleted.');
    }

    public function placeEvent(Request $request, Entity $timeline, Entity $event): \Illuminate\Http\RedirectResponse
    {
        $this->assertTimelineEntity($timeline);

        $validated = $request->validate([
            'entry_label'          => ['nullable', 'string', 'max:255'],
            'au_date'             => ['nullable', 'string'],
            'source_date'         => ['nullable', 'string'],
            'source_date_universe'=> ['nullable', 'string'],
            'timeline_position'   => ['nullable', 'integer'],
            'primordial_era'      => ['boolean'],
            'era_entity_id'       => ['nullable', 'integer', 'exists:entities,id'],
            'concurrency_group_id'=> ['nullable', 'integer', 'exists:concurrency_groups,id'],
            'time_density'        => ['nullable', 'string', 'in:' . implode(',', Timeline::TIME_DENSITY_LEVELS)],
            'causality_type'      => ['nullable', 'string', 'in:' . implode(',', Timeline::CAUSALITY_TYPES)],
            'causality_notes'     => ['nullable', 'string'],
            'event_significance'  => ['nullable', 'string', 'in:' . implode(',', Timeline::EVENT_SIGNIFICANCE_LEVELS)],
            'is_atemporal'        => ['boolean'],
            'public_narrative'    => ['nullable', 'array'],
            'true_narrative'      => ['nullable', 'array'],
            'narrative_divergence'=> ['nullable', 'string', 'in:' . implode(',', Timeline::NARRATIVE_DIVERGENCE_LEVELS)],
            'truth_revealed_at_era'=> ['nullable', 'string'],
            'temporal_certainty'  => ['nullable', 'string', 'in:' . implode(',', Timeline::TEMPORAL_CERTAINTY_LEVELS)],
        ]);

        $this->service->placeEvent($timeline, $event, $validated);

        return $this->back('Event placed on timeline.');
    }

    public function removeEvent(Entity $timeline, Timeline $entry): \Illuminate\Http\RedirectResponse
    {
        $this->assertTimelineEntity($timeline);
        abort_unless((int) $entry->timeline_id === (int) $timeline->id, 404);

        $this->service->removeFromTimeline($entry);

        return $this->back('Event removed from timeline.');
    }

    private function assertTimelineEntity(Entity $timeline): void
    {
        abort_unless($timeline->entity_type === 'timeline', 404);
    }
}
