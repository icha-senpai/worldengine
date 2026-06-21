<?php

namespace App\Http\Controllers\Identity;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\VersionAndCanonState;
use App\Domain\Identity\Services\EntityService;

class VersionController extends Controller
{
    public function __construct(
        private readonly EntityService $entityService,
    ) {}

    // GET /entities/{entity}/versions
    public function index(Entity $entity): Response
    {
        $versions = $entity->versions()
            ->orderByDesc('version_number')
            ->get();

        return $this->page('Entities/Versions/Index', [
            'entity'   => $entity->only(['id', 'name', 'entity_type']),
            'versions' => $versions,
        ]);
    }

    // GET /entities/{entity}/versions/{version}
    public function show(Entity $entity, VersionAndCanonState $version): Response
    {
        abort_unless((int) $version->entity_id === (int) $entity->id, 404);

        return $this->page('Entities/Versions/Show', [
            'entity'  => $entity->only(['id', 'name', 'entity_type']),
            'version' => $version,
        ]);
    }

    // POST /entities/{entity}/versions
    // Creates a manual canon state snapshot
    public function store(Request $request, Entity $entity): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'version_label' => ['nullable', 'string', 'max:255'],
            'what_changed'  => ['nullable', 'string'],
            'why_changed'   => ['nullable', 'string'],
            'valid_from_era'=> ['nullable', 'string'],
            'is_version_zero'=> ['boolean'],
        ]);

        if ($request->boolean('is_version_zero')) {
            $this->entityService->saveVersionZero($entity, $validated);
        } else {
            $this->entityService->saveManualCanonState($entity, $validated);
        }

        return $this->back('Canon state saved.');
    }
}
