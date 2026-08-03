<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsInventoryStack;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsMarketListing;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsMarketTransaction;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsTool;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsVendorSale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketplaceService
{
    public function __construct(private ConnectedRealmsPlayerService $players, private ItemCatalogService $items) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshotFor(ConnectedRealmsPlayer $player): array
    {
        $activeListings = ConnectedRealmsMarketListing::query()
            ->with(['seller:id,display_name', 'tool'])
            ->where('status', ConnectedRealmsMarketListing::STATUS_ACTIVE)
            ->latest()
            ->limit(60)
            ->get()
            ->map(fn (ConnectedRealmsMarketListing $listing): array => $this->listingPayload($listing, $player))
            ->values()
            ->all();
        $recentTransactions = ConnectedRealmsMarketTransaction::query()
            ->with(['seller:id,display_name', 'buyer:id,display_name'])
            ->latest()
            ->limit(40)
            ->get()
            ->map(fn (ConnectedRealmsMarketTransaction $transaction): array => $this->transactionPayload($transaction))
            ->values()
            ->all();

        return [
            'npc_vendor' => [
                'key' => 'ledger_steward',
                'name' => 'Ledger Steward',
                'description' => 'Buys tradeable inventory at the market floor so player listings cannot be posted below buyback value.',
            ],
            'sellable_inventory' => $this->sellableInventoryFor($player),
            'sellable_tools' => $this->sellableToolsFor($player),
            'active_listings' => $activeListings,
            'my_listings' => ConnectedRealmsMarketListing::query()
                ->with('tool')
                ->where('seller_player_id', $player->id)
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (ConnectedRealmsMarketListing $listing): array => $this->listingPayload($listing, $player))
                ->values()
                ->all(),
            'recent_transactions' => $recentTransactions,
            'market_board' => $this->marketBoard($activeListings, $recentTransactions),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createListing(User $user, string $listingType, string $itemKey, int $quantity, int $unitPrice, ?int $toolId = null): array
    {
        return DB::transaction(function () use ($user, $listingType, $itemKey, $quantity, $unitPrice, $toolId): array {
            $player = $this->lockedPlayerFor($user);

            if ($listingType === ConnectedRealmsMarketListing::TYPE_TOOL) {
                return $this->createToolListing($player, $toolId, $unitPrice);
            }

            $stack = ConnectedRealmsInventoryStack::query()
                ->where('player_id', $player->id)
                ->where('item_key', $itemKey)
                ->lockForUpdate()
                ->first();

            if ($stack === null || $stack->quantity < $quantity) {
                throw ValidationException::withMessages([
                    'item_key' => 'You do not have enough of that item to list.',
                ]);
            }

            $item = $this->items->enrich([
                'item_key' => $stack->item_key,
                'item_name' => $stack->item_name,
                'rarity' => $stack->rarity,
                'quantity' => $quantity,
            ]);
            $marketFloor = (int) $item['market_floor_price'];
            $marketCeiling = (int) $item['market_ceiling_price'];

            if ($unitPrice < $marketFloor || $unitPrice > $marketCeiling) {
                throw ValidationException::withMessages([
                    'unit_price' => "{$item['item_name']} must be listed between {$marketFloor} and {$marketCeiling} gold each.",
                ]);
            }

            $stack->quantity -= $quantity;

            if ($stack->quantity <= 0) {
                $stack->delete();
            } else {
                $stack->save();
            }

            $listing = ConnectedRealmsMarketListing::create([
                'seller_player_id' => $player->id,
                'listing_type' => ConnectedRealmsMarketListing::TYPE_ITEM,
                'item_key' => $stack->item_key,
                'item_name' => $stack->item_name,
                'rarity' => $stack->rarity,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'status' => ConnectedRealmsMarketListing::STATUS_ACTIVE,
            ]);

            return $this->items->enrich([
                'type' => 'market_listing',
                'label' => $listing->item_name,
                'item_key' => $listing->item_key,
                'item_name' => $listing->item_name,
                'rarity' => $listing->rarity,
                'listing_id' => $listing->id,
                'quantity' => $listing->quantity,
                'unit_price' => $listing->unit_price,
                'total_price' => $listing->quantity * $listing->unit_price,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function sellToNpc(User $user, string $itemKey, int $quantity): array
    {
        return DB::transaction(function () use ($user, $itemKey, $quantity): array {
            $player = $this->lockedPlayerFor($user);
            $stack = ConnectedRealmsInventoryStack::query()
                ->where('player_id', $player->id)
                ->where('item_key', $itemKey)
                ->lockForUpdate()
                ->first();

            if ($stack === null || $stack->quantity < $quantity) {
                throw ValidationException::withMessages([
                    'item_key' => 'You do not have enough of that item to sell.',
                ]);
            }

            $item = $this->items->enrich([
                'item_key' => $stack->item_key,
                'item_name' => $stack->item_name,
                'rarity' => $stack->rarity,
                'quantity' => $quantity,
            ]);
            $unitPrice = (int) $item['npc_buy_price'];
            $totalPrice = $unitPrice * $quantity;

            $stack->quantity -= $quantity;

            if ($stack->quantity <= 0) {
                $stack->delete();
            } else {
                $stack->save();
            }

            $player->forceFill([
                'gold' => $player->gold + $totalPrice,
            ])->save();

            $sale = ConnectedRealmsVendorSale::create([
                'player_id' => $player->id,
                'vendor_key' => 'ledger_steward',
                'vendor_name' => 'Ledger Steward',
                'item_key' => $item['item_key'],
                'item_name' => $item['item_name'],
                'rarity' => $item['rarity'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'item_snapshot' => $item,
            ]);

            return [
                'type' => 'npc_sale',
                'id' => $sale->id,
                'label' => $item['item_name'],
                'vendor_name' => 'Ledger Steward',
                'item_key' => $item['item_key'],
                'item_name' => $item['item_name'],
                'rarity' => $item['rarity'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'gold_awarded' => $totalPrice,
                'items_delivered' => [$item],
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function buyListing(User $user, int $listingId): array
    {
        return DB::transaction(function () use ($user, $listingId): array {
            $buyer = $this->lockedPlayerFor($user);
            $listing = ConnectedRealmsMarketListing::query()
                ->whereKey($listingId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($listing->status !== ConnectedRealmsMarketListing::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'listing' => 'That listing is no longer active.',
                ]);
            }

            if ($listing->seller_player_id === $buyer->id) {
                throw ValidationException::withMessages([
                    'listing' => 'You cannot buy your own listing.',
                ]);
            }

            $totalPrice = $listing->quantity * $listing->unit_price;

            if ($buyer->gold < $totalPrice) {
                throw ValidationException::withMessages([
                    'listing' => 'You do not have enough gold for that listing.',
                ]);
            }

            $seller = ConnectedRealmsPlayer::query()
                ->whereKey($listing->seller_player_id)
                ->lockForUpdate()
                ->firstOrFail();

            $buyer->forceFill([
                'gold' => $buyer->gold - $totalPrice,
            ])->save();
            $seller->forceFill([
                'gold' => $seller->gold + $totalPrice,
            ])->save();

            $listing->forceFill([
                'status' => ConnectedRealmsMarketListing::STATUS_SOLD,
                'sold_at' => now(),
            ])->save();

            $toolSnapshot = null;

            if ($listing->listing_type === ConnectedRealmsMarketListing::TYPE_TOOL) {
                $tool = ConnectedRealmsTool::query()
                    ->whereKey($listing->tool_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $tool->forceFill([
                    'player_id' => $buyer->id,
                    'status' => ConnectedRealmsTool::STATUS_INVENTORY,
                ])->save();

                $toolSnapshot = $this->players->toolInstancePayload($tool);
            } else {
                $stack = ConnectedRealmsInventoryStack::query()->firstOrNew([
                    'player_id' => $buyer->id,
                    'item_key' => $listing->item_key,
                ]);
                $stack->fill([
                    'item_name' => $listing->item_name,
                    'rarity' => $listing->rarity,
                    'quantity' => (int) $stack->quantity + $listing->quantity,
                ]);
                $stack->save();
            }

            $transaction = ConnectedRealmsMarketTransaction::create([
                'listing_id' => $listing->id,
                'seller_player_id' => $seller->id,
                'buyer_player_id' => $buyer->id,
                'listing_type' => $listing->listing_type,
                'tool_id' => $listing->tool_id,
                'item_key' => $listing->item_key,
                'item_name' => $listing->item_name,
                'rarity' => $listing->rarity,
                'quantity' => $listing->quantity,
                'unit_price' => $listing->unit_price,
                'total_price' => $totalPrice,
                'tool_snapshot' => $toolSnapshot,
            ]);

            return $this->listingResultPayload([
                'type' => 'market_purchase',
                'label' => $listing->item_name,
                'listing_type' => $listing->listing_type,
                'item_key' => $listing->item_key,
                'item_name' => $listing->item_name,
                'rarity' => $listing->rarity,
                'tool' => $toolSnapshot,
                'transaction_id' => $transaction->id,
                'quantity' => $listing->quantity,
                'total_price' => $totalPrice,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelListing(User $user, int $listingId): array
    {
        return DB::transaction(function () use ($user, $listingId): array {
            $player = $this->lockedPlayerFor($user);
            $listing = ConnectedRealmsMarketListing::query()
                ->whereKey($listingId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($listing->seller_player_id !== $player->id) {
                throw ValidationException::withMessages([
                    'listing' => 'You can only cancel your own listings.',
                ]);
            }

            if ($listing->status !== ConnectedRealmsMarketListing::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'listing' => 'That listing is no longer active.',
                ]);
            }

            if ($listing->listing_type === ConnectedRealmsMarketListing::TYPE_TOOL) {
                ConnectedRealmsTool::query()
                    ->whereKey($listing->tool_id)
                    ->where('player_id', $player->id)
                    ->update(['status' => ConnectedRealmsTool::STATUS_INVENTORY]);
            } else {
                $stack = ConnectedRealmsInventoryStack::query()->firstOrNew([
                    'player_id' => $player->id,
                    'item_key' => $listing->item_key,
                ]);
                $stack->fill([
                    'item_name' => $listing->item_name,
                    'rarity' => $listing->rarity,
                    'quantity' => (int) $stack->quantity + $listing->quantity,
                ]);
                $stack->save();
            }

            $listing->forceFill([
                'status' => ConnectedRealmsMarketListing::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ])->save();

            return $this->listingResultPayload([
                'type' => 'market_cancel',
                'label' => $listing->item_name,
                'listing_type' => $listing->listing_type,
                'item_key' => $listing->item_key,
                'item_name' => $listing->item_name,
                'rarity' => $listing->rarity,
                'tool' => $listing->tool_snapshot,
                'listing_id' => $listing->id,
                'quantity' => $listing->quantity,
            ]);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sellableInventoryFor(ConnectedRealmsPlayer $player): array
    {
        return ($player->relationLoaded('inventoryStacks')
            ? $player->inventoryStacks
            : $player->inventoryStacks()->orderBy('item_name')->get())
            ->filter(fn (ConnectedRealmsInventoryStack $stack): bool => $stack->quantity > 0)
            ->map(fn (ConnectedRealmsInventoryStack $stack): array => $this->items->enrich([
                'item_key' => $stack->item_key,
                'item_name' => $stack->item_name,
                'rarity' => $stack->rarity,
                'quantity' => $stack->quantity,
            ]))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sellableToolsFor(ConnectedRealmsPlayer $player): array
    {
        return $player->tools()
            ->where('status', ConnectedRealmsTool::STATUS_INVENTORY)
            ->where('origin', '!=', 'starter')
            ->orderBy('item_name')
            ->get()
            ->map(fn (ConnectedRealmsTool $tool): array => $this->players->toolInstancePayload($tool))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function listingPayload(ConnectedRealmsMarketListing $listing, ConnectedRealmsPlayer $viewer): array
    {
        $toolPayload = $listing->listing_type === ConnectedRealmsMarketListing::TYPE_TOOL
            ? ($listing->tool_snapshot ?? ($listing->tool === null ? null : $this->players->toolInstancePayload($listing->tool)))
            : null;
        $payload = [
            'id' => $listing->id,
            'listing_type' => $listing->listing_type,
            'tool_id' => $listing->tool_id,
            'seller_player_id' => $listing->seller_player_id,
            'seller_name' => $listing->seller?->display_name ?? 'Unknown Player',
            'item_key' => $listing->item_key,
            'item_name' => $listing->item_name,
            'rarity' => $listing->rarity,
            'quantity' => $listing->quantity,
            'unit_price' => $listing->unit_price,
            'total_price' => $listing->quantity * $listing->unit_price,
            'status' => $listing->status,
            'is_mine' => $listing->seller_player_id === $viewer->id,
            'can_buy' => $listing->status === ConnectedRealmsMarketListing::STATUS_ACTIVE
                && $listing->seller_player_id !== $viewer->id
                && $viewer->gold >= ($listing->quantity * $listing->unit_price),
            'created_at' => optional($listing->created_at)->toIso8601String(),
            'tool' => $toolPayload,
        ];

        return $listing->listing_type === ConnectedRealmsMarketListing::TYPE_TOOL
            ? [
                ...($toolPayload ?? $this->items->enrich($payload)),
                ...$payload,
                'quantity' => 1,
                'total_price' => $listing->unit_price,
                'market_floor_price' => $toolPayload['market_floor_price'] ?? null,
                'market_ceiling_price' => $toolPayload['market_ceiling_price'] ?? null,
                'market_price_band' => $toolPayload['market_price_band'] ?? null,
            ]
            : $this->items->enrich($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function createToolListing(ConnectedRealmsPlayer $player, ?int $toolId, int $unitPrice): array
    {
        if ($toolId === null) {
            throw ValidationException::withMessages([
                'tool_id' => 'Choose a tool to list.',
            ]);
        }

        $tool = ConnectedRealmsTool::query()
            ->where('player_id', $player->id)
            ->whereKey($toolId)
            ->lockForUpdate()
            ->first();

        if ($tool === null || $tool->status !== ConnectedRealmsTool::STATUS_INVENTORY || $tool->origin === 'starter') {
            throw ValidationException::withMessages([
                'tool_id' => 'That tool is not available to list.',
            ]);
        }

        $toolPayload = $this->players->toolInstancePayload($tool);
        $marketFloor = (int) $toolPayload['market_floor_price'];
        $marketCeiling = (int) $toolPayload['market_ceiling_price'];

        if ($unitPrice < $marketFloor || $unitPrice > $marketCeiling) {
            throw ValidationException::withMessages([
                'unit_price' => "{$tool->item_name} must be listed between {$marketFloor} and {$marketCeiling} gold.",
            ]);
        }

        $tool->forceFill([
            'status' => ConnectedRealmsTool::STATUS_LISTED,
        ])->save();

        $listing = ConnectedRealmsMarketListing::create([
            'seller_player_id' => $player->id,
            'listing_type' => ConnectedRealmsMarketListing::TYPE_TOOL,
            'tool_id' => $tool->id,
            'item_key' => $tool->item_key,
            'item_name' => $tool->item_name,
            'rarity' => $tool->rarity,
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'tool_snapshot' => $toolPayload,
            'status' => ConnectedRealmsMarketListing::STATUS_ACTIVE,
        ]);

        return [
            ...$toolPayload,
            'type' => 'market_listing',
            'listing_type' => ConnectedRealmsMarketListing::TYPE_TOOL,
            'label' => $listing->item_name,
            'listing_id' => $listing->id,
            'quantity' => 1,
            'unit_price' => $listing->unit_price,
            'total_price' => $listing->unit_price,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function listingResultPayload(array $payload): array
    {
        return ($payload['listing_type'] ?? ConnectedRealmsMarketListing::TYPE_ITEM) === ConnectedRealmsMarketListing::TYPE_TOOL
            ? $payload
            : $this->items->enrich($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionPayload(ConnectedRealmsMarketTransaction $transaction): array
    {
        $payload = [
            'id' => $transaction->id,
            'listing_type' => $transaction->listing_type,
            'tool_id' => $transaction->tool_id,
            'item_key' => $transaction->item_key,
            'item_name' => $transaction->item_name,
            'rarity' => $transaction->rarity,
            'quantity' => $transaction->quantity,
            'unit_price' => $transaction->unit_price,
            'total_price' => $transaction->total_price,
            'seller_name' => $transaction->seller?->display_name ?? 'Unknown Player',
            'buyer_name' => $transaction->buyer?->display_name ?? 'Unknown Player',
            'tool' => $transaction->tool_snapshot,
            'created_at' => optional($transaction->created_at)->toIso8601String(),
        ];

        return $transaction->listing_type === ConnectedRealmsMarketListing::TYPE_TOOL
            ? $payload
            : $this->items->enrich($payload);
    }

    /**
     * @param  list<array<string, mixed>>  $activeListings
     * @param  list<array<string, mixed>>  $recentTransactions
     * @return array<string, mixed>
     */
    private function marketBoard(array $activeListings, array $recentTransactions): array
    {
        $rows = collect([...$activeListings, ...$recentTransactions])
            ->groupBy(fn (array $entry): string => (string) (($entry['listing_type'] ?? ConnectedRealmsMarketListing::TYPE_ITEM).':'.($entry['item_key'] ?? $entry['tool_id'] ?? $entry['item_name'])))
            ->map(function ($entries): array {
                $first = $entries->first();
                $listingRows = $entries->where('status', ConnectedRealmsMarketListing::STATUS_ACTIVE);
                $saleRows = $entries->filter(fn (array $entry): bool => isset($entry['buyer_name']));
                $prices = $entries->pluck('unit_price')->filter(fn ($price): bool => is_numeric($price))->map(fn ($price): int => (int) $price)->values();
                $floor = (int) ($first['market_floor_price'] ?? $first['npc_buy_price'] ?? 1);
                $ceiling = (int) ($first['market_ceiling_price'] ?? max($floor, ($prices->max() ?? $floor) * 2));
                $average = $prices->isEmpty() ? $floor : (int) round($prices->average());
                $recommended = min($ceiling, max($floor, $average > 0 ? $average : $floor));

                return [
                    'item_key' => $first['item_key'] ?? null,
                    'item_name' => $first['item_name'] ?? 'Unknown Item',
                    'listing_type' => $first['listing_type'] ?? ConnectedRealmsMarketListing::TYPE_ITEM,
                    'rarity' => $first['rarity'] ?? 'common',
                    'quality' => $first['quality'] ?? null,
                    'item_class' => $first['item_class'] ?? (($first['tool'] ?? null) ? 'tool' : 'item'),
                    'material_family' => $first['material_family'] ?? null,
                    'active_supply' => (int) $listingRows->sum('quantity'),
                    'active_listing_count' => $listingRows->count(),
                    'recent_sale_count' => $saleRows->count(),
                    'recent_volume' => (int) $saleRows->sum('total_price'),
                    'lowest_price' => $prices->min() ?? $floor,
                    'highest_price' => $prices->max() ?? $ceiling,
                    'average_price' => $average,
                    'recommended_price' => $recommended,
                    'market_floor_price' => $floor,
                    'market_ceiling_price' => $ceiling,
                    'market_price_band' => "{$floor}-{$ceiling}g",
                    'velocity' => match (true) {
                        $saleRows->count() >= 8 => 'Hot',
                        $saleRows->count() >= 3 => 'Moving',
                        $listingRows->count() >= 5 => 'Supplied',
                        default => 'Quiet',
                    },
                ];
            })
            ->sortBy([
                ['recent_volume', 'desc'],
                ['active_listing_count', 'desc'],
                ['item_name', 'asc'],
            ])
            ->values();

        return [
            'summary' => [
                'tracked_items' => $rows->count(),
                'active_supply' => $rows->sum('active_supply'),
                'recent_volume' => $rows->sum('recent_volume'),
                'hot_items' => $rows->where('velocity', 'Hot')->count(),
            ],
            'rows' => $rows->take(80)->all(),
        ];
    }

    private function lockedPlayerFor(User $user): ConnectedRealmsPlayer
    {
        $player = $this->players->playerForUser($user);

        return ConnectedRealmsPlayer::query()
            ->whereKey($player->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
