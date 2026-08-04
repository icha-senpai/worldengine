<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsAchievementClaim;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProgressionService
{
    public function __construct(private SkillCatalogService $catalog) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshotFor(ConnectedRealmsPlayer $player, int $totalExperience, int $inventoryQuantity): array
    {
        $actionCount = $player->actionLogs()->count();
        $craftCount = $player->craftingLogs()->count();
        $jobCount = $player->jobCompletions()->count();
        $expeditionCount = $player->expeditionRuns()->count();
        $vendorSaleCount = $player->vendorSales()->count();
        $marketListingCount = $player->marketListings()->count();
        $marketPurchaseCount = $player->marketPurchases()->count();
        $marketActivityCount = $marketListingCount + $marketPurchaseCount;
        $tradeActivityCount = $marketActivityCount + $vendorSaleCount;
        $totalActivityCount = $actionCount + $craftCount + $jobCount + $expeditionCount;
        $inventoryStacks = $player->relationLoaded('inventoryStacks')
            ? $player->inventoryStacks
            : $player->inventoryStacks()->get();
        $equipmentCount = $player->relationLoaded('equipmentSlots')
            ? $player->equipmentSlots->count()
            : $player->equipmentSlots()->count();
        $highestSkill = $player->skills->sortByDesc('experience')->first();
        $skillLevels = $player->skills
            ->mapWithKeys(fn ($skill): array => [$skill->skill => $this->catalog->levelForExperience($skill->experience)]);
        $highestSkillLevel = (int) ($skillLevels->max() ?? 1);
        $accountLevel = $this->accountLevel($totalExperience);
        $masteredSkills = $player->skills
            ->filter(fn ($skill): bool => $this->catalog->levelForExperience($skill->experience) >= SkillCatalogService::MAX_LEVEL)
            ->count();
        $activeSkills = $player->skills
            ->filter(fn ($skill): bool => $skill->experience > 0)
            ->count();
        $skillsAtLevel = fn (int $level): int => $skillLevels
            ->filter(fn (int $skillLevel): bool => $skillLevel >= $level)
            ->count();
        $activeSkillsInCategory = fn (string $category): int => $player->skills
            ->filter(fn ($skill): bool => $skill->experience > 0 && $this->catalog->definition($skill->skill)['category'] === $category)
            ->count();
        $highestCategoryLevel = fn (string $category): int => (int) $player->skills
            ->filter(fn ($skill): bool => $this->catalog->definition($skill->skill)['category'] === $category)
            ->map(fn ($skill): int => $this->catalog->levelForExperience($skill->experience))
            ->max();
        $highestNamedSkillLevel = fn (array $skills): int => (int) $skillLevels
            ->filter(fn (int $level, string $skill): bool => in_array($skill, $skills, true))
            ->max();
        $achievementClaims = $player->relationLoaded('achievementClaims')
            ? $player->achievementClaims
            : $player->achievementClaims()->latest('claimed_at')->get();
        $rewardLoadout = $this->rewardLoadoutPayload($player, $achievementClaims);

        return [
            'account_level' => $accountLevel,
            'next_account_level_experience' => $accountLevel * 250,
            'skill_count' => count(SkillCatalogService::keys()),
            'active_skill_count' => $activeSkills,
            'mastered_skill_count' => $masteredSkills,
            'pacing' => $this->catalog->pacing(),
            'achievements' => $this->applyAchievementClaims([
                $this->achievement('first_steps', 'First Steps', 'Complete any gathering action.', $actionCount >= 1, 'Gathering'),
                $this->achievement('working_hands', 'Working Hands', 'Complete a crafting recipe.', $craftCount >= 1, 'Crafting'),
                $this->achievement('contract_hand', 'Commission Hand', 'Turn in a guild commission.', $jobCount >= 1, 'Jobs'),
                $this->achievement('pathfinder', 'Pathfinder', 'Resolve an expedition.', $expeditionCount >= 1, 'Exploration'),
                $this->achievement('market_voice', 'Market Voice', 'List or buy through the marketplace.', $marketActivityCount >= 1, 'Trade'),
                $this->achievement('full_pockets', 'Full Pockets', 'Hold at least 100 gold.', $player->gold >= 100, 'Wealth'),
                $this->achievement('packed_satchel', 'Packed Satchel', 'Hold at least 25 inventory items.', $inventoryQuantity >= 25, 'Inventory'),
                $this->achievement('skill_spark', 'Skill Spark', 'Reach level 2 in any skill.', $highestSkillLevel >= 2, 'Skills'),
                $this->achievement('ready_toolbelt', 'Ready Toolbelt', 'Own a full starter toolbelt.', $equipmentCount >= 7, 'Equipment'),
                $this->achievement('steady_hands', 'Steady Hands', 'Complete 10 gathering actions.', $actionCount >= 10, 'Gathering'),
                $this->achievement('route_runner', 'Route Runner', 'Complete 50 gathering actions.', $actionCount >= 50, 'Gathering'),
                $this->achievement('field_legend', 'Field Legend', 'Complete 250 gathering actions.', $actionCount >= 250, 'Gathering'),
                $this->achievement('gathering_circle', 'Gathering Circle', 'Earn XP in three gathering skills.', $activeSkillsInCategory('Gathering') >= 3, 'Gathering'),
                $this->achievement('wilds_initiate', 'Wilds Initiate', 'Reach level 5 in any gathering skill.', $highestCategoryLevel('Gathering') >= 5, 'Gathering'),
                $this->achievement('wilds_specialist', 'Wilds Specialist', 'Reach level 25 in any gathering skill.', $highestCategoryLevel('Gathering') >= 25, 'Gathering'),
                $this->achievement('bench_warm', 'Bench Warm', 'Complete 5 crafting recipes.', $craftCount >= 5, 'Crafting'),
                $this->achievement('workshop_shift', 'Workshop Shift', 'Complete 25 crafting recipes.', $craftCount >= 25, 'Crafting'),
                $this->achievement('artisan_season', 'Artisan Season', 'Complete 100 crafting recipes.', $craftCount >= 100, 'Crafting'),
                $this->achievement('profession_sampler', 'Profession Sampler', 'Earn XP in five profession skills.', $activeSkillsInCategory('Crafting') + $activeSkillsInCategory('Processing') >= 5, 'Crafting'),
                $this->achievement('apprentice_artisan', 'Apprentice Artisan', 'Reach level 5 in any crafting or processing profession.', max($highestCategoryLevel('Crafting'), $highestCategoryLevel('Processing')) >= 5, 'Crafting'),
                $this->achievement('master_artisan', 'Master Artisan', 'Reach level 50 in any crafting or processing profession.', max($highestCategoryLevel('Crafting'), $highestCategoryLevel('Processing')) >= 50, 'Crafting'),
                $this->achievement('reliable_hand', 'Reliable Hand', 'Turn in 5 guild commissions.', $jobCount >= 5, 'Jobs'),
                $this->achievement('guild_worker', 'Guild Worker', 'Turn in 25 guild commissions.', $jobCount >= 25, 'Jobs'),
                $this->achievement('contract_legend', 'Commission Legend', 'Turn in 100 guild commissions.', $jobCount >= 100, 'Jobs'),
                $this->achievement('caravaner', 'Caravaner', 'Resolve 5 expeditions.', $expeditionCount >= 5, 'Exploration'),
                $this->achievement('far_runner', 'Far Runner', 'Resolve 25 expeditions.', $expeditionCount >= 25, 'Exploration'),
                $this->achievement('worldwalker', 'Worldwalker', 'Resolve 100 expeditions.', $expeditionCount >= 100, 'Exploration'),
                $this->achievement('world_skill_spark', 'World Skill Spark', 'Earn XP in two world skills.', $activeSkillsInCategory('World') >= 2, 'Exploration'),
                $this->achievement('trail_authority', 'Trail Authority', 'Reach level 25 in any world skill.', $highestCategoryLevel('World') >= 25, 'Exploration'),
                $this->achievement('vendor_regular', 'Vendor Regular', 'Sell inventory to the NPC market-floor vendor.', $vendorSaleCount >= 1, 'Trade'),
                $this->achievement('ledger_friend', 'Ledger Friend', 'Sell to the NPC vendor 10 times.', $vendorSaleCount >= 10, 'Trade'),
                $this->achievement('market_stall', 'Market Stall', 'Create 5 player market listings.', $marketListingCount >= 5, 'Trade'),
                $this->achievement('buyer_eye', 'Buyer Eye', 'Buy 5 player market listings.', $marketPurchaseCount >= 5, 'Trade'),
                $this->achievement('trade_regular', 'Trade Regular', 'Complete 25 total market or vendor actions.', $tradeActivityCount >= 25, 'Trade'),
                $this->achievement('coin_chest', 'Coin Chest', 'Hold at least 500 gold.', $player->gold >= 500, 'Wealth'),
                $this->achievement('treasury_key', 'Treasury Key', 'Hold at least 2,500 gold.', $player->gold >= 2500, 'Wealth'),
                $this->achievement('realm_fortune', 'Realm Fortune', 'Hold at least 10,000 gold.', $player->gold >= 10000, 'Wealth'),
                $this->achievement('quartermaster', 'Quartermaster', 'Hold at least 100 inventory items.', $inventoryQuantity >= 100, 'Inventory'),
                $this->achievement('warehouse_mind', 'Warehouse Mind', 'Hold at least 250 inventory items.', $inventoryQuantity >= 250, 'Inventory'),
                $this->achievement('collector_shelf', 'Collector Shelf', 'Hold 10 unique inventory stacks.', $inventoryStacks->count() >= 10, 'Inventory'),
                $this->achievement('rare_keeper', 'Rare Keeper', 'Hold an uncommon or better item stack.', $inventoryStacks->contains(fn ($stack): bool => in_array($stack->rarity, ['uncommon', 'rare', 'epic', 'legendary'], true)), 'Inventory'),
                $this->achievement('apprentice_spark', 'Apprentice Spark', 'Reach level 5 in any skill.', $highestSkillLevel >= 5, 'Skills'),
                $this->achievement('journeyman_spark', 'Journeyman Spark', 'Reach level 10 in any skill.', $highestSkillLevel >= 10, 'Skills'),
                $this->achievement('expert_spark', 'Expert Spark', 'Reach level 25 in any skill.', $highestSkillLevel >= 25, 'Skills'),
                $this->achievement('master_spark', 'Master Spark', 'Reach level 50 in any skill.', $highestSkillLevel >= 50, 'Skills'),
                $this->achievement('legend_spark', 'Legend Spark', 'Reach level 75 in any skill.', $highestSkillLevel >= 75, 'Skills'),
                $this->achievement('level_100_oath', 'Level 100 Oath', 'Reach level 100 in any skill.', $highestSkillLevel >= SkillCatalogService::MAX_LEVEL, 'Mastery'),
                $this->achievement('broad_training', 'Broad Training', 'Earn XP in 5 different skills.', $activeSkills >= 5, 'Skills'),
                $this->achievement('polymath_path', 'Polymath Path', 'Earn XP in 10 different skills.', $activeSkills >= 10, 'Skills'),
                $this->achievement('full_slate', 'Full Slate', 'Earn XP in every skill.', $activeSkills >= count(SkillCatalogService::keys()), 'Mastery'),
                $this->achievement('skill_quiver', 'Skill Quiver', 'Reach level 10 in five skills.', $skillsAtLevel(10) >= 5, 'Skills'),
                $this->achievement('mastery_circle', 'Mastery Circle', 'Reach level 50 in five skills.', $skillsAtLevel(50) >= 5, 'Mastery'),
                $this->achievement('realm_mastery', 'Realm Mastery', 'Master five level 100 skills.', $masteredSkills >= 5, 'Mastery'),
                $this->achievement('combat_recruit', 'Combat Recruit', 'Reach level 5 in any combat skill.', $highestCategoryLevel('Combat') >= 5, 'Combat'),
                $this->achievement('threat_breaker', 'Threat Breaker', 'Reach level 25 in any combat skill.', $highestCategoryLevel('Combat') >= 25, 'Combat'),
                $this->achievement('battle_company', 'Battle Company', 'Earn XP in three combat skills.', $activeSkillsInCategory('Combat') >= 3, 'Combat'),
                $this->achievement('hunter_edge', 'Hunter Edge', 'Reach level 10 in Hunting, Combat, or Slayer.', $highestNamedSkillLevel(['hunting', 'combat', 'slayer']) >= 10, 'Combat'),
                $this->achievement('social_foothold', 'Social Foothold', 'Earn XP in Reputation, Leadership, or Trading.', $activeSkillsInCategory('Social') >= 1, 'Social'),
                $this->achievement('known_name', 'Known Name', 'Reach level 10 in any social skill.', $highestCategoryLevel('Social') >= 10, 'Social'),
                $this->achievement('realm_regular', 'Realm Regular', 'Complete 25 total actions, crafts, jobs, or expeditions.', $totalActivityCount >= 25, 'Account'),
                $this->achievement('realm_veteran', 'Realm Veteran', 'Complete 250 total actions, crafts, jobs, or expeditions.', $totalActivityCount >= 250, 'Account'),
                ...$this->accountMilestoneAchievements($accountLevel),
                ...$this->skillMilestoneAchievements($skillLevels),
            ], $achievementClaims),
            'claimed_rewards' => $achievementClaims
                ->map(fn (ConnectedRealmsAchievementClaim $claim): array => [
                    'achievement_key' => $claim->achievement_key,
                    'achievement_label' => $claim->achievement_label,
                    'category' => $claim->category,
                    'reward' => $claim->reward,
                    'claimed_at' => optional($claim->claimed_at)->toIso8601String(),
                ])
                ->values()
                ->all(),
            'reward_options' => $this->rewardOptions($achievementClaims),
            'reward_loadout' => $rewardLoadout,
            'stats' => [
                'actions' => $actionCount,
                'crafts' => $craftCount,
                'jobs' => $jobCount,
                'expeditions' => $expeditionCount,
                'market_activity' => $marketActivityCount,
                'vendor_sales' => $vendorSaleCount,
                'trade_activity' => $tradeActivityCount,
                'total_activity' => $totalActivityCount,
                'highest_skill' => $highestSkill === null ? null : [
                    'skill' => $highestSkill->skill,
                    'label' => $this->catalog->definition($highestSkill->skill)['label'],
                    'level' => $highestSkillLevel,
                    'experience' => $highestSkill->experience,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function claimAchievement(ConnectedRealmsPlayer $player, string $achievementKey): array
    {
        return DB::transaction(function () use ($player, $achievementKey): array {
            $lockedPlayer = ConnectedRealmsPlayer::query()
                ->whereKey($player->id)
                ->lockForUpdate()
                ->firstOrFail();
            $totalExperience = (int) $lockedPlayer->skills()->sum('experience');
            $inventoryQuantity = (int) $lockedPlayer->inventoryStacks()->sum('quantity');
            $achievement = collect($this->snapshotFor($lockedPlayer, $totalExperience, $inventoryQuantity)['achievements'])
                ->firstWhere('key', $achievementKey);

            if (! is_array($achievement)) {
                throw ValidationException::withMessages([
                    'achievement' => 'That achievement does not exist.',
                ]);
            }

            if (! $achievement['unlocked']) {
                throw ValidationException::withMessages([
                    'achievement' => 'That achievement is not unlocked yet.',
                ]);
            }

            if ($achievement['claimed']) {
                throw ValidationException::withMessages([
                    'achievement' => 'That achievement reward has already been claimed.',
                ]);
            }

            $reward = $achievement['reward'];
            $gold = max(0, (int) ($reward['gold'] ?? 0));
            $claim = ConnectedRealmsAchievementClaim::query()->create([
                'player_id' => $lockedPlayer->id,
                'achievement_key' => $achievement['key'],
                'achievement_label' => $achievement['label'],
                'category' => $achievement['category'],
                'reward' => $reward,
                'claimed_at' => now(),
            ]);

            $updates = [
                'gold' => $lockedPlayer->gold + $gold,
            ];
            $rewardLoadout = $this->normalizeRewardLoadoutKeys($lockedPlayer->reward_loadout);

            if (($lockedPlayer->title === null || $lockedPlayer->title === '') && is_string($reward['title'] ?? null)) {
                $updates['title'] = $reward['title'];
            }

            if (is_string($reward['title'] ?? null) && $rewardLoadout['title_claim_key'] === null) {
                $rewardLoadout['title_claim_key'] = $achievement['key'];
            }

            $updates['reward_loadout'] = $rewardLoadout;

            $lockedPlayer->forceFill($updates)->save();
            $claims = ConnectedRealmsAchievementClaim::query()
                ->where('player_id', $lockedPlayer->id)
                ->latest('claimed_at')
                ->get();

            return [
                'type' => 'achievement_claim',
                'id' => $claim->id,
                'achievement_key' => $achievement['key'],
                'label' => $achievement['label'],
                'reward' => $reward,
                'reward_loadout' => $this->rewardLoadoutPayload($lockedPlayer->refresh(), $claims),
                'gold_awarded' => $gold,
                'title_applied' => array_key_exists('title', $updates),
                'claimed_at' => $claim->claimed_at->toIso8601String(),
            ];
        });
    }

    /**
     * @param  array{title_claim_key?: string|null}  $loadout
     * @return array<string, mixed>
     */
    public function updateRewardLoadout(ConnectedRealmsPlayer $player, array $loadout): array
    {
        return DB::transaction(function () use ($player, $loadout): array {
            $lockedPlayer = ConnectedRealmsPlayer::query()
                ->whereKey($player->id)
                ->lockForUpdate()
                ->firstOrFail();
            $claims = ConnectedRealmsAchievementClaim::query()
                ->where('player_id', $lockedPlayer->id)
                ->latest('claimed_at')
                ->get();
            $claimsByKey = $claims->keyBy('achievement_key');
            $requestedLoadout = $this->normalizeRewardLoadoutKeys($loadout);
            $previousLoadout = $this->normalizeRewardLoadoutKeys($lockedPlayer->reward_loadout);
            $selectedTitleClaim = $this->claimForLoadoutSlot($claimsByKey, $requestedLoadout['title_claim_key'], 'title', 'title_claim_key');
            $updates = [
                'reward_loadout' => $requestedLoadout,
            ];

            if ($selectedTitleClaim instanceof ConnectedRealmsAchievementClaim) {
                $updates['title'] = $selectedTitleClaim->reward['title'];
            } elseif ($previousLoadout['title_claim_key'] !== null) {
                $previousTitleClaim = $claimsByKey->get($previousLoadout['title_claim_key']);

                if ($previousTitleClaim instanceof ConnectedRealmsAchievementClaim
                    && $lockedPlayer->title === ($previousTitleClaim->reward['title'] ?? null)) {
                    $updates['title'] = null;
                }
            }

            $lockedPlayer->forceFill($updates)->save();
            $lockedPlayer->setRelation('achievementClaims', $claims);

            return [
                'type' => 'reward_loadout',
                'label' => 'Reward Loadout',
                'title' => $selectedTitleClaim?->reward['title'] ?? null,
                'reward_loadout' => $this->rewardLoadoutPayload($lockedPlayer->refresh(), $claims),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function achievement(string $key, string $label, string $description, bool $unlocked, string $category): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'unlocked' => $unlocked,
            'claimed' => false,
            'can_claim' => $unlocked,
            'category' => $category,
            'category_key' => str($category)->slug('_')->toString(),
            'reward' => $this->achievementReward($key, $label, $category),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $achievements
     * @param  Collection<int, ConnectedRealmsAchievementClaim>  $claims
     * @return list<array<string, mixed>>
     */
    private function applyAchievementClaims(array $achievements, Collection $claims): array
    {
        $claimsByKey = $claims->keyBy('achievement_key');

        return collect($achievements)
            ->map(function (array $achievement) use ($claimsByKey): array {
                $claim = $claimsByKey->get($achievement['key']);

                return [
                    ...$achievement,
                    'claimed' => $claim !== null,
                    'can_claim' => $achievement['unlocked'] && $claim === null,
                    'claimed_at' => optional($claim?->claimed_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ConnectedRealmsAchievementClaim>  $claims
     * @return array{titles: list<array<string, mixed>>}
     */
    private function rewardOptions(Collection $claims): array
    {
        return [
            'titles' => $this->rewardOptionRows($claims, 'title'),
        ];
    }

    /**
     * @param  Collection<int, ConnectedRealmsAchievementClaim>  $claims
     * @return array{title_claim_key: string|null, title_label: string|null, title_source: string|null, has_equipped: bool}
     */
    private function rewardLoadoutPayload(ConnectedRealmsPlayer $player, Collection $claims): array
    {
        $loadout = $this->normalizeRewardLoadoutKeys($player->reward_loadout);
        $claimsByKey = $claims->keyBy('achievement_key');
        $titleClaim = $claimsByKey->get($loadout['title_claim_key']);

        return [
            'title_claim_key' => $titleClaim instanceof ConnectedRealmsAchievementClaim ? $titleClaim->achievement_key : null,
            'title_label' => $titleClaim instanceof ConnectedRealmsAchievementClaim ? (string) ($titleClaim->reward['title'] ?? '') : null,
            'title_source' => $titleClaim instanceof ConnectedRealmsAchievementClaim ? $titleClaim->achievement_label : null,
            'has_equipped' => $titleClaim instanceof ConnectedRealmsAchievementClaim,
        ];
    }

    /**
     * @param  Collection<string, ConnectedRealmsAchievementClaim>  $claimsByKey
     */
    private function claimForLoadoutSlot(Collection $claimsByKey, ?string $achievementKey, string $rewardKey, string $field): ?ConnectedRealmsAchievementClaim
    {
        if ($achievementKey === null) {
            return null;
        }

        $claim = $claimsByKey->get($achievementKey);

        if (! $claim instanceof ConnectedRealmsAchievementClaim) {
            throw ValidationException::withMessages([
                $field => 'Claim that reward before equipping it.',
            ]);
        }

        if ($this->rewardValue($claim->reward, $rewardKey) === null) {
            throw ValidationException::withMessages([
                $field => 'That reward cannot be equipped in this slot.',
            ]);
        }

        return $claim;
    }

    /**
     * @param  Collection<int, ConnectedRealmsAchievementClaim>  $claims
     * @return list<array<string, mixed>>
     */
    private function rewardOptionRows(Collection $claims, string $rewardKey): array
    {
        return $claims
            ->filter(fn (ConnectedRealmsAchievementClaim $claim): bool => $this->rewardValue($claim->reward, $rewardKey) !== null)
            ->map(fn (ConnectedRealmsAchievementClaim $claim): array => [
                'key' => $claim->achievement_key,
                'label' => match ($rewardKey) {
                    'title' => $this->rewardValue($claim->reward, 'title'),
                    default => $this->rewardValue($claim->reward, $rewardKey),
                },
                'source' => $claim->achievement_label,
                'category' => $claim->category,
                'claimed_at' => optional($claim->claimed_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{title_claim_key: string|null}
     */
    private function normalizeRewardLoadoutKeys(mixed $loadout): array
    {
        $loadout = is_array($loadout) ? $loadout : [];

        return [
            'title_claim_key' => $this->nullableRewardKey($loadout['title_claim_key'] ?? null),
        ];
    }

    private function nullableRewardKey(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{title: string, gold: int}
     */
    private function achievementReward(string $key, string $label, string $category): array
    {
        $level = (int) (preg_match('/_(100|75|50|25|10|5)$/', $key, $matches) === 1 ? $matches[1] : 0);
        $categoryKey = str($category)->slug('_')->toString();

        return [
            'title' => $level >= 100
                ? "{$label} Crown"
                : match ($categoryKey) {
                    'gathering', 'gathering_milestones' => 'Trailmarked',
                    'crafting', 'crafting_milestones', 'processing_milestones' => 'Workshop Hand',
                    'jobs' => 'Guild Hand',
                    'exploration', 'world_milestones' => 'Pathfinder',
                    'wealth' => 'Coinbound',
                    'inventory' => 'Quartermaster',
                    'skills', 'account' => 'Realm Regular',
                    'trade' => 'Ledger Notch',
                    'equipment' => 'Toolbelt Mark',
                    'mastery' => 'Mastery Sigil',
                    'combat' => 'Warden Mark',
                    'social' => 'Council Favor',
                    default => $label,
                },
            'gold' => match (true) {
                $level >= 100 => 1000,
                $level >= 75 => 650,
                $level >= 50 => 400,
                $level >= 25 => 180,
                $level >= 10 => 80,
                $level >= 5 => 35,
                default => 15,
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $reward
     */
    private function rewardValue(array $reward, string $rewardKey): ?string
    {
        $value = $reward[$rewardKey] ?? null;

        if (is_array($value)) {
            $value = $value['label'] ?? null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function accountLevel(int $totalExperience): int
    {
        return max(1, intdiv($totalExperience, 250) + 1);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accountMilestoneAchievements(int $accountLevel): array
    {
        return collect([5, 10, 25, 50, 75, 100])
            ->map(fn (int $level): array => [
                ...$this->achievement(
                    "account_level_{$level}",
                    "Account Level {$level}",
                    "Reach account level {$level}.",
                    $accountLevel >= $level,
                    'Account'
                ),
                'level' => $level,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, int>  $skillLevels
     * @return list<array<string, mixed>>
     */
    private function skillMilestoneAchievements(Collection $skillLevels): array
    {
        return collect(SkillCatalogService::keys())
            ->flatMap(function (string $skill) use ($skillLevels): array {
                $definition = $this->catalog->definition($skill);
                $currentLevel = (int) ($skillLevels->get($skill) ?? 1);

                return collect([5, 10, 25, 50, 75, SkillCatalogService::MAX_LEVEL])
                    ->map(fn (int $level): array => [
                        ...$this->achievement(
                            "skill_milestone_{$skill}_{$level}",
                            "{$definition['label']} Level {$level}",
                            "Reach level {$level} in {$definition['label']}.",
                            $currentLevel >= $level,
                            "{$definition['category']} Milestones"
                        ),
                        'skill' => $skill,
                        'level' => $level,
                    ])
                    ->all();
            })
            ->values()
            ->all();
    }
}
