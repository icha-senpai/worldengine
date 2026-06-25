<?php

namespace App\Http\Controllers\Lore;

use App\Domain\Identity\ValueObjects\VisibilityLevel;
use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Lore\Models\CrossoverEntryPoint;
use App\Support\Validation\DataverseRules;

class CrossoverEntryPointController extends Controller
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
        $validated = $request->validate(DataverseRules::web('crossover-entry-points', 'store'));

        $ep = CrossoverEntryPoint::create($validated);

        return $this->to('crossover-entry-points.show', [$ep], 'Entry point created.');
    }

    public function show(CrossoverEntryPoint $crossoverEntryPoint): Response
    {
        return $this->showPage($crossoverEntryPoint);
    }

    public function edit(CrossoverEntryPoint $crossoverEntryPoint): Response
    {
        return $this->showPage($crossoverEntryPoint, [
            'editDrawer' => [
                'statuses' => CrossoverEntryPoint::STATUSES,
            ],
        ]);
    }

    public function update(Request $request, CrossoverEntryPoint $crossoverEntryPoint): \Illuminate\Http\RedirectResponse
    {
        $crossoverEntryPoint->update($request->validate(
            DataverseRules::web('crossover-entry-points', 'update')
        ));

        return $this->to('crossover-entry-points.show', [$crossoverEntryPoint], 'Entry point updated.');
    }

    public function destroy(CrossoverEntryPoint $crossoverEntryPoint): \Illuminate\Http\RedirectResponse
    {
        $crossoverEntryPoint->delete();

        return $this->to('crossover-entry-points.index', [], 'Entry point deleted.');
    }



    private function indexPage(Request $request, array $props = []): Response
    {
        $query = CrossoverEntryPoint::query()->orderBy('source_universe');

        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            $query->where('source_universe', 'like', "%{$term}%");
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('visibility')) {
            $query->where('visibility', $request->string('visibility')->toString());
        }

        return $this->page('Lore/CrossoverEntryPoints/Index', array_merge([
            'entryPoints' => $query->get(),
            'filters' => $request->only(['q', 'status', 'visibility']),
            'statuses' => CrossoverEntryPoint::STATUSES,
        ], $props));
    }

    private function createFormProps(): array
    {
        return [
            'statuses' => CrossoverEntryPoint::STATUSES,
        
        ];
    }

    private function showPage(CrossoverEntryPoint $crossoverEntryPoint, array $props = []): Response
    {
        return $this->pageWithNotionNote('Lore/CrossoverEntryPoints/Show', $crossoverEntryPoint, 'crossover_entry_points', array_merge([
            'entryPoint' => $crossoverEntryPoint->load('firstDocumentedCrossingEvent:id,name'),
        ], $props));
    }
}
