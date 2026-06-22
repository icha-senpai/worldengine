<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ApiCollectionResource extends ResourceCollection
{
    public function __construct(
        mixed $resource,
        private readonly string $type,
        private readonly array $includes = [],
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return $this->collection
            ->map(fn ($record) => (new ApiRecordResource($record, $this->type, $this->includes))->resolve($request))
            ->values()
            ->all();
    }
}
