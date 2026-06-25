<?php

namespace App\Http\Controllers\Production;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Production\Models\PipelineItem;
use App\Http\Controllers\Controller;
use App\Support\Validation\DataverseRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class PipelineItemController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->indexPage($request);
    }

    public function create(Request $request): Response
    {
        $parent = $request->integer('parent')
            ? PipelineItem::query()->find($request->integer('parent'))
            : null;

        $drawerProps = array_merge($this->createFormProps(), [
            'initialParentItemId' => $parent?->id,
            'closeHref' => $parent
                ? route('pipeline.show', $parent)
                : route('pipeline.index'),
        ]);

        if ($parent) {
            return $this->showPage($parent, [
                'createDrawer' => $drawerProps,
            ]);
        }

        return $this->indexPage($request, [
            'createDrawer' => $drawerProps,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('pipeline-items', 'store'));

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
        return $this->showPage($pipeline);
    }

    public function edit(PipelineItem $pipeline): Response
    {
        return $this->showPage($pipeline, [
            'editDrawer' => [
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
            ],
        ]);
    }

    public function update(Request $request, PipelineItem $pipeline): RedirectResponse
    {
        $pipeline->update($request->validate(DataverseRules::web('pipeline-items', 'update')));

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

    private function indexPage(Request $request, array $props = []): Response
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

        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            $query->where('title', 'like', "%{$term}%");
        }

        return $this->page('Production/Pipeline/Index', array_merge([
            'items' => $query->paginate(40)->withQueryString(),
            'filters' => $request->only(['q', 'type', 'stage']),
            'pipelineTypes' => PipelineItem::PIPELINE_TYPES,
            'pipelineStages' => PipelineItem::PIPELINE_STAGES,
        ], $props));
    }

    private function createFormProps(): array
    {
        return [
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
        ];
    }

    private function showPage(PipelineItem $pipeline, array $props = []): Response
    {
        $pipeline->load([
            'children.povCharacter:id,name',
            'povCharacter:id,name',
            'location:id,name',
            'trackedEntity:id,name',
            'sensoryPalette:id,title',
            'parent:id,title',
        ]);

        return $this->pageWithNotionNote('Production/Pipeline/Show', $pipeline, 'pipeline_items', array_merge([
            'item' => $pipeline,
        ], $props));
    }
}
