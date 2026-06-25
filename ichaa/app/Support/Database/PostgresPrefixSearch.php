<?php

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Builder;

class PostgresPrefixSearch
{
    public static function apply(Builder $query, string $term, string $vector = 'search_vector'): Builder
    {
        $tsQuery = self::prefixTsQuery($term);

        if ($tsQuery === null) {
            return $query;
        }

        return $query->whereRaw(
            "{$vector} @@ to_tsquery('english', ?)",
            [$tsQuery]
        )->orderByRaw(
            "ts_rank({$vector}, to_tsquery('english', ?)) DESC",
            [$tsQuery]
        );
    }

    public static function prefixTsQuery(string $term): ?string
    {
        $normalized = preg_replace('/[^\pL\pN\s]+/u', ' ', mb_strtolower($term));
        $tokens = preg_split('/\s+/', trim($normalized ?? '')) ?: [];
        $tokens = array_values(array_unique(array_filter($tokens, fn (string $token) => $token !== '')));

        if ($tokens === []) {
            return null;
        }

        return implode(' & ', array_map(
            fn (string $token) => "{$token}:*",
            $tokens
        ));
    }
}
