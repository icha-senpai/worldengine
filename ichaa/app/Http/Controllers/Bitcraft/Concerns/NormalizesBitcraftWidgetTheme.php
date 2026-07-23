<?php

namespace App\Http\Controllers\Bitcraft\Concerns;

trait NormalizesBitcraftWidgetTheme
{
    /**
     * @return array<string, array<int, string>>
     */
    protected function widgetThemeValidationRules(): array
    {
        return [
            'theme' => ['nullable', 'string', 'in:dataverse,harbor,grove,ember,violet'],
            'accentColor' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'highlightColor' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'panelColor' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'textColor' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'mutedColor' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'borderColor' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'fontScale' => ['nullable', 'integer', 'min:80', 'max:140'],
            'width' => ['nullable', 'integer', 'min:280', 'max:900'],
            'radius' => ['nullable', 'integer', 'min:0', 'max:32'],
            'panelOpacity' => ['nullable', 'integer', 'min:20', 'max:100'],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function widgetThemeInputKeys(): array
    {
        return [
            'theme',
            'accentColor',
            'highlightColor',
            'panelColor',
            'textColor',
            'mutedColor',
            'borderColor',
            'fontScale',
            'width',
            'radius',
            'panelOpacity',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    protected function widgetThemeSettings(array $validated, array $stored): array
    {
        $theme = $this->normalizeWidgetThemeName($validated['theme'] ?? data_get($stored, 'theme', 'dataverse'));
        $preset = $this->widgetThemePreset($theme);
        $colors = $theme === 'dataverse' ? $preset : [
            'accentColor' => $this->normalizeWidgetColor($validated['accentColor'] ?? data_get($stored, 'accentColor'), $preset['accentColor']),
            'highlightColor' => $this->normalizeWidgetColor($validated['highlightColor'] ?? data_get($stored, 'highlightColor'), $preset['highlightColor']),
            'panelColor' => $this->normalizeWidgetColor($validated['panelColor'] ?? data_get($stored, 'panelColor'), $preset['panelColor']),
            'textColor' => $this->normalizeWidgetColor($validated['textColor'] ?? data_get($stored, 'textColor'), $preset['textColor']),
            'mutedColor' => $this->normalizeWidgetColor($validated['mutedColor'] ?? data_get($stored, 'mutedColor'), $preset['mutedColor']),
            'borderColor' => $this->normalizeWidgetColor($validated['borderColor'] ?? data_get($stored, 'borderColor'), $preset['borderColor']),
        ];

        return [
            'theme' => $theme,
            ...$colors,
            'fontScale' => $this->normalizeWidgetNumber($validated['fontScale'] ?? data_get($stored, 'fontScale'), 100, 80, 140),
            'width' => $this->normalizeWidgetNumber($validated['width'] ?? data_get($stored, 'width'), 450, 280, 900),
            'radius' => $this->normalizeWidgetNumber($validated['radius'] ?? data_get($stored, 'radius'), 18, 0, 32),
            'panelOpacity' => $this->normalizeWidgetNumber($validated['panelOpacity'] ?? data_get($stored, 'panelOpacity'), 96, 20, 100),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function widgetThemePreset(string $theme): array
    {
        return [
            'dataverse' => [
                'accentColor' => '#d4a44a',
                'highlightColor' => '#6fb08d',
                'panelColor' => '#110a18',
                'textColor' => '#fff6e6',
                'mutedColor' => '#b99456',
                'borderColor' => '#8c6531',
            ],
            'harbor' => [
                'accentColor' => '#38bdf8',
                'highlightColor' => '#22c55e',
                'panelColor' => '#082f49',
                'textColor' => '#f0f9ff',
                'mutedColor' => '#bae6fd',
                'borderColor' => '#0ea5e9',
            ],
            'grove' => [
                'accentColor' => '#a3e635',
                'highlightColor' => '#2dd4bf',
                'panelColor' => '#1a2e05',
                'textColor' => '#f7fee7',
                'mutedColor' => '#bef264',
                'borderColor' => '#65a30d',
            ],
            'ember' => [
                'accentColor' => '#fb923c',
                'highlightColor' => '#facc15',
                'panelColor' => '#431407',
                'textColor' => '#fff7ed',
                'mutedColor' => '#fdba74',
                'borderColor' => '#f97316',
            ],
            'violet' => [
                'accentColor' => '#c084fc',
                'highlightColor' => '#f0abfc',
                'panelColor' => '#2e1065',
                'textColor' => '#faf5ff',
                'mutedColor' => '#ddd6fe',
                'borderColor' => '#a855f7',
            ],
        ][$theme];
    }

    private function normalizeWidgetThemeName(mixed $theme): string
    {
        $theme = (string) $theme;

        return in_array($theme, ['dataverse', 'harbor', 'grove', 'ember', 'violet'], true)
            ? $theme
            : 'dataverse';
    }

    private function normalizeWidgetColor(mixed $color, string $fallback): string
    {
        $color = trim((string) $color);

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? $color : $fallback;
    }

    private function normalizeWidgetNumber(mixed $value, int $fallback, int $min, int $max): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        return min($max, max($min, (int) $value));
    }
}
