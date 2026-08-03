<template>
    <section class="surface-section xl:col-span-2">
        <div class="surface-section__header">
            <div class="surface-section__copy">
                <span class="surface-section__title">Latest Result</span>
                <p class="surface-section__subtitle">
                    {{ result.label }}<span v-if="contextLabel"> · {{ contextLabel }}</span>
                </p>
            </div>
            <span class="tag capitalize">{{ resultTypeLabel }}</span>
        </div>

        <div class="surface-section__body">
            <div class="grid gap-4 xl:grid-cols-[16rem_minmax(0,1fr)]">
                <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                    <p class="text-sm font-ui text-primary">Result Index</p>
                    <div class="mt-3 grid gap-2 text-xs">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Type</span>
                            <span class="text-primary">{{ resultTypeLabel }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Records</span>
                            <span class="text-primary">{{ recordCount }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Items</span>
                            <span class="text-primary">{{ itemCount }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Gold</span>
                            <span class="text-primary">{{ goldDeltaLabel }}</span>
                        </div>
                    </div>
                </div>

                <div class="grid gap-4">
                    <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-ui text-primary">{{ result.label }}</p>
                                <p class="mt-1 text-xs text-muted-2">{{ resultDetailLabel }}</p>
                            </div>
                            <span class="tag">{{ recordCount }} records</span>
                        </div>

                        <div v-if="summaryRows.length" class="mt-4 grid gap-3 md:grid-cols-3">
                            <div
                                v-for="row in summaryRows"
                                :key="row.label"
                                class="rounded-md border border-border bg-canvas px-3 py-3"
                            >
                                <p class="text-xs uppercase tracking-[0.14em] text-muted-3">{{ row.label }}</p>
                                <p class="mt-2 text-lg font-ui text-primary">{{ row.value }}</p>
                                <p class="mt-1 text-xs text-muted-2">{{ row.detail }}</p>
                            </div>
                        </div>

                        <div v-if="detailRows.length" class="mt-4 grid gap-3">
                            <article
                                v-for="(row, index) in detailRows"
                                :key="row.key"
                                class="grid gap-3 rounded-md border border-border bg-canvas px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_auto]"
                            >
                                <div class="grid h-9 w-9 place-items-center rounded-md border border-border bg-surface-2 text-sm font-ui text-primary">
                                    #{{ index + 1 }}
                                </div>

                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="min-w-0 truncate text-sm font-ui text-primary">{{ row.label }}</p>
                                        <span class="tag">{{ row.type }}</span>
                                    </div>
                                    <p class="mt-1 text-xs text-muted-2">{{ row.detail }}</p>
                                </div>

                                <div class="text-left md:text-right">
                                    <p class="text-sm font-ui text-primary">{{ row.value }}</p>
                                    <p class="mt-1 text-xs text-muted-3">{{ row.subvalue }}</p>
                                </div>
                            </article>
                        </div>

                        <div v-if="resultItems.length" class="mt-4 grid gap-3">
                            <article
                                v-for="(item, index) in resultItems"
                                :key="`${item.kind}-${item.item_key ?? item.label}-${index}`"
                                class="grid gap-3 rounded-md border border-border bg-canvas px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_auto]"
                            >
                                <div class="grid h-9 w-9 place-items-center rounded-md border border-border bg-surface-2 text-sm font-ui text-primary">
                                    #{{ index + 1 }}
                                </div>

                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="min-w-0 truncate text-sm font-ui text-primary">{{ item.item_name ?? item.label }}</p>
                                        <span class="tag capitalize">{{ item.kind }}</span>
                                        <span v-if="item.rarity" class="tag capitalize">{{ item.rarity }}</span>
                                        <span v-if="item.quality" class="tag capitalize">{{ item.quality }}</span>
                                    </div>
                                    <p class="mt-1 text-xs text-muted-2">
                                        {{ itemDetail(item) }}
                                    </p>
                                </div>

                                <div class="text-left md:text-right">
                                    <p class="text-sm font-ui text-primary">x{{ formatNumber(item.quantity ?? 1) }}</p>
                                    <p v-if="item.total_weight" class="mt-1 text-xs text-muted-3">{{ item.total_weight }} wt</p>
                                    <p v-else-if="item.weight" class="mt-1 text-xs text-muted-3">{{ item.weight }} wt ea</p>
                                </div>
                            </article>
                        </div>

                        <p v-if="!summaryRows.length && !detailRows.length && !resultItems.length" class="mt-4 text-sm text-muted-2">
                            No result records available.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    result: {
        type: Object,
        required: true,
    },
})

const resultTypeLabel = computed(() => typeLabels[props.result.type] ?? (props.result.type ? props.result.type.replaceAll('_', ' ') : 'action'))
const contextLabel = computed(() => props.result.location ?? props.result.region ?? props.result.category ?? props.result.skill_label ?? null)
const resultDetailLabel = computed(() => {
    if (contextLabel.value) {
        return `${resultTypeLabel.value} completed in ${contextLabel.value}.`
    }

    return `${resultTypeLabel.value} completed.`
})
const resultItems = computed(() => [
    ...itemsWithKind(props.result.items_awarded, 'awarded'),
    ...itemsWithKind(props.result.items_created, 'created'),
    ...itemsWithKind(props.result.items_delivered, 'delivered'),
    ...itemsWithKind(props.result.items_consumed, 'consumed'),
    ...itemsWithKind(props.result.supplies_consumed, 'consumed'),
    ...directResultItem.value,
])
const directResultItem = computed(() => {
    if (!props.result.item_key || hasNestedResultItem.value) {
        return []
    }

    return [{
        item_key: props.result.item_key,
        item_name: props.result.item_name ?? props.result.label,
        rarity: props.result.rarity,
        quality: props.result.quality,
        item_class: props.result.item_class,
        material_family: props.result.material_family,
        weight: props.result.weight,
        total_weight: props.result.total_weight,
        vendor_value: props.result.vendor_value,
        quantity: props.result.quantity ?? 1,
        kind: resultTypeLabel.value,
    }]
})
const hasNestedResultItem = computed(() => [
    props.result.items_awarded,
    props.result.items_created,
    props.result.items_delivered,
    props.result.items_consumed,
    props.result.supplies_consumed,
].some((items) => (items ?? []).some((item) => item.item_key === props.result.item_key)))
const rewardRows = computed(() => (props.result.rewards ?? []).map((reward) => ({
    key: `reward-${reward.type}-${reward.label}`,
    type: 'reward',
    label: reward.label,
    detail: 'Commission payout',
    value: formatNumber(reward.quantity),
    subvalue: reward.type,
})))
const detailRows = computed(() => [
    ...toolRows.value,
    ...eventRows.value,
    ...rewardRows.value,
])
const toolRows = computed(() => {
    if (!props.result.tool) {
        return []
    }

    return [{
        key: 'tool',
        type: 'equipment',
        label: props.result.tool.item_name,
        detail: props.result.tool.slot_label ?? 'Equipped tool',
        value: `+${props.result.tool.experience_bonus ?? 0} XP`,
        subvalue: `+${props.result.tool.yield_bonus ?? 0} yield`,
    }]
})
const eventRows = computed(() => {
    if (!props.result.event) {
        return []
    }

    return [{
        key: 'event',
        type: 'world event',
        label: props.result.event.label,
        detail: 'Bonus applied',
        value: `+${props.result.event.experience ?? 0} XP`,
        subvalue: `+${props.result.event.yield ?? 0} yield`,
    }]
})
const summaryRows = computed(() => [
    metricRow('XP', props.result.experience_awarded, 'Experience gained', '+'),
    metricRow('Gold Earned', props.result.gold_awarded, 'Gold added', '+'),
    metricRow('Gold Spent', props.result.gold_spent ?? positiveOrNull(props.result.gold_cost), 'Gold removed', '-'),
    metricRow('Trade Value', props.result.total_price, 'Marketplace value'),
].filter(Boolean))
const itemCount = computed(() => resultItems.value.reduce((total, item) => total + Number(item.quantity ?? 1), 0))
const recordCount = computed(() => summaryRows.value.length + detailRows.value.length + resultItems.value.length)
const goldDeltaLabel = computed(() => {
    if (props.result.gold_awarded !== undefined) {
        return `+${formatNumber(props.result.gold_awarded)}`
    }

    if (props.result.gold_spent !== undefined || props.result.gold_cost > 0) {
        return `-${formatNumber(props.result.gold_spent ?? props.result.gold_cost)}`
    }

    if (props.result.total_price) {
        return formatNumber(props.result.total_price)
    }

    return '0'
})

const typeLabels = {
    crafting: 'crafting',
    expedition: 'expedition',
    job: 'job',
    market_cancel: 'market cancel',
    market_listing: 'market listing',
    market_purchase: 'market purchase',
    shop: 'shop',
    tool_rarity_upgrade: 'tool upgrade',
    tool_tier_upgrade: 'tool tier',
    tool_equip: 'tool equip',
    tool_unequip: 'tool unequip',
}

function itemsWithKind(items, kind) {
    return (items ?? []).map((item) => ({
        ...item,
        kind,
    }))
}

function metricRow(label, value, detail, prefix = '') {
    if (value === undefined || value === null || Number(value) === 0) {
        return null
    }

    return {
        label,
        value: `${prefix}${formatNumber(value)}`,
        detail,
    }
}

function positiveOrNull(value) {
    return Number(value) > 0 ? value : null
}

function formatNumber(value) {
    return Number(value ?? 0).toLocaleString()
}

function itemDetail(item) {
    return [
        item.item_class,
        item.material_family,
        item.vendor_value ? `${item.vendor_value}g value` : null,
    ].filter(Boolean).join(' · ') || 'Inventory record'
}
</script>
