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
            ->withCount('timelineEntries as entry_count')
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
        $events = $this->service->getTimelineEvents($timeline->id);

        return $this->page('Temporal/Timelines/Show', [
            'timeline'  => $timeline,
            'atemporal' => $events['atemporal'],
            'events'    => $events['chronological'],
        ]);
    }

    public function edit(Entity $timeline): Response
    {
        return $this->page('Temporal/Timelines/Edit', [
            'timeline' => $timeline,
        ]);
    }

    public function update(Request $request, Entity $timeline): \Illuminate\Http\RedirectResponse
    {
        $timeline->update($request->validate([
            'name'              => ['sometimes', 'string'],
            'brief_description' => ['nullable', 'string'],
        ]));

        return $this->back('Timeline updated.');
    }

    public function destroy(Entity $timeline): \Illuminate\Http\RedirectResponse
    {
        $timeline->delete();

        return $this->to('timelines.index', [], 'Timeline deleted.');
    }

    public function placeEvent(Request $request, Entity $timeline, Entity $event): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'au_date'             => ['nullable', 'string'],
            'timeline_position'   => ['nullable', 'numeric'],
            'era_entity_id'       => ['nullable', 'integer', 'exists:entities,id'],
            'concurrency_group_id'=> ['nullable', 'integer'],
            'event_significance'  => ['nullable', 'string'],
            'is_atemporal'        => ['boolean'],
            'public_narrative'    => ['nullable', 'array'],
            'true_narrative'      => ['nullable', 'array'],
            'temporal_certainty'  => ['nullable', 'string'],
        ]);

        $this->service->placeEvent($timeline, $event, $validated);

        return $this->back('Event placed on timeline.');
    }

    public function removeEvent(Timeline $entry): \Illuminate\Http\RedirectResponse
    {
        $this->service->removeFromTimeline($entry);

        return $this->back('Event removed from timeline.');
    }
}
