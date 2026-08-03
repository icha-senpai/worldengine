<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use Illuminate\Support\Collection;

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

        return [
            'account_level' => $accountLevel,
            'next_account_level_experience' => $accountLevel * 250,
            'skill_count' => count(SkillCatalogService::keys()),
            'active_skill_count' => $activeSkills,
            'mastered_skill_count' => $masteredSkills,
            'pacing' => $this->catalog->pacing(),
            'achievements' => [
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
            ],
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
    private function achievement(string $key, string $label, string $description, bool $unlocked, string $category): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'unlocked' => $unlocked,
            'category' => $category,
            'category_key' => str($category)->slug('_')->toString(),
            'reward' => $this->achievementReward($key, $label, $category),
        ];
    }

    /**
     * @return array{title: string, gold: int, profile_badge: string, unlock: string}
     */
    private function achievementReward(string $key, string $label, string $category): array
    {
        $level = (int) (preg_match('/_(100|75|50|25|10|5)$/', $key, $matches) === 1 ? $matches[1] : 0);
        $categoryKey = str($category)->slug('_')->toString();

        return [
            'title' => $level >= 100
                ? "{$label} Crown"
                : match ($categoryKey) {
                    'trade' => 'Ledger Notch',
                    'equipment' => 'Toolbelt Mark',
                    'mastery' => 'Mastery Sigil',
                    'combat' => 'Warden Mark',
                    'social' => 'Council Favor',
                    default => "{$label} Badge",
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
            'profile_badge' => str($label)->slug('_')->toString(),
            'unlock' => match ($categoryKey) {
                'gathering', 'gathering_milestones' => 'Resource run banner trim',
                'crafting', 'crafting_milestones', 'processing_milestones' => 'Workshop stamp and recipe pin',
                'trade', 'social', 'social_milestones' => 'Ledger profile seal',
                'equipment' => 'Tool provenance stamp',
                'combat', 'combat_milestones' => 'Warden profile crest',
                'exploration', 'world_milestones' => 'Route map badge',
                'mastery' => 'Mastery frame',
                default => 'Profile badge',
            },
        ];
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
