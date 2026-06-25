<?php

namespace App\Http\Controllers\Lore;

use App\Domain\Identity\Models\Entity;
use App\Domain\Lore\Models\SourceCanonReference;
use App\Http\Controllers\Controller;
use App\Support\Validation\DataverseRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class SourceCanonReferenceController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->indexPage($request);
    }

    public function create(): Response
    {
        return $this->indexPage(request(), [
            'createDrawer' => $this->formProps(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('canon-references', 'store'));

        $ref = SourceCanonReference::create($validated);

        return $this->to('canon-references.show', [$ref], 'Canon reference created.');
    }

    public function show(SourceCanonReference $canonReference): Response
    {
        return $this->showPage($canonReference);
    }

    public function edit(SourceCanonReference $canonReference): Response
    {
        return $this->showPage($canonReference, [
            'editDrawer' => $this->formProps(),
        ]);
    }

    public function update(Request $request, SourceCanonReference $canonReference): RedirectResponse
    {
        $canonReference->update($request->validate(
            DataverseRules::web('canon-references', 'update')
        ));

        return $this->to('canon-references.show', [$canonReference], 'Canon reference updated.');
    }

    public function destroy(SourceCanonReference $canonReference): RedirectResponse
    {
        $canonReference->delete();

        return $this->to('canon-references.index', [], 'Reference deleted.');
    }

    private function indexPage(Request $request, array $props = []): Response
    {
        $query = SourceCanonReference::universeLevel()->byPriority();

        if ($request->filled('universe')) {
            $query->forUniverse($request->universe);
        }

        if ($request->filled('research_status')) {
            $query->where('research_status', $request->string('research_status')->toString());
        }

        if ($request->filled('visibility')) {
            $query->where('visibility', $request->string('visibility')->toString());
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            $query->where(function ($inner) use ($term) {
                $inner->where('title', 'like', "%{$term}%")
                    ->orWhere('universe', 'like', "%{$term}%");
            });
        }

        return $this->page('Lore/CanonReferences/Index', array_merge([
            'references' => $query->with('childReferences')->get(),
            'filters' => $request->only(['universe', 'research_status', 'visibility', 'q']),
            'universes' => SourceCanonReference::query()
                ->select('universe')
                ->distinct()
                ->orderBy('universe')
                ->pluck('universe')
                ->filter()
                ->values(),
            'researchStatuses' => SourceCanonReference::RESEARCH_STATUSES,
        ], $props));

    }

    private function formProps(): array
    {
        return [
            'parentReferences' => SourceCanonReference::query()
                ->select('id', 'title', 'level', 'universe')
                ->orderBy('universe')
                ->orderBy('title')
                ->get(),
            'levels' => SourceCanonReference::LEVELS,
            'categoryTypes' => SourceCanonReference::CATEGORY_TYPES,
            'elementTypes' => SourceCanonReference::ELEMENT_TYPES,
            'researchStatuses' => SourceCanonReference::RESEARCH_STATUSES,
            'researchConfidences' => SourceCanonReference::RESEARCH_CONFIDENCES,
            'universePriorities' => SourceCanonReference::UNIVERSE_PRIORITIES,
            'entities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
        ];
    }

    private function showPage(SourceCanonReference $canonReference, array $props = []): Response
    {
        $canonReference->load(['childReferences.childReferences', 'auEntity:id,name', 'linkedEntities:id,name']);

        return $this->pageWithNotionNote('Lore/CanonReferences/Show', $canonReference, 'canon_references', array_merge([
            'reference' => $canonReference,
        ], $props));
    }
}
