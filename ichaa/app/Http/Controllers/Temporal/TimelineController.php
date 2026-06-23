<?php

namespace App\Http\Controllers\Temporal;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Services\EntityService;
use App\Domain\Temporal\Models\ConcurrencyGroup;
use App\Domain\Temporal\Models\Timeline;
use App\Domain\Temporal\Services\TemporalService;
use App\Http\Controllers\Controller;
use App\Support\Validation\DataverseRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Response;

class TimelineController extends Controller
{
    public function __construct(
        private readonly TemporalService $service,
        private readonly EntityService $entityService,
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
        $validated = $request->validate(DataverseRules::web('timelines', 'store'));

        // Match the entity create path so blank UI values fall back to entity defaults.
        $validated = array_filter($validated, fn ($v) => ! ($v === '' || $v === null) || is_array($v) || is_bool($v));

        $entity = $this->entityService->create(array_merge(
            $validated,
            ['entity_type' => 'timeline']
        ));

        return $this->to('timelines.show', [$entity], "Timeline '{$entity->name}' created.");
    }

    public function show(Entity $timeline): Response
    {
        $this->assertTimelineEntity($timeline);
        return $this->showPage($timeline);
    }

    public function edit(Entity $timeline): Response
    {
        $this->assertTimelineEntity($timeline);

        return $this->showPage($timeline, [
            'editDrawer' => [],
        ]);
    }

    public function update(Request $request, Entity $timeline): RedirectResponse
    {
        $this->assertTimelineEntity($timeline);

        $timeline->update($request->validate(DataverseRules::web('timelines', 'update')));

        return $this->to('timelines.show', [$timeline], 'Timeline updated.');
    }

    public function destroy(Entity $timeline): RedirectResponse
    {
        $this->assertTimelineEntity($timeline);

        $timeline->delete();

        return $this->to('timelines.index', [], 'Timeline deleted.');
    }

    public function placeEvent(Request $request, Entity $timeline, Entity $event): RedirectResponse
    {
        $this->assertTimelineEntity($timeline);

        $validated = $request->validate($this->timelineEntryRules());

        // Keep placement resilient when optional UI fields are left blank and the
        // underlying schema is narrower than the full domain model contract.
        $validated = $this->filterTimelinePersistableData($validated);

        $this->service->placeEvent($timeline, $event, $validated);

        return $this->back('Event placed on timeline.');
    }

    public function editEvent(Entity $timeline, Timeline $entry): Response
    {
        $this->assertTimelineEntity($timeline);
        $this->assertTimelineEventBelongsToTimeline($timeline, $entry);

        $entry->load([
            'eventEntity:id,name,entity_type',
            'concurrencyGroup:id,name,au_date',
        ]);

        $concurrencyGroups = ConcurrencyGroup::query()
            ->orderBy('au_date')
            ->orderBy('name')
            ->get(['id', 'name', 'au_date', 'narrative_significance']);

        return $this->showPage($timeline, [
            'eventEditDrawer' => [
                'entry' => $entry,
                'concurrencyGroups' => $concurrencyGroups,
                'eventSignificanceLevels' => Timeline::EVENT_SIGNIFICANCE_LEVELS,
            ],
        ]);
    }

    public function updateEvent(Request $request, Entity $timeline, Timeline $entry): RedirectResponse
    {
        $this->assertTimelineEntity($timeline);
        $this->assertTimelineEventBelongsToTimeline($timeline, $entry);

        $validated = $this->filterTimelinePersistableData(
            $request->validate($this->timelineEntryRules())
        );

        $this->service->updateTimelineEntry($entry, $validated);

        return $this->to('timelines.show', [$timeline], 'Timeline event updated.');
    }

    public function removeEvent(Entity $timeline, Timeline $entry): RedirectResponse
    {
        $this->assertTimelineEntity($timeline);
        $this->assertTimelineEventBelongsToTimeline($timeline, $entry);

        $this->service->removeFromTimeline($entry);

        return $this->back('Event removed from timeline.');
    }

    private function assertTimelineEntity(Entity $timeline): void
    {
        abort_unless($timeline->entity_type === 'timeline', 404);
    }

    private function assertTimelineEventBelongsToTimeline(Entity $timeline, Timeline $entry): void
    {
        abort_unless((int) $entry->timeline_id === (int) $timeline->id, 404);
    }

    private function timelineEntryRules(): array
    {
        return DataverseRules::webAction('timeline-update-event');
    }

    private function filterTimelinePersistableData(array $validated): array
    {
        $filtered = array_filter(
            $validated,
            fn ($value) => ! ($value === '' || $value === null) || is_array($value) || is_bool($value)
        );

        $supportedColumns = array_flip(Schema::getColumnListing('timeline'));

        return array_intersect_key($filtered, $supportedColumns);
    }



    private function indexPage(Request $request, array $props = []): Response
    {
        $timelines = Entity::ofType('timeline')
            ->withCount('timelineEvents as entry_count')
            ->orderBy('name')
            ->get(['id', 'name', 'status']);

                return $this->page('Temporal/Timelines/Index', array_merge([
            'timelines' => $timelines,
        ], $props));
    
    }

    private function createFormProps(): array
    {
        return [];
    }

    private function showPage(Entity $timeline, array $props = []): Response
    {
        $events = $this->service->getTimelineEvents($timeline->id);
        $placedEventIds = Timeline::onTimeline($timeline->id)->pluck('event_entity_id');

        $availableEvents = Entity::events()
            ->when(
                $placedEventIds->isNotEmpty(),
                fn ($query) => $query->whereNotIn('id', $placedEventIds)
            )
            ->orderBy('name')
            ->get(['id', 'name', 'entity_type']);

        $concurrencyGroups = ConcurrencyGroup::query()
            ->orderBy('au_date')
            ->orderBy('name')
            ->get(['id', 'name', 'au_date', 'narrative_significance']);

        return $this->pageWithNotionNote('Temporal/Timelines/Show', $timeline, 'timelines', array_merge([
            'timeline' => $timeline,
            'atemporal' => $events['atemporal'],
            'events' => $events['chronological'],
            'availableEvents' => $availableEvents,
            'concurrencyGroups' => $concurrencyGroups,
            'eventSignificanceLevels' => Timeline::EVENT_SIGNIFICANCE_LEVELS,
        ], $props));
    }
}
