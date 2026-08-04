<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsInventoryStack;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsMarketListing;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class ConnectedRealmsSimulationService
{
    public function __construct(
        private ConnectedRealmsPlayerService $players,
        private GatheringActionService $gathering,
        private CraftingService $crafting,
        private JobContractService $jobs,
        private ExpeditionService $expeditions,
        private MarketplaceService $marketplace,
        private ShopService $shop,
        private ItemCatalogService $items,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function simulateRicoAndKye(float $hours = 2.0, bool $fresh = true): array
    {
        $startedAt = now();
        $endsAt = $startedAt->copy()->addSeconds((int) round($hours * 3600));
        $previousCooldownOverride = config('connected_realms.action_cooldown_seconds');
        $summaries = [];

        config(['connected_realms.action_cooldown_seconds' => null]);
        Carbon::setTestNow($startedAt);

        try {
            foreach ($this->personas() as $key => $persona) {
                $summaries[$key] = $this->simulatePersona($persona, $startedAt->copy(), $endsAt->copy(), $fresh);
            }
        } finally {
            config(['connected_realms.action_cooldown_seconds' => $previousCooldownOverride]);
            Carbon::setTestNow();
        }

        return [
            'hours' => $hours,
            'started_at' => $startedAt->toIso8601String(),
            'ended_at' => $endsAt->toIso8601String(),
            'personas' => $summaries,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function personas(): array
    {
        return [
            'rico' => [
                'name' => 'Rico',
                'email' => 'rico@evergather.local',
                'title' => 'Sunfield Line Cook',
                'home_region' => 'moonwake_coast',
                'appearance' => ['body_style' => 'balanced', 'palette' => 'verdant', 'hair_style' => 'short', 'outfit' => 'artisan'],
                'starting_gold' => 520,
                'tool_offers' => ['amberbound_tidehook_rod', 'amberbound_seedwake_cultivator'],
                'actions' => ['fish', 'farm', 'tidal_pools', 'seed_sorting', 'creek_traps', 'bean_rows', 'shoal_cast', 'herb_bed'],
                'craft_skills' => ['cooking'],
                'job_skills' => ['cooking'],
                'expedition_skills' => [],
                'starter_items' => [],
            ],
            'kye' => [
                'name' => 'Kye',
                'email' => 'kye@evergather.local',
                'title' => 'Redfang Scout',
                'home_region' => 'glimmerfen_trail',
                'appearance' => ['body_style' => 'compact', 'palette' => 'ember', 'hair_style' => 'braided', 'outfit' => 'delver'],
                'starting_gold' => 560,
                'tool_offers' => ['amberbound_snarefang_trap_kit', 'amberbound_mosskeeper_satchel'],
                'actions' => ['hunt', 'forage', 'snare_line', 'wild_seed_bed', 'burrow_watch', 'mushroom_ring', 'trail_tracking', 'root_patch'],
                'craft_skills' => [],
                'job_skills' => ['combat', 'slayer'],
                'expedition_skills' => ['combat', 'slayer'],
                'starter_items' => [
                    ['item_key' => 'iron_knife', 'item_name' => 'Iron Knife', 'rarity' => 'common', 'quantity' => 2],
                    ['item_key' => 'trail_bow', 'item_name' => 'Trail Bow', 'rarity' => 'common', 'quantity' => 2],
                    ['item_key' => 'hunter_ration', 'item_name' => 'Hunter Ration', 'rarity' => 'common', 'quantity' => 3],
                    ['item_key' => 'snare_trigger', 'item_name' => 'Snare Trigger', 'rarity' => 'uncommon', 'quantity' => 2],
                    ['item_key' => 'brine_soup', 'item_name' => 'Brine Soup', 'rarity' => 'common', 'quantity' => 2],
                    ['item_key' => 'field_repair_kit', 'item_name' => 'Field Repair Kit', 'rarity' => 'common', 'quantity' => 1],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $persona
     * @return array<string, mixed>
     */
    private function simulatePersona(array $persona, Carbon $startedAt, Carbon $endsAt, bool $fresh): array
    {
        $user = $this->userForPersona($persona);

        if ($fresh) {
            ConnectedRealmsPlayer::query()
                ->where('user_id', $user->id)
                ->delete();
        }

        $player = $this->players->playerForUser($user);
        $player->forceFill([
            'display_name' => $persona['name'],
            'title' => $persona['title'],
            'home_region' => $persona['home_region'],
            'appearance' => $persona['appearance'],
            'gold' => max((int) $player->gold, (int) $persona['starting_gold']),
            'last_action_at' => null,
            'next_action_at' => null,
        ])->save();

        $this->grantStarterItems($player, $persona['starter_items']);
        $toolsBought = $this->buyTools($user, $persona['tool_offers']);
        $actionCount = $this->runActionWindow($user, $persona, $startedAt, $endsAt);
        $listingSummary = $this->listSpoils($user);
        $player = ConnectedRealmsPlayer::query()
            ->where('user_id', $user->id)
            ->with(['skills', 'equipmentSlots', 'inventoryStacks'])
            ->firstOrFail();

        return [
            'user_id' => $user->id,
            'player_id' => $player->id,
            'display_name' => $player->display_name,
            'gold' => $player->gold,
            'actions_completed' => $actionCount,
            'tools_bought' => $toolsBought,
            'active_listings' => $listingSummary,
            'skills' => $player->skills
                ->sortBy('skill')
                ->map(fn ($skill): array => [
                    'skill' => $skill->skill,
                    'level' => $skill->level,
                    'experience' => $skill->experience,
                ])
                ->values()
                ->all(),
            'equipped_tools' => $player->equipmentSlots
                ->sortBy('slot')
                ->map(fn ($slot): array => [
                    'slot' => $slot->slot,
                    'item_name' => $slot->item_name,
                    'rarity' => $slot->rarity,
                ])
                ->values()
                ->all(),
            'remaining_inventory_stacks' => $player->inventoryStacks->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $persona
     */
    private function userForPersona(array $persona): User
    {
        $user = User::query()->firstOrNew(['email' => $persona['email']]);
        $user->fill([
            'name' => $persona['name'],
            'password' => Hash::make('connected-realms-sim'),
        ]);
        $user->email_verified_at ??= now();
        $user->save();

        Role::findOrCreate(User::ROLE_CONNECTED_REALMS, 'web');
        $user->assignRole(User::ROLE_CONNECTED_REALMS);

        return $user;
    }

    /**
     * @param  list<array{item_key: string, item_name: string, rarity: string, quantity: int}>  $items
     */
    private function grantStarterItems(ConnectedRealmsPlayer $player, array $items): void
    {
        foreach ($items as $item) {
            $this->addInventory($player, $item['item_key'], $item['item_name'], $item['rarity'], $item['quantity']);
        }
    }

    /**
     * @param  list<string>  $offerKeys
     * @return list<string>
     */
    private function buyTools(User $user, array $offerKeys): array
    {
        $bought = [];

        foreach ($offerKeys as $offerKey) {
            try {
                $result = $this->shop->buy($user, $offerKey);
                $bought[] = $result['label'];
            } catch (ValidationException) {
                continue;
            }
        }

        return $bought;
    }

    /**
     * @param  array<string, mixed>  $persona
     */
    private function runActionWindow(User $user, array $persona, Carbon $startedAt, Carbon $endsAt): int
    {
        $cursor = $startedAt->copy();
        $actionCount = 0;
        $actionIndex = 0;

        while ($cursor->lessThan($endsAt)) {
            Carbon::setTestNow($cursor);

            $player = ConnectedRealmsPlayer::query()
                ->where('user_id', $user->id)
                ->firstOrFail();
            $actionKey = $this->nextUnlockedActionKey($player, $persona['actions'], $actionIndex);

            if ($actionKey === null) {
                break;
            }

            $result = $this->gathering->perform($user, $actionKey, 'simulation');
            $actionCount++;
            $actionIndex++;

            $this->runCrafts($user, $persona['craft_skills']);
            $this->runJobs($user, $persona['job_skills']);
            $this->runExpeditions($user, $persona['expedition_skills']);

            $cursor = Carbon::parse($result['next_action_at']);
        }

        return $actionCount;
    }

    /**
     * @param  list<string>  $preferredActions
     */
    private function nextUnlockedActionKey(ConnectedRealmsPlayer $player, array $preferredActions, int $offset): ?string
    {
        $actions = collect($this->gathering->availableActionsFor($player))
            ->where('is_unlocked', true)
            ->keyBy('key');
        $count = count($preferredActions);

        for ($index = 0; $index < $count; $index++) {
            $actionKey = $preferredActions[($offset + $index) % $count];

            if ($actions->has($actionKey)) {
                return $actionKey;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $skillKeys
     */
    private function runCrafts(User $user, array $skillKeys): void
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $player = ConnectedRealmsPlayer::query()
                ->where('user_id', $user->id)
                ->with(['skills', 'inventoryStacks'])
                ->firstOrFail();
            $recipe = collect($this->crafting->availableRecipesFor($player))
                ->whereIn('skill', $skillKeys)
                ->where('can_craft', true)
                ->sortByDesc('required_level')
                ->first();

            if ($recipe === null) {
                return;
            }

            $this->crafting->craft($user, $recipe['key']);
        }
    }

    /**
     * @param  list<string>  $skillKeys
     */
    private function runJobs(User $user, array $skillKeys): void
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $player = ConnectedRealmsPlayer::query()
                ->where('user_id', $user->id)
                ->with(['skills', 'inventoryStacks'])
                ->firstOrFail();
            $job = collect($this->jobs->availableJobsFor($player))
                ->whereIn('skill', $skillKeys)
                ->where('can_complete', true)
                ->sortByDesc('required_level')
                ->first();

            if ($job === null) {
                return;
            }

            $this->jobs->complete($user, $job['key']);
        }
    }

    /**
     * @param  list<string>  $skillKeys
     */
    private function runExpeditions(User $user, array $skillKeys): void
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $player = ConnectedRealmsPlayer::query()
                ->where('user_id', $user->id)
                ->with(['skills', 'inventoryStacks'])
                ->firstOrFail();
            $expedition = collect($this->expeditions->availableExpeditionsFor($player))
                ->whereIn('skill', $skillKeys)
                ->where('can_start', true)
                ->sortByDesc('required_level')
                ->first();

            if ($expedition === null) {
                return;
            }

            $this->expeditions->run($user, $expedition['key']);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listSpoils(User $user): array
    {
        $player = ConnectedRealmsPlayer::query()
            ->where('user_id', $user->id)
            ->with('inventoryStacks')
            ->firstOrFail();
        $listed = [];

        foreach ($player->inventoryStacks->sortByDesc('quantity')->take(12) as $stack) {
            if ($stack->quantity < 1) {
                continue;
            }

            $item = $this->items->enrich([
                'item_key' => $stack->item_key,
                'item_name' => $stack->item_name,
                'rarity' => $stack->rarity,
                'quantity' => $stack->quantity,
            ]);
            $quantity = max(1, min((int) $stack->quantity, (int) floor($stack->quantity / 2) ?: 1, 8));
            $unitPrice = min(
                (int) $item['market_ceiling_price'],
                max((int) $item['market_floor_price'], (int) ceil($item['vendor_value'] * 1.15)),
            );

            try {
                $listing = $this->marketplace->createListing($user, ConnectedRealmsMarketListing::TYPE_ITEM, $stack->item_key, $quantity, $unitPrice);
            } catch (ValidationException) {
                continue;
            }

            $listed[] = [
                'item_name' => $listing['item_name'],
                'quantity' => $listing['quantity'],
                'unit_price' => $listing['unit_price'],
                'total_price' => $listing['total_price'],
            ];
        }

        return $listed;
    }

    private function addInventory(ConnectedRealmsPlayer $player, string $itemKey, string $itemName, string $rarity, int $quantity): void
    {
        $stack = ConnectedRealmsInventoryStack::query()->firstOrNew([
            'player_id' => $player->id,
            'item_key' => $itemKey,
        ]);

        $stack->fill([
            'item_name' => $itemName,
            'rarity' => $rarity,
            'quantity' => (int) $stack->quantity + $quantity,
        ]);
        $stack->save();
    }
}
