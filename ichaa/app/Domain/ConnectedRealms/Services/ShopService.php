<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsEquipmentSlot;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsInventoryStack;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShopService
{
    public function __construct(private ConnectedRealmsPlayerService $players, private ItemCatalogService $items) {}

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $offerCache = null;

    /**
     * @var array<string, array{label: string, noun: string, slot_skill: string}>
     */
    private const TOOL_FAMILIES = [
        'fishing' => ['label' => 'Fishing', 'noun' => 'Rod', 'slot_skill' => 'fishing'],
        'mining' => ['label' => 'Mining', 'noun' => 'Pickaxe', 'slot_skill' => 'mining'],
        'woodcutting' => ['label' => 'Woodcutting', 'noun' => 'Hatchet', 'slot_skill' => 'woodcutting'],
        'foraging' => ['label' => 'Foraging', 'noun' => 'Satchel', 'slot_skill' => 'foraging'],
        'hunting' => ['label' => 'Hunting', 'noun' => 'Trap Kit', 'slot_skill' => 'hunting'],
        'farming' => ['label' => 'Farming', 'noun' => 'Cultivator', 'slot_skill' => 'farming'],
        'excavation' => ['label' => 'Excavation', 'noun' => 'Survey Trowel', 'slot_skill' => 'excavation'],
    ];

    /**
     * @var list<array{tier: string, prefix: string, price: int, required_level: int, experience: int, yield: int}>
     */
    private const TOOL_TIERS = [
        ['tier' => 'uncommon', 'prefix' => 'Apprentice', 'price' => 80, 'required_level' => 1, 'experience' => 9, 'yield' => 2],
        ['tier' => 'rare', 'prefix' => 'Guild', 'price' => 260, 'required_level' => 15, 'experience' => 16, 'yield' => 3],
        ['tier' => 'rare', 'prefix' => 'Artisan', 'price' => 430, 'required_level' => 25, 'experience' => 21, 'yield' => 4],
        ['tier' => 'epic', 'prefix' => 'Runed', 'price' => 620, 'required_level' => 35, 'experience' => 24, 'yield' => 4],
        ['tier' => 'epic', 'prefix' => 'Masterwork', 'price' => 760, 'required_level' => 40, 'experience' => 25, 'yield' => 4],
        ['tier' => 'epic', 'prefix' => 'Crown', 'price' => 980, 'required_level' => 45, 'experience' => 29, 'yield' => 5],
        ['tier' => 'legendary', 'prefix' => 'Realmforged', 'price' => 1900, 'required_level' => 70, 'experience' => 38, 'yield' => 6],
        ['tier' => 'legendary', 'prefix' => 'Mythic', 'price' => 3400, 'required_level' => 85, 'experience' => 52, 'yield' => 8],
        ['tier' => 'legendary', 'prefix' => 'Ascendant', 'price' => 5200, 'required_level' => 100, 'experience' => 70, 'yield' => 11],
    ];

    /**
     * @var array<string, array{label: string, category: string, price: int, required_level: int, item_key: string, item_name: string, rarity: string, quantity: int}>
     */
    private const MATERIAL_OFFERS = [
        'bundle_fishers_icebox' => ['label' => "Fisher's Icebox", 'category' => 'Materials', 'price' => 28, 'required_level' => 1, 'item_key' => 'river_minnow', 'item_name' => 'River Minnow', 'rarity' => 'common', 'quantity' => 6],
        'bundle_ore_crate' => ['label' => 'Ore Crate', 'category' => 'Materials', 'price' => 35, 'required_level' => 1, 'item_key' => 'iron_ore', 'item_name' => 'Iron Ore', 'rarity' => 'common', 'quantity' => 6],
        'bundle_lumber_cart' => ['label' => 'Lumber Cart', 'category' => 'Materials', 'price' => 32, 'required_level' => 1, 'item_key' => 'ashwood_log', 'item_name' => 'Ashwood Log', 'rarity' => 'common', 'quantity' => 6],
        'bundle_herbalist_roll' => ['label' => 'Herbalist Roll', 'category' => 'Materials', 'price' => 34, 'required_level' => 1, 'item_key' => 'mooncap_mushroom', 'item_name' => 'Mooncap Mushroom', 'rarity' => 'common', 'quantity' => 6],
        'bundle_hide_pack' => ['label' => 'Hide Pack', 'category' => 'Materials', 'price' => 42, 'required_level' => 1, 'item_key' => 'soft_hide', 'item_name' => 'Soft Hide', 'rarity' => 'common', 'quantity' => 5],
        'bundle_seed_sack' => ['label' => 'Seed Sack', 'category' => 'Materials', 'price' => 34, 'required_level' => 1, 'item_key' => 'sunfield_grain', 'item_name' => 'Sunfield Grain', 'rarity' => 'common', 'quantity' => 7],
        'bundle_relic_case' => ['label' => 'Relic Case', 'category' => 'Materials', 'price' => 55, 'required_level' => 1, 'item_key' => 'relic_fragment', 'item_name' => 'Relic Fragment', 'rarity' => 'common', 'quantity' => 5],
        'bundle_guild_commission' => ['label' => 'Guild Commission Kit', 'category' => 'Commissions', 'price' => 120, 'required_level' => 10, 'item_key' => 'trade_manifest', 'item_name' => 'Trade Manifest', 'rarity' => 'common', 'quantity' => 1],
        'bundle_expedition_cache' => ['label' => 'Expedition Cache', 'category' => 'Commissions', 'price' => 180, 'required_level' => 20, 'item_key' => 'route_map', 'item_name' => 'Route Map', 'rarity' => 'common', 'quantity' => 1],
    ];

    /**
     * @return list<string>
     */
    public static function offerKeys(): array
    {
        return array_keys(self::offers());
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotFor(ConnectedRealmsPlayer $player): array
    {
        $equipmentBySkill = ($player->relationLoaded('equipmentSlots')
            ? $player->equipmentSlots
            : $player->equipmentSlots()->with('tool')->orderBy('slot')->get())
            ->keyBy(fn (ConnectedRealmsEquipmentSlot $slot): string => (string) ($slot->bonuses['skill'] ?? $slot->slot));

        return [
            'offers' => collect(self::offers())
                ->map(function (array $offer, string $key) use ($player, $equipmentBySkill): array {
                    $skillLevel = $offer['skill'] === null ? 1 : $this->players->currentSkillLevel($player, $offer['skill']);
                    $currentTool = $offer['kind'] === 'tool'
                        ? $equipmentBySkill->get($offer['skill'])
                        : null;
                    $isEquipped = $currentTool?->item_key === $offer['item_key'];
                    $isDowngrade = $currentTool !== null
                        && ! $isEquipped
                        && $this->toolPower($currentTool->bonuses ?? []) >= $this->toolPower($offer['bonuses'] ?? []);
                    $isPurchasableTool = ! $isEquipped && ! $isDowngrade;

                    return $this->items->enrich([
                        'key' => $key,
                        ...$offer,
                        'skill_label' => $offer['skill'] === null ? null : str($offer['skill'])->headline()->toString(),
                        'skill_level' => $skillLevel,
                        'is_unlocked' => $skillLevel >= $offer['required_level'],
                        'can_buy' => $player->gold >= $offer['price'] && $skillLevel >= $offer['required_level'] && ($offer['kind'] !== 'tool' || $isPurchasableTool),
                        'is_equipped' => $isEquipped,
                        'is_downgrade' => $isDowngrade,
                        'current_tool' => $this->players->toolPayload($currentTool),
                        'ownership_status' => $this->ownershipStatus($offer, $currentTool, $isEquipped, $isDowngrade),
                    ]);
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buy(User $user, string $offerKey): array
    {
        $offer = self::offers()[$offerKey] ?? null;

        if ($offer === null) {
            throw ValidationException::withMessages([
                'offer' => 'That shop offer is not available.',
            ]);
        }

        return DB::transaction(function () use ($user, $offerKey, $offer): array {
            $player = $this->players->playerForUser($user);
            $player = ConnectedRealmsPlayer::query()
                ->whereKey($player->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($player->gold < $offer['price']) {
                throw ValidationException::withMessages([
                    'offer' => 'You do not have enough gold for that offer.',
                ]);
            }

            if ($offer['skill'] !== null && $this->players->currentSkillLevel($player, $offer['skill']) < $offer['required_level']) {
                throw ValidationException::withMessages([
                    'offer' => "You need level {$offer['required_level']} {$offer['skill_label']} for that offer.",
                ]);
            }

            if ($offer['kind'] === 'tool') {
                $currentTool = $this->players->equipmentForSkill($player, $offer['skill']);

                if ($currentTool?->item_key === $offer['item_key']) {
                    throw ValidationException::withMessages([
                        'offer' => "{$offer['item_name']} is already equipped.",
                    ]);
                }

                if ($currentTool !== null && $this->toolPower($currentTool->bonuses ?? []) >= $this->toolPower($offer['bonuses'] ?? [])) {
                    throw ValidationException::withMessages([
                        'offer' => "{$currentTool->item_name} is already as strong or stronger.",
                    ]);
                }
            }

            $player->forceFill([
                'gold' => $player->gold - $offer['price'],
            ])->save();

            if ($offer['kind'] === 'tool') {
                $tool = $this->players->equipTool(
                    $player,
                    $offer['skill'],
                    $offer['item_key'],
                    $offer['item_name'],
                    $offer['rarity'],
                    $offer['durability'],
                    $offer['bonuses'],
                    'bought',
                    null,
                    $offer['required_level'],
                );

                return [
                    'type' => 'shop',
                    'offer_key' => $offerKey,
                    'label' => $offer['label'],
                    'gold_spent' => $offer['price'],
                    'tool' => $this->players->toolPayload($tool),
                ];
            }

            $stack = ConnectedRealmsInventoryStack::query()->firstOrNew([
                'player_id' => $player->id,
                'item_key' => $offer['item_key'],
            ]);
            $stack->fill([
                'item_name' => $offer['item_name'],
                'rarity' => $offer['rarity'],
                'quantity' => (int) $stack->quantity + $offer['quantity'],
            ]);
            $stack->save();

            $item = $this->items->enrich([
                'item_key' => $offer['item_key'],
                'item_name' => $offer['item_name'],
                'rarity' => $offer['rarity'],
                'quantity' => $offer['quantity'],
            ]);

            return [
                'type' => 'shop',
                'offer_key' => $offerKey,
                'label' => $offer['label'],
                'gold_spent' => $offer['price'],
                'items_awarded' => [$item],
            ];
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function offers(): array
    {
        if (self::$offerCache !== null) {
            return self::$offerCache;
        }

        $offers = [];

        foreach ((new ToolCatalogService)->families() as $skill => $family) {
            foreach (self::TOOL_TIERS as $tier) {
                $itemName = "{$tier['prefix']} {$family['noun']}";
                $key = str($itemName)->slug('_')->toString();

                $offers[$key] = [
                    'label' => $itemName,
                    'kind' => 'tool',
                    'category' => "{$family['label']} Tools",
                    'skill' => $skill,
                    'skill_label' => $family['label'],
                    'price' => $tier['price'],
                    'required_level' => $tier['required_level'],
                    'item_key' => $key,
                    'item_name' => $itemName,
                    'rarity' => $tier['tier'],
                    'quantity' => 1,
                    'durability' => 100,
                    'bonuses' => [
                        'experience' => $tier['experience'],
                        'yield' => $tier['yield'],
                    ],
                ];
            }
        }

        foreach (self::MATERIAL_OFFERS as $key => $offer) {
            $offers[$key] = [
                ...$offer,
                'kind' => 'item',
                'skill' => null,
                'skill_label' => null,
                'durability' => null,
                'bonuses' => null,
            ];
        }

        self::$offerCache = $offers;

        return self::$offerCache;
    }

    /**
     * @param  array<string, mixed>  $bonuses
     */
    private function toolPower(array $bonuses): int
    {
        return ((int) ($bonuses['experience'] ?? 0) * 10) + (int) ($bonuses['yield'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function ownershipStatus(array $offer, mixed $currentTool, bool $isEquipped, bool $isDowngrade): string
    {
        if ($offer['kind'] !== 'tool') {
            return 'Consumable purchase';
        }

        if ($isEquipped) {
            return 'Already equipped';
        }

        if ($isDowngrade && $currentTool !== null) {
            return "{$currentTool->item_name} is stronger";
        }

        if ($currentTool !== null) {
            return "Upgrade over {$currentTool->item_name}";
        }

        return 'New tool';
    }
}
