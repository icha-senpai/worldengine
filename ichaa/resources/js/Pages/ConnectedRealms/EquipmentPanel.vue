<template>
    <section class="surface-section xl:col-span-2">
        <div class="surface-section__header">
            <div class="surface-section__copy">
                <span class="surface-section__title">Equipment</span>
                <p class="surface-section__subtitle">{{ equipment.length }} tools equipped · {{ inventoryTools.length }} stored tools.</p>
            </div>
        </div>

        <div class="surface-section__body">
            <div class="grid gap-4 xl:grid-cols-[16rem_minmax(0,1fr)]">
                <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-ui text-primary">Tool Loadout</p>
                        <span class="tag">{{ visibleEquipmentWithUpgrades.length }}</span>
                    </div>

                    <div class="mt-4 grid gap-2">
                        <button
                            v-for="filter in categoryFilters"
                            :key="filter.key"
                            type="button"
                            class="grid gap-2 rounded-md border border-border bg-canvas px-3 py-2 text-left transition hover:border-focus/60"
                            :class="{ 'border-focus/70 bg-focus/10': selectedCategory === filter.key }"
                            @click="selectedCategory = filter.key"
                        >
                            <span class="flex min-w-0 items-center justify-between gap-2">
                                <span class="truncate text-xs font-ui text-primary">{{ filter.label }}</span>
                                <span class="text-[11px] text-muted-3">{{ filter.count }}</span>
                            </span>
                            <span class="h-1.5 overflow-hidden rounded-full bg-surface-1">
                                <span class="block h-full rounded-full bg-focus" :style="{ width: `${filterProgress(filter)}%` }" />
                            </span>
                        </button>
                    </div>

                    <div class="mt-3 grid gap-2 text-xs">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Equipped</span>
                            <span class="text-primary">{{ equipment.length }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Visible</span>
                            <span class="text-primary">{{ visibleEquipmentWithUpgrades.length }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">XP Bonus</span>
                            <span class="text-primary">+{{ totalExperience }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Yield Bonus</span>
                            <span class="text-primary">+{{ totalYield }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Stored Tools</span>
                            <span class="text-primary">{{ inventoryTools.length }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Tier Ready</span>
                            <span class="text-primary">{{ toolTierUpgrades.ready_count ?? 0 }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Rarity Ready</span>
                            <span class="text-primary">{{ toolRarityUpgrades.ready_count ?? 0 }}</span>
                        </div>
                    </div>
                </div>

                <div v-if="visibleEquipmentWithUpgrades.length" class="grid content-start gap-3">
                    <article
                        v-for="(entry, index) in visibleEquipmentWithUpgrades"
                        :key="entry.tool.slot"
                        class="grid min-h-44 items-start gap-3 rounded-md border border-border bg-surface-2 px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_9rem]"
                    >
                        <div class="grid h-9 w-9 place-items-center rounded-md border border-border bg-canvas text-sm font-ui text-primary">
                            #{{ index + 1 }}
                        </div>

                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="min-w-0 truncate text-sm font-ui text-primary">{{ entry.tool.item_name }}</p>
                                <span class="tag capitalize">{{ entry.tool.rarity }}</span>
                                <span v-if="entry.tool.quality" class="tag capitalize">{{ entry.tool.quality }}</span>
                                <span v-if="entry.tool.origin_label" class="tag">{{ entry.tool.origin_label }}</span>
                            </div>
                            <p class="mt-1 text-xs text-muted-2">
                                {{ entry.tool.category }} · {{ entry.tool.slot_label }}<span v-if="entry.tool.maker_name"> · made by {{ entry.tool.maker_name }}</span>
                            </p>
                            <p v-if="entry.tool.signature_trait" class="mt-2 text-xs text-muted-2">
                                {{ entry.tool.signature_trait }} · {{ entry.tool.discipline }}
                            </p>
                            <div v-if="entry.tool.perks?.length" class="mt-3 grid gap-2">
                                <div
                                    v-for="perk in entry.tool.perks.slice(0, 3)"
                                    :key="`${entry.tool.slot}-${perk.key}`"
                                    class="rounded-md border border-border bg-canvas px-3 py-2"
                                >
                                    <p class="text-xs font-ui text-primary">{{ perk.label }}</p>
                                    <p class="mt-1 text-xs text-muted-3">{{ perk.description }}</p>
                                </div>
                            </div>
                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-canvas">
                                <div
                                    class="h-full rounded-full bg-success"
                                    :style="{ width: `${entry.tool.durability}%` }"
                                />
                            </div>
                            <div v-if="entry.upgrade" class="mt-3">
                                <div class="flex items-center justify-between gap-3 text-xs">
                                    <span class="text-muted-2">Rarity progress</span>
                                    <span class="text-primary">{{ entry.upgrade.rarity_progress }}%</span>
                                </div>
                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-canvas">
                                    <div
                                        class="h-full rounded-full bg-focus"
                                        :style="{ width: `${entry.upgrade.rarity_progress}%` }"
                                    />
                                </div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <span v-if="entry.upgrade.target_rarity" class="tag capitalize">{{ entry.upgrade.current_rarity }} to {{ entry.upgrade.target_rarity }}</span>
                                    <span v-if="entry.upgrade.rarity_cap && !entry.upgrade.is_max_rarity" class="tag capitalize">cap {{ entry.upgrade.rarity_cap }}</span>
                                    <span v-if="entry.upgrade.is_tier_capped" class="tag">{{ entry.upgrade.status }}</span>
                                    <span v-if="!entry.upgrade.is_max_rarity" class="tag">{{ entry.upgrade.success_chance }}% hit</span>
                                    <span v-if="!entry.upgrade.is_max_rarity" class="tag">{{ entry.upgrade.progress_gain_min }}-{{ entry.upgrade.progress_gain_max }} progress</span>
                                    <span v-if="!entry.upgrade.is_max_rarity" class="tag">{{ entry.upgrade.gold_cost }}g</span>
                                    <span
                                        v-for="material in entry.upgrade.materials"
                                        :key="`${entry.tool.slot}-rarity-${material.item_key}`"
                                        v-if="!entry.upgrade.is_max_rarity"
                                        class="tag"
                                    >
                                        {{ material.quantity }} {{ material.item_name }}
                                    </span>
                                    <span v-if="entry.upgrade.is_max_rarity" class="tag">Max rarity</span>
                                </div>
                            </div>
                            <div v-if="entry.tier" class="mt-3 rounded-md border border-border bg-canvas px-3 py-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs font-ui text-primary">Next tier</p>
                                    <span class="tag">{{ entry.tier.status }}</span>
                                </div>
                                <p class="mt-2 text-xs text-muted-2">
                                    {{ entry.tier.is_max_tier ? 'Highest tool tier reached.' : `${entry.tier.next_item_name} · ${entry.tier.craft_skill_label} Lv ${entry.tier.required_level}` }}
                                </p>
                                <div v-if="!entry.tier.is_max_tier" class="mt-2 flex flex-wrap gap-2">
                                    <span class="tag">{{ entry.tier.gold_cost }}g</span>
                                    <span class="tag">+{{ entry.tier.experience_awarded }} XP</span>
                                    <span
                                        v-for="ingredient in entry.tier.ingredients"
                                        :key="`${entry.tool.slot}-${ingredient.item_key}`"
                                        class="tag"
                                    >
                                        {{ ingredient.quantity }} {{ ingredient.item_name }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="grid content-between gap-3 md:justify-items-end">
                            <div class="flex flex-wrap content-start gap-2 md:justify-end">
                                <span class="tag">+{{ entry.tool.experience_bonus }} XP</span>
                                <span class="tag">+{{ entry.tool.yield_bonus }} yield</span>
                                <span v-if="entry.tool.weight" class="tag">{{ entry.tool.weight }} wt</span>
                                <span v-if="entry.tool.upgrade_count" class="tag">{{ entry.tool.upgrade_count }} upgrades</span>
                                <span v-if="entry.tool.rarity_upgrade_attempts" class="tag">{{ entry.tool.rarity_upgrade_attempts }} attempts</span>
                            </div>

                            <button
                                v-if="entry.upgrade"
                                type="button"
                                class="app-btn app-btn--sm"
                                :disabled="rarityForm.processing || !entry.upgrade.can_upgrade"
                                @click="attemptRarityUpgrade(entry.tool.slot)"
                            >
                                {{ runningEquipmentAction === equipmentActionKey('rarity', entry.tool.slot) ? 'Attempting...' : entry.upgrade.is_max_rarity ? 'Maxed' : entry.upgrade.can_upgrade ? 'Attempt' : entry.upgrade.status }}
                            </button>
                            <button
                                v-if="entry.tier"
                                type="button"
                                class="app-btn app-btn--ghost app-btn--sm"
                                :disabled="tierForm.processing || !entry.tier.can_upgrade"
                                @click="attemptTierUpgrade(entry.tool.slot)"
                            >
                                {{ runningEquipmentAction === equipmentActionKey('tier', entry.tool.slot) ? 'Upgrading...' : entry.tier.is_max_tier ? 'Max Tier' : entry.tier.can_upgrade ? 'Tier Up' : entry.tier.status }}
                            </button>
                            <button
                                type="button"
                                class="app-btn app-btn--ghost app-btn--sm"
                                :disabled="inventoryForm.processing || entry.tool.origin === 'starter'"
                                @click="unequipTool(entry.tool.slot)"
                            >
                                {{ runningEquipmentAction === equipmentActionKey('unequip', entry.tool.slot) ? 'Unequipping...' : entry.tool.origin === 'starter' ? 'Field Kit' : 'Unequip' }}
                            </button>
                        </div>
                    </article>
                </div>

                <p v-else class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2">
                    No tools match this category.
                </p>
            </div>

            <p v-if="rarityForm.errors.slot" class="mt-4 text-sm text-(--accent-pink)">
                {{ rarityForm.errors.slot }}
            </p>
            <p v-if="tierForm.errors.slot" class="mt-2 text-sm text-(--accent-pink)">
                {{ tierForm.errors.slot }}
            </p>
            <p v-if="inventoryForm.errors.slot || inventoryForm.errors.tool_id" class="mt-2 text-sm text-(--accent-pink)">
                {{ inventoryForm.errors.slot ?? inventoryForm.errors.tool_id }}
            </p>

            <div v-if="inventoryTools.length" class="mt-4 rounded-md border border-border bg-surface-2 px-3 py-3">
                <p class="text-sm font-ui text-primary">Tool Inventory</p>
                <div class="mt-3 grid gap-2 md:grid-cols-2">
                    <article
                        v-for="tool in inventoryTools"
                        :key="tool.tool_id"
                        class="min-h-36 rounded-md border border-border bg-canvas px-3 py-3"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="truncate text-sm font-ui text-primary">{{ tool.item_name }}</p>
                            <span class="tag capitalize">{{ tool.rarity }}</span>
                            <span class="tag">{{ tool.status_label }}</span>
                        </div>
                        <p class="mt-1 text-xs text-muted-2">
                            {{ tool.slot_label }} · +{{ tool.experience_bonus }} XP · +{{ tool.yield_bonus }} yield
                        </p>
                        <p v-if="tool.signature_trait" class="mt-2 text-xs text-muted-2">
                            {{ tool.signature_trait }} · {{ tool.discipline }}
                        </p>
                        <p class="mt-2 text-xs text-muted-3">
                            Market {{ tool.market_price_band }}<span v-if="tool.maker_name"> · made by {{ tool.maker_name }}</span>
                        </p>
                        <button
                            type="button"
                            class="app-btn app-btn--sm mt-3"
                            :disabled="inventoryForm.processing"
                            @click="equipTool(tool.tool_id)"
                        >
                            {{ runningEquipmentAction === equipmentActionKey('equip', tool.tool_id) ? 'Equipping...' : 'Equip' }}
                        </button>
                    </article>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { equipmentReloadProps } from './reloadProps'

const props = defineProps({
    equipment: {
        type: Array,
        required: true,
    },
    toolInventory: {
        type: Array,
        required: true,
    },
    toolRarityUpgrades: {
        type: Object,
        required: true,
    },
    toolTierUpgrades: {
        type: Object,
        required: true,
    },
})

const selectedCategory = ref('All')
const runningEquipmentAction = ref('')
const totalExperience = computed(() => props.equipment.reduce((total, tool) => total + tool.experience_bonus, 0))
const totalYield = computed(() => props.equipment.reduce((total, tool) => total + tool.yield_bonus, 0))
const rarityForm = useForm({
    slot: null,
})
const tierForm = useForm({
    slot: null,
})
const inventoryForm = useForm({
    tool_id: null,
    slot: null,
})
const upgradesBySlot = computed(() => Object.fromEntries((props.toolRarityUpgrades.options ?? []).map((upgrade) => [upgrade.slot, upgrade])))
const tierUpgradesBySlot = computed(() => Object.fromEntries((props.toolTierUpgrades.options ?? []).map((upgrade) => [upgrade.slot, upgrade])))
const equipmentWithUpgrades = computed(() => props.equipment.map((tool) => ({
    tool,
    upgrade: upgradesBySlot.value[tool.slot] ?? null,
    tier: tierUpgradesBySlot.value[tool.slot] ?? null,
})))
const categoryFilters = computed(() => ['All', ...new Set(props.equipment.map((tool) => tool.category).filter(Boolean))].map((filter) => ({
    key: filter,
    label: filter,
    count: props.equipment.filter((tool) => filter === 'All' || tool.category === filter).length,
})))
const visibleEquipmentWithUpgrades = computed(() => equipmentWithUpgrades.value.filter((entry) => selectedCategory.value === 'All' || entry.tool.category === selectedCategory.value))
const inventoryTools = computed(() => props.toolInventory.filter((tool) => tool.status === 'inventory'))

function filterProgress(filter) {
    if (!filter.count) {
        return 0
    }

    const upgradeReadyCount = equipmentWithUpgrades.value.filter((entry) => (filter.key === 'All' || entry.tool.category === filter.key) && (entry.upgrade?.can_upgrade || entry.tier?.can_upgrade)).length

    return Math.round((upgradeReadyCount / filter.count) * 100)
}

function attemptRarityUpgrade(slot) {
    rarityForm.slot = slot
    rarityForm.post(route('evergather.tools.rarity-upgrades.store'), {
        preserveScroll: true,
        only: equipmentReloadProps,
        onStart: () => {
            runningEquipmentAction.value = equipmentActionKey('rarity', slot)
        },
        onFinish: () => {
            runningEquipmentAction.value = ''
        },
    })
}

function attemptTierUpgrade(slot) {
    tierForm.slot = slot
    tierForm.post(route('evergather.tools.tier-upgrades.store'), {
        preserveScroll: true,
        only: equipmentReloadProps,
        onStart: () => {
            runningEquipmentAction.value = equipmentActionKey('tier', slot)
        },
        onFinish: () => {
            runningEquipmentAction.value = ''
        },
    })
}

function equipTool(toolId) {
    inventoryForm.tool_id = toolId
    inventoryForm.slot = null
    inventoryForm.post(route('evergather.tools.equipment.store'), {
        preserveScroll: true,
        only: equipmentReloadProps,
        onStart: () => {
            runningEquipmentAction.value = equipmentActionKey('equip', toolId)
        },
        onFinish: () => {
            runningEquipmentAction.value = ''
        },
    })
}

function unequipTool(slot) {
    inventoryForm.slot = slot
    inventoryForm.tool_id = null
    inventoryForm.delete(route('evergather.tools.equipment.destroy'), {
        preserveScroll: true,
        only: equipmentReloadProps,
        onStart: () => {
            runningEquipmentAction.value = equipmentActionKey('unequip', slot)
        },
        onFinish: () => {
            runningEquipmentAction.value = ''
        },
    })
}

function equipmentActionKey(action, value) {
    return `${action}:${value}`
}
</script>
