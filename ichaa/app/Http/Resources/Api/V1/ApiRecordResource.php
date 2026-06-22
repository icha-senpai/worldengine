<?php

namespace App\Http\Resources\Api\V1;

use App\Support\Api\ApiRecordPresenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiRecordResource extends JsonResource
{
    public function __construct(
        Model $resource,
        private readonly string $type,
        private readonly array $includes = [],
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return app(ApiRecordPresenter::class)->present($this->type, $this->resource);
    }
}
