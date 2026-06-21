<?php

namespace App\Http\Controllers\Production;

use App\Domain\Identity\Models\Entity;
use App\Domain\Production\Models\Meta;
use App\Http\Controllers\Controller;
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
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:'.implode(',', Meta::CATEGORIES)],
            'meta_note_type' => ['required', 'string', 'in:'.implode(',', Meta::NOTE_TYPES)],
            'content' => ['nullable', 'array'],
            'sense_sight' => ['nullable', 'string'],
            'sense_sound' => ['nullable', 'string'],
            'sense_smell' => ['nullable', 'string'],
            'sense_taste' => ['nullable', 'string'],
            'sense_touch' => ['nullable', 'string'],
            'sense_magical' => ['nullable', 'string'],
            'emotional_register' => ['nullable', 'string'],
            'symbol_name' => ['nullable', 'string', 'max:255'],
            'symbol_origin_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
            'symbol_usage_context' => ['nullable', 'string'],
            'symbol_associated_entity_ids' => ['nullable', 'array'],
            'symbol_scope' => ['nullable', 'string', 'in:'.implode(',', Meta::SYMBOL_SCOPES)],
            'priority' => ['nullable', 'string', 'in:'.implode(',', Meta::PRIORITIES)],
            'action_status' => ['nullable', 'string', 'in:'.implode(',', Meta::ACTION_STATUSES)],
            'visibility' => ['nullable', 'string'],
            'content_classification' => ['nullable', 'string'],
        ]);

        $validated = array_filter($validated, fn ($v) => ! ($v === '' || $v === null) || is_array($v) || is_bool($v));

        $note = Meta::create($validated);

        return $this->to('meta.show', [$note], "Note '{$note->title}' created.");
    }

    public function show(Meta $meta): Response
    {
        $meta->load(['entities:id,name,entity_type', 'supersededBy:id,title']);

        return $this->page('Production/Meta/Show', [
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
        $meta->update($request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string'],
            'meta_note_type' => ['sometimes', 'string'],
            'content' => ['nullable', 'array'],
            'priority' => ['nullable', 'string'],
            'action_status' => ['nullable', 'string'],
            'resolution_notes' => ['nullable', 'array'],
            'resolved_at' => ['nullable', 'date'],
            'sense_sight' => ['nullable', 'string'],
            'sense_sound' => ['nullable', 'string'],
            'sense_smell' => ['nullable', 'string'],
            'sense_taste' => ['nullable', 'string'],
            'sense_touch' => ['nullable', 'string'],
            'sense_magical' => ['nullable', 'string'],
            'emotional_register' => ['nullable', 'string'],
        ]));

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
        $validated = $request->validate([
            'superseded_by_meta_id' => ['required', 'integer', 'exists:meta,id'],
            'supersession_reason' => ['nullable', 'string'],
        ]);

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
