<?php

namespace App\Domain\System\Services;

use App\Domain\System\Models\Revision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class RevisionService
{
    public function currentRevisionId(string $resourceType, int|string $resourceId): int
    {
        return (int) (Revision::query()
            ->forResource($resourceType, $resourceId)
            ->max('id') ?? 0);
    }

    public function record(
        string $resourceType,
        Model $model,
        string $action,
        ?array $beforePayload,
        ?array $afterPayload,
        Request $request,
        ?int $restoredFromRevisionId = null,
    ): Revision {
        $token = $request->user()?->currentAccessToken();

        return Revision::query()->create([
            'resource_type' => $resourceType,
            'resource_id' => (string) $model->getKey(),
            'action' => $action,
            'before_payload' => $beforePayload,
            'after_payload' => $afterPayload,
            'diff_payload' => $this->diffPayload($beforePayload, $afterPayload),
            'reason' => (string) data_get($request->input('meta', []), 'reason', ''),
            'source' => (string) data_get($request->input('meta', []), 'source', 'mcp'),
            'actor_user_id' => $request->user()?->getKey(),
            'token_name' => $token?->name,
            'base_revision_id' => $this->baseRevisionIdFrom($request),
            'restored_from_revision_id' => $restoredFromRevisionId,
        ]);
    }

    public function restoreModel(Model $model, Revision $revision): Model
    {
        $payload = $revision->after_payload ?? [];
        $fillable = $model->getFillable();
        $restorable = Arr::only($payload, $fillable);

        return DB::transaction(function () use ($model, $restorable) {
            if (method_exists($model, 'restore') && $model->trashed()) {
                $model->restore();
            }

            $model->fill($restorable);
            $model->save();

            return $model->fresh();
        });
    }

    public function baseRevisionIdFrom(Request $request): int
    {
        return (int) data_get($request->input('meta', []), 'base_revision_id', 0);
    }

    private function diffPayload(?array $beforePayload, ?array $afterPayload): array
    {
        $beforePayload ??= [];
        $afterPayload ??= [];

        $keys = array_values(array_unique(array_merge(array_keys($beforePayload), array_keys($afterPayload))));
        $diff = [];

        foreach ($keys as $key) {
            $before = $beforePayload[$key] ?? null;
            $after = $afterPayload[$key] ?? null;

            if ($before !== $after) {
                $diff[$key] = [
                    'before' => $before,
                    'after' => $after,
                ];
            }
        }

        return $diff;
    }
}
