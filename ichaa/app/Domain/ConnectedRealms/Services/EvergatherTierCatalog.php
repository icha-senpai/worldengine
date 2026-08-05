<?php

namespace App\Domain\ConnectedRealms\Services;

class EvergatherTierCatalog
{
    /**
     * @var list<array{level: int, item_tier: int, band: string, key_slug: string, mark: string, station: string, rarity: string, experience: array{int, int}, gold: array{int, int}, cooldown: int}>
     */
    private const TIERS = [
        ['level' => 1, 'item_tier' => 1, 'band' => '1-30', 'key_slug' => 'starter', 'mark' => 'Candlemark', 'station' => 'Candlemark Station', 'rarity' => 'common', 'experience' => [22, 34], 'gold' => [3, 8], 'cooldown' => 70],
        ['level' => 5, 'item_tier' => 2, 'band' => '1-30', 'key_slug' => 'local', 'mark' => 'Wayside', 'station' => 'Wayside Station', 'rarity' => 'common', 'experience' => [30, 46], 'gold' => [4, 10], 'cooldown' => 85],
        ['level' => 10, 'item_tier' => 3, 'band' => '1-30', 'key_slug' => 'apprentice', 'mark' => 'Moonwake', 'station' => 'Moonwake Station', 'rarity' => 'uncommon', 'experience' => [40, 60], 'gold' => [6, 13], 'cooldown' => 100],
        ['level' => 20, 'item_tier' => 4, 'band' => '1-30', 'key_slug' => 'guild', 'mark' => 'Hearthsign', 'station' => 'Hearthsign Station', 'rarity' => 'uncommon', 'experience' => [58, 86], 'gold' => [8, 18], 'cooldown' => 125],
        ['level' => 30, 'item_tier' => 5, 'band' => '30-50', 'key_slug' => 'runed', 'mark' => 'Runebound', 'station' => 'Runebound Station', 'rarity' => 'rare', 'experience' => [78, 116], 'gold' => [12, 25], 'cooldown' => 155],
        ['level' => 40, 'item_tier' => 6, 'band' => '30-50', 'key_slug' => 'storm', 'mark' => 'Stormglass', 'station' => 'Stormglass Station', 'rarity' => 'rare', 'experience' => [102, 152], 'gold' => [16, 34], 'cooldown' => 190],
        ['level' => 50, 'item_tier' => 7, 'band' => '50-80', 'key_slug' => 'elite', 'mark' => 'Highguild', 'station' => 'Highguild Station', 'rarity' => 'rare', 'experience' => [132, 196], 'gold' => [22, 45], 'cooldown' => 230],
        ['level' => 65, 'item_tier' => 8, 'band' => '50-80', 'key_slug' => 'elder', 'mark' => 'Elderwake', 'station' => 'Elderwake Station', 'rarity' => 'epic', 'experience' => [176, 260], 'gold' => [30, 62], 'cooldown' => 285],
        ['level' => 80, 'item_tier' => 9, 'band' => '80-100', 'key_slug' => 'mythic', 'mark' => 'Mythgate', 'station' => 'Mythgate Station', 'rarity' => 'legendary', 'experience' => [238, 350], 'gold' => [42, 84], 'cooldown' => 350],
        ['level' => 100, 'item_tier' => 10, 'band' => '80-100', 'key_slug' => 'evergather', 'mark' => 'Crownmark', 'station' => 'Crownmark Station', 'rarity' => 'mythic', 'experience' => [360, 520], 'gold' => [62, 120], 'cooldown' => 430],
    ];

    /**
     * @return list<array{level: int, item_tier: int, band: string, key_slug: string, mark: string, station: string, rarity: string, experience: array{int, int}, gold: array{int, int}, cooldown: int}>
     */
    public static function tiers(): array
    {
        return app(ConnectedRealmsContentService::class)->applyList('tiers', self::baseTiers());
    }

    /**
     * @return list<array{level: int, item_tier: int, band: string, key_slug: string, mark: string, station: string, rarity: string, experience: array{int, int}, gold: array{int, int}, cooldown: int}>
     */
    public static function baseTiers(): array
    {
        return self::TIERS;
    }

    /**
     * @return list<array{level: int, item_tier: int, band: string, key_slug: string, mark: string, station: string, rarity: string, experience: array{int, int}, gold: array{int, int}, cooldown: int}>
     */
    public static function tiersBetween(int $minimumLevel, int $maximumLevel): array
    {
        return array_values(array_filter(
            self::tiers(),
            fn (array $tier): bool => $tier['level'] >= $minimumLevel && $tier['level'] <= $maximumLevel,
        ));
    }

    /**
     * @return list<int>
     */
    public static function levels(): array
    {
        return collect(self::tiers())
            ->pluck('level')
            ->all();
    }

    public static function isTierLevel(int $level): bool
    {
        return in_array($level, self::levels(), true);
    }

    public static function nextTierLevelFor(int $level): int
    {
        foreach (self::tiers() as $tier) {
            if ($tier['level'] >= $level) {
                return $tier['level'];
            }
        }

        return self::tiers()[array_key_last(self::tiers())]['level'];
    }

    public static function progressionPhaseForLevel(int $level): string
    {
        $level = self::nextTierLevelFor($level);

        return match (true) {
            $level <= 10 => 'Early',
            $level <= 50 => 'Mid',
            default => 'Endgame',
        };
    }

    /**
     * @return array{level: int, item_tier: int, band: string, key_slug: string, mark: string, station: string, rarity: string, experience: array{int, int}, gold: array{int, int}, cooldown: int}
     */
    public static function tierForLevel(int $level): array
    {
        $tiers = self::tiers();
        $selected = $tiers[0];

        foreach ($tiers as $tier) {
            if ($tier['level'] > $level) {
                break;
            }

            $selected = $tier;
        }

        return $selected;
    }

    public static function markForLevel(int $level): string
    {
        return self::tierForLevel($level)['mark'];
    }

    public static function itemTierForLevel(int $level): int
    {
        return (int) self::tierForLevel($level)['item_tier'];
    }

    public static function itemTierForMark(string $mark): ?int
    {
        foreach (self::tiers() as $tier) {
            if (strcasecmp($tier['mark'], $mark) === 0 || strcasecmp($tier['key_slug'], $mark) === 0) {
                return (int) $tier['item_tier'];
            }
        }

        return null;
    }
}
