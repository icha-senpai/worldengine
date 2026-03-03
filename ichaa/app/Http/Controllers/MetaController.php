<?php

namespace App\Http\Controllers\Production;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Production\Models\Meta;
use App\Domain\Production\Services\ProductionService;

class MetaController extends Controller
{
    public function __construct(
        private readonly ProductionService $service,
    ) {}

    public function index(): Response
    {
        return $this->page('Production/Meta/Index', [
            'activeMeta'    => Meta::active()->ordered()->get(),
            'plannedMeta'   => Meta::planned()->ordered()->get(),
            'completedMeta' => Meta::complete()->ordered()->get(),
        ]);
    }

    public function create(): Response
    {
        return $this->page('Production/Meta/Create', [
            'types'    => Meta::META_TYPES,
            'statuses' => Meta::STATUSES,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'title'             => ['required', 'string', 'max:255'],
            'meta_type'         => ['required', 'string', 'in:' . implode(',', Meta::META_TYPES)],
            'status'            => ['nullable', 'string'],
            'synopsis'          => ['nullable', 'array'],
            'target_word_count' => ['nullable', 'integer'],
        ]);

        $meta = $this->service->createMeta($validated);

        return $this->to('meta.show', [$meta], "'{$meta->title}' created.");
    }

    public function show(Meta $meta): Response
    {
        $meta->load([
            'pipelineItems',
            'entities:id,name,entity_type',
        ]);

        return $this->page('Production/Meta/Show', [
            'meta'  => $meta,
            'stats' => $this->service->getSessionStats(30),
        ]);
    }

    public function edit(Meta $meta): Response
    {
        return $this->page('Production/Meta/Edit', [
            'meta'  => $meta,
            'types' => Meta::META_TYPES,
        ]);
    }

    public function update(Request $request, Meta $meta): \Illuminate\Http\RedirectResponse
    {
        $this->service->updateMeta($meta, $request->validate([
            'title'              => ['sometimes', 'string'],
            'synopsis'           => ['nullable', 'array'],
            'full_outline'       => ['nullable', 'array'],
            'themes'             => ['nullable', 'array'],
            'author_notes'       => ['nullable', 'array'],
            'current_word_count' => ['nullable', 'integer'],
            'status'             => ['nullable', 'string'],
        ]));

        return $this->to('meta.show', [$meta], 'Updated.');
    }

    public function destroy(Meta $meta): \Illuminate\Http\RedirectResponse
    {
        $meta->delete();

        return $this->to('meta.index', [], 'Deleted.');
    }

    public function advance(Meta $meta): \Illuminate\Http\RedirectResponse
    {
        $this->service->advanceStatus($meta);

        return $this->back("Status advanced to '{$meta->fresh()->status}'.");
    }

    public function linkEntity(Request $request, Meta $meta, Entity $entity): \Illuminate\Http\RedirectResponse
    {
        $this->service->linkEntity($meta, $entity, $request->only(['role_in_meta', 'sort_order']));

        return $this->back("{$entity->name} linked.");
    }

    public function unlinkEntity(Meta $meta, Entity $entity): \Illuminate\Http\RedirectResponse
    {
        $this->service->unlinkEntity($meta, $entity);

        return $this->back("{$entity->name} unlinked.");
    }
}
