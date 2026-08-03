<?php

namespace App\Domain\ConnectedRealms\Services;

class WorldEventService
{
    /**
     * @var array<string, array{
     *     label: string,
     *     status: string,
     *     region: string,
     *     category: string,
     *     skills: list<string>,
     *     starts_at: string,
     *     ends_at: string,
     *     description: string,
     *     bonus: array{experience: int, yield: int, gold: int},
     *     reward: string
     * }>
     */
    private const EVENTS = [
        'meteorfall' => [
            'label' => 'Meteorfall',
            'status' => 'active',
            'category' => 'Gathering Surge',
            'region' => 'Emberdeep Quarry',
            'skills' => ['mining', 'smelting', 'smithing'],
            'starts_at' => '2026-08-03T00:00:00-05:00',
            'ends_at' => '2026-08-04T00:00:00-05:00',
            'description' => 'Star-metal sparks through the quarry seams and turns careful ore work into better toolmaking runs.',
            'bonus' => ['experience' => 2, 'yield' => 1, 'gold' => 0],
            'reward' => 'Star Metal Ingot chances from late quarry and forge paths.',
        ],
        'wardens_muster' => [
            'label' => 'Warden\'s Muster',
            'status' => 'active',
            'category' => 'Combat Call',
            'region' => 'Old Gate Shield Line',
            'skills' => ['combat', 'defense', 'healing', 'leadership'],
            'starts_at' => '2026-08-03T06:00:00-05:00',
            'ends_at' => '2026-08-04T06:00:00-05:00',
            'description' => 'Gate captains rotate live drills, triage rounds, and command practice through the lower wall.',
            'bonus' => ['experience' => 3, 'yield' => 0, 'gold' => 1],
            'reward' => 'Defense badges, medic satchels, and crew banners move faster through combat activities.',
        ],
        'market_moon' => [
            'label' => 'Market Moon',
            'status' => 'active',
            'category' => 'Trade Cycle',
            'region' => 'Regional Trade Loop',
            'skills' => ['trading', 'reputation', 'jewelcrafting', 'cooking'],
            'starts_at' => '2026-08-03T12:00:00-05:00',
            'ends_at' => '2026-08-04T12:00:00-05:00',
            'description' => 'Ledger houses open their night counters, lifting brokerage, faction favor, and prepared-goods turnover.',
            'bonus' => ['experience' => 2, 'yield' => 1, 'gold' => 2],
            'reward' => 'Trade writs, faction seals, fine meals, and jewelry settings become better market targets.',
        ],
        'leviathan_tide' => [
            'label' => 'Leviathan Tide',
            'status' => 'upcoming',
            'category' => 'Coastal Surge',
            'region' => 'Moonwake Coast',
            'skills' => ['fishing', 'sailing', 'boatbuilding', 'cooking'],
            'starts_at' => '2026-08-04T18:00:00-05:00',
            'ends_at' => '2026-08-05T00:00:00-05:00',
            'description' => 'The deep tide pushes heavy catches, skiff work, and coastal provisions into the harbor.',
            'bonus' => ['experience' => 3, 'yield' => 1, 'gold' => 1],
            'reward' => 'Leviathan scales, tide charts, and feast ingredients become premium listings.',
        ],
        'greenmarch' => [
            'label' => 'Greenmarch',
            'status' => 'upcoming',
            'category' => 'Wild Growth',
            'region' => 'Glimmerfen Trail',
            'skills' => ['foraging', 'alchemy', 'survival', 'tailoring'],
            'starts_at' => '2026-08-05T12:00:00-05:00',
            'ends_at' => '2026-08-06T00:00:00-05:00',
            'description' => 'Herbs, fiber, spores, and camp supplies crowd the old paths after warm rain.',
            'bonus' => ['experience' => 2, 'yield' => 2, 'gold' => 0],
            'reward' => 'Alchemy reagents and survival caches become easier to stockpile.',
        ],
        'vault_lanterns' => [
            'label' => 'Vault Lanterns',
            'status' => 'upcoming',
            'category' => 'Delver Window',
            'region' => 'Lower Vault Wing',
            'skills' => ['dungeoneering', 'exploration', 'cartography', 'magic'],
            'starts_at' => '2026-08-06T18:00:00-05:00',
            'ends_at' => '2026-08-07T06:00:00-05:00',
            'description' => 'Survey lanterns burn blue through the vault wing, exposing rooms that usually stay sealed.',
            'bonus' => ['experience' => 4, 'yield' => 1, 'gold' => 1],
            'reward' => 'Vault keys, map fragments, and focus shards become event-board chase items.',
        ],
        'craftsmen_convocation' => [
            'label' => 'Craftsmen Convocation',
            'status' => 'upcoming',
            'category' => 'Guild Crafting',
            'region' => 'Guild Hall Shop',
            'skills' => ['carpentry', 'weaving', 'engineering', 'furniture', 'construction'],
            'starts_at' => '2026-08-07T09:00:00-05:00',
            'ends_at' => '2026-08-08T09:00:00-05:00',
            'description' => 'Master shops post public benches for building kits, frames, furniture, and tuned mechanisms.',
            'bonus' => ['experience' => 3, 'yield' => 1, 'gold' => 1],
            'reward' => 'Frames, scaffolds, finishing kits, and gearwork sell into a stronger event market.',
        ],
        'briarwake_hunt' => [
            'label' => 'Briarwake Hunt',
            'status' => 'upcoming',
            'category' => 'Hunting Season',
            'region' => 'Briarwake Bounty Board',
            'skills' => ['hunting', 'slayer', 'leatherworking', 'ranged'],
            'starts_at' => '2026-08-08T16:00:00-05:00',
            'ends_at' => '2026-08-09T16:00:00-05:00',
            'description' => 'Bounty callers mark clean trails for hides, trophies, ranged trials, and monster work.',
            'bonus' => ['experience' => 3, 'yield' => 2, 'gold' => 1],
            'reward' => 'Cured leather, marked bones, fletching bundles, and monster trophies become active chase goods.',
        ],
    ];

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function calendar(): array
    {
        $events = collect(self::EVENTS)
            ->map(fn (array $event, string $key): array => [
                'key' => $key,
                'label' => $event['label'],
                'status' => $event['status'],
                'category' => $event['category'],
                'region' => $event['region'],
                'skills' => $event['skills'],
                'skill' => $event['skills'][0] ?? null,
                'skill_label' => collect($event['skills'])->map(fn (string $skill): string => str($skill)->headline()->toString())->join(', '),
                'starts_at' => $event['starts_at'],
                'ends_at' => $event['ends_at'],
                'description' => $event['description'],
                'experience_bonus' => $event['bonus']['experience'],
                'yield_bonus' => $event['bonus']['yield'],
                'gold_bonus' => $event['bonus']['gold'],
                'reward' => $event['reward'],
            ])
            ->values();

        return [
            'active' => $events->where('status', 'active')->values()->all(),
            'upcoming' => $events->where('status', 'upcoming')->values()->all(),
            'categories' => $events
                ->groupBy('category')
                ->map(fn ($entries, string $category): array => [
                    'key' => str($category)->slug('_')->toString(),
                    'label' => $category,
                    'count' => $entries->count(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{key: string, label: string, experience: int, yield: int, gold: int, context: string}|null
     */
    public function gatheringBonusForSkill(string $skill): ?array
    {
        return $this->bonusForSkill($skill, 'gathering');
    }

    /**
     * @return array{key: string, label: string, experience: int, yield: int, gold: int, context: string}|null
     */
    public function bonusForSkill(string $skill, string $context): ?array
    {
        foreach (self::EVENTS as $key => $event) {
            if ($event['status'] === 'active' && in_array($skill, $event['skills'], true)) {
                return [
                    'key' => $key,
                    'label' => $event['label'],
                    'experience' => $event['bonus']['experience'],
                    'yield' => $event['bonus']['yield'],
                    'gold' => $event['bonus']['gold'],
                    'context' => $context,
                ];
            }
        }

        return null;
    }
}
