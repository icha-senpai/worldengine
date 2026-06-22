<?php

namespace App\Http\Controllers\Lore;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Lore\Models\SourceCanonReference;

class SourceCanonReferenceController extends Controller
{
    public function index(Request $request): Response
    {
        $query = SourceCanonReference::universeLevel()->byPriority();

        if ($request->filled('universe')) {
            $query->forUniverse($request->universe);
        }

        return $this->page('Lore/CanonReferences/Index', [
            'references' => $query->with('childReferences')->get(),
            'filters'    => $request->only(['universe']),
        ]);
    }

    public function create(): Response
    {
        return $this->page('Lore/CanonReferences/Create', [
            'parentReferences'   => SourceCanonReference::query()
                ->select('id', 'title', 'level', 'universe')
                ->orderBy('universe')
                ->orderBy('title')
                ->get(),
            'levels'             => SourceCanonReference::LEVELS,
            'categoryTypes'      => SourceCanonReference::CATEGORY_TYPES,
            'elementTypes'       => SourceCanonReference::ELEMENT_TYPES,
            'researchStatuses'   => SourceCanonReference::RESEARCH_STATUSES,
            'universePriorities' => SourceCanonReference::UNIVERSE_PRIORITIES,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'universe'            => ['required', 'string'],
            'level'               => ['required', 'string', 'in:' . implode(',', SourceCanonReference::LEVELS)],
            'title'               => ['required', 'string', 'max:255'],
            'parent_reference_id' => ['nullable', 'integer', 'exists:source_canon_reference,id'],
            'universe_priority'   => ['nullable', 'string'],
            'research_status'     => ['nullable', 'string'],
        ]);

        $ref = SourceCanonReference::create($validated);

        return $this->to('canon-references.show', [$ref], 'Canon reference created.');
    }

    public function show(SourceCanonReference $canonReference): Response
    {
        $canonReference->load(['childReferences.childReferences', 'auEntity:id,name', 'linkedEntities:id,name']);

        return $this->pageWithNotionNote('Lore/CanonReferences/Show', $canonReference, 'canon_references', [
            'reference' => $canonReference,
        ]);
    }

    public function edit(SourceCanonReference $canonReference): Response
    {
        return $this->page('Lore/CanonReferences/Edit', [
            'entities'         => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'reference'       => $canonReference,
            'researchStatuses'=> SourceCanonReference::RESEARCH_STATUSES,
        ]);
    }

    public function update(Request $request, SourceCanonReference $canonReference): \Illuminate\Http\RedirectResponse
    {
        $canonReference->update($request->validate([
            'title'               => ['sometimes', 'string'],
            'content'             => ['nullable', 'array'],
            'research_status'     => ['nullable', 'string'],
            'research_confidence' => ['nullable', 'string'],
            'canon_disputed'      => ['boolean'],
            'au_entity_id'        => ['nullable', 'integer'],
        ]));

        return $this->back('Canon reference updated.');
    }

    public function destroy(SourceCanonReference $canonReference): \Illuminate\Http\RedirectResponse
    {
        $canonReference->delete();

        return $this->to('canon-references.index', [], 'Reference deleted.');
    }
}
