<?php

namespace App\Http\Controllers\Production;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\ContentClassification;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
use App\Domain\Production\Models\Meta;
use App\Http\Controllers\Controller;
use App\Support\Validation\DataverseRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class MetaController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->indexPage($request);
    }

    public function create(): Response
    {
        return $this->indexPage(request(), [
            'createDrawer' => $this->metaFormProps(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('meta', 'store'));

        $validated = array_filter($validated, fn ($v) => ! ($v === '' || $v === null) || is_array($v) || is_bool($v));

        $note = Meta::create($validated);

        return $this->to('meta.show', [$note], "Note '{$note->title}' created.");
    }

    public function show(Meta $meta): Response
    {
        return $this->showPage($meta);
    }

    public function edit(Meta $meta): Response
    {
        return $this->showPage($meta, [
            'editDrawer' => $this->metaFormProps(),
        ]);
    }

    public function update(Request $request, Meta $meta): RedirectResponse
    {
        $meta->update($request->validate(DataverseRules::web('meta', 'update')));

        return $this->to('meta.show', [$meta], 'Note updated.');
    }

    public function destroy(Meta $meta): RedirectResponse
    {
        $meta->delete();

        return $this->to('meta.index', [], 'Note deleted.');
    }

    public function resolve(Request $request, Meta $meta): RedirectResponse
    {
        $meta->update([
            'action_status' => 'resolved',
            'resolved_at' => now(),
            'resolution_notes' => $request->input('resolution_notes'),
        ]);

        return $this->back('Note resolved.');
    }

    public function supersede(Request $request, Meta $meta): RedirectResponse
    {
        $validated = $request->validate(DataverseRules::webAction('meta-supersede'));

        $meta->update(array_merge($validated, [
            'superseded_at' => now(),
        ]));

        return $this->back('Note superseded.');
    }

    public function linkEntity(Meta $meta, Entity $entity): RedirectResponse
    {
        $meta->entities()->syncWithoutDetaching([$entity->id]);

        return $this->back("{$entity->name} linked.");
    }

    public function unlinkEntity(Meta $meta, Entity $entity): RedirectResponse
    {
        $meta->entities()->detach($entity->id);

        return $this->back("{$entity->name} unlinked.");
    }

    private function indexPage(Request $request, array $props = []): Response
    {
        $query = Meta::current()->latest();

        if ($request->filled('category')) {
            $query->ofCategory($request->category);
        }

        if ($request->filled('type')) {
            $query->ofNoteType($request->type);
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            $query->where(function ($inner) use ($term) {
                $inner->where('title', 'like', "%{$term}%")
                    ->orWhere('category', 'like', "%{$term}%")
                    ->orWhere('meta_note_type', 'like', "%{$term}%");
            });
        }

        if ($request->boolean('unresolved')) {
            $query->unresolved();
        }

        if ($request->boolean('blocking')) {
            $query->blocking();
        }

        return $this->page('Production/Meta/Index', array_merge([
            'notes' => $query->paginate(40)->withQueryString(),
            'filters' => $request->only(['q', 'category', 'type', 'unresolved', 'blocking']),
            'categories' => Meta::CATEGORIES,
            'noteTypes' => Meta::NOTE_TYPES,
        ], $props));
    }

    private function showPage(Meta $meta, array $props = []): Response
    {
        $meta->load(['entities:id,name,entity_type', 'supersededBy:id,title']);

        $symbolEntityIds = collect($meta->symbol_associated_entity_ids ?? []);

        if ($meta->symbol_origin_entity_id) {
            $symbolEntityIds->prepend($meta->symbol_origin_entity_id);
        }

        $symbolEntities = Entity::query()
            ->select('id', 'name', 'entity_type')
            ->whereIn('id', $symbolEntityIds->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        $meta->setRelation('symbolOriginEntity', $symbolEntities->get($meta->symbol_origin_entity_id));
        $meta->setRelation(
            'symbolAssociatedEntities',
            $symbolEntityIds
                ->slice($meta->symbol_origin_entity_id ? 1 : 0)
                ->map(fn ($id) => $symbolEntities->get($id))
                ->filter()
                ->values()
        );

        return $this->pageWithNotionNote('Production/Meta/Show', $meta, 'meta', array_merge([
            'note' => $meta,
            'entities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'supersedeOptions' => Meta::query()
                ->select('id', 'title')
                ->whereKeyNot($meta->id)
                ->orderByDesc('id')
                ->get(),
        ], $props));
    }

    private function metaFormProps(): array
    {
        return [
            'entities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'categories' => Meta::CATEGORIES,
            'noteTypes' => Meta::NOTE_TYPES,
            'priorities' => Meta::PRIORITIES,
            'actionStatuses' => Meta::ACTION_STATUSES,
            'symbolScopes' => Meta::SYMBOL_SCOPES,
            'visibilityLevels' => VisibilityLevel::ALL,
            'contentClassifications' => ContentClassification::ALL,
        ];
    }
}
