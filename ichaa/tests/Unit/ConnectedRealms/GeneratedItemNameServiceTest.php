<?php

namespace Tests\Unit\ConnectedRealms;

use App\Domain\ConnectedRealms\Services\AchievementTitleCatalog;
use App\Domain\ConnectedRealms\Services\CraftingService;
use App\Domain\ConnectedRealms\Services\EvergatherTierCatalog;
use App\Domain\ConnectedRealms\Services\ExpeditionService;
use App\Domain\ConnectedRealms\Services\GatheringActionService;
use App\Domain\ConnectedRealms\Services\GeneratedItemNameService;
use App\Domain\ConnectedRealms\Services\JobContractService;
use App\Domain\ConnectedRealms\Services\ShopService;
use App\Domain\ConnectedRealms\Services\SkillActivityService;
use App\Domain\ConnectedRealms\Services\SkillCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class GeneratedItemNameServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_builtin_generated_item_names_are_deterministic_curated_and_not_double_tiered(): void
    {
        $namesByProgression = [];

        foreach (['fishing', 'mining', 'woodcutting', 'foraging', 'hunting', 'farming', 'excavation'] as $skill) {
            foreach ([20, 30, 40, 50] as $level) {
                $name = GeneratedItemNameService::midgameGatheringResourceName($skill, $level);

                $this->assertSame($name, GeneratedItemNameService::midgameGatheringResourceName($skill, $level));
                $this->assertNotFallback($name);
                $this->assertDoesNotContainMultipleTierMarks($name);
                $namesByProgression["gathering:{$skill}"][] = $name;
            }
        }

        $endgameResources = [
            'fishing' => ['fish', 'scale', 'pearl'],
            'mining' => ['ore', 'geode', 'coal'],
            'woodcutting' => ['log', 'resin', 'branch'],
            'foraging' => ['bloom', 'root', 'spore'],
            'hunting' => ['hide', 'claw', 'meat'],
            'farming' => ['grain', 'seed', 'fruit'],
            'excavation' => ['relic', 'rune', 'tablet'],
        ];

        foreach ($endgameResources as $skill => $resources) {
            foreach ([65 => 'Elderwake', 80 => 'Mythgate', 100 => 'Crownmark'] as $level => $mark) {
                foreach ($resources as $resource) {
                    $name = GeneratedItemNameService::endgameGatheringResourceName($skill, $resource, $mark);

                    $this->assertSame($name, GeneratedItemNameService::endgameGatheringResourceName($skill, $resource, $mark));
                    $this->assertNotFallback($name);
                    $this->assertDoesNotContainMultipleTierMarks($name);
                    $namesByProgression["gathering:{$skill}:{$resource}"][] = $name;
                }
            }
        }

        foreach ([
            'smelting', 'milling', 'tanning', 'cutting', 'weaving', 'smithing', 'carpentry', 'cooking',
            'alchemy', 'tailoring', 'leatherworking', 'engineering', 'enchanting', 'jewelcrafting',
            'boatbuilding', 'furniture', 'construction', 'cartography', 'trading',
        ] as $skill) {
            foreach ([20, 30, 40, 50] as $level) {
                $name = GeneratedItemNameService::midgameCraftOutputName($skill, $level);

                $this->assertSame($name, GeneratedItemNameService::midgameCraftOutputName($skill, $level));
                $this->assertNotFallback($name);
                $this->assertDoesNotContainMultipleTierMarks($name);
                $namesByProgression["craft:{$skill}"][] = $name;
            }

            foreach ([65, 80, 100] as $level) {
                $name = GeneratedItemNameService::endgameCraftOutputName($skill, $level);

                $this->assertSame($name, GeneratedItemNameService::endgameCraftOutputName($skill, $level));
                $this->assertNotFallback($name);
                $this->assertDoesNotContainMultipleTierMarks($name);
                $namesByProgression["craft:{$skill}"][] = $name;
            }
        }

        foreach ($namesByProgression as $progression => $names) {
            $normalized = collect($names)->map(fn (string $name): string => $this->withoutTierMarks($name));

            $this->assertSame(
                $normalized->all(),
                $normalized->uniqueStrict()->values()->all(),
                "{$progression} has repeated names after tier metadata is removed.",
            );
        }
    }

    public function test_achievement_title_catalog_covers_builtin_milestones_without_old_formula_terms(): void
    {
        $reflection = new ReflectionClass(AchievementTitleCatalog::class);
        $skillTitles = $reflection->getReflectionConstant('SKILL_TITLES')->getValue();
        $baseTitles = $reflection->getReflectionConstant('BASE_TITLES')->getValue();
        $accountTitles = $reflection->getReflectionConstant('ACCOUNT_TITLES')->getValue();
        $allTitles = collect($baseTitles)
            ->merge($accountTitles)
            ->merge(collect($skillTitles)->flatten(1))
            ->values();

        foreach (SkillCatalogService::keys() as $skill) {
            foreach (EvergatherTierCatalog::levels() as $level) {
                $this->assertArrayHasKey($skill, $skillTitles);
                $this->assertArrayHasKey($level, $skillTitles[$skill]);
            }
        }

        $this->assertSame($allTitles->all(), $allTitles->uniqueStrict()->values()->all());
        $this->assertFalse($allTitles->contains('Unlockable Oddity'));

        $blockedFragments = ['Kilroy', 'Inspector Gadget', 'Captain Planet', 'Project Runway', 'Gandalf', 'Tom Nook', 'Scrooge', 'Neo', 'The Witcher', 'Final Boss', 'Orepost', 'Potionposting', 'Facetflex', 'Trackstack'];

        foreach ($blockedFragments as $fragment) {
            $this->assertFalse(
                $allTitles->contains(fn (string $title): bool => str_contains($title, $fragment)),
                "Unexpected old title fragment [{$fragment}] found.",
            );
        }
    }

    public function test_stable_item_keys_resolve_to_one_display_name_across_builtin_consumers(): void
    {
        $itemsByKey = [];

        foreach ($this->privateStaticCatalog(GatheringActionService::class, 'actionDefinitions') as $key => $action) {
            $this->recordItems($itemsByKey, $action['loot'], "action:{$key}");
        }

        foreach ($this->privateStaticCatalog(SkillActivityService::class, 'activities') as $key => $activity) {
            $this->recordItems($itemsByKey, $activity['loot'], "activity:{$key}");
        }

        foreach ($this->privateStaticCatalog(CraftingService::class, 'recipes') as $key => $recipe) {
            $this->recordItems($itemsByKey, $recipe['ingredients'], "recipe ingredients:{$key}");
            $this->recordItems($itemsByKey, $recipe['outputs'], "recipe outputs:{$key}");
        }

        foreach ($this->privateStaticCatalog(JobContractService::class, 'jobs') as $key => $job) {
            $this->recordItems($itemsByKey, $job['requirements'], "job:{$key}");
        }

        foreach ($this->privateStaticCatalog(ExpeditionService::class, 'expeditions') as $key => $expedition) {
            $this->recordItems($itemsByKey, $expedition['supplies'], "expedition supplies:{$key}");
            $this->recordItems($itemsByKey, $expedition['rewards'], "expedition rewards:{$key}");
        }

        foreach ($this->privateStaticCatalog(ShopService::class, 'offers') as $key => $offer) {
            $this->recordItems($itemsByKey, [$offer], "shop:{$key}");
        }

        $conflicts = collect($itemsByKey)
            ->filter(fn (array $item): bool => count($item['names']) > 1)
            ->map(fn (array $item, string $key): string => "{$key}: ".implode(', ', array_keys($item['names'])))
            ->values()
            ->all();

        $this->assertSame([], $conflicts);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function privateStaticCatalog(string $class, string $method): array
    {
        $reflection = new ReflectionMethod($class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null);
    }

    /**
     * @param  array<string, array{names: array<string, true>, sources: list<string>}>  $itemsByKey
     * @param  list<array<string, mixed>>  $items
     */
    private function recordItems(array &$itemsByKey, array $items, string $source): void
    {
        foreach ($items as $item) {
            $key = (string) ($item['item_key'] ?? $item['key'] ?? '');
            $name = (string) ($item['item_name'] ?? $item['name'] ?? '');

            if ($key === '' || $name === '') {
                continue;
            }

            $itemsByKey[$key] ??= ['names' => [], 'sources' => []];
            $itemsByKey[$key]['names'][$name] = true;
            $itemsByKey[$key]['sources'][] = $source;
        }
    }

    private function assertNotFallback(string $name): void
    {
        $this->assertStringNotContainsString('Custom ', $name);
    }

    private function assertDoesNotContainMultipleTierMarks(string $name): void
    {
        $matches = collect(EvergatherTierCatalog::tiers())
            ->pluck('mark')
            ->filter(fn (string $mark): bool => preg_match('/\b'.preg_quote($mark, '/').'\b/i', $name) === 1)
            ->values();

        $this->assertLessThanOrEqual(1, $matches->count(), "{$name} contains multiple tier marks.");
    }

    private function withoutTierMarks(string $name): string
    {
        $pattern = collect(EvergatherTierCatalog::tiers())
            ->pluck('mark')
            ->map(fn (string $mark): string => preg_quote($mark, '/'))
            ->join('|');

        $name = preg_replace('/\b(?:'.$pattern.')\b/i', '', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', trim($name)) ?? trim($name);

        return str($name)->lower()->toString();
    }
}
