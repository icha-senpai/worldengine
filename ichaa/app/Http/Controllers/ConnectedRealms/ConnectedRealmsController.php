<?php

namespace App\Http\Controllers\ConnectedRealms;

use App\Domain\ConnectedRealms\Services\ConnectedRealmsLeaderboardService;
use App\Domain\ConnectedRealms\Services\ConnectedRealmsPlayerService;
use App\Domain\ConnectedRealms\Services\CraftingService;
use App\Domain\ConnectedRealms\Services\ExpeditionService;
use App\Domain\ConnectedRealms\Services\GatheringActionService;
use App\Domain\ConnectedRealms\Services\JobContractService;
use App\Domain\ConnectedRealms\Services\MarketplaceService;
use App\Domain\ConnectedRealms\Services\ProgressionService;
use App\Domain\ConnectedRealms\Services\ShopService;
use App\Domain\ConnectedRealms\Services\SkillActivityService;
use App\Domain\ConnectedRealms\Services\ToolInventoryService;
use App\Domain\ConnectedRealms\Services\ToolRarityUpgradeService;
use App\Domain\ConnectedRealms\Services\ToolTierUpgradeService;
use App\Domain\ConnectedRealms\Services\WorldEventService;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConnectedRealms\BuyMarketListingRequest;
use App\Http\Requests\ConnectedRealms\CancelMarketListingRequest;
use App\Http\Requests\ConnectedRealms\StoreAchievementClaimRequest;
use App\Http\Requests\ConnectedRealms\StoreCraftingRequest;
use App\Http\Requests\ConnectedRealms\StoreExpeditionRequest;
use App\Http\Requests\ConnectedRealms\StoreGatheringActionRequest;
use App\Http\Requests\ConnectedRealms\StoreJobCompletionRequest;
use App\Http\Requests\ConnectedRealms\StoreMarketListingRequest;
use App\Http\Requests\ConnectedRealms\StoreRewardLoadoutRequest;
use App\Http\Requests\ConnectedRealms\StoreShopPurchaseRequest;
use App\Http\Requests\ConnectedRealms\StoreSkillActivityRequest;
use App\Http\Requests\ConnectedRealms\StoreToolEquipRequest;
use App\Http\Requests\ConnectedRealms\StoreToolRarityUpgradeRequest;
use App\Http\Requests\ConnectedRealms\StoreToolTierUpgradeRequest;
use App\Http\Requests\ConnectedRealms\StoreToolUnequipRequest;
use App\Http\Requests\ConnectedRealms\StoreVendorSaleRequest;
use App\Http\Requests\ConnectedRealms\UpdateCharacterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class ConnectedRealmsController extends Controller
{
    public function index(Request $request, ConnectedRealmsPlayerService $players, GatheringActionService $gathering, SkillActivityService $activities, CraftingService $crafting, JobContractService $jobs, ExpeditionService $expeditions, MarketplaceService $marketplace, ProgressionService $progression, WorldEventService $worldEvents, ShopService $shop, ToolRarityUpgradeService $toolUpgrades, ToolTierUpgradeService $toolTierUpgrades, ConnectedRealmsLeaderboardService $leaderboards): Response
    {
        return $this->page('ConnectedRealms/Index', [
            ...$players->profileForUser($request->user(), $gathering, $activities, $crafting, $jobs, $expeditions, $marketplace, $progression, $worldEvents, $shop, $toolUpgrades, $toolTierUpgrades),
            'leaderboards' => fn (): array => $leaderboards->snapshot(),
            'last_result' => $request->session()->get('connected_realms_result'),
        ]);
    }

    public function updateCharacter(UpdateCharacterRequest $request, ConnectedRealmsPlayerService $players): RedirectResponse
    {
        $players->updateCharacter($request->user(), $request->validated());

        return redirect()
            ->route('evergather.index')
            ->with('success', 'Character updated.');
    }

    public function store(StoreGatheringActionRequest $request, GatheringActionService $gathering): RedirectResponse
    {
        $result = $gathering->perform($request->user(), $request->action());

        return redirect()
            ->route('evergather.index')
            ->with('success', "{$result['label']} action completed.")
            ->with('connected_realms_result', $result);
    }

    public function performActivity(StoreSkillActivityRequest $request, SkillActivityService $activities): RedirectResponse
    {
        $result = $activities->perform($request->user(), $request->activity());

        return redirect()
            ->route('evergather.index')
            ->with('success', "{$result['label']} completed.")
            ->with('connected_realms_result', $result);
    }

    public function claimAchievement(StoreAchievementClaimRequest $request, ConnectedRealmsPlayerService $players, ProgressionService $progression): RedirectResponse
    {
        $result = $progression->claimAchievement($players->playerForUser($request->user()), $request->achievementKey());

        return redirect()
            ->route('evergather.index')
            ->with('success', "{$result['label']} reward claimed.")
            ->with('connected_realms_result', $result);
    }

    public function updateRewardLoadout(StoreRewardLoadoutRequest $request, ConnectedRealmsPlayerService $players, ProgressionService $progression): RedirectResponse
    {
        $result = $progression->updateRewardLoadout($players->playerForUser($request->user()), $request->loadout());

        return redirect()
            ->route('evergather.index')
            ->with('success', 'Reward loadout updated.')
            ->with('connected_realms_result', $result);
    }

    public function craft(StoreCraftingRequest $request, CraftingService $crafting): RedirectResponse
    {
        $result = $crafting->craft($request->user(), $request->recipe());

        return redirect()
            ->route('evergather.index')
            ->with('success', "{$result['label']} crafted.")
            ->with('connected_realms_result', $result);
    }

    public function completeJob(StoreJobCompletionRequest $request, JobContractService $jobs): RedirectResponse
    {
        $result = $jobs->complete($request->user(), $request->job());

        return redirect()
            ->route('evergather.index')
            ->with('success', "{$result['label']} completed.")
            ->with('connected_realms_result', $result);
    }

    public function runExpedition(StoreExpeditionRequest $request, ExpeditionService $expeditions): RedirectResponse
    {
        $result = $expeditions->run($request->user(), $request->expedition());

        return redirect()
            ->route('evergather.index')
            ->with('success', "{$result['label']} resolved.")
            ->with('connected_realms_result', $result);
    }

    public function buyShopOffer(StoreShopPurchaseRequest $request, ShopService $shop): RedirectResponse
    {
        $result = $shop->buy($request->user(), $request->offer());

        return redirect()
            ->route('evergather.index')
            ->with('success', "{$result['label']} purchased.")
            ->with('connected_realms_result', $result);
    }

    public function upgradeToolRarity(StoreToolRarityUpgradeRequest $request, ToolRarityUpgradeService $toolUpgrades): RedirectResponse
    {
        $result = $toolUpgrades->attempt($request->user(), $request->slot());

        return redirect()
            ->route('evergather.index')
            ->with('success', $result['message'])
            ->with('connected_realms_result', $result);
    }

    public function upgradeToolTier(StoreToolTierUpgradeRequest $request, ToolTierUpgradeService $toolTierUpgrades): RedirectResponse
    {
        $result = $toolTierUpgrades->upgrade($request->user(), $request->slot());

        return redirect()
            ->route('evergather.index')
            ->with('success', "{$result['previous_item_name']} upgraded to {$result['item_name']}.")
            ->with('connected_realms_result', $result);
    }

    public function equipTool(StoreToolEquipRequest $request, ToolInventoryService $toolInventory): RedirectResponse
    {
        $result = $toolInventory->equip($request->user(), $request->toolId());

        return redirect()
            ->route('evergather.index')
            ->with('success', "{$result['label']} equipped.")
            ->with('connected_realms_result', $result);
    }

    public function unequipTool(StoreToolUnequipRequest $request, ToolInventoryService $toolInventory): RedirectResponse
    {
        $result = $toolInventory->unequip($request->user(), $request->slot());

        return redirect()
            ->route('evergather.index')
            ->with('success', "{$result['stored_tool_name']} unequipped.")
            ->with('connected_realms_result', $result);
    }

    public function listMarketItem(StoreMarketListingRequest $request, MarketplaceService $marketplace): RedirectResponse
    {
        $result = $marketplace->createListing($request->user(), $request->listingType(), $request->itemKey(), $request->quantity(), $request->unitPrice(), $request->toolId());

        return redirect()
            ->route('evergather.index')
            ->with('success', "{$result['label']} listed.")
            ->with('connected_realms_result', $result);
    }

    public function sellToNpc(StoreVendorSaleRequest $request, MarketplaceService $marketplace): RedirectResponse
    {
        $result = $marketplace->sellToNpc($request->user(), $request->itemKey(), $request->quantity());

        return redirect()
            ->route('evergather.index')
            ->with('success', "{$result['label']} sold to {$result['vendor_name']}.")
            ->with('connected_realms_result', $result);
    }

    public function buyMarketListing(BuyMarketListingRequest $request, int $listing, MarketplaceService $marketplace): RedirectResponse
    {
        $result = $marketplace->buyListing($request->user(), $listing);

        return redirect()
            ->route('evergather.index')
            ->with('success', "{$result['label']} purchased.")
            ->with('connected_realms_result', $result);
    }

    public function cancelMarketListing(CancelMarketListingRequest $request, int $listing, MarketplaceService $marketplace): RedirectResponse
    {
        $result = $marketplace->cancelListing($request->user(), $listing);

        return redirect()
            ->route('evergather.index')
            ->with('success', "{$result['label']} listing cancelled.")
            ->with('connected_realms_result', $result);
    }
}
