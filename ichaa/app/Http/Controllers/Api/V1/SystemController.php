<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\System\Models\NotionNote;
use App\Domain\System\Models\Revision;
use App\Domain\System\Services\NotionDataverseSyncService;
use App\Domain\System\Services\RevisionService;
use App\Support\Api\ApiMutationService;
use App\Support\Api\ApiRecordPresenter;
use App\Support\Api\ApiResourceRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SystemController extends ApiController
{
    public function __construct(
        private readonly RevisionService $revisions,
        private readonly ApiMutationService $mutations,
        private readonly NotionDataverseSyncService $notionSync,
        private readonly ApiRecordPresenter $presenter,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $this->authorizeToken($request, 'read', '*');

        $term = trim((string) $request->query('search', ''));

        if ($term === '') {
            return response()->json([
                'data' => [],
                'included' => [],
                'meta' => $this->responseMeta($request, [
                    'includes' => $this->requestedIncludes($request),
                    'filters' => $request->query('filter', []),
                ]),
            ]);
        }

        $resourceFilter = $request->query('filter.resource') ?? $request->query('filter.resource_type');
        $resources = $resourceFilter && in_array($resourceFilter, ApiResourceRegistry::slugs(), true)
            ? [$resourceFilter]
            : array_values(array_filter(
                ApiResourceRegistry::slugs(),
                fn (string $resource) => $resource !== 'notion-notes',
            ));

        $results = [];
        $included = [];

        foreach ($resources as $resource) {
            $query = ApiResourceRegistry::query($resource);
            $matchedNoteIds = $this->matchingNotionNoteIds($resource, $term);
            $applied = false;

            $query->where(function (Builder $inner) use ($resource, $term, $matchedNoteIds, &$applied) {
                $applied = $this->applySearch($inner, $term, $resource);

                if ($matchedNoteIds !== []) {
                    $method = $applied ? 'orWhereIn' : 'whereIn';
                    $inner->{$method}($inner->getModel()->qualifyColumn($inner->getModel()->getKeyName()), $matchedNoteIds);
                    $applied = true;
                }
            });

            if (! $applied) {
                continue;
            }

            /** @var EloquentCollection<int, Model> $matches */
            $matches = $query->limit(5)->get();

            foreach ($matches as $match) {
                $payload = $this->presenter->present($resource, $match);
                $payload['meta']['match_context'] = $this->matchContext($resource, $match->toArray(), $term, $match->getKey());
                $results[] = $payload;

                foreach ($this->presenter->included($resource, $match, $this->requestedIncludes($request)) as $relation => $value) {
                    $included[$relation] ??= [];
                    $included[$relation][(string) $match->getKey()] = $value;
                }
            }
        }

        return response()->json([
            'data' => $results,
            'included' => $included,
            'meta' => $this->responseMeta($request, [
                'term' => $term,
                'includes' => $this->requestedIncludes($request),
                'filters' => $request->query('filter', []),
            ]),
        ]);
    }

    public function trash(Request $request): JsonResponse
    {
        $this->authorizeToken($request, 'read', '*');

        $items = [];
        $included = [];

        foreach (ApiResourceRegistry::slugs() as $resource) {
            $modelClass = ApiResourceRegistry::modelClass($resource);

            if (! ApiResourceRegistry::supportsSoftDeletes($modelClass)) {
                continue;
            }

            $query = ApiResourceRegistry::query($resource)->onlyTrashed();

            /** @var EloquentCollection<int, Model> $records */
            $records = $query->limit(50)->get();

            foreach ($records as $record) {
                $items[] = $this->presenter->present($resource, $record);

                foreach ($this->presenter->included($resource, $record, $this->requestedIncludes($request)) as $relation => $value) {
                    $included[$relation] ??= [];
                    $included[$relation][(string) $record->getKey()] = $value;
                }
            }
        }

        usort($items, fn (array $left, array $right) => strcmp(
            $right['attributes']['deleted_at'] ?? '',
            $left['attributes']['deleted_at'] ?? '',
        ));

        return response()->json([
            'data' => $items,
            'included' => $included,
            'meta' => $this->responseMeta($request, [
                'count' => count($items),
                'includes' => $this->requestedIncludes($request),
            ]),
        ]);
    }

    public function restoreTrash(Request $request, string $resource, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'restore', $resource);

        $model = ApiResourceRegistry::resolveRecord($resource, $record, true);
        $this->assertRevision($request, $resource, $model);

        $before = $model->attributesToArray();
        $restored = $this->mutations->restore($model);
        $this->revisions->record($resource, $restored, 'restore', $before, $restored->attributesToArray(), $request);

        return $this->jsonRecord($resource, $restored, $request);
    }

    public function syncNotion(Request $request, string $resource): JsonResponse
    {
        $this->authorizeToken($request, 'sync:notion');
        abort_unless(
            in_array($resource, NotionDataverseSyncService::supportedResources(), true),
            404,
            "Unsupported Notion sync resource [{$resource}].",
        );

        $stats = $this->notionSync->sync(
            $resource,
            (bool) $request->boolean('include_drafts'),
            (bool) $request->boolean('dry_run'),
        );

        return response()->json([
            'data' => [
                'resource' => $resource,
                'stats' => $stats,
            ],
            'included' => [],
            'meta' => $this->responseMeta($request),
        ]);
    }

    public function showRevision(Request $request, Revision $revision): JsonResponse
    {
        $this->authorizeToken($request, 'history', $revision->resource_type);

        return response()->json([
            'data' => $revision->toArray(),
            'included' => [],
            'meta' => $this->responseMeta($request),
        ]);
    }

    public function restoreRevision(Request $request, Revision $revision): JsonResponse
    {
        $this->authorizeToken($request, 'restore', $revision->resource_type);

        $record = ApiResourceRegistry::resolveRecord($revision->resource_type, $revision->resource_id, true);
        $this->assertRevision($request, $revision->resource_type, $record);

        $before = $record->attributesToArray();
        $restored = $this->revisions->restoreModel($record, $revision);
        $this->revisions->record(
            $revision->resource_type,
            $restored,
            'restore_revision',
            $before,
            $restored->attributesToArray(),
            $request,
            $revision->id,
        );

        return $this->jsonRecord($revision->resource_type, $restored, $request);
    }

    public function compareRevisions(Request $request): JsonResponse
    {
        $left = Revision::query()->findOrFail($request->integer('left'));
        $right = Revision::query()->findOrFail($request->integer('right'));

        $this->authorizeToken($request, 'history', $left->resource_type);
        $this->authorizeToken($request, 'history', $right->resource_type);

        return response()->json([
            'data' => [
                'left' => $left->toArray(),
                'right' => $right->toArray(),
                'comparison' => [
                    'before' => $left->after_payload,
                    'after' => $right->after_payload,
                    'diff' => $right->diff_payload,
                ],
            ],
            'included' => [],
            'meta' => $this->responseMeta($request),
        ]);
    }

    private function applySearch(Builder $query, string $term, string $resource): bool
    {
        if (method_exists($query->getModel(), 'scopeSearch')) {
            $query->search($term);
            return true;
        }

        $fields = ApiResourceRegistry::searchableFields($resource);

        if ($fields === []) {
            return false;
        }

        $query->where(function (Builder $inner) use ($fields, $term) {
            foreach ($fields as $index => $field) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $inner->{$method}($field, 'like', "%{$term}%");
            }
        });

        return true;
    }

    private function matchContext(string $resource, array $record, string $term, int|string $recordId): string
    {
        foreach (ApiResourceRegistry::searchableFields($resource) as $field) {
            $value = data_get($record, $field);

            if (is_string($value) && stripos($value, $term) !== false) {
                return Str::limit($value, 180);
            }
        }

        $notionResource = ApiResourceRegistry::definition($resource)['notion_resource'] ?? null;

        if ($notionResource) {
            $note = NotionNote::query()
                ->where('sync_resource', $notionResource)
                ->where('noteable_type', ApiResourceRegistry::modelClass($resource))
                ->where('noteable_id', $recordId)
                ->whereRaw("content ILIKE ?", ["%{$term}%"])
                ->first();

            if ($note) {
                return Str::limit(preg_replace('/\s+/', ' ', strip_tags($note->content)) ?? '', 180);
            }
        }

        return '';
    }

    private function matchingNotionNoteIds(string $resource, string $term): array
    {
        $notionResource = ApiResourceRegistry::definition($resource)['notion_resource'] ?? null;

        if (! $notionResource) {
            return [];
        }

        return NotionNote::query()
            ->where('sync_resource', $notionResource)
            ->where('noteable_type', ApiResourceRegistry::modelClass($resource))
            ->whereRaw('content ILIKE ?', ["%{$term}%"])
            ->pluck('noteable_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
