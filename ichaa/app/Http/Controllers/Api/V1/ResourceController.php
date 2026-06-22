<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\System\Services\RevisionService;
use App\Http\Requests\Api\V1\ApiIndexRequest;
use App\Http\Requests\Api\V1\ApiWriteRequest;
use App\Support\Api\ApiMutationService;
use App\Support\Api\ApiPayload;
use App\Support\Api\ApiResourceRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceController extends ApiController
{
    public function __construct(
        private readonly ApiMutationService $mutations,
        private readonly RevisionService $revisions,
    ) {}

    public function index(ApiIndexRequest $request, string $resource): JsonResponse
    {
        $this->authorizeToken($request, 'read', $resource);

        $query = ApiResourceRegistry::query($resource);
        $modelClass = ApiResourceRegistry::modelClass($resource);

        if ($request->boolean('with_trashed') && ApiResourceRegistry::supportsSoftDeletes($modelClass)) {
            $query->withTrashed();
        } elseif ($request->boolean('only_trashed') && ApiResourceRegistry::supportsSoftDeletes($modelClass)) {
            $query->onlyTrashed();
        }

        $this->applyFilters($query, $request, $resource);
        $this->applySearch($query, $request, $resource);
        $this->applySort($query, $request, $resource);

        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $records = $query->paginate($perPage)->appends($request->query());

        return $this->jsonCollection($resource, $records->items(), $request, [
            'pagination' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
            'filters' => $request->query('filter', []),
            'search' => $request->query('search'),
            'sort' => $request->query('sort'),
            'with_trashed' => $request->boolean('with_trashed'),
            'only_trashed' => $request->boolean('only_trashed'),
        ]);
    }

    public function show(Request $request, string $resource, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'read', $resource);

        $withTrashed = $request->boolean('with_trashed') || $request->boolean('only_trashed');
        $model = ApiResourceRegistry::resolveRecord($resource, $record, $withTrashed);

        if ($request->boolean('only_trashed')) {
            abort_unless(
                method_exists($model, 'trashed') && $model->trashed(),
                404,
                'Resource not found.',
            );
        }

        return $this->jsonRecord($resource, $model, $request);
    }

    public function store(ApiWriteRequest $request, string $resource): JsonResponse
    {
        $this->authorizeToken($request, 'write', $resource);

        if ($response = $this->validateOnlyResponse($request, $resource)) {
            return $response;
        }

        $record = $this->mutations->create($resource, ApiPayload::fromRequest($request));
        $this->revisions->record($resource, $record, 'create', null, $record->fresh()->attributesToArray(), $request);

        return $this->jsonRecord($resource, $record, $request, 201);
    }

    public function update(ApiWriteRequest $request, string $resource, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', $resource);

        $model = ApiResourceRegistry::resolveRecord($resource, $record, true);

        if ($response = $this->validateOnlyResponse($request, $resource, $model)) {
            return $response;
        }

        $this->assertRevision($request, $resource, $model);

        $before = $model->attributesToArray();
        $updated = $this->mutations->update($resource, $model, ApiPayload::fromRequest($request));
        $this->revisions->record($resource, $updated, 'update', $before, $updated->fresh()->attributesToArray(), $request);

        return $this->jsonRecord($resource, $updated, $request);
    }

    public function destroy(Request $request, string $resource, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'delete', $resource);
        $modelClass = ApiResourceRegistry::modelClass($resource);

        abort_unless(
            ApiResourceRegistry::supportsSoftDeletes($modelClass),
            409,
            "Resource [{$resource}] does not support soft deletes in v1.",
        );

        $model = ApiResourceRegistry::resolveRecord($resource, $record, true);

        if ($response = $this->validateOnlyResponse($request, $resource, $model)) {
            return $response;
        }

        $this->assertRevision($request, $resource, $model);

        $before = $model->attributesToArray();
        $this->mutations->delete($resource, $model);
        $fresh = ApiResourceRegistry::resolveRecord($resource, $record, true);
        $this->revisions->record($resource, $fresh, 'delete', $before, $fresh->attributesToArray(), $request);

        return response()->json([
            'data' => null,
            'included' => [],
            'meta' => $this->responseMeta($request, ['deleted' => true]),
        ]);
    }

    private function applyFilters(Builder $query, Request $request, string $resource): void
    {
        $filters = $request->query('filter', []);

        if (! is_array($filters)) {
            return;
        }

        $modelClass = ApiResourceRegistry::modelClass($resource);
        $casts = (new $modelClass())->getCasts();
        $allowedFilters = ApiResourceRegistry::filterableFields($resource);

        foreach ($filters as $field => $value) {
            if ($value === '' || $value === null || ! in_array($field, $allowedFilters, true)) {
                continue;
            }

            if (($casts[$field] ?? null) === 'array') {
                $query->whereJsonContains($field, $value);
                continue;
            }

            $query->where($field, $value);
        }
    }

    private function applySearch(Builder $query, Request $request, string $resource): void
    {
        $term = trim((string) $request->query('search', ''));

        if ($term === '') {
            return;
        }

        if (method_exists($query->getModel(), 'scopeSearch')) {
            $query->search($term);
            return;
        }

        $fields = ApiResourceRegistry::searchableFields($resource);

        if ($fields === []) {
            return;
        }

        $query->where(function (Builder $inner) use ($fields, $term) {
            foreach ($fields as $index => $field) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $inner->{$method}($field, 'like', "%{$term}%");
            }
        });
    }

    private function applySort(Builder $query, Request $request, string $resource): void
    {
        $sort = trim((string) $request->query('sort', ''));

        if ($sort === '') {
            $query->latest('id');
            return;
        }

        $allowedSorts = ApiResourceRegistry::sortableFields($resource);

        foreach (explode(',', $sort) as $column) {
            $column = trim($column);
            if ($column === '') {
                continue;
            }

            $direction = str_starts_with($column, '-') ? 'desc' : 'asc';
            $field = ltrim($column, '-');

            if (in_array($field, $allowedSorts, true)) {
                $query->orderBy($field, $direction);
            }
        }
    }
}
