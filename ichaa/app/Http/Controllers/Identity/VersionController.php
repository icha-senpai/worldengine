<?php

namespace App\Http\Controllers\Identity;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\VersionAndCanonState;
use App\Domain\Identity\Services\EntityService;
use App\Domain\Identity\ValueObjects\ContentClassification;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
use App\Support\Validation\DataverseRules;

class VersionController extends Controller
{
    public function __construct(
        private readonly EntityService $entityService,
    ) {}

    // GET /entities/{entity}/versions
    public function index(Request $request, Entity $entity): Response
    {
        $versions = $entity->versions()
            ->with([
                'sourceEntity:id,name',
                'terminatedBy:id,name',
                'supersededBy:id,version_label,version_number',
            ])
            ->when($request->filled('state'), fn ($query) => $query->where('version_state', $request->string('state')->value()))
            ->when($request->filled('trigger'), fn ($query) => $query->where('trigger_type', $request->string('trigger')->value()))
            ->when($request->filled('type'), fn ($query) => $query->where('version_type', $request->string('type')->value()))
            ->when(
                $request->string('current')->value() === 'only',
                fn ($query) => $query->where('is_current', true),
            )
            ->when(
                $request->string('current')->value() === 'exclude',
                fn ($query) => $query->where('is_current', false),
            )
            ->when(
                $request->string('version_zero')->value() === 'only',
                fn ($query) => $query->where('is_version_zero', true),
            )
            ->when(
                $request->string('version_zero')->value() === 'exclude',
                fn ($query) => $query->where('is_version_zero', false),
            )
            ->orderByDesc('is_version_zero')
            ->orderByDesc('version_number')
            ->get();

        $summaryVersions = $entity->versions()
            ->orderByDesc('version_number')
            ->get([
                'id',
                'version_label',
                'version_number',
                'version_state',
                'trigger_type',
                'is_current',
                'is_version_zero',
                'version_zero_confidence',
                'valid_from_era',
            ]);

        $currentVersion = $summaryVersions->firstWhere('is_current', true);
        $versionZero = $summaryVersions->firstWhere('is_version_zero', true);

        return $this->page('Entities/Versions/Index', [
            'entity'   => array_merge(
                $entity->only(['id', 'name', 'entity_type', 'visibility', 'content_classification']),
                ['current_version_number' => $currentVersion?->version_number ?? 1],
            ),
            'versions' => $versions,
            'filters' => $request->only(['state', 'trigger', 'type', 'current', 'version_zero']),
            'summary' => [
                'current' => $currentVersion,
                'versionZero' => $versionZero,
                'counts' => [
                    'total' => $summaryVersions->count(),
                    'automatic' => $summaryVersions->where('trigger_type', 'automatic')->count(),
                    'versionZero' => $summaryVersions->where('is_version_zero', true)->count(),
                    'deprecated' => $summaryVersions->where('version_state', 'deprecated')->count(),
                ],
            ],
            'versionStates' => ['current', 'archived', 'deprecated', 'iteration_failed'],
            'versionTypes' => ['soft', 'hard_iteration'],
            'triggerTypes' => ['manual', 'automatic'],
            'currentOptions' => [
                ['value' => 'only', 'label' => 'Current only'],
                ['value' => 'exclude', 'label' => 'Hide current'],
            ],
            'versionZeroOptions' => [
                ['value' => 'only', 'label' => 'Version zero only'],
                ['value' => 'exclude', 'label' => 'Hide version zero'],
            ],
            'visibilityLevels' => $this->options(VisibilityLevel::ALL),
            'contentClassifications' => $this->options(ContentClassification::ALL),
            'versionZeroConfidenceLevels' => $this->options(['rough', 'developing', 'solid', 'verified']),
        ]);
    }

    // GET /entities/{entity}/versions/{version}
    public function show(Entity $entity, VersionAndCanonState $version): Response
    {
        abort_unless((int) $version->entity_id === (int) $entity->id, 404);
        $version->load([
            'sourceEntity:id,name',
            'terminatedBy:id,name',
            'supersededBy:id,version_label,version_number',
        ]);

        return $this->page('Entities/Versions/Show', [
            'entity'  => $entity->only(['id', 'name', 'entity_type']),
            'version' => $version,
        ]);
    }

    // POST /entities/{entity}/versions
    // Creates a manual canon state snapshot
    public function store(Request $request, Entity $entity): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate(DataverseRules::webAction('entity-save-version'));

        if ($request->boolean('is_version_zero')) {
            $this->entityService->saveVersionZero($entity, $validated);
        } else {
            $this->entityService->saveManualCanonState($entity, $validated);
        }

        return $this->to('entities.versions.index', [$entity], 'Canon state saved.');
    }

    private function options(array $values): array
    {
        return array_map(fn (string $value) => [
            'value' => $value,
            'label' => str($value)->replace('_', ' ')->title()->value(),
        ], $values);
    }
}
