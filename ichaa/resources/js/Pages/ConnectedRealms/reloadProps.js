const baseActionProps = [
    'player',
    'inventory',
    'item_guide',
    'recent_actions',
    'summary',
    'last_result',
    'progression',
]

export const actionReloadProps = [
    ...baseActionProps,
    'actions',
]

export const activityReloadProps = [
    ...baseActionProps,
    'skill_activities',
]

export const craftingReloadProps = [
    'player',
    'inventory',
    'item_guide',
    'crafting_recipes',
    'jobs',
    'expeditions',
    'marketplace',
    'equipment',
    'tool_inventory',
    'tool_rarity_upgrades',
    'tool_tier_upgrades',
    'recent_crafts',
    'summary',
    'last_result',
    'progression',
]

export const jobReloadProps = [
    'player',
    'inventory',
    'item_guide',
    'jobs',
    'marketplace',
    'recent_jobs',
    'summary',
    'last_result',
    'progression',
]

export const expeditionReloadProps = [
    'player',
    'inventory',
    'item_guide',
    'expeditions',
    'recent_expeditions',
    'summary',
    'last_result',
    'progression',
]

export const shopReloadProps = [
    'player',
    'shop',
    'equipment',
    'tool_inventory',
    'tool_rarity_upgrades',
    'tool_tier_upgrades',
    'inventory',
    'item_guide',
    'marketplace',
    'summary',
    'last_result',
]

export const equipmentReloadProps = [
    'player',
    'equipment',
    'tool_inventory',
    'tool_rarity_upgrades',
    'tool_tier_upgrades',
    'actions',
    'skill_activities',
    'item_guide',
    'shop',
    'summary',
    'last_result',
]

export const marketplaceReloadProps = [
    'player',
    'marketplace',
    'inventory',
    'item_guide',
    'tool_inventory',
    'summary',
    'last_result',
]

export const characterReloadProps = [
    'player',
    'summary',
    'last_result',
]

export const achievementReloadProps = [
    'player',
    'progression',
    'summary',
    'last_result',
]
