<?php

namespace App\Http\Controllers\Admin;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsContentEntry;
use App\Domain\ConnectedRealms\Services\ConnectedRealmsContentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveConnectedRealmsContentEntryRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class ConnectedRealmsContentController extends Controller
{
    public function index(Request $request, ConnectedRealmsContentService $content): Response
    {
        $surface = $content->surfaceKey((string) $request->query('surface', 'tiers'));

        return $this->page('Admin/EvergatherContent/Index', [
            'surfaces' => $content->surfaceOptions(),
            'active_surface' => $surface,
            'entries' => $content->adminEntriesFor($surface),
        ]);
    }

    public function store(SaveConnectedRealmsContentEntryRequest $request): RedirectResponse
    {
        $entry = ConnectedRealmsContentEntry::query()->updateOrCreate(
            [
                'surface' => $request->validated('surface'),
                'entry_key' => $request->validated('entry_key'),
            ],
            [
                'label' => $request->validated('label'),
                'category' => $request->validated('category'),
                'required_level' => $request->validated('required_level'),
                'rarity' => $request->validated('rarity'),
                'enabled' => $request->boolean('enabled'),
                'sort_order' => (int) $request->validated('sort_order', 0),
                'payload' => $request->payload(),
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ],
        );

        return redirect()
            ->route('admin.evergather-content.index', ['surface' => $entry->surface])
            ->with('success', 'Evergather content saved.');
    }

    public function update(SaveConnectedRealmsContentEntryRequest $request, ConnectedRealmsContentEntry $entry): RedirectResponse
    {
        $entry->fill([
            'surface' => $request->validated('surface'),
            'entry_key' => $request->validated('entry_key'),
            'label' => $request->validated('label'),
            'category' => $request->validated('category'),
            'required_level' => $request->validated('required_level'),
            'rarity' => $request->validated('rarity'),
            'enabled' => $request->boolean('enabled'),
            'sort_order' => (int) $request->validated('sort_order', 0),
            'payload' => $request->payload(),
            'updated_by' => $request->user()?->id,
        ])->save();

        return redirect()
            ->route('admin.evergather-content.index', ['surface' => $entry->surface])
            ->with('success', 'Evergather content updated.');
    }
}
