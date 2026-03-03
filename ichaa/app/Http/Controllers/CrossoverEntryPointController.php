<?php

namespace App\Http\Controllers\Lore;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Lore\Models\CrossoverEntryPoint;

class CrossoverEntryPointController extends Controller
{
    public function index(): Response
    {
        return $this->page('Lore/CrossoverEntryPoints/Index', [
            'entryPoints' => CrossoverEntryPoint::orderBy('source_universe')->get(),
        ]);
    }

    public function create(): Response
    {
        return $this->page('Lore/CrossoverEntryPoints/Create', [
            'statuses' => CrossoverEntryPoint::STATUSES,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'source_universe' => ['required', 'string'],
            'entry_mechanism' => ['nullable', 'array'],
            'status'          => ['nullable', 'string', 'in:' . implode(',', CrossoverEntryPoint::STATUSES)],
        ]);

        $ep = CrossoverEntryPoint::create($validated);

        return $this->to('crossover-entry-points.show', [$ep], 'Entry point created.');
    }

    public function show(CrossoverEntryPoint $crossoverEntryPoint): Response
    {
        return $this->page('Lore/CrossoverEntryPoints/Show', [
            'entryPoint' => $crossoverEntryPoint->load('firstDocumentedCrossingEvent:id,name'),
        ]);
    }

    public function edit(CrossoverEntryPoint $crossoverEntryPoint): Response
    {
        return $this->page('Lore/CrossoverEntryPoints/Edit', [
            'entryPoint' => $crossoverEntryPoint,
            'statuses'   => CrossoverEntryPoint::STATUSES,
        ]);
    }

    public function update(Request $request, CrossoverEntryPoint $crossoverEntryPoint): \Illuminate\Http\RedirectResponse
    {
        $crossoverEntryPoint->update($request->validate([
            'entry_mechanism'                => ['nullable', 'array'],
            'power_transition_rules'         => ['nullable', 'array'],
            'physical_transition_rules'      => ['nullable', 'array'],
            'memory_and_identity_rules'      => ['nullable', 'array'],
            'psychological_transition_rules' => ['nullable', 'array'],
            'return_rules'                   => ['nullable', 'array'],
            'status'                         => ['nullable', 'string'],
        ]));

        return $this->back('Entry point updated.');
    }

    public function destroy(CrossoverEntryPoint $crossoverEntryPoint): \Illuminate\Http\RedirectResponse
    {
        $crossoverEntryPoint->delete();

        return $this->to('crossover-entry-points.index', [], 'Entry point deleted.');
    }
}
