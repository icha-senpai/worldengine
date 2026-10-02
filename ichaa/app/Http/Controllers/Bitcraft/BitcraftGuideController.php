<?php

namespace App\Http\Controllers\Bitcraft;

use App\Domain\Bitcraft\Models\BitcraftGuide;
use App\Domain\Bitcraft\Services\BitcraftSpacetimeStaticData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bitcraft\SaveBitcraftGuideRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BitcraftGuideController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:255']]);
        $canManage = $request->user()->canAccessAdmin();
        $query = BitcraftGuide::query()
            ->select(['id', 'user_id', 'title', 'summary', 'category', 'published_at', 'updated_at'])
            ->with('author:id,name');

        if (! $canManage) {
            $query->published();
        }

        $search = trim($filters['q'] ?? '');

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query->where('title', 'ilike', '%'.$search.'%')
                    ->orWhere('summary', 'ilike', '%'.$search.'%')
                    ->orWhere('category', 'ilike', '%'.$search.'%');
            });
        }

        return Inertia::render('Bitcraft/Guides/Index', [
            'guides' => $query->orderByDesc('updated_at')->orderByDesc('id')->paginate(15)->withQueryString(),
            'filters' => ['q' => $search],
            'canManage' => $canManage,
        ]);
    }

    public function show(Request $request, BitcraftGuide $guide): Response
    {
        $canManage = $request->user()->canAccessAdmin();

        abort_unless($canManage || ($guide->published_at !== null && $guide->published_at->lte(now())), 404);

        return Inertia::render('Bitcraft/Guides/Show', [
            'guide' => $guide->load('author:id,name'),
            'canManage' => $canManage,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Bitcraft/Guides/Form', ['guide' => null]);
    }

    public function items(Request $request, BitcraftSpacetimeStaticData $spacetime): JsonResponse
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:255']]);

        return response()->json([
            'items' => $spacetime->catalogSearch(trim($filters['q'] ?? ''), 40),
            'available' => $spacetime->isAvailable(),
        ]);
    }

    public function store(SaveBitcraftGuideRequest $request): RedirectResponse
    {
        $guide = BitcraftGuide::query()->create([
            ...$request->safe()->except('is_published'),
            'user_id' => $request->user()->id,
            'published_at' => $request->boolean('is_published') ? now() : null,
        ]);

        return redirect()->route('bitcraft.guides.show', $guide)->with('success', 'Guide saved.');
    }

    public function edit(BitcraftGuide $guide): Response
    {
        return Inertia::render('Bitcraft/Guides/Form', ['guide' => $guide]);
    }

    public function update(SaveBitcraftGuideRequest $request, BitcraftGuide $guide): RedirectResponse
    {
        $guide->update([
            ...$request->safe()->except('is_published'),
            'published_at' => $request->boolean('is_published') ? ($guide->published_at ?? now()) : null,
        ]);

        return redirect()->route('bitcraft.guides.show', $guide)->with('success', 'Guide updated.');
    }
}
