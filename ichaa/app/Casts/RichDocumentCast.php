<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use JsonException;

class RichDocumentCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $this->normalize($value);
        }

        if (! is_string($value)) {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            if (is_array($decoded)) {
                return $this->normalize($decoded);
            }
        } catch (JsonException) {
            // Fall back to plain text conversion.
        }

        return $this->fromPlainText($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $normalized = $this->normalize($value);

        return $normalized === null
            ? null
            : json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function normalize(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return null;
            }

            try {
                $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($decoded) && $this->isRichDocument($decoded)) {
                    return $decoded;
                }
            } catch (JsonException) {
                // Fall back to plain text conversion.
            }

            return $this->fromPlainText($value);
        }

        if (! is_array($value)) {
            return null;
        }

        if ($this->isRichDocument($value)) {
            return $value;
        }

        return $this->fromPlainText(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function isRichDocument(array $value): bool
    {
        return ($value['type'] ?? null) === 'doc' && array_key_exists('content', $value);
    }

    private function fromPlainText(?string $value): ?array
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        $content = collect(preg_split('/\R{2,}/u', $text) ?: [])
            ->map(static fn (string $paragraph) => trim($paragraph))
            ->filter()
            ->map(static fn (string $paragraph) => [
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => $paragraph,
                ]],
            ])
            ->values()
            ->all();

        return [
            'type' => 'doc',
            'content' => $content,
        ];
    }
}
