<?php

namespace App\Domain\System\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class NotionPropertyMapper
{
    public function propertyKey(array $page, string $property): ?string
    {
        $properties = $page['properties'] ?? [];

        if (array_key_exists($property, $properties)) {
            return $property;
        }

        $expected = $this->normalizePropertyLabel($property);

        foreach (array_keys($properties) as $key) {
            if ($this->normalizePropertyLabel((string) $key) === $expected) {
                return (string) $key;
            }
        }

        return null;
    }

    public function hasProperty(array $page, string $property): bool
    {
        return $this->propertyKey($page, $property) !== null;
    }

    public function pageId(array $page): string
    {
        return (string) ($page['id'] ?? '');
    }

    public function lastEditedAt(array $page): ?Carbon
    {
        $value = $page['last_edited_time'] ?? null;

        return filled($value) ? Carbon::parse($value) : null;
    }

    public function title(array $page, string $property): ?string
    {
        $property = $this->propertyKey($page, $property);
        $chunks = $property ? data_get($page, "properties.{$property}.title", []) : [];

        return $this->plainTextList($chunks);
    }

    public function richText(array $page, string $property): ?string
    {
        $property = $this->propertyKey($page, $property);
        $chunks = $property ? data_get($page, "properties.{$property}.rich_text", []) : [];

        return $this->plainTextList($chunks);
    }

    public function selectOrRichText(array $page, string $property): ?string
    {
        return $this->select($page, $property)
            ?? $this->richText($page, $property);
    }

    public function checkbox(array $page, string $property): bool
    {
        $property = $this->propertyKey($page, $property);

        return $property ? (bool) data_get($page, "properties.{$property}.checkbox", false) : false;
    }

    public function select(array $page, string $property): ?string
    {
        $property = $this->propertyKey($page, $property);

        return $property ? data_get($page, "properties.{$property}.select.name") : null;
    }

    public function relationIds(array $page, string $property): array
    {
        $property = $this->propertyKey($page, $property);

        return collect($property ? data_get($page, "properties.{$property}.relation", []) : [])
            ->pluck('id')
            ->filter()
            ->values()
            ->all();
    }

    public function multiSelect(array $page, string $property): array
    {
        $property = $this->propertyKey($page, $property);

        return collect($property ? data_get($page, "properties.{$property}.multi_select", []) : [])
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
    }

    public function normalizeKey(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return Str::of($value)
            ->replace(['&', '/'], ' ')
            ->replace('-', '_')
            ->lower()
            ->snake()
            ->trim('_')
            ->value();
    }

    public function parseUniverseOrigin(?string $value): array
    {
        if (blank($value)) {
            return [[], null];
        }

        $parts = array_map('trim', explode('|', $value, 2));
        $universes = collect(preg_split('/[,;]+/', $parts[0] ?? '', -1, PREG_SPLIT_NO_EMPTY))
            ->map(static fn (string $item) => trim($item))
            ->filter()
            ->values()
            ->all();

        $origin = isset($parts[1]) ? $this->normalizeKey($parts[1]) : null;

        if (! in_array($origin, ['native', 'canonical', 'alternate', 'original', 'hybrid'], true)) {
            $origin = null;
        }

        return [$universes, $origin];
    }

    private function plainTextList(array $chunks): ?string
    {
        $text = collect($chunks)
            ->map(static fn (array $chunk) => $chunk['plain_text'] ?? '')
            ->implode('');

        return filled($text) ? trim($text) : null;
    }

    private function normalizePropertyLabel(string $value): string
    {
        return Str::of($value)
            ->trim()
            ->replaceMatches('/\s*\(required\)\s*$/i', '')
            ->trim()
            ->lower()
            ->value();
    }
}
