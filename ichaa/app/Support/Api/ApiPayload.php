<?php

namespace App\Support\Api;

use Illuminate\Http\Request;

class ApiPayload
{
    public static function fromRequest(Request $request): array
    {
        $attributes = data_get($request->input('data', []), 'attributes', []);
        $relationships = data_get($request->input('data', []), 'relationships', []);

        if (! is_array($attributes)) {
            $attributes = [];
        }

        if (! is_array($relationships)) {
            $relationships = [];
        }

        return array_merge($attributes, $relationships);
    }
}
