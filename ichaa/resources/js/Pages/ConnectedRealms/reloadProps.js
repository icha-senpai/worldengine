const baseActionProps = [
    'player',
    'inventory',
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
    'crafting_recipes',
    'recent_crafts',
    'summary',
    'last_result',
    'progression',
]

export const jobReloadProps = [
    'player',
    'inventory',
    'jobs',
    'recent_jobs',
    'summary',
    'last_result',
    'progression',
]

export const expeditionReloadProps = [
    'player',
    'inventory',
    'expeditions',
    'recent_expeditions',
    'summary',
    'last_result',
    'progression',
]

export const shopReloadProps = [
    'player',
    'shop',
    'inventory',
    'summary',
    'last_result',
]

export const equipmentReloadProps = [
    'player',
    'equipment',
    'tool_inventory',
    'tool_rarity_upgrades',
    'tool_tier_upgrades',
    'summary',
    'last_result',
]

export const marketplaceReloadProps = [
    'player',
    'marketplace',
    'inventory',
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
