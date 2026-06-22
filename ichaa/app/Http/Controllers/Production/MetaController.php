<?php

namespace App\Http\Controllers\Production;

use App\Domain\Identity\Models\Entity;
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
        $query = Meta::current()->latest();

        if ($request->filled('category')) {
            $query->ofCategory($request->category);
        }

        if ($request->filled('type')) {
            $query->ofNoteType($request->type);
        }

        if ($request->boolean('unresolved')) {
            $query->unresolved();
        }

        if ($request->boolean('blocking')) {
            $query->blocking();
        }

        return $this->page('Production/Meta/Index', [
            'notes' => $query->paginate(40)->withQueryString(),
            'filters' => $request->only(['category', 'type', 'unresolved', 'blocking']),
            'categories' => Meta::CATEGORIES,
            'noteTypes' => Meta::NOTE_TYPES,
        ]);
    }

    public function create(): Response
    {
        return $this->page('Production/Meta/Create', [
            'entities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'categories' => Meta::CATEGORIES,
            'noteTypes' => Meta::NOTE_TYPES,
            'priorities' => Meta::PRIORITIES,
            'actionStatuses' => Meta::ACTION_STATUSES,
            'symbolScopes' => Meta::SYMBOL_SCOPES,
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
        $meta->load(['entities:id,name,entity_type', 'supersededBy:id,title']);

        return $this->pageWithNotionNote('Production/Meta/Show', $meta, 'meta', [
            'note' => $meta,
        ]);
    }

    public function edit(Meta $meta): Response
    {
        return $this->page('Production/Meta/Edit', [
            'entities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'note' => $meta,
            'categories' => Meta::CATEGORIES,
            'noteTypes' => Meta::NOTE_TYPES,
            'priorities' => Meta::PRIORITIES,
            'actionStatuses' => Meta::ACTION_STATUSES,
            'symbolScopes' => Meta::SYMBOL_SCOPES,
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
}
