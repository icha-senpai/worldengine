<?php

namespace App\Support\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class ApiAuthorizer
{
    public static function ensure(Request $request, string $ability, ?string $resource = null): void
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token) {
            throw new AuthorizationException('API token required.');
        }

        $candidates = ['*'];

        if (str_contains($ability, ':')) {
            [$prefix] = explode(':', $ability, 2);
            $candidates[] = $ability;
            $candidates[] = "{$prefix}:*";
        } else {
            $candidates[] = "{$ability}:*";

            if ($resource) {
                $candidates[] = "{$ability}:{$resource}";
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            if ($request->user()->tokenCan($candidate)) {
                return;
            }
        }

        throw new AuthorizationException('Forbidden.');
    }
}
