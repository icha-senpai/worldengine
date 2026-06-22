<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\System\Services\RevisionService;
use App\Exceptions\StaleRevisionException;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiRecordPresenter;
use App\Support\Api\ApiAuthorizer;
use App\Support\Validation\DataverseRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

abstract class ApiController extends Controller
{
    protected function responseMeta(Request $request, array $meta = []): array
    {
        return array_merge([
            'api_version' => 'v1',
            'request_id' => $request->headers->get('X-Request-Id'),
        ], $meta);
    }

    protected function authorizeToken(Request $request, string $ability, ?string $resource = null): void
    {
        ApiAuthorizer::ensure($request, $ability, $resource);
    }

    protected function assertRevision(Request $request, string $resource, Model $record): void
    {
        $revisionService = app(RevisionService::class);
        $expected = $revisionService->currentRevisionId($resource, $record->getKey());
        $provided = $revisionService->baseRevisionIdFrom($request);

        if ($expected !== $provided) {
            throw new StaleRevisionException($expected, $provided);
        }
    }

    protected function jsonRecord(
        string $resource,
        Model $record,
        Request $request,
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        $includes = $this->requestedIncludes($request);
        $payload = $this->presentRecord($resource, $record, $request);

        return response()->json([
            'data' => $payload['data'],
            'included' => $payload['included'],
            'meta' => $this->responseMeta($request, array_merge([
                'current_revision_id' => data_get($payload, 'data.meta.current_revision_id'),
                'includes' => $includes,
            ], $meta)),
        ], $status);
    }

    protected function jsonCollection(
        string $resource,
        iterable $records,
        Request $request,
        array $meta = [],
    ): JsonResponse {
        $includes = $this->requestedIncludes($request);
        $data = [];
        $included = [];

        foreach ($records as $record) {
            $payload = $this->presentRecord($resource, $record, $request);
            $data[] = $payload['data'];

            foreach ($payload['included'] as $relation => $value) {
                $included[$relation] ??= [];
                $included[$relation][(string) $record->getKey()] = $value;
            }
        }

        return response()->json([
            'data' => $data,
            'included' => $included,
            'meta' => $this->responseMeta($request, array_merge([
                'includes' => $includes,
            ], $meta)),
        ]);
    }

    protected function requestedIncludes(Request $request): array
    {
        return collect(explode(',', (string) $request->query('include', '')))
            ->map(fn (string $include) => trim($include))
            ->filter()
            ->values()
            ->all();
    }

    protected function presentRecord(string $resource, Model $record, Request $request): array
    {
        /** @var ApiRecordPresenter $presenter */
        $presenter = app(ApiRecordPresenter::class);

        return [
            'data' => $presenter->present($resource, $record),
            'included' => $presenter->included($resource, $record, $this->requestedIncludes($request)),
        ];
    }

    protected function validateAction(Request $request, string $action, bool $requireBaseRevision = true): array
    {
        return Validator::make(
            $request->all(),
            array_merge(
                DataverseRules::apiAction($action),
                DataverseRules::metaRules($requireBaseRevision),
            ),
        )->validate();
    }

    protected function shouldValidateOnly(Request $request): bool
    {
        return (bool) data_get($request->input('meta', []), 'validate_only', false);
    }

    protected function validateOnlyResponse(
        Request $request,
        string $resource,
        ?Model $record = null,
    ): ?JsonResponse {
        if (! $this->shouldValidateOnly($request)) {
            return null;
        }

        return response()->json([
            'data' => [
                'type' => $resource,
                'id' => $record ? (int) $record->getKey() : null,
                'attributes' => data_get($request->input('data', []), 'attributes', []),
                'relationships' => data_get($request->input('data', []), 'relationships', []),
            ],
            'included' => [],
            'meta' => $this->responseMeta($request, [
                'validated' => true,
                'validate_only' => true,
                'current_revision_id' => $record
                    ? app(RevisionService::class)->currentRevisionId($resource, $record->getKey())
                    : 0,
            ]),
        ]);
    }
}
