<?php

namespace App\Http\Controllers\Production;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Production\Models\PipelineItem;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class PipelineItemController extends Controller
{
    public function index(Request $request): Response
    {
        $query = PipelineItem::topLevel()
            ->with(['povCharacter:id,name', 'location:id,name'])
            ->withCount('children')
            ->ordered();

        if ($request->filled('type')) {
            $query->ofType($request->type);
        }

        if ($request->filled('stage')) {
            $query->atStage($request->stage);
        }

        return $this->page('Production/Pipeline/Index', [
            'items' => $query->paginate(40)->withQueryString(),
            'filters' => $request->only(['type', 'stage']),
            'pipelineTypes' => PipelineItem::PIPELINE_TYPES,
            'pipelineStages' => PipelineItem::PIPELINE_STAGES,
        ]);
    }

    public function create(): Response
    {
        return $this->page('Production/Pipeline/Create', [
            'parentItems' => PipelineItem::query()
                ->select('id', 'title', 'pipeline_type')
                ->ordered()
                ->get(),
            'characterEntities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->whereIn('entity_type', array_merge(EntityType::CATEGORIES['people'], EntityType::POWERED_TYPES))
                ->orderBy('name')
                ->get()
                ->unique('id')
                ->values(),
            'locationEntities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->whereIn('entity_type', EntityType::SPATIAL_TYPES)
                ->orderBy('name')
                ->get(),
            'entities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'pipelineTypes' => PipelineItem::PIPELINE_TYPES,
            'pipelineStages' => PipelineItem::PIPELINE_STAGES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'pipeline_type' => ['required', 'string', 'in:'.implode(',', PipelineItem::PIPELINE_TYPES)],
            'pipeline_stage' => ['nullable', 'string', 'in:'.implode(',', PipelineItem::PIPELINE_STAGES)],
            'parent_pipeline_item_id' => ['nullable', 'integer', 'exists:writing_pipeline,id'],
            'pov_character_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
            'location_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
            'tracked_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
            'emotional_beat' => ['nullable', 'string', 'max:255'],
            'narrative_purpose' => ['nullable', 'string'],
            'arc_stage' => ['nullable', 'string', 'max:255'],
            'arc_notes' => ['nullable', 'string'],
            'visibility' => ['nullable', 'string'],
            'content_classification' => ['nullable', 'string'],
        ]);

        $validated = array_filter($validated, fn ($v) => ! ($v === '' || $v === null) || is_array($v) || is_bool($v));

        // Set sort order after siblings
        $parentId = $validated['parent_pipeline_item_id'] ?? null;
        $maxOrder = PipelineItem::where('parent_pipeline_item_id', $parentId)->max('sort_order') ?? 0;
        $validated['sort_order'] = $maxOrder + 1;
        $validated['pipeline_stage'] = $validated['pipeline_stage'] ?? 'concept';

        $item = PipelineItem::create($validated);

        return $this->to('pipeline.show', [$item], "'{$item->title}' created.");
    }

    public function show(PipelineItem $pipeline): Response
    {
        $pipeline->load([
            'children.povCharacter:id,name',
            'povCharacter:id,name',
            'location:id,name',
            'trackedEntity:id,name',
            'sensoryPalette:id,title',
            'parent:id,title',
        ]);

        return $this->pageWithNotionNote('Production/Pipeline/Show', $pipeline, 'pipeline_items', [
            'item' => $pipeline,
        ]);
    }

    public function edit(PipelineItem $pipeline): Response
    {
        return $this->page('Production/Pipeline/Edit', [
            'characterEntities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->whereIn('entity_type', array_merge(EntityType::CATEGORIES['people'], EntityType::POWERED_TYPES))
                ->orderBy('name')
                ->get()
                ->unique('id')
                ->values(),
            'locationEntities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->whereIn('entity_type', EntityType::SPATIAL_TYPES)
                ->orderBy('name')
                ->get(),
            'entities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'item' => $pipeline,
            'pipelineTypes' => PipelineItem::PIPELINE_TYPES,
            'pipelineStages' => PipelineItem::PIPELINE_STAGES,
        ]);
    }

    public function update(Request $request, PipelineItem $pipeline): RedirectResponse
    {
        $pipeline->update($request->validate([
            'title' => ['sometimes', 'string'],
            'pipeline_type' => ['sometimes', 'string'],
            'pipeline_stage' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'word_count' => ['nullable', 'integer'],
            'reading_time_minutes' => ['nullable', 'integer'],
            'emotional_beat' => ['nullable', 'string'],
            'narrative_purpose' => ['nullable', 'string'],
            'arc_stage' => ['nullable', 'string'],
            'arc_notes' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'pov_character_entity_id' => ['nullable', 'integer'],
            'location_entity_id' => ['nullable', 'integer'],
            'add_to_voice_samples' => ['boolean'],
        ]));

        return $this->to('pipeline.show', [$pipeline], 'Updated.');
    }

    public function destroy(PipelineItem $pipeline): RedirectResponse
    {
        $pipeline->delete();

        return $this->to('pipeline.index', [], 'Deleted.');
    }

    public function advance(PipelineItem $pipeline): RedirectResponse
    {
        $progression = [
            'concept' => 'outlined',
            'outlined' => 'drafted',
            'drafted' => 'revised',
            'revised' => 'complete',
        ];

        $next = $progression[$pipeline->pipeline_stage] ?? null;

        if ($next) {
            $pipeline->update(['pipeline_stage' => $next]);
        }

        return $this->back("Stage advanced to '{$pipeline->fresh()->pipeline_stage}'.");
    }
}
