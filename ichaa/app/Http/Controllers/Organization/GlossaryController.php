<?php

namespace App\Http\Controllers\Organization;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Organization\Models\Glossary;
use App\Support\Validation\DataverseRules;

class GlossaryController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Glossary::active()->orderBy('term');

        if ($request->filled('universe')) {
            $query->fromUniverse($request->universe);
        }

        if ($request->filled('context')) {
            $query->where('usage_context', $request->context);
        }

        return $this->page('Glossary/Index', [
            'terms'   => $query->paginate(60)->withQueryString(),
            'filters' => $request->only(['universe', 'context']),
        ]);
    }

    public function create(): Response
    {
        return $this->page('Glossary/Create');
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('glossary', 'store'));

        $term = Glossary::create($validated);

        return $this->to('glossary.show', [$term], "Term '{$term->term}' added.");
    }

    public function show(Glossary $glossary): Response
    {
        return $this->pageWithNotionNote('Glossary/Show', $glossary, 'glossary', ['term' => $glossary]);
    }

    public function edit(Glossary $glossary): Response
    {
        return $this->page('Glossary/Edit', ['term' => $glossary]);
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
}
