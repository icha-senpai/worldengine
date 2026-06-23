<?php

namespace App\Http\Controllers\Organization;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Organization\Models\Glossary;
use App\Domain\Identity\ValueObjects\SourceUniverse;
use App\Support\Validation\DataverseRules;

class GlossaryController extends Controller
{
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

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('glossary', 'store'));
        $validated['term_status'] = $validated['term_status'] ?? 'active';

        $term = Glossary::create($validated);

        return $this->to('glossary.show', [$term], "Term '{$term->term}' added.");
    }

    public function show(Glossary $glossary): Response
    {
        return $this->showPage($glossary);
    }

    public function edit(Glossary $glossary): Response
    {
        return $this->showPage($glossary, [
            'editDrawer' => $this->formProps(),
        ]);
    }

    public function update(Request $request, Glossary $glossary): \Illuminate\Http\RedirectResponse
    {
        $glossary->update($request->validate(DataverseRules::web('glossary', 'update')));

        return $this->to('glossary.show', [$glossary], 'Term updated.');
    }

    public function destroy(Glossary $glossary): \Illuminate\Http\RedirectResponse
    {
        $glossary->delete();

        return $this->to('glossary.index', [], 'Term deleted.');
    }



    private function indexPage(Request $request, array $props = []): Response
    {
        $query = Glossary::active()->orderBy('term');

        if ($request->filled('universe')) {
            $query->fromUniverse($request->universe);
        }

        if ($request->filled('context')) {
            $query->where('usage_context', $request->context);
        }

                return $this->page('Glossary/Index', array_merge([
            'terms'   => $query->paginate(60)->withQueryString(),
            'filters' => $request->only(['universe', 'context']),
        ], $props));
    
    }

    private function createFormProps(): array
    {
        return $this->formProps();
    }

    private function formProps(): array
    {
        return [
            'usageContexts' => Glossary::USAGE_CONTEXTS,
            'termStatuses' => Glossary::TERM_STATUSES,
            'originUniverses' => SourceUniverse::ALL,
        ];
    }

    private function showPage(Glossary $glossary, array $props = []): Response
    {
        return $this->pageWithNotionNote('Glossary/Show', $glossary, 'glossary', array_merge([
            'term' => $glossary,
        ], $props));
    }
}
