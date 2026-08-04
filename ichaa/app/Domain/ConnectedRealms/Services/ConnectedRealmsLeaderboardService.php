<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsActionLog;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsLeaderboardBoard;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsLeaderboardEntry;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsLeaderboardSeason;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsMarketTransaction;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayerSkill;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsTool;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsVendorSale;
use Illuminate\Support\Facades\DB;

class ConnectedRealmsLeaderboardService
{
    private const CURRENT_SEASON_KEY = 'current';

    private const BOARD_DEFINITIONS = [
        ['key' => 'wealth', 'group_key' => 'summary', 'group_label' => 'Summary', 'label' => 'Gold Hoards', 'description' => 'Players ranked by raw gold carried.', 'sort_order' => 10],
        ['key' => 'overall', 'group_key' => 'summary', 'group_label' => 'Summary', 'label' => 'Total Mastery', 'description' => 'Players ranked by total skill experience.', 'sort_order' => 20],
        ['key' => 'realm_score', 'group_key' => 'summary', 'group_label' => 'Summary', 'label' => 'Realm Renown', 'description' => 'A blended score across XP, gold, actions, crafting, jobs, expeditions, and inventory.', 'sort_order' => 30],
        ['key' => 'skills', 'group_key' => 'skills', 'group_label' => 'Skills', 'label' => 'Top Skill XP', 'description' => 'The highest individual skill records in the realm.', 'sort_order' => 40],
        ['key' => 'skill_champions', 'group_key' => 'skills', 'group_label' => 'Skills', 'label' => 'Skill Champions', 'description' => 'The current leader for every skill that has an XP record.', 'sort_order' => 50],
        ['key' => 'activity', 'group_key' => 'activity', 'group_label' => 'Activity', 'label' => 'All Activity', 'description' => 'Every logged action type rolled into one pace board.', 'sort_order' => 60],
        ['key' => 'gathering', 'group_key' => 'activity', 'group_label' => 'Activity', 'label' => 'Gathering Actions', 'description' => 'Players pushing the most website gathering actions.', 'sort_order' => 70],
        ['key' => 'crafting', 'group_key' => 'activity', 'group_label' => 'Activity', 'label' => 'Crafting Output', 'description' => 'Players completing the most recipes.', 'sort_order' => 80],
        ['key' => 'jobs', 'group_key' => 'activity', 'group_label' => 'Activity', 'label' => 'Commission Board', 'description' => 'Players turning inventory into posted rewards.', 'sort_order' => 90],
        ['key' => 'expeditions', 'group_key' => 'activity', 'group_label' => 'Activity', 'label' => 'Expeditions', 'description' => 'Players resolving supplied expedition routes.', 'sort_order' => 100],
        ['key' => 'inventory', 'group_key' => 'trade', 'group_label' => 'Trade', 'label' => 'Collectors', 'description' => 'Players holding the deepest inventories by quantity.', 'sort_order' => 110],
        ['key' => 'market_sellers', 'group_key' => 'trade', 'group_label' => 'Trade', 'label' => 'Market Sellers', 'description' => 'Players earning the most gold from marketplace sales.', 'sort_order' => 120],
        ['key' => 'market_buyers', 'group_key' => 'trade', 'group_label' => 'Trade', 'label' => 'Market Buyers', 'description' => 'Players spending the most gold on marketplace purchases.', 'sort_order' => 130],
        ['key' => 'market_volume', 'group_key' => 'trade', 'group_label' => 'Trade', 'label' => 'Trade Volume', 'description' => 'Players with the most combined marketplace buying and selling volume.', 'sort_order' => 140],
        ['key' => 'vendor_floor', 'group_key' => 'trade', 'group_label' => 'Trade', 'label' => 'Vendor Floor', 'description' => 'Players converting inventory into NPC floor gold.', 'sort_order' => 150],
        ['key' => 'tool_crafters', 'group_key' => 'equipment', 'group_label' => 'Equipment', 'label' => 'Toolwrights', 'description' => 'Players producing, buying, and upgrading non-starter tools.', 'sort_order' => 160],
        ['key' => 'rarity_artisans', 'group_key' => 'equipment', 'group_label' => 'Equipment', 'label' => 'Rarity Artisans', 'description' => 'Players pushing rarity attempts and successful rarity history.', 'sort_order' => 170],
        ['key' => 'event_participants', 'group_key' => 'events', 'group_label' => 'Events', 'label' => 'Event Runners', 'description' => 'Players completing actions while world events are active.', 'sort_order' => 180],
        ['key' => 'event_experience', 'group_key' => 'events', 'group_label' => 'Events', 'label' => 'Event XP', 'description' => 'Players earning the most XP during event-tagged actions.', 'sort_order' => 190],
    ];

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return DB::transaction(function (): array {
            $season = $this->activeSeason();

            $this->refreshSeason($season);

            return $this->snapshotFromSeason($season);
        });
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function generateBoards(): array
    {
        return [
            'wealth' => $this->wealth(),
            'overall' => $this->overall(),
            'skills' => $this->skills(),
            'skill_champions' => $this->skillChampions(),
            'activity' => $this->activity(),
            'gathering' => $this->gathering(),
            'crafting' => $this->crafting(),
            'jobs' => $this->jobs(),
            'expeditions' => $this->expeditions(),
            'inventory' => $this->inventory(),
            'market_sellers' => $this->marketSellers(),
            'market_buyers' => $this->marketBuyers(),
            'market_volume' => $this->marketVolume(),
            'vendor_floor' => $this->vendorFloor(),
            'tool_crafters' => $this->toolCrafters(),
            'rarity_artisans' => $this->rarityArtisans(),
            'event_participants' => $this->eventParticipants(),
            'event_experience' => $this->eventExperience(),
            'realm_score' => $this->realmScore(),
        ];
    }

    private function activeSeason(): ConnectedRealmsLeaderboardSeason
    {
        return ConnectedRealmsLeaderboardSeason::query()->firstOrCreate(
            ['key' => self::CURRENT_SEASON_KEY],
            [
                'name' => 'Current Season',
                'active' => true,
                'starts_at' => now(),
            ],
        );
    }

    private function refreshSeason(ConnectedRealmsLeaderboardSeason $season): void
    {
        $generatedBoards = $this->generateBoards();

        foreach (self::BOARD_DEFINITIONS as $definition) {
            $board = ConnectedRealmsLeaderboardBoard::query()->updateOrCreate(
                [
                    'season_id' => $season->id,
                    'key' => $definition['key'],
                ],
                [
                    'group_key' => $definition['group_key'],
                    'group_label' => $definition['group_label'],
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'sort_order' => $definition['sort_order'],
                ],
            );

            $this->storeEntries($board, $generatedBoards[$definition['key']] ?? []);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function storeEntries(ConnectedRealmsLeaderboardBoard $board, array $entries): void
    {
        $board->entries()->delete();
        $now = now();

        $rows = collect($entries)
            ->values()
            ->map(fn (array $entry, int $index): array => [
                'board_id' => $board->id,
                'player_id' => $entry['id'] ?? null,
                'rank' => $index + 1,
                'display_name' => $entry['display_name'],
                'species_label' => $entry['species_label'] ?? null,
                'skill' => $entry['skill'] ?? null,
                'skill_label' => $entry['skill_label'] ?? null,
                'score' => (int) ($entry['score'] ?? 0),
                'score_label' => $entry['score_label'] ?? (string) ($entry['score'] ?? 0),
                'detail' => $entry['detail'] ?? null,
                'metrics' => json_encode($this->metricsForEntry($entry)),
                'recorded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            ConnectedRealmsLeaderboardEntry::query()->insert($rows);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotFromSeason(ConnectedRealmsLeaderboardSeason $season): array
    {
        $boards = ConnectedRealmsLeaderboardBoard::query()
            ->with(['entries' => fn ($query) => $query->orderBy('rank')])
            ->where('season_id', $season->id)
            ->orderBy('sort_order')
            ->get();

        $snapshot = [
            'season' => [
                'key' => $season->key,
                'name' => $season->name,
                'starts_at' => $season->starts_at?->toISOString(),
                'ends_at' => $season->ends_at?->toISOString(),
            ],
            'boards' => $boards
                ->map(fn (ConnectedRealmsLeaderboardBoard $board): array => $this->boardPayload($board))
                ->values()
                ->all(),
            'groups' => $this->groupsFromBoards($boards),
        ];

        foreach ($boards as $board) {
            $snapshot[$board->key] = $board->entries
                ->map(fn (ConnectedRealmsLeaderboardEntry $entry): array => $this->entryPayload($entry))
                ->values()
                ->all();
        }

        return $snapshot;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function wealth(): array
    {
        return ConnectedRealmsPlayer::query()
            ->orderByDesc('gold')
            ->orderBy('display_name')
            ->limit(10)
            ->get(['id', 'display_name', 'species', 'gold'])
            ->map(fn (ConnectedRealmsPlayer $player): array => [
                ...$this->playerIdentity($player),
                'score' => $player->gold,
                'score_label' => "{$player->gold} gold",
                'detail' => $this->speciesLabel($player->species),
                'gold' => $player->gold,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function boardPayload(ConnectedRealmsLeaderboardBoard $board): array
    {
        return [
            'key' => $board->key,
            'group_key' => $board->group_key,
            'group_label' => $board->group_label,
            'label' => $board->label,
            'description' => $board->description,
            'sort_order' => $board->sort_order,
            'count' => $board->entries->count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function groupsFromBoards($boards): array
    {
        return $boards
            ->groupBy('group_key')
            ->map(function ($groupBoards, string $groupKey): array {
                return [
                    'key' => $groupKey,
                    'label' => $groupBoards->first()->group_label,
                    'boards' => $groupBoards->pluck('key')->values()->all(),
                    'count' => $groupBoards->sum(fn (ConnectedRealmsLeaderboardBoard $board): int => $board->entries->count()),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function entryPayload(ConnectedRealmsLeaderboardEntry $entry): array
    {
        return [
            'id' => $entry->player_id,
            'rank' => $entry->rank,
            'display_name' => $entry->display_name,
            'species_label' => $entry->species_label,
            'skill' => $entry->skill,
            'skill_label' => $entry->skill_label,
            'score' => $entry->score,
            'score_label' => $entry->score_label,
            'detail' => $entry->detail,
            ...($entry->metrics ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function metricsForEntry(array $entry): array
    {
        return collect($entry)
            ->except(['id', 'display_name', 'species_label', 'skill', 'skill_label', 'score', 'score_label', 'detail'])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function overall(): array
    {
        return ConnectedRealmsPlayer::query()
            ->withSum('skills as total_experience', 'experience')
            ->withMax('skills as highest_level', 'level')
            ->get(['id', 'display_name', 'species'])
            ->map(fn (ConnectedRealmsPlayer $player): array => [
                ...$this->playerIdentity($player),
                'score' => (int) $player->total_experience,
                'score_label' => number_format((int) $player->total_experience).' XP',
                'detail' => 'Highest skill Lv '.((int) $player->highest_level),
                'highest_level' => (int) $player->highest_level,
            ])
            ->filter(fn (array $entry): bool => $entry['score'] > 0)
            ->sortByDesc('score')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function skills(): array
    {
        return ConnectedRealmsPlayerSkill::query()
            ->with('player:id,display_name,species')
            ->orderByDesc('experience')
            ->orderByDesc('level')
            ->orderBy('skill')
            ->limit(12)
            ->get(['id', 'player_id', 'skill', 'level', 'experience'])
            ->map(fn (ConnectedRealmsPlayerSkill $skill): array => $this->skillEntry($skill))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function skillChampions(): array
    {
        return ConnectedRealmsPlayerSkill::query()
            ->with('player:id,display_name,species')
            ->orderBy('skill')
            ->orderByDesc('experience')
            ->get(['id', 'player_id', 'skill', 'level', 'experience'])
            ->groupBy('skill')
            ->map(fn ($skills): array => $this->skillEntry($skills->first()))
            ->sortBy('skill_label')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activity(): array
    {
        return ConnectedRealmsPlayer::query()
            ->withCount(['actionLogs', 'craftingLogs', 'jobCompletions', 'expeditionRuns'])
            ->get(['id', 'display_name', 'species'])
            ->map(function (ConnectedRealmsPlayer $player): array {
                $total = (int) $player->action_logs_count
                    + (int) $player->crafting_logs_count
                    + (int) $player->job_completions_count
                    + (int) $player->expedition_runs_count;

                return [
                    ...$this->playerIdentity($player),
                    'score' => $total,
                    'score_label' => "{$total} actions",
                    'detail' => "{$player->action_logs_count} gather · {$player->crafting_logs_count} craft · {$player->job_completions_count} jobs · {$player->expedition_runs_count} expeditions",
                    'action_count' => (int) $player->action_logs_count,
                    'craft_count' => (int) $player->crafting_logs_count,
                    'job_count' => (int) $player->job_completions_count,
                    'expedition_count' => (int) $player->expedition_runs_count,
                ];
            })
            ->sortByDesc('score')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gathering(): array
    {
        return ConnectedRealmsPlayer::query()
            ->withCount('actionLogs')
            ->withSum('actionLogs as gathered_experience', 'experience_awarded')
            ->withSum('actionLogs as gathered_gold', 'gold_awarded')
            ->orderByDesc('action_logs_count')
            ->orderByDesc('gathered_experience')
            ->orderBy('display_name')
            ->limit(10)
            ->get(['id', 'display_name', 'species'])
            ->map(fn (ConnectedRealmsPlayer $player): array => [
                ...$this->playerIdentity($player),
                'score' => (int) $player->action_logs_count,
                'score_label' => "{$player->action_logs_count} actions",
                'detail' => number_format((int) $player->gathered_experience).' XP · '.((int) $player->gathered_gold).' gold',
                'experience' => (int) $player->gathered_experience,
                'gold' => (int) $player->gathered_gold,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function crafting(): array
    {
        return ConnectedRealmsPlayer::query()
            ->withCount('craftingLogs')
            ->withSum('craftingLogs as crafting_experience', 'experience_awarded')
            ->orderByDesc('crafting_logs_count')
            ->orderByDesc('crafting_experience')
            ->orderBy('display_name')
            ->limit(10)
            ->get(['id', 'display_name', 'species'])
            ->map(fn (ConnectedRealmsPlayer $player): array => [
                ...$this->playerIdentity($player),
                'score' => (int) $player->crafting_logs_count,
                'score_label' => "{$player->crafting_logs_count} crafts",
                'detail' => number_format((int) $player->crafting_experience).' crafting XP',
                'experience' => (int) $player->crafting_experience,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jobs(): array
    {
        return ConnectedRealmsPlayer::query()
            ->withCount('jobCompletions')
            ->withSum('jobCompletions as job_gold', 'gold_awarded')
            ->withSum('jobCompletions as job_experience', 'experience_awarded')
            ->orderByDesc('job_completions_count')
            ->orderByDesc('job_gold')
            ->orderBy('display_name')
            ->limit(10)
            ->get(['id', 'display_name', 'species'])
            ->map(fn (ConnectedRealmsPlayer $player): array => [
                ...$this->playerIdentity($player),
                'score' => (int) $player->job_completions_count,
                'score_label' => "{$player->job_completions_count} jobs",
                'detail' => ((int) $player->job_gold).' gold · '.number_format((int) $player->job_experience).' XP',
                'gold' => (int) $player->job_gold,
                'experience' => (int) $player->job_experience,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function expeditions(): array
    {
        return ConnectedRealmsPlayer::query()
            ->withCount('expeditionRuns')
            ->withSum('expeditionRuns as expedition_gold', 'gold_awarded')
            ->withSum('expeditionRuns as expedition_experience', 'experience_awarded')
            ->orderByDesc('expedition_runs_count')
            ->orderByDesc('expedition_experience')
            ->orderBy('display_name')
            ->limit(10)
            ->get(['id', 'display_name', 'species'])
            ->map(fn (ConnectedRealmsPlayer $player): array => [
                ...$this->playerIdentity($player),
                'score' => (int) $player->expedition_runs_count,
                'score_label' => "{$player->expedition_runs_count} expeditions",
                'detail' => number_format((int) $player->expedition_experience).' XP · '.((int) $player->expedition_gold).' gold',
                'gold' => (int) $player->expedition_gold,
                'experience' => (int) $player->expedition_experience,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inventory(): array
    {
        return ConnectedRealmsPlayer::query()
            ->withCount('inventoryStacks')
            ->withSum('inventoryStacks as inventory_quantity', 'quantity')
            ->get(['id', 'display_name', 'species'])
            ->map(fn (ConnectedRealmsPlayer $player): array => [
                ...$this->playerIdentity($player),
                'score' => (int) $player->inventory_quantity,
                'score_label' => "{$player->inventory_quantity} items",
                'detail' => "{$player->inventory_stacks_count} unique stacks",
                'stack_count' => (int) $player->inventory_stacks_count,
            ])
            ->filter(fn (array $entry): bool => $entry['score'] > 0)
            ->sortByDesc('score')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function marketSellers(): array
    {
        return $this->marketRows('seller_player_id', 'seller');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function marketBuyers(): array
    {
        return $this->marketRows('buyer_player_id', 'buyer');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function marketVolume(): array
    {
        $sellerRows = ConnectedRealmsMarketTransaction::query()
            ->select(['seller_player_id as player_id', DB::raw('SUM(total_price) as sold_value'), DB::raw('0 as bought_value'), DB::raw('COUNT(*) as trade_count')])
            ->groupBy('seller_player_id');
        $buyerRows = ConnectedRealmsMarketTransaction::query()
            ->select(['buyer_player_id as player_id', DB::raw('0 as sold_value'), DB::raw('SUM(total_price) as bought_value'), DB::raw('COUNT(*) as trade_count')])
            ->groupBy('buyer_player_id');

        return DB::query()
            ->fromSub($sellerRows->unionAll($buyerRows), 'market_volume')
            ->select(['player_id', DB::raw('SUM(sold_value) as sold_value'), DB::raw('SUM(bought_value) as bought_value'), DB::raw('SUM(trade_count) as trade_count')])
            ->groupBy('player_id')
            ->orderByDesc(DB::raw('SUM(sold_value) + SUM(bought_value)'))
            ->limit(10)
            ->get()
            ->map(function ($row): array {
                $player = ConnectedRealmsPlayer::query()->find($row->player_id);
                $score = (int) $row->sold_value + (int) $row->bought_value;

                return [
                    ...$this->playerIdentity($player),
                    'score' => $score,
                    'score_label' => "{$score} gold",
                    'detail' => "{$row->trade_count} trades · sold {$row->sold_value}g · bought {$row->bought_value}g",
                    'sold_value' => (int) $row->sold_value,
                    'bought_value' => (int) $row->bought_value,
                    'trade_count' => (int) $row->trade_count,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function vendorFloor(): array
    {
        return ConnectedRealmsVendorSale::query()
            ->select(['player_id', DB::raw('SUM(total_price) as floor_value'), DB::raw('SUM(quantity) as item_quantity'), DB::raw('COUNT(*) as sale_count')])
            ->with('player:id,display_name,species')
            ->groupBy('player_id')
            ->orderByDesc('floor_value')
            ->limit(10)
            ->get()
            ->map(fn (ConnectedRealmsVendorSale $sale): array => [
                ...$this->playerIdentity($sale->player),
                'score' => (int) $sale->floor_value,
                'score_label' => "{$sale->floor_value} gold",
                'detail' => "{$sale->item_quantity} items · {$sale->sale_count} steward sales",
                'item_quantity' => (int) $sale->item_quantity,
                'sale_count' => (int) $sale->sale_count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function toolCrafters(): array
    {
        return ConnectedRealmsTool::query()
            ->select(['player_id', DB::raw('COUNT(*) as tool_count'), DB::raw('SUM(tier_upgrade_count) as tier_work'), DB::raw('SUM(upgrade_count) as rarity_successes')])
            ->with('player:id,display_name,species')
            ->where('origin', '!=', 'starter')
            ->groupBy('player_id')
            ->orderByDesc('tool_count')
            ->orderByDesc('tier_work')
            ->limit(10)
            ->get()
            ->map(fn (ConnectedRealmsTool $tool): array => [
                ...$this->playerIdentity($tool->player),
                'score' => (int) $tool->tool_count,
                'score_label' => "{$tool->tool_count} tools",
                'detail' => "{$tool->tier_work} tier work · {$tool->rarity_successes} rarity successes",
                'tier_work' => (int) $tool->tier_work,
                'rarity_successes' => (int) $tool->rarity_successes,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rarityArtisans(): array
    {
        return ConnectedRealmsTool::query()
            ->select(['player_id', DB::raw('SUM(rarity_upgrade_attempts) as attempts'), DB::raw('SUM(upgrade_count) as successes')])
            ->with('player:id,display_name,species')
            ->groupBy('player_id')
            ->havingRaw('SUM(rarity_upgrade_attempts) > 0')
            ->orderByDesc('successes')
            ->orderByDesc('attempts')
            ->limit(10)
            ->get()
            ->map(fn (ConnectedRealmsTool $tool): array => [
                ...$this->playerIdentity($tool->player),
                'score' => (int) $tool->successes,
                'score_label' => "{$tool->successes} successes",
                'detail' => "{$tool->attempts} attempts banked across the toolbelt",
                'attempts' => (int) $tool->attempts,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventParticipants(): array
    {
        return $this->eventRows('COUNT(*)', 'event actions');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventExperience(): array
    {
        return $this->eventRows('SUM(experience_awarded)', 'event XP');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function realmScore(): array
    {
        return ConnectedRealmsPlayer::query()
            ->withCount(['actionLogs', 'craftingLogs', 'jobCompletions', 'expeditionRuns', 'inventoryStacks'])
            ->withSum('skills as total_experience', 'experience')
            ->get(['id', 'display_name', 'species', 'gold'])
            ->map(function (ConnectedRealmsPlayer $player): array {
                $score = (int) round(
                    ((int) $player->total_experience / 10)
                    + ((int) $player->gold * 3)
                    + ((int) $player->action_logs_count * 12)
                    + ((int) $player->crafting_logs_count * 16)
                    + ((int) $player->job_completions_count * 20)
                    + ((int) $player->expedition_runs_count * 24)
                    + ((int) $player->inventory_stacks_count * 4)
                );

                return [
                    ...$this->playerIdentity($player),
                    'score' => $score,
                    'score_label' => number_format($score).' renown',
                    'detail' => number_format((int) $player->total_experience).' XP · '.$player->gold.' gold',
                ];
            })
            ->sortByDesc('score')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function marketRows(string $playerColumn, string $role): array
    {
        return ConnectedRealmsMarketTransaction::query()
            ->select([
                $playerColumn,
                DB::raw('COUNT(*) as transaction_count'),
                DB::raw('SUM(total_price) as traded_value'),
                DB::raw('SUM(quantity) as item_quantity'),
            ])
            ->with($role.':id,display_name,species')
            ->groupBy($playerColumn)
            ->orderByDesc('traded_value')
            ->orderByDesc('transaction_count')
            ->limit(10)
            ->get()
            ->map(function (ConnectedRealmsMarketTransaction $transaction) use ($role): array {
                $player = $transaction->{$role};

                return [
                    ...$this->playerIdentity($player),
                    'score' => (int) $transaction->traded_value,
                    'score_label' => ((int) $transaction->traded_value).' gold',
                    'detail' => "{$transaction->transaction_count} trades · {$transaction->item_quantity} items",
                    'transaction_count' => (int) $transaction->transaction_count,
                    'item_quantity' => (int) $transaction->item_quantity,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventRows(string $scoreExpression, string $scoreSuffix): array
    {
        return ConnectedRealmsActionLog::query()
            ->select(['player_id', DB::raw("{$scoreExpression} as event_score"), DB::raw('COUNT(*) as event_count'), DB::raw('SUM(experience_awarded) as event_experience'), DB::raw('SUM(gold_awarded) as event_gold')])
            ->with('player:id,display_name,species')
            ->whereNotNull('event_key')
            ->groupBy('player_id')
            ->orderByDesc('event_score')
            ->orderByDesc('event_experience')
            ->limit(10)
            ->get()
            ->map(fn (ConnectedRealmsActionLog $log): array => [
                ...$this->playerIdentity($log->player),
                'score' => (int) $log->event_score,
                'score_label' => number_format((int) $log->event_score).' '.$scoreSuffix,
                'detail' => "{$log->event_count} event actions · ".number_format((int) $log->event_experience).' XP · '.((int) $log->event_gold).' gold',
                'event_count' => (int) $log->event_count,
                'experience' => (int) $log->event_experience,
                'gold' => (int) $log->event_gold,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function skillEntry(ConnectedRealmsPlayerSkill $skill): array
    {
        return [
            'id' => $skill->player_id,
            'skill_record_id' => $skill->id,
            'display_name' => $skill->player?->display_name ?? 'Unknown Player',
            'species_label' => $this->speciesLabel($skill->player?->species),
            'skill' => $skill->skill,
            'skill_label' => str($skill->skill)->headline()->toString(),
            'level' => $skill->level,
            'experience' => $skill->experience,
            'score' => $skill->experience,
            'score_label' => 'Lv '.$skill->level,
            'detail' => number_format($skill->experience).' XP',
        ];
    }

    /**
     * @return array{id: int|null, display_name: string, species_label: string}
     */
    private function playerIdentity(?ConnectedRealmsPlayer $player): array
    {
        return [
            'id' => $player?->id,
            'display_name' => $player?->display_name ?? 'Unknown Player',
            'species_label' => $this->speciesLabel($player?->species),
        ];
    }

    private function speciesLabel(?string $species): string
    {
        return str($species ?? 'unknown')->headline()->toString();
    }
}
