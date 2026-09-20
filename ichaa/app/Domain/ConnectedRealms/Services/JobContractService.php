<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsInventoryStack;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsJobCompletion;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsJobContract;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JobContractService
{
    private const DEFAULT_ROTATION = 'daily';

    private const DEFAULT_COMPLETION_CAP = 3;

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $jobCache = null;

    public function __construct(private ConnectedRealmsPlayerService $players, private ItemCatalogService $items) {}

    /**
     * @return list<string>
     */
    public static function jobKeys(): array
    {
        return array_keys(self::jobs());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableJobsFor(ConnectedRealmsPlayer $player): array
    {
        $inventory = ($player->relationLoaded('inventoryStacks')
            ? $player->inventoryStacks
            : $player->inventoryStacks()->get())
            ->keyBy('item_key');

        $jobCatalog = self::jobs();
        $completionCounts = $this->completionCountsForJobs($player, array_keys($jobCatalog));
        $activeContracts = ConnectedRealmsJobContract::query()
            ->where('player_id', $player->id)
            ->where('status', ConnectedRealmsJobContract::STATUS_ACTIVE)
            ->whereIn('job_key', array_keys(self::jobs()))
            ->get()
            ->keyBy('job_key');

        return collect($jobCatalog)
            ->map(function (array $job, string $key) use ($inventory, $player, $completionCounts, $activeContracts): array {
                $requiredLevel = (int) ($job['required_level'] ?? 1);
                $skillProgress = $this->players->skillProgressFor($player, $job['skill']);
                $skillLevel = $skillProgress['level'];
                $completionCap = $this->completionCap($job);
                $completedInRotation = (int) ($completionCounts[$key] ?? 0);
                $remainingCompletions = max(0, $completionCap - $completedInRotation);
                $isCoreContract = $this->isCoreContract($job);
                $activeContract = $activeContracts->get($key);
                $progressRequired = $this->progressRequiredFor($job);
                $progressQuantity = $activeContract instanceof ConnectedRealmsJobContract
                    ? min($progressRequired, (int) $activeContract->progress_quantity)
                    : 0;
                $hasProgress = $progressQuantity >= $progressRequired;
                $requirements = collect($job['requirements'])
                    ->map(function (array $requirement) use ($inventory): array {
                        $ownedQuantity = (int) ($inventory->get($requirement['item_key'])?->quantity ?? 0);

                        return $this->items->enrich([
                            ...$requirement,
                            'owned_quantity' => $ownedQuantity,
                            'has_enough' => $ownedQuantity >= $requirement['quantity'],
                        ]);
                    })
                    ->values()
                    ->all();
                $hasRequirements = collect($requirements)->every(fn (array $requirement): bool => $requirement['has_enough']);
                $isUnlocked = $skillLevel >= $requiredLevel;
                $isDemandAvailable = $remainingCompletions > 0;
                $isAccepted = $activeContract instanceof ConnectedRealmsJobContract;

                return [
                    'key' => $key,
                    'label' => $job['label'],
                    'category' => $job['category'],
                    'skill' => $job['skill'],
                    'skill_label' => $skillProgress['skill_label'],
                    'required_level' => $requiredLevel,
                    'skill_level' => $skillLevel,
                    'skill_progress' => $skillProgress,
                    'is_unlocked' => $isUnlocked,
                    'experience' => $job['experience'],
                    'gold' => $job['gold'],
                    'tier' => $job['tier'] ?? null,
                    'tier_mark' => $job['tier_mark'] ?? null,
                    'archetype' => $job['archetype'] ?? null,
                    'objective_type' => $job['objective_type'] ?? 'deliver',
                    'objective' => $job['objective'] ?? [],
                    'demand_channel' => (string) ($job['demand_channel'] ?? 'posted_commission'),
                    'demand_pool' => $job['demand_pool'] ?? null,
                    'world_consumer' => $job['world_consumer'] ?? null,
                    'purpose' => $job['purpose'] ?? null,
                    'sink' => $job['sink'] ?? null,
                    'tool_material_profile' => $job['tool_material_profile'] ?? null,
                    'rotation' => (string) ($job['rotation'] ?? self::DEFAULT_ROTATION),
                    'completion_cap' => $completionCap,
                    'completed_in_rotation' => $completedInRotation,
                    'remaining_completions' => $remainingCompletions,
                    'is_demand_available' => $isDemandAvailable,
                    'requires_acceptance' => $isCoreContract,
                    'is_accepted' => $isAccepted,
                    'can_accept' => $isCoreContract && ! $isAccepted && $isUnlocked && $isDemandAvailable,
                    'accepted_at' => $activeContract?->accepted_at?->toIso8601String(),
                    'progress_quantity' => $progressQuantity,
                    'progress_required' => $progressRequired,
                    'progress_percent' => $progressRequired > 0 ? (int) floor(($progressQuantity / $progressRequired) * 100) : 100,
                    'requirements' => $requirements,
                    'rewards' => $job['rewards'],
                    'can_complete' => ($isCoreContract ? ($isAccepted && $hasProgress) : true)
                        && $hasRequirements
                        && $isUnlocked
                        && $isDemandAvailable,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function accept(User $user, string $jobKey): array
    {
        return DB::transaction(function () use ($user, $jobKey): array {
            $player = $this->players->playerForUser($user);
            $player = ConnectedRealmsPlayer::query()
                ->whereKey($player->id)
                ->lockForUpdate()
                ->firstOrFail();
            $job = self::jobForKey($jobKey);

            if ($job === null) {
                throw ValidationException::withMessages([
                    'job' => 'That Evergather profession contract is not available.',
                ]);
            }

            if (! $this->isCoreContract($job)) {
                throw ValidationException::withMessages([
                    'job' => 'Only core profession contracts need acceptance.',
                ]);
            }

            $requiredLevel = (int) ($job['required_level'] ?? 1);

            if ($this->players->currentSkillLevel($player, $job['skill']) < $requiredLevel) {
                throw ValidationException::withMessages([
                    'job' => "You need level {$requiredLevel} ".str($job['skill'])->headline()->toString().' for that contract.',
                ]);
            }

            $this->ensureDemandAvailable($player, $jobKey, $job);

            $existing = ConnectedRealmsJobContract::query()
                ->where('player_id', $player->id)
                ->where('job_key', $jobKey)
                ->where('status', ConnectedRealmsJobContract::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'job' => "{$job['label']} is already accepted.",
                ]);
            }

            $contract = ConnectedRealmsJobContract::create([
                'player_id' => $player->id,
                'job_key' => $jobKey,
                'job_name' => $job['label'],
                'category' => $job['category'],
                'skill' => $job['skill'],
                'objective_type' => $job['objective_type'],
                'objective' => $job['objective'] ?? [],
                'required_quantity' => $this->progressRequiredFor($job),
                'progress_quantity' => 0,
                'status' => ConnectedRealmsJobContract::STATUS_ACTIVE,
                'accepted_at' => now(),
            ]);

            return [
                'type' => 'job_acceptance',
                'id' => $contract->id,
                'job_key' => $jobKey,
                'label' => $job['label'],
                'category' => $job['category'],
                'skill' => $job['skill'],
                'objective_type' => $job['objective_type'],
                'progress_required' => $contract->required_quantity,
                'progress_quantity' => $contract->progress_quantity,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function complete(User $user, string $jobKey): array
    {
        return DB::transaction(function () use ($user, $jobKey): array {
            $player = $this->players->playerForUser($user);
            $player = ConnectedRealmsPlayer::query()
                ->whereKey($player->id)
                ->lockForUpdate()
                ->firstOrFail();
            $job = self::jobForKey($jobKey);

            if ($job === null) {
                throw ValidationException::withMessages([
                    'job' => 'That Evergather job is not available.',
                ]);
            }

            $requiredLevel = (int) ($job['required_level'] ?? 1);

            if ($this->players->currentSkillLevel($player, $job['skill']) < $requiredLevel) {
                throw ValidationException::withMessages([
                    'job' => "You need level {$requiredLevel} ".str($job['skill'])->headline()->toString().' for that job.',
                ]);
            }

            $this->ensureDemandAvailable($player, $jobKey, $job);
            $contract = null;

            if ($this->isCoreContract($job)) {
                $contract = ConnectedRealmsJobContract::query()
                    ->where('player_id', $player->id)
                    ->where('job_key', $jobKey)
                    ->where('status', ConnectedRealmsJobContract::STATUS_ACTIVE)
                    ->lockForUpdate()
                    ->first();

                if ($contract === null) {
                    throw ValidationException::withMessages([
                        'job' => "{$job['label']} must be accepted before it can be completed.",
                    ]);
                }

                if ($contract->progress_quantity < $contract->required_quantity) {
                    throw ValidationException::withMessages([
                        'job' => "{$job['label']} needs {$contract->required_quantity} qualifying progress before completion.",
                    ]);
                }
            }

            $requirementKeys = collect($job['requirements'])->pluck('item_key')->all();
            $stacks = ConnectedRealmsInventoryStack::query()
                ->where('player_id', $player->id)
                ->whereIn('item_key', $requirementKeys)
                ->lockForUpdate()
                ->get()
                ->keyBy('item_key');

            foreach ($job['requirements'] as $requirement) {
                $stack = $stacks->get($requirement['item_key']);

                if ($stack === null || $stack->quantity < $requirement['quantity']) {
                    throw ValidationException::withMessages([
                        'job' => "You need {$requirement['quantity']} {$requirement['item_name']} for that job.",
                    ]);
                }
            }

            foreach ($job['requirements'] as $requirement) {
                $stack = $stacks->get($requirement['item_key']);
                $stack->quantity -= $requirement['quantity'];

                if ($stack->quantity <= 0) {
                    $stack->delete();

                    continue;
                }

                $stack->save();
            }

            $delivered = $this->items->enrichMany($job['requirements']);

            $player->forceFill([
                'gold' => $player->gold + $job['gold'],
            ])->save();

            $skillProgress = $this->players->skillProgressPayload(
                $this->players->awardSkillExperience($player, $job['skill'], $job['experience']),
            );

            $completion = ConnectedRealmsJobCompletion::create([
                'player_id' => $player->id,
                'job_key' => $jobKey,
                'job_name' => $job['label'],
                'category' => $job['category'],
                'items_delivered' => $delivered,
                'rewards' => $job['rewards'],
                'experience_awarded' => $job['experience'],
                'gold_awarded' => $job['gold'],
            ]);

            if ($contract instanceof ConnectedRealmsJobContract) {
                $contract->forceFill([
                    'status' => ConnectedRealmsJobContract::STATUS_COMPLETED,
                    'completed_at' => now(),
                ])->save();
            }

            return [
                'type' => 'job',
                'id' => $completion->id,
                'job_key' => $jobKey,
                'label' => $job['label'],
                'category' => $job['category'],
                'skill' => $job['skill'],
                'skill_label' => $skillProgress['skill_label'],
                'skill_level' => $skillProgress['level'],
                'skill_experience' => $skillProgress['experience'],
                'next_level_experience' => $skillProgress['next_level_experience'],
                'skill_progress' => $skillProgress,
                'items_delivered' => $delivered,
                'rewards' => $job['rewards'],
                'experience_awarded' => $job['experience'],
                'gold_awarded' => $job['gold'],
                'remaining_completions' => $this->remainingCompletions($player, $jobKey, $job),
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function recordItemProgress(ConnectedRealmsPlayer $player, string $skill, string $objectiveType, array $items, int $tierLevel): void
    {
        $itemQuantities = collect($items)
            ->mapWithKeys(fn (array $item): array => [(string) $item['item_key'] => (int) ($item['quantity'] ?? 0)])
            ->filter(fn (int $quantity): bool => $quantity > 0)
            ->all();

        if ($itemQuantities === []) {
            return;
        }

        $contracts = ConnectedRealmsJobContract::query()
            ->where('player_id', $player->id)
            ->where('skill', $skill)
            ->where('objective_type', $objectiveType)
            ->where('status', ConnectedRealmsJobContract::STATUS_ACTIVE)
            ->lockForUpdate()
            ->get();

        foreach ($contracts as $contract) {
            $objective = $contract->objective ?? [];

            if ((int) ($objective['tier_level'] ?? 0) !== $tierLevel) {
                continue;
            }

            $itemKey = (string) ($objective['item_key'] ?? '');
            $quantity = (int) ($itemQuantities[$itemKey] ?? 0);

            if ($quantity < 1) {
                continue;
            }

            $contract->forceFill([
                'progress_quantity' => min($contract->required_quantity, $contract->progress_quantity + $quantity),
            ])->save();
        }
    }

    public function recordMenuProgress(ConnectedRealmsPlayer $player, string $skill, int $tierLevel): void
    {
        $contracts = ConnectedRealmsJobContract::query()
            ->where('player_id', $player->id)
            ->where('skill', $skill)
            ->where('status', ConnectedRealmsJobContract::STATUS_ACTIVE)
            ->whereIn('objective_type', [
                'menu_action_count',
                'menu_run_count',
                'faction_request_count',
                'leadership_operation_count',
                'trade_operation_count',
            ])
            ->lockForUpdate()
            ->get();

        foreach ($contracts as $contract) {
            $objective = $contract->objective ?? [];

            if ((int) ($objective['tier_level'] ?? 0) !== $tierLevel) {
                continue;
            }

            $contract->forceFill([
                'progress_quantity' => min($contract->required_quantity, $contract->progress_quantity + 1),
            ])->save();
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function jobs(): array
    {
        if (self::$jobCache !== null) {
            return self::$jobCache;
        }

        self::$jobCache = self::normalizeRequiredLevels(
            app(ConnectedRealmsContentService::class)->apply('job_contracts', self::baseJobs()),
        );

        return self::$jobCache;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function jobForKey(string $jobKey): ?array
    {
        if (self::$jobCache !== null) {
            return self::$jobCache[$jobKey] ?? null;
        }

        $job = app(ConnectedRealmsContentService::class)->definitionFor(
            'job_contracts',
            $jobKey,
            self::baseJobForKey($jobKey),
        );

        if ($job === null) {
            return null;
        }

        return self::normalizeRequiredLevels([$jobKey => $job])[$jobKey];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function baseJobForKey(string $jobKey): ?array
    {
        $job = CoreJobContractCatalog::contractForKey($jobKey);

        if ($job === null) {
            return null;
        }

        return self::boundedJob(self::normalizeRequiredLevels([$jobKey => $job])[$jobKey]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function baseJobs(): array
    {
        return collect(self::normalizeRequiredLevels(CoreJobContractCatalog::contracts()))
            ->map(fn (array $job): array => self::boundedJob($job))
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $jobs
     * @return array<string, array<string, mixed>>
     */
    private static function normalizeRequiredLevels(array $jobs): array
    {
        return collect($jobs)
            ->map(fn (array $job): array => [
                ...$job,
                'required_level' => EvergatherTierCatalog::nextTierLevelFor((int) ($job['required_level'] ?? 1)),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    private static function boundedJob(array $job): array
    {
        return [
            ...$job,
            'rotation' => $job['rotation'] ?? self::DEFAULT_ROTATION,
            'completion_cap' => (int) ($job['completion_cap'] ?? self::DEFAULT_COMPLETION_CAP),
        ];
    }

    /**
     * @param  list<string>  $jobKeys
     * @return array<string, int>
     */
    private function completionCountsForJobs(ConnectedRealmsPlayer $player, array $jobKeys): array
    {
        if ($jobKeys === []) {
            return [];
        }

        return ConnectedRealmsJobCompletion::query()
            ->where('player_id', $player->id)
            ->whereIn('job_key', $jobKeys)
            ->where('created_at', '>=', now()->startOfDay())
            ->selectRaw('job_key, count(*) as completion_count')
            ->groupBy('job_key')
            ->pluck('completion_count', 'job_key')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function ensureDemandAvailable(ConnectedRealmsPlayer $player, string $jobKey, array $job): void
    {
        if ($this->remainingCompletions($player, $jobKey, $job) > 0) {
            return;
        }

        throw ValidationException::withMessages([
            'job' => "{$job['label']} has no demand remaining in this {$job['rotation']} rotation.",
        ]);
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function remainingCompletions(ConnectedRealmsPlayer $player, string $jobKey, array $job): int
    {
        $completed = (int) ConnectedRealmsJobCompletion::query()
            ->where('player_id', $player->id)
            ->where('job_key', $jobKey)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        return max(0, $this->completionCap($job) - $completed);
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function completionCap(array $job): int
    {
        return max(1, (int) ($job['completion_cap'] ?? self::DEFAULT_COMPLETION_CAP));
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function isCoreContract(array $job): bool
    {
        return ($job['demand_channel'] ?? null) === 'core_profession_contract';
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function progressRequiredFor(array $job): int
    {
        $objective = $job['objective'] ?? [];

        if (isset($objective['target_count'])) {
            return max(1, (int) $objective['target_count']);
        }

        return max(1, (int) collect($job['requirements'] ?? [])->sum('quantity'));
    }
}
