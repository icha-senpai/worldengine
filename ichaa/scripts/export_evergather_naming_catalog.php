<?php

declare(strict_types=1);

use App\Domain\ConnectedRealms\Services\AchievementTitleCatalog;
use App\Domain\ConnectedRealms\Services\ConnectedRealmsLeaderboardService;
use App\Domain\ConnectedRealms\Services\CraftingService;
use App\Domain\ConnectedRealms\Services\EvergatherTierCatalog;
use App\Domain\ConnectedRealms\Services\ExpeditionService;
use App\Domain\ConnectedRealms\Services\GatheringActionService;
use App\Domain\ConnectedRealms\Services\JobContractService;
use App\Domain\ConnectedRealms\Services\ShopService;
use App\Domain\ConnectedRealms\Services\SkillActivityService;
use App\Domain\ConnectedRealms\Services\SkillCatalogService;
use App\Domain\ConnectedRealms\Services\ToolCatalogService;
use App\Domain\ConnectedRealms\Services\ToolEffectService;
use App\Domain\ConnectedRealms\Services\WorldEventService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/**
 * @return array<string, array<string, mixed>>
 */
function privateStaticCatalog(string $class, string $method): array
{
    $reflection = new ReflectionMethod($class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(null);
}

/**
 * @return array<int, array<string, mixed>>
 */
function privateConstantList(string $class, string $constant): array
{
    $reflection = new ReflectionClass($class);

    return $reflection->getReflectionConstant($constant)?->getValue() ?? [];
}

function cell(mixed $value): string
{
    if (is_array($value)) {
        $value = implode(', ', array_map(cell(...), $value));
    }

    return str_replace(["\r", "\n", '|'], [' ', ' ', '\|'], trim((string) $value));
}

/**
 * @param  list<string>  $headers
 * @param  list<list<mixed>>  $rows
 * @return list<string>
 */
function table(array $headers, array $rows): array
{
    $lines = [];
    $lines[] = '| '.implode(' | ', array_map(cell(...), $headers)).' |';
    $lines[] = '| '.implode(' | ', array_fill(0, count($headers), '---')).' |';

    foreach ($rows as $row) {
        $lines[] = '| '.implode(' | ', array_map(cell(...), $row)).' |';
    }

    $lines[] = '';

    return $lines;
}

/**
 * @param  list<array<string, mixed>>  $items
 * @param  array<string, array{names: array<string, true>, roles: array<string, true>, sources: array<string, true>}>  $itemIndex
 */
function recordItems(array &$itemIndex, array $items, string $role, string $source): void
{
    foreach ($items as $item) {
        $key = (string) ($item['item_key'] ?? $item['key'] ?? '');
        $name = (string) ($item['item_name'] ?? $item['name'] ?? '');

        if ($key === '' || $name === '') {
            continue;
        }

        $itemIndex[$key] ??= ['names' => [], 'roles' => [], 'sources' => []];
        $itemIndex[$key]['names'][$name] = true;
        $itemIndex[$key]['roles'][$role] = true;
        $itemIndex[$key]['sources'][$source] = true;
    }
}

/**
 * @param  list<array<string, mixed>>  $items
 */
function itemList(array $items): string
{
    return collect($items)
        ->map(fn (array $item): string => (string) ($item['item_name'] ?? $item['name'] ?? $item['label'] ?? $item['item_key'] ?? $item['key'] ?? ''))
        ->filter()
        ->join('; ');
}

/**
 * @param  array<string, array<string, list<string>>>  $labelIndex
 */
function recordLabel(array &$labelIndex, string $surface, mixed $label, string $source): void
{
    if (! is_string($label) || trim($label) === '') {
        return;
    }

    $labelIndex[$surface][$label] ??= [];
    $labelIndex[$surface][$label][] = $source;
}

function normalizedGeneratedName(string $name): string
{
    $tierWords = tierWords();
    $normalized = preg_replace('/\b(?:'.implode('|', $tierWords).')\b/', '', $name) ?? $name;
    $normalized = preg_replace('/\s+/', ' ', trim($normalized)) ?? trim($normalized);

    return str($normalized)->lower()->toString();
}

/**
 * @return list<string>
 */
function tierWords(): array
{
    return collect(EvergatherTierCatalog::tiers())
        ->map(fn (array $tier): string => $tier['mark'])
        ->merge(['Candleline', 'Highguard', 'Crownline', 'Silverbank', 'Sablecross', 'Starline', 'Astral', 'Prismatic', 'Mythrite', 'Realmwake'])
        ->unique()
        ->values()
        ->all();
}

function normalizedSkeleton(string $name): string
{
    $withoutTiers = normalizedGeneratedName($name);
    $skeleton = preg_replace('/\b[a-z][a-z-]*\b/', '{word}', $withoutTiers) ?? $withoutTiers;

    return preg_replace('/\s+/', ' ', trim($skeleton)) ?? trim($skeleton);
}

/**
 * @param  list<string>  $names
 * @return list<array{string, string, int, string}>
 */
function doubleTierRows(array $names): array
{
    $tierWords = tierWords();

    return collect($names)
        ->map(function (string $name) use ($tierWords): ?array {
            $matches = collect($tierWords)
                ->filter(fn (string $word): bool => preg_match('/\b'.preg_quote($word, '/').'\b/i', $name) === 1)
                ->values();

            if ($matches->count() <= 1) {
                return null;
            }

            return [$name, $matches->join(', '), $matches->count(), normalizedGeneratedName($name)];
        })
        ->filter()
        ->values()
        ->all();
}

$skills = app(SkillCatalogService::class)->all();
$tools = app(ToolCatalogService::class);
$toolFamilies = $tools->families();
$toolTiers = $tools->tierPath();
$actions = privateStaticCatalog(GatheringActionService::class, 'actionDefinitions');
$activities = privateStaticCatalog(SkillActivityService::class, 'activities');
$recipes = privateStaticCatalog(CraftingService::class, 'recipes');
$jobs = privateStaticCatalog(JobContractService::class, 'jobs');
$expeditions = privateStaticCatalog(ExpeditionService::class, 'expeditions');
$shopOffers = privateStaticCatalog(ShopService::class, 'offers');
$worldEvents = collect(app(WorldEventService::class)->calendar())
    ->only(['active', 'upcoming'])
    ->flatMap(fn (array $events): array => $events)
    ->values()
    ->all();
$leaderboards = privateConstantList(ConnectedRealmsLeaderboardService::class, 'BOARD_DEFINITIONS');
$toolGrades = privateConstantList(ToolEffectService::class, 'RARITY_EFFECTS');
$toolTraits = privateConstantList(ToolEffectService::class, 'SKILL_TRAITS');
$baseAchievementTitles = privateConstantList(AchievementTitleCatalog::class, 'BASE_TITLES');
$accountAchievementTitles = privateConstantList(AchievementTitleCatalog::class, 'ACCOUNT_TITLES');
$skillAchievementTitles = privateConstantList(AchievementTitleCatalog::class, 'SKILL_TITLES');
$itemIndex = [];
$labelIndex = [];

foreach ($skills as $skill) {
    recordLabel($labelIndex, 'skills', $skill['label'] ?? null, (string) $skill['key']);

    foreach (($skill['unlocks'] ?? []) as $level => $unlock) {
        recordLabel($labelIndex, 'skill unlocks', $unlock, "{$skill['key']}:{$level}");
    }
}

foreach ($actions as $key => $action) {
    recordLabel($labelIndex, 'gathering actions', $action['label'] ?? null, (string) $key);
}

foreach ($activities as $key => $activity) {
    recordLabel($labelIndex, 'skill activities', $activity['label'] ?? null, (string) $key);
}

foreach ($recipes as $key => $recipe) {
    recordLabel($labelIndex, 'recipes', $recipe['label'] ?? null, (string) $key);
}

foreach ($jobs as $key => $job) {
    recordLabel($labelIndex, 'jobs', $job['label'] ?? null, (string) $key);
}

foreach ($expeditions as $key => $expedition) {
    recordLabel($labelIndex, 'expeditions', $expedition['label'] ?? null, (string) $key);
}

foreach ($shopOffers as $key => $offer) {
    recordLabel($labelIndex, 'shop offers', $offer['label'] ?? null, (string) $key);
}

foreach ($worldEvents as $event) {
    recordLabel($labelIndex, 'world events', $event['label'] ?? null, (string) $event['key']);
}

foreach ($leaderboards as $board) {
    recordLabel($labelIndex, 'leaderboards', $board['label'] ?? null, (string) $board['key']);
}

foreach ($baseAchievementTitles as $key => $title) {
    recordLabel($labelIndex, 'achievement reward titles', $title, (string) $key);
}

foreach ($accountAchievementTitles as $level => $title) {
    recordLabel($labelIndex, 'achievement reward titles', $title, "account_level_{$level}");
}

foreach ($skillAchievementTitles as $skill => $titles) {
    foreach ($titles as $level => $title) {
        recordLabel($labelIndex, 'achievement reward titles', $title, "skill_milestone_{$skill}_{$level}");
    }
}

$duplicateLabelRows = collect($labelIndex)
    ->flatMap(fn (array $labels, string $surface) => collect($labels)
        ->filter(fn (array $sources): bool => count($sources) > 1)
        ->map(fn (array $sources, string $label): array => [$surface, $label, count($sources), implode(', ', $sources)])
        ->values())
    ->values()
    ->all();

$prefixSwapSurfaces = [
    'gathering actions' => collect($actions)->pluck('label')->all(),
    'gathering loot' => collect($actions)
        ->flatMap(fn (array $action): array => collect($action['loot'])->pluck('name')->all())
        ->all(),
    'skill activities' => collect($activities)->pluck('label')->all(),
    'activity loot' => collect($activities)
        ->flatMap(fn (array $activity): array => collect($activity['loot'])->pluck('item_name')->all())
        ->all(),
    'recipe labels' => collect($recipes)->pluck('label')->all(),
    'recipe outputs' => collect($recipes)
        ->flatMap(fn (array $recipe): array => collect($recipe['outputs'])->pluck('item_name')->all())
        ->all(),
    'recipe ingredients' => collect($recipes)
        ->flatMap(fn (array $recipe): array => collect($recipe['ingredients'])->pluck('item_name')->all())
        ->all(),
    'jobs' => collect($jobs)->pluck('label')->all(),
    'job requirements' => collect($jobs)
        ->flatMap(fn (array $job): array => collect($job['requirements'])->pluck('item_name')->all())
        ->all(),
    'expeditions' => collect($expeditions)->pluck('label')->all(),
    'expedition supplies' => collect($expeditions)
        ->flatMap(fn (array $expedition): array => collect($expedition['supplies'])->pluck('item_name')->all())
        ->all(),
    'expedition rewards' => collect($expeditions)
        ->flatMap(fn (array $expedition): array => collect($expedition['rewards'])->pluck('item_name')->all())
        ->all(),
    'shop offers' => collect($shopOffers)->pluck('label')->all(),
    'shop items' => collect($shopOffers)->pluck('item_name')->all(),
    'tool names' => collect($toolFamilies)
        ->flatMap(fn (array $family): array => collect($toolTiers)
            ->map(fn (array $tier): string => $tools->tierToolName($family, $tier))
            ->all())
        ->values()
        ->all(),
    'achievement reward titles' => collect($labelIndex['achievement reward titles'] ?? [])->keys()->all(),
    'skill unlocks' => collect($skills)
        ->flatMap(fn (array $skill): array => collect($skill['unlocks'])
            ->map(fn (string $unlock): string => $unlock)
            ->all())
        ->all(),
];
$prefixSwapRows = collect($prefixSwapSurfaces)
    ->flatMap(fn (array $labels, string $surface) => collect($labels)
        ->filter()
        ->groupBy(fn (string $label): string => normalizedGeneratedName($label))
        ->filter(fn ($group, string $normalized): bool => $normalized !== '' && $group->unique()->count() > 1)
        ->map(fn ($group, string $normalized): array => [
            $surface,
            $normalized,
            $group->unique()->count(),
            $group->unique()->values()->join(', '),
        ])
        ->values())
    ->values()
    ->all();

$allNames = collect($labelIndex)
    ->flatMap(fn (array $labels): array => array_keys($labels))
    ->merge(collect($prefixSwapSurfaces)->flatten())
    ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
    ->unique()
    ->values();
$doubleTierReviewRows = doubleTierRows($allNames->all());
$skeletonRows = $allNames
    ->groupBy(fn (string $name): string => normalizedSkeleton($name))
    ->filter(fn ($group, string $skeleton): bool => $skeleton !== '' && $group->unique()->count() >= 8)
    ->map(fn ($group, string $skeleton): array => [$skeleton, $group->unique()->count(), $group->unique()->take(20)->join(', ')])
    ->values()
    ->all();
$finalNounRows = $allNames
    ->map(fn (string $name): string => str($name)->afterLast(' ')->lower()->toString())
    ->filter()
    ->countBy()
    ->filter(fn (int $count): bool => $count >= 12)
    ->sortDesc()
    ->map(fn (int $count, string $noun): array => [$noun, $count])
    ->values()
    ->all();
$phraseRows = $allNames
    ->flatMap(function (string $name): array {
        $words = preg_split('/\s+/', str($name)->lower()->replaceMatches('/[^a-z0-9 -]/', '')->toString()) ?: [];
        $phrases = [];

        foreach ([2, 3] as $size) {
            for ($index = 0; $index <= count($words) - $size; $index++) {
                $phrase = implode(' ', array_slice($words, $index, $size));

                if (trim($phrase) !== '') {
                    $phrases[] = $phrase;
                }
            }
        }

        return $phrases;
    })
    ->countBy()
    ->filter(fn (int $count): bool => $count >= 10)
    ->sortDesc()
    ->map(fn (int $count, string $phrase): array => [$phrase, $count])
    ->values()
    ->all();
$itemNameConflictRows = collect($itemIndex)
    ->filter(fn (array $item): bool => count($item['names']) > 1)
    ->map(fn (array $item, string $key): array => [$key, implode(', ', array_keys($item['names'])), implode(', ', array_keys($item['sources']))])
    ->values()
    ->all();
$fallbackNameRows = $allNames
    ->filter(fn (string $name): bool => str_contains($name, 'Custom ') || str_contains($name, 'Reward Title'))
    ->map(fn (string $name): array => [$name])
    ->values()
    ->all();

$lines = [
    '# Evergather Naming Catalog',
    '',
    'Generated from the Laravel service catalogs. Use this as the review sheet for skill, job, resource, shop, expedition, activity, tool, event, and leaderboard names.',
    '',
    'Generated at: '.now()->toIso8601String(),
    '',
    '## Counts',
    '',
];

$lines = [
    ...$lines,
    ...table(['Surface', 'Count'], [
        ['Skills', count($skills)],
        ['Tool families', count($toolFamilies)],
        ['Tool tier names', count($toolFamilies) * count($toolTiers)],
        ['Achievement reward titles', count($baseAchievementTitles) + count($accountAchievementTitles) + collect($skillAchievementTitles)->flatten(1)->count()],
        ['Gathering actions', count($actions)],
        ['Skill activities', count($activities)],
        ['Recipes', count($recipes)],
        ['Jobs', count($jobs)],
        ['Expeditions', count($expeditions)],
        ['Shop offers', count($shopOffers)],
        ['World events', count($worldEvents)],
        ['Leaderboards', count($leaderboards)],
    ]),
    '## Skills',
    '',
    ...table(['Key', 'Label', 'Category', 'Type', 'Role', 'Unlocks'], collect($skills)
        ->map(fn (array $skill): array => [
            $skill['key'],
            $skill['label'],
            $skill['category'],
            $skill['type'],
            $skill['role'],
            collect($skill['unlocks'])->map(fn (string $unlock, int $level): string => "{$level}: {$unlock}")->join('; '),
        ])
        ->all()),
    '## Tool Families',
    '',
    ...table(['Skill', 'Family label', 'Starter item', 'Crafted line', 'Tool noun', 'Craft skill', 'Base ingredient'], collect($toolFamilies)
        ->map(fn (array $family, string $skill): array => [
            $skill,
            $family['label'],
            $family['starter_item_name'] ?? '',
            $family['line'],
            $family['noun'],
            $family['craft'],
            $family['base_name'],
        ])
        ->values()
        ->all()),
    '## Tool Tier Names',
    '',
];

$toolTierRows = [];
foreach ($toolFamilies as $skill => $family) {
    recordItems($itemIndex, [[
        'item_key' => $family['starter_item_key'] ?? '',
        'item_name' => $family['starter_item_name'] ?? '',
    ]], 'starter tool', "tool family:{$skill}");

    foreach ($toolTiers as $tier) {
        $toolName = $tools->tierToolName($family, $tier);
        $toolTierRows[] = [$skill, $tier['level'], $tier['rarity'], $toolName, $family['base_name'], itemList(array_values(array_filter([$tier['extra'] ?? null])))];
        recordItems($itemIndex, [[
            'item_key' => $tools->tierToolKey($family, $tier),
            'item_name' => $toolName,
        ]], 'crafted tool', "tool tier:{$skill}:{$tier['level']}");
        recordItems($itemIndex, $tools->tierIngredients($family, $tier, $tier['extra'] ?? null), 'tool ingredient', "tool tier:{$skill}:{$tier['level']}");
    }
}

$lines = [
    ...$lines,
    ...table(['Skill', 'Level', 'Rarity', 'Tool name', 'Base ingredient', 'Extra ingredient'], $toolTierRows),
    '## Gathering Actions',
    '',
];

$actionRows = [];
foreach ($actions as $key => $action) {
    $actionRows[] = [$key, $action['skill'], $action['required_level'] ?? 1, $action['label'], $action['location'], itemList($action['loot'])];
    recordItems($itemIndex, $action['loot'], 'action loot', "action:{$key}");
}

$lines = [
    ...$lines,
    ...table(['Key', 'Skill', 'Level', 'Label', 'Location', 'Loot'], $actionRows),
    '## Skill Activities',
    '',
];

$activityRows = [];
foreach ($activities as $key => $activity) {
    $activityRows[] = [$key, $activity['skill'], $activity['required_level'], $activity['label'], $activity['track'], $activity['location'], itemList($activity['loot'])];
    recordItems($itemIndex, $activity['loot'], 'activity loot', "activity:{$key}");
}

$lines = [
    ...$lines,
    ...table(['Key', 'Skill', 'Level', 'Label', 'Track', 'Location', 'Loot'], $activityRows),
    '## Recipes',
    '',
];

$recipeRows = [];
foreach ($recipes as $key => $recipe) {
    $recipeRows[] = [$key, $recipe['skill'], $recipe['required_level'] ?? 1, $recipe['category'] ?? 'Crafting', $recipe['label'], itemList($recipe['ingredients']), itemList($recipe['outputs'])];
    recordItems($itemIndex, $recipe['ingredients'], 'recipe ingredient', "recipe:{$key}");
    recordItems($itemIndex, $recipe['outputs'], 'recipe output', "recipe:{$key}");
}

$lines = [
    ...$lines,
    ...table(['Key', 'Skill', 'Level', 'Category', 'Label', 'Ingredients', 'Outputs'], $recipeRows),
    '## Jobs',
    '',
];

$jobRows = [];
foreach ($jobs as $key => $job) {
    $jobRows[] = [$key, $job['skill'], $job['required_level'] ?? 1, $job['category'], $job['label'], itemList($job['requirements']), itemList($job['rewards'])];
    recordItems($itemIndex, $job['requirements'], 'job requirement', "job:{$key}");
}

$lines = [
    ...$lines,
    ...table(['Key', 'Skill', 'Level', 'Category', 'Label', 'Requirements', 'Rewards'], $jobRows),
    '## Expeditions',
    '',
];

$expeditionRows = [];
foreach ($expeditions as $key => $expedition) {
    $expeditionRows[] = [$key, $expedition['skill'], $expedition['required_level'] ?? 1, $expedition['label'], $expedition['region'], itemList($expedition['supplies']), itemList($expedition['rewards'])];
    recordItems($itemIndex, $expedition['supplies'], 'expedition supply', "expedition:{$key}");
    recordItems($itemIndex, $expedition['rewards'], 'expedition reward', "expedition:{$key}");
}

$lines = [
    ...$lines,
    ...table(['Key', 'Skill', 'Level', 'Label', 'Region', 'Supplies', 'Rewards'], $expeditionRows),
    '## Shop Offers',
    '',
];

$offerRows = [];
foreach ($shopOffers as $key => $offer) {
    $offerRows[] = [$key, $offer['skill'] ?? '', $offer['required_level'] ?? 1, $offer['category'], $offer['label'], $offer['item_name'], $offer['price']];
    recordItems($itemIndex, [$offer], 'shop offer', "shop:{$key}");
}

$lines = [
    ...$lines,
    ...table(['Key', 'Skill', 'Level', 'Category', 'Label', 'Item', 'Price'], $offerRows),
    '## World Events',
    '',
    ...table(['Key', 'Label', 'Status', 'Category', 'Region', 'Skills', 'Reward'], collect($worldEvents)
        ->map(fn (array $event): array => [$event['key'], $event['label'], $event['status'], $event['category'], $event['region'], $event['skill_label'], $event['reward']])
        ->all()),
    '## Leaderboards',
    '',
    ...table(['Key', 'Group', 'Label', 'Description'], collect($leaderboards)
        ->map(fn (array $board): array => [$board['key'], $board['group_label'], $board['label'], $board['description']])
        ->all()),
    '## Tool Grades',
    '',
    ...table(['Rarity', 'Grade label', 'Cooldown', 'Critical', 'Preservation', 'Market'], collect($toolGrades)
        ->map(fn (array $grade, string $rarity): array => [$rarity, $grade['label'], $grade['cooldown'], $grade['critical'], $grade['preservation'], $grade['market']])
        ->values()
        ->all()),
    '## Duplicate Label Review',
    '',
    ...table(['Surface', 'Label', 'Count', 'Sources'], $duplicateLabelRows === [] ? [['None', 'None', 0, 'No repeated labels found on tracked naming surfaces.']] : $duplicateLabelRows),
    '## Prefix-Swap Review',
    '',
    ...table(['Surface', 'Normalized name', 'Count', 'Labels'], $prefixSwapRows === [] ? [['None', 'None', 0, 'No prefix-swapped labels found on tracked naming surfaces.']] : $prefixSwapRows),
    '## Tool Traits',
    '',
    ...table(['Skill', 'Signature trait', 'Discipline'], collect($toolTraits)
        ->map(fn (array $trait, string $skill): array => [$skill, $trait['signature'], $trait['discipline']])
        ->values()
        ->all()),
    '## Achievement Reward Titles',
    '',
    ...table(['Key', 'Title'], [
        ...collect($baseAchievementTitles)->map(fn (string $title, string $key): array => [$key, $title])->values()->all(),
        ...collect($accountAchievementTitles)->map(fn (string $title, int $level): array => ["account_level_{$level}", $title])->values()->all(),
        ...collect($skillAchievementTitles)
            ->flatMap(fn (array $titles, string $skill): array => collect($titles)->map(fn (string $title, int $level): array => ["skill_milestone_{$skill}_{$level}", $title])->all())
            ->values()
            ->all(),
    ]),
    '## Double Tier Word Review',
    '',
    ...table(['Name', 'Tier words', 'Count', 'Normalized'], $doubleTierReviewRows === [] ? [['None', 'None', 0, 'No names with multiple tier words found.']] : $doubleTierReviewRows),
    '## Repeated Skeleton Review',
    '',
    ...table(['Skeleton', 'Count', 'Examples'], $skeletonRows === [] ? [['None', 0, 'No repeated skeletons above threshold.']] : $skeletonRows),
    '## Frequent Final Nouns',
    '',
    ...table(['Final noun', 'Count'], $finalNounRows === [] ? [['None', 0]] : $finalNounRows),
    '## Frequent Phrases',
    '',
    ...table(['Phrase', 'Count'], $phraseRows === [] ? [['None', 0]] : $phraseRows),
    '## Item Key Name Conflicts',
    '',
    ...table(['Item key', 'Display names', 'Sources'], $itemNameConflictRows === [] ? [['None', 'None', 'No item keys with multiple display names found.']] : $itemNameConflictRows),
    '## Fallback Name Review',
    '',
    ...table(['Name'], $fallbackNameRows === [] ? [['No fallback-generated names found on tracked naming surfaces.']] : $fallbackNameRows),
    '## Item Name Index',
    '',
    ...table(['Item key', 'Display name', 'Roles', 'Sources'], collect($itemIndex)
        ->sortKeys()
        ->map(fn (array $item, string $key): array => [
            $key,
            implode(', ', array_keys($item['names'])),
            implode(', ', array_keys($item['roles'])),
            implode(', ', array_keys($item['sources'])),
        ])
        ->values()
        ->all()),
];

file_put_contents(__DIR__.'/../docs/evergather-naming-catalog.md', implode(PHP_EOL, $lines).PHP_EOL);

echo 'Wrote docs/evergather-naming-catalog.md'.PHP_EOL;
