<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsContentEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

class ConnectedRealmsContentService
{
    /**
     * @var array<string, Collection<int, ConnectedRealmsContentEntry>>
     */
    private static array $storedEntryCache = [];

    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private static array $effectiveDefinitionCache = [];

    /**
     * @return array<string, string>
     */
    public static function surfaces(): array
    {
        return [
            'tiers' => 'Tiers',
            'skill_definitions' => 'Skills',
            'skill_activities' => 'Skill Activities',
            'gathering_actions' => 'Gathering Actions',
            'crafting_recipes' => 'Crafting Recipes',
            'job_contracts' => 'Job Contracts',
            'expeditions' => 'Expeditions',
            'shop_offers' => 'Shop Offers',
            'world_events' => 'World Events',
            'tool_families' => 'Tool Families',
            'tool_tiers' => 'Tool Tiers',
            'item_rules' => 'Item Rules',
            'achievements' => 'Achievements',
        ];
    }

    public function surfaceKey(string $surface): string
    {
        return array_key_exists($surface, self::surfaces()) ? $surface : 'tiers';
    }

    public static function forgetSurface(string $surface): void
    {
        unset(self::$storedEntryCache[$surface]);
        unset(self::$effectiveDefinitionCache[$surface]);

        if ($surface === 'tiers') {
            EvergatherTierCatalog::forgetCache();
            self::$effectiveDefinitionCache = [];
        }

        if ($surface === 'skill_definitions') {
            SkillCatalogService::forgetCache();
        }
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function surfaceOptions(): array
    {
        return collect(self::surfaces())
            ->map(fn (string $label, string $key): array => [
                'key' => $key,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $fallback
     * @return array<string, array<string, mixed>>
     */
    public function apply(string $surface, array $fallback): array
    {
        if ($this->cacheEffectiveDefinitions($surface) && array_key_exists($surface, self::$effectiveDefinitionCache)) {
            return self::$effectiveDefinitionCache[$surface];
        }

        $entries = $this->storedEntries($surface);

        if ($entries->isEmpty()) {
            if ($this->cacheEffectiveDefinitions($surface)) {
                self::$effectiveDefinitionCache[$surface] = $fallback;

                return self::$effectiveDefinitionCache[$surface];
            }

            return $fallback;
        }

        $definitions = $fallback;

        foreach ($entries as $entry) {
            if (! $entry->enabled) {
                unset($definitions[$entry->entry_key]);

                continue;
            }

            $definitions[$entry->entry_key] = $this->normalizePayloadForSurface(
                $surface,
                $this->payloadFor($entry, $definitions[$entry->entry_key] ?? []),
            );
        }

        uasort($definitions, function (array $first, array $second): int {
            return [
                (int) ($first['sort_order'] ?? 0),
                (int) ($first['required_level'] ?? $first['level'] ?? 1),
                (string) ($first['label'] ?? $first['mark'] ?? ''),
            ] <=> [
                (int) ($second['sort_order'] ?? 0),
                (int) ($second['required_level'] ?? $second['level'] ?? 1),
                (string) ($second['label'] ?? $second['mark'] ?? ''),
            ];
        });

        if ($this->cacheEffectiveDefinitions($surface)) {
            self::$effectiveDefinitionCache[$surface] = $definitions;

            return self::$effectiveDefinitionCache[$surface];
        }

        return $definitions;
    }

    /**
     * @param  list<array<string, mixed>>  $fallback
     * @return list<array<string, mixed>>
     */
    public function applyList(string $surface, array $fallback, string $keyField = 'key_slug'): array
    {
        $keyed = collect($fallback)
            ->mapWithKeys(fn (array $entry): array => [
                (string) ($entry[$keyField] ?? $entry['key'] ?? str($entry['label'] ?? $entry['mark'] ?? 'entry')->slug('_')->toString()) => $entry,
            ])
            ->all();

        return array_values($this->apply($surface, $keyed));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function adminEntriesFor(string $surface): array
    {
        $fallback = $this->fallbackFor($surface);
        $stored = $this->storedEntries($surface)->keyBy('entry_key');
        $keys = collect(array_keys($fallback))
            ->merge($stored->keys())
            ->unique()
            ->values();

        return $keys
            ->map(function (string $key) use ($fallback, $stored, $surface): array {
                $storedEntry = $stored->get($key);
                $fallbackPayload = $fallback[$key] ?? [];
                $effectivePayload = $storedEntry instanceof ConnectedRealmsContentEntry
                    ? $this->payloadFor($storedEntry, $fallbackPayload)
                    : $fallbackPayload;

                if ($surface === 'skill_definitions') {
                    $effectivePayload = app(SkillCatalogService::class)->withTierUnlocks($key, $effectivePayload);
                }

                $effectivePayload = $this->normalizePayloadForSurface($surface, $effectivePayload);

                return [
                    'id' => $storedEntry?->id,
                    'surface' => $surface,
                    'entry_key' => $key,
                    'label' => $storedEntry?->label ?? $effectivePayload['label'] ?? $effectivePayload['mark'] ?? str($key)->headline()->toString(),
                    'category' => $storedEntry?->category ?? $effectivePayload['category'] ?? $effectivePayload['band'] ?? $effectivePayload['region'] ?? null,
                    'required_level' => $storedEntry?->required_level ?? $effectivePayload['required_level'] ?? $effectivePayload['level'] ?? null,
                    'rarity' => $storedEntry?->rarity ?? $effectivePayload['rarity'] ?? null,
                    'enabled' => $storedEntry?->enabled ?? true,
                    'sort_order' => $storedEntry?->sort_order ?? 0,
                    'source' => $storedEntry instanceof ConnectedRealmsContentEntry ? 'database' : 'code',
                    'payload' => $effectivePayload,
                    'updated_at' => optional($storedEntry?->updated_at)->toIso8601String(),
                ];
            })
            ->sortBy([
                ['sort_order', 'asc'],
                ['required_level', 'asc'],
                ['label', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, ConnectedRealmsContentEntry>
     */
    private function storedEntries(string $surface): Collection
    {
        if (array_key_exists($surface, self::$storedEntryCache)) {
            return self::$storedEntryCache[$surface];
        }

        try {
            self::$storedEntryCache[$surface] = ConnectedRealmsContentEntry::query()
                ->where('surface', $surface)
                ->orderBy('sort_order')
                ->orderBy('required_level')
                ->orderBy('label')
                ->get();

            return self::$storedEntryCache[$surface];
        } catch (QueryException) {
            return collect();
        }
    }

    private function cacheEffectiveDefinitions(string $surface): bool
    {
        return $surface !== 'achievements';
    }

    /**
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function payloadFor(ConnectedRealmsContentEntry $entry, array $fallback = []): array
    {
        return [
            ...$fallback,
            ...($entry->payload ?? []),
            ...array_filter([
                'label' => $entry->label,
                'category' => $entry->category,
                'required_level' => $entry->required_level,
                'rarity' => $entry->rarity,
                'sort_order' => $entry->sort_order,
            ], fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayloadForSurface(string $surface, array $payload): array
    {
        if ($surface === 'tiers') {
            return $payload;
        }

        if ($surface === 'tool_tiers' && (array_key_exists('level', $payload) || array_key_exists('required_level', $payload))) {
            $level = EvergatherTierCatalog::nextTierLevelFor((int) ($payload['level'] ?? $payload['required_level'] ?? 1));

            return [
                ...$payload,
                'level' => $level,
                'required_level' => $level,
                'item_tier' => EvergatherTierCatalog::itemTierForLevel($level),
            ];
        }

        if (array_key_exists('required_level', $payload)) {
            $requiredLevel = EvergatherTierCatalog::nextTierLevelFor((int) $payload['required_level']);

            return [
                ...$payload,
                'required_level' => $requiredLevel,
                'item_tier' => EvergatherTierCatalog::itemTierForLevel($requiredLevel),
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fallbackFor(string $surface): array
    {
        return match ($surface) {
            'tiers' => collect(EvergatherTierCatalog::baseTiers())
                ->mapWithKeys(fn (array $tier): array => [$tier['key_slug'] => $tier])
                ->all(),
            'skill_definitions' => app(SkillCatalogService::class)->baseDefinitions(),
            'skill_activities' => SkillActivityService::baseActivities(),
            'gathering_actions' => GatheringActionService::baseActionDefinitions(),
            'crafting_recipes' => CraftingService::baseRecipes(),
            'job_contracts' => JobContractService::baseJobs(),
            'expeditions' => ExpeditionService::baseExpeditions(),
            'shop_offers' => ShopService::baseOffers(),
            'world_events' => WorldEventService::baseEvents(),
            'tool_families' => app(ToolCatalogService::class)->baseFamilies(),
            'tool_tiers' => collect(app(ToolCatalogService::class)->baseTierPath())
                ->mapWithKeys(fn (array $tier): array => [str($tier['name_mark'])->slug('_')->toString() => $tier])
                ->all(),
            'item_rules' => app(ItemCatalogService::class)->baseKeyRules(),
            default => [],
        };
    }
}
