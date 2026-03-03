<?php

namespace App\Http\Controllers\Production;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Production\Models\Meta;
use App\Domain\Production\Models\PipelineItem;
use App\Domain\Production\Services\ProductionService;

class PipelineItemController extends Controller
{
    public function __construct(
        private readonly ProductionService $service,
    ) {}

    public function index(Request $request): Response
    {
        $query = PipelineItem::pending()
            ->with(['meta:id,title', 'entity:id,name'])
            ->ordered();

        if ($request->boolean('critical')) {
            $query->critical();
        }

        return $this->page('Production/Pipeline/Index', [
            'items'   => $query->paginate(40)->withQueryString(),
            'filters' => $request->only(['critical']),
        ]);
    }

    public function create(): Response
    {
        return $this->page('Production/Pipeline/Create', [
            'itemTypes'  => PipelineItem::ITEM_TYPES,
            'priorities' => PipelineItem::PRIORITIES,
            'metas'      => Meta::active()->get(['id', 'title']),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'meta_id'           => ['nullable', 'integer', 'exists:meta,id'],
            'entity_id'         => ['nullable', 'integer', 'exists:entities,id'],
            'item_type'         => ['required', 'string', 'in:' . implode(',', PipelineItem::ITEM_TYPES)],
            'title'             => ['required', 'string', 'max:255'],
            'description'       => ['nullable', 'array'],
            'priority'          => ['nullable', 'string', 'in:' . implode(',', PipelineItem::PRIORITIES)],
            'blocking_question' => ['nullable', 'string'],
        ]);

        if ($validated['meta_id'] ?? null) {
            $meta = Meta::findOrFail($validated['meta_id']);
            $this->service->createPipelineItem($meta, $validated);
        } else {
            $entity = Entity::findOrFail($validated['entity_id']);
            $this->service->createEntityPipelineItem($entity, $validated);
        }

        return $this->back('Pipeline item created.');
    }

    public function show(PipelineItem $pipelineItem): Response
    {
        return $this->page('Production/Pipeline/Show', [
            'item' => $pipelineItem->load(['meta:id,title', 'entity:id,name']),
        ]);
    }

    public function edit(PipelineItem $pipelineItem): Response
    {
        return $this->page('Production/Pipeline/Edit', [
            'item'       => $pipelineItem,
            'itemTypes'  => PipelineItem::ITEM_TYPES,
            'priorities' => PipelineItem::PRIORITIES,
            'statuses'   => PipelineItem::STATUSES,
        ]);
    }

    public function update(Request $request, PipelineItem $pipelineItem): \Illuminate\Http\RedirectResponse
    {
        $this->service->updatePipelineItem($pipelineItem, $request->validate([
            'title'             => ['sometimes', 'string'],
            'description'       => ['nullable', 'array'],
            'status'            => ['nullable', 'string'],
            'priority'          => ['nullable', 'string'],
            'blocking_question' => ['nullable', 'string'],
        ]));

        return $this->back('Item updated.');
    }

    public function destroy(PipelineItem $pipelineItem): \Illuminate\Http\RedirectResponse
    {
        $pipelineItem->delete();

        return $this->back('Item deleted.');
    }

    public function resolve(Request $request, PipelineItem $pipelineItem): \Illuminate\Http\RedirectResponse
    {
        $this->service->resolvePipelineItem($pipelineItem, $request->input('resolution_notes'));

        return $this->back('Item resolved.');
    }
}
