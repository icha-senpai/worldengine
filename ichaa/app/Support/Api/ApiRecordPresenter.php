<?php

namespace App\Support\Api;

use App\Domain\System\Services\RevisionService;
use Illuminate\Database\Eloquent\Model;

class ApiRecordPresenter
{
    public function __construct(
        private readonly RevisionService $revisionService,
    ) {}

    public function present(string $resource, Model $model): array
    {
        return [
            'type' => $resource,
            'id' => (int) $model->getKey(),
            'attributes' => $model->attributesToArray(),
            'meta' => [
                'current_revision_id' => $this->revisionService->currentRevisionId($resource, $model->getKey()),
            ],
        ];
    }

    public function included(string $resource, Model $model, array $requestedIncludes = []): array
    {
        $definition = ApiResourceRegistry::definition($resource);
        $allowedIncludes = $definition['includes'] ?? [];
        $requestedIncludes = array_values(array_intersect($requestedIncludes, $allowedIncludes));
        $included = [];

        foreach ($requestedIncludes as $include) {
            if (method_exists($model, $include)) {
                $model->loadMissing($include);
                $included[$include] = $model->getRelation($include)?->toArray();
            }
        }

        return $included;
    }
}
