<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsEquipmentSlot;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsTool;

class ToolEffectService
{
    /**
     * @var array<string, array{rank: int, label: string, cooldown: int, critical: int, preservation: int, market: int}>
     */
    private const RARITY_EFFECTS = [
        'common' => ['rank' => 1, 'label' => 'Field-Worn', 'cooldown' => 0, 'critical' => 0, 'preservation' => 0, 'market' => 0],
        'uncommon' => ['rank' => 2, 'label' => 'Tuned', 'cooldown' => 1, 'critical' => 2, 'preservation' => 1, 'market' => 8],
        'rare' => ['rank' => 3, 'label' => 'Guild-Sealed', 'cooldown' => 2, 'critical' => 4, 'preservation' => 2, 'market' => 18],
        'epic' => ['rank' => 4, 'label' => 'Runebound', 'cooldown' => 4, 'critical' => 7, 'preservation' => 4, 'market' => 34],
        'legendary' => ['rank' => 5, 'label' => 'Realm-Forged', 'cooldown' => 7, 'critical' => 11, 'preservation' => 7, 'market' => 60],
    ];

    /**
     * @var array<string, array{signature: string, discipline: string}>
     */
    private const SKILL_TRAITS = [
        'fishing' => ['signature' => 'Tidehook Memory', 'discipline' => 'Moonwake Linecraft'],
        'mining' => ['signature' => 'Seam-Sense Strike', 'discipline' => 'Emberdeep Veincraft'],
        'woodcutting' => ['signature' => 'Heartwood Reading', 'discipline' => 'Whisperbough Axecraft'],
        'foraging' => ['signature' => 'Glimmerfen Eye', 'discipline' => 'Trailwise Gathering'],
        'hunting' => ['signature' => 'Quiet Snare Pattern', 'discipline' => 'Briarwake Tracking'],
        'farming' => ['signature' => 'Seedbed Rhythm', 'discipline' => 'Sunfield Cultivation'],
        'excavation' => ['signature' => 'Relic Dust Tuning', 'discipline' => 'Old-Road Surveying'],
        'smelting' => ['signature' => 'Coalbed Heat Sense', 'discipline' => 'Forgehall Crucible Work'],
        'milling' => ['signature' => 'True-Grain Plane', 'discipline' => 'Millhouse Joinery'],
        'tanning' => ['signature' => 'Soft-Cure Timing', 'discipline' => 'Briarwake Hidework'],
        'cutting' => ['signature' => 'Facetline Focus', 'discipline' => 'Gemcutter Row Precision'],
        'weaving' => ['signature' => 'Loom-Tension Read', 'discipline' => 'Sunfield Threadwork'],
        'smithing' => ['signature' => 'Anvil Echo', 'discipline' => 'Moonwake Hammercraft'],
        'carpentry' => ['signature' => 'Joinery Bite', 'discipline' => 'Whisperbough Woodwrighting'],
        'cooking' => ['signature' => 'Hearth-Salt Balance', 'discipline' => 'Moonwake Provisioning'],
        'alchemy' => ['signature' => 'Stillroom Bloom', 'discipline' => 'Glimmerfen Reagent Work'],
        'tailoring' => ['signature' => 'Pattern-Keeper Stitch', 'discipline' => 'Sunfield Clothcraft'],
        'leatherworking' => ['signature' => 'Awlmark Binding', 'discipline' => 'Briarwake Leathercraft'],
        'engineering' => ['signature' => 'Clocktooth Alignment', 'discipline' => 'Gearwright Calibration'],
        'enchanting' => ['signature' => 'Ward-Oil Resonance', 'discipline' => 'Moon Ward Infusion'],
        'jewelcrafting' => ['signature' => 'Setting Spark', 'discipline' => 'Gemcutter Setting Work'],
        'boatbuilding' => ['signature' => 'Keelcurve Measure', 'discipline' => 'Moonwake Shipwrighting'],
        'furniture' => ['signature' => 'Varnish Depth', 'discipline' => 'Guild Hall Finishwork'],
        'construction' => ['signature' => 'Loadstone Plumb', 'discipline' => 'Settlement Framecraft'],
        'combat' => ['signature' => 'Ring-Step Tempo', 'discipline' => 'Moonwake Blade Drill'],
        'slayer' => ['signature' => 'Marked Quarry Instinct', 'discipline' => 'Bounty Board Pursuit'],
        'defense' => ['signature' => 'Shieldline Anchor', 'discipline' => 'Old Gate Guardwork'],
        'healing' => ['signature' => 'Steady Hand Round', 'discipline' => 'Infirmary Fieldcare'],
        'magic' => ['signature' => 'Focus-Flare Control', 'discipline' => 'Moon Ward Channeling'],
        'ranged' => ['signature' => 'High-Perch Aim', 'discipline' => 'Range Trial Marksmanship'],
        'exploration' => ['signature' => 'Hidden Mile Sense', 'discipline' => 'Scout Route Reading'],
        'dungeoneering' => ['signature' => 'Vaultstep Caution', 'discipline' => 'Lower Vault Delving'],
        'sailing' => ['signature' => 'Stormbreak Bearing', 'discipline' => 'Channel Navigation'],
        'survival' => ['signature' => 'Cold-Camp Resolve', 'discipline' => 'Wild Circuit Endurance'],
        'cartography' => ['signature' => 'Ridge-Line Draft', 'discipline' => 'Surveyor Mapwork'],
        'reputation' => ['signature' => 'Council Seal Grace', 'discipline' => 'Faction Representation'],
        'leadership' => ['signature' => 'Muster Banner Pull', 'discipline' => 'Crew Coordination'],
        'trading' => ['signature' => 'Ledger Margin Read', 'discipline' => 'Regional Brokerage'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function payloadForEquipment(ConnectedRealmsEquipmentSlot|ConnectedRealmsTool|null $tool): array
    {
        if ($tool === null) {
            return $this->emptyPayload();
        }

        $skill = (string) ($tool instanceof ConnectedRealmsEquipmentSlot
            ? ($tool->bonuses['skill'] ?? str($tool->slot)->after('tool_')->toString())
            : $tool->skill);
        $rarity = (string) ($tool->rarity ?? 'common');
        $tierLevel = max(0, (int) ($tool->tier_level ?? 0));
        $upgradeCount = max(0, (int) ($tool->upgrade_count ?? 0));
        $tierUpgradeCount = max(0, (int) ($tool instanceof ConnectedRealmsTool ? $tool->tier_upgrade_count : ($tool->tool?->tier_upgrade_count ?? 0)));
        $rarityAttempts = max(0, (int) ($tool->rarity_upgrade_attempts ?? 0));
        $rarityEffect = self::RARITY_EFFECTS[$rarity] ?? self::RARITY_EFFECTS['common'];
        $trait = self::SKILL_TRAITS[$skill] ?? ['signature' => 'Wayfinder Handling', 'discipline' => 'General Guildcraft'];
        $historyRank = intdiv($upgradeCount + $tierUpgradeCount + $rarityAttempts, 3);
        $tierRank = intdiv($tierLevel, 10);
        $criticalChance = min(35, $rarityEffect['critical'] + $tierRank + intdiv($historyRank, 2));
        $cooldownReduction = min(20, $rarityEffect['cooldown'] + intdiv($tierLevel, 20));
        $preservationChance = min(24, $rarityEffect['preservation'] + intdiv($upgradeCount + $tierUpgradeCount, 4));
        $marketPremium = $rarityEffect['market'] + ($tierLevel * 2) + ($upgradeCount * 12) + ($tierUpgradeCount * 16);

        return [
            'grade' => $rarityEffect['label'],
            'signature_trait' => $trait['signature'],
            'discipline' => $trait['discipline'],
            'modifiers' => [
                'critical_chance' => $criticalChance,
                'cooldown_reduction' => $cooldownReduction,
                'material_preservation' => $preservationChance,
                'market_premium' => $marketPremium,
            ],
            'perks' => array_values(array_filter([
                [
                    'key' => "{$skill}_signature",
                    'label' => $trait['signature'],
                    'description' => "+{$criticalChance}% critical success chance while working {$trait['discipline']}.",
                ],
                $cooldownReduction > 0 ? [
                    'key' => 'quickened_handling',
                    'label' => 'Quickened Handling',
                    'description' => "{$cooldownReduction}% action cooldown reduction, capped before the test cooldown override.",
                ] : null,
                $preservationChance > 0 ? [
                    'key' => 'careful_material_hand',
                    'label' => 'Careful Material Hand',
                    'description' => "{$preservationChance}% chance to preserve one consumed material on supported crafting paths.",
                ] : null,
                $marketPremium > 0 ? [
                    'key' => 'provenance_value',
                    'label' => 'Provenance Value',
                    'description' => "+{$marketPremium}g market floor from rarity, tier, maker, and upgrade history.",
                ] : null,
            ])),
        ];
    }

    /**
     * @return array{experience: int, yield: int, gold: int, cooldown_reduction: int, critical_chance: int, material_preservation: int}
     */
    public function actionModifiers(ConnectedRealmsEquipmentSlot|ConnectedRealmsTool|null $tool): array
    {
        $payload = $this->payloadForEquipment($tool);
        $bonuses = $tool?->bonuses ?? [];
        $criticalChance = (int) ($payload['modifiers']['critical_chance'] ?? 0);

        return [
            'experience' => max(0, (int) ($bonuses['experience'] ?? 0)) + intdiv($criticalChance, 5),
            'yield' => max(0, (int) ($bonuses['yield'] ?? 0)) + intdiv($criticalChance, 12),
            'gold' => intdiv($criticalChance, 8),
            'cooldown_reduction' => (int) ($payload['modifiers']['cooldown_reduction'] ?? 0),
            'critical_chance' => $criticalChance,
            'material_preservation' => (int) ($payload['modifiers']['material_preservation'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(): array
    {
        return [
            'grade' => null,
            'signature_trait' => null,
            'discipline' => null,
            'modifiers' => [
                'critical_chance' => 0,
                'cooldown_reduction' => 0,
                'material_preservation' => 0,
                'market_premium' => 0,
            ],
            'perks' => [],
        ];
    }
}
