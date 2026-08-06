<template>
    <PopupCard
        :show="show"
        :title="orderBook?.item?.name ?? 'Order book'"
        :subtitle="subtitle"
        eyebrow="Market order book"
        max-width="5xl"
        @close="$emit('close')"
    >
        <template #actions>
            <span class="tag">{{ formatCount(sortedSellOrders.length) }} sell</span>
            <span class="tag">{{ formatCount(sortedBuyOrders.length) }} buy</span>
            <span class="tag">Lowest sell {{ formatCoins(orderBook?.stats?.lowestSell) }}</span>
            <span class="tag">Highest buy {{ formatCoins(orderBook?.stats?.highestBuy) }}</span>
        </template>

        <div class="mb-4 flex flex-wrap gap-2 border-b border-border pb-4">
            <button
                v-for="option in sortOptions"
                :key="option.value"
                type="button"
                class="tag transition-colors hover:text-focus"
                :class="{ 'border-[rgb(var(--accent-cyan-rgb)/0.55)] text-focus': sortKey === option.value }"
                @click="setSort(option.value)"
            >
                {{ sortButtonLabel(option) }}
            </button>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <OrderList
                title="Sell Orders"
                side="sell"
                empty-label="No active sell orders."
                :orders="sortedSellOrders"
                :claim-link-href="claimLinkHref"
            />
            <OrderList
                title="Buy Orders"
                side="buy"
                empty-label="No active buy orders."
                :orders="sortedBuyOrders"
                :claim-link-href="claimLinkHref"
            />
        </div>
    </PopupCard>
</template>

<script setup>
import { computed, h, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import PopupCard from '@/Components/ui/PopupCard.vue'

const props = defineProps({
    show: { type: Boolean, default: false },
    orderBook: { type: Object, default: null },
    claimLinkHref: { type: Function, required: true },
})

defineEmits(['close'])

const sortOptions = [
    { value: 'price', label: 'Price' },
    { value: 'quantity', label: 'Qty' },
    { value: 'lineTotal', label: 'Line total' },
]

const sortKey = ref('price')
const sortDirection = ref('desc')

const sortedSellOrders = computed(() => sortedOrders(props.orderBook?.sellOrders ?? []))
const sortedBuyOrders = computed(() => sortedOrders(props.orderBook?.buyOrders ?? []))

const subtitle = computed(() => {
    const category = props.orderBook?.item?.category

    return category ? `Real Bitjita orders · ${category}` : 'Real Bitjita orders'
})

const formatCoins = (value) => {
    if (value === null || value === undefined || value === '') {
        return '-'
    }

    const number = Number(value)

    return Number.isFinite(number) ? number.toLocaleString() : String(value)
}

const formatCount = (value) => {
    if (value === null || value === undefined || value === '') {
        return '0'
    }

    const number = Number(value)

    if (number === 2147483647) {
        return '∞'
    }

    return Number.isFinite(number) ? number.toLocaleString() : String(value)
}

const normalizeTimestamp = (value) => {
    if (!value) {
        return null
    }

    const normalized = String(value)
        .replace(' ', 'T')
        .replace(/(\.\d{3})\d+/, '$1')
        .replace(/([+-]\d{2})$/, '$1:00')

    const date = new Date(normalized)

    if (Number.isNaN(date.getTime())) {
        const fallback = String(value).match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/)

        return fallback ? `${fallback[1]} ${fallback[2]}` : String(value)
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date)
}

const formatTotal = (order) => {
    const total = lineTotal(order)

    return total === null ? '-' : formatCoins(total)
}

const lineTotal = (order) => {
    const price = numericValue(order.price)
    const quantity = numericValue(order.quantity)

    return price !== null && quantity !== null ? price * quantity : null
}

const numericValue = (value) => {
    const number = Number(value)

    return Number.isFinite(number) ? number : null
}

const setSort = (key) => {
    if (sortKey.value === key) {
        sortDirection.value = sortDirection.value === 'desc' ? 'asc' : 'desc'

        return
    }

    sortKey.value = key
    sortDirection.value = 'desc'
}

const sortButtonLabel = (option) => {
    const direction = sortKey.value === option.value && sortDirection.value === 'asc' ? 'low' : 'high'

    return `${option.label} ${direction}`
}

const sortValue = (order) => {
    if (sortKey.value === 'quantity') {
        return numericValue(order.quantity)
    }

    if (sortKey.value === 'lineTotal') {
        return lineTotal(order)
    }

    return numericValue(order.price)
}

const sortedOrders = (orders) => [...orders].sort((first, second) => {
    const firstValue = sortValue(first)
    const secondValue = sortValue(second)

    if (firstValue === null && secondValue === null) {
        return String(first.claimName ?? '').localeCompare(String(second.claimName ?? ''))
    }

    if (firstValue === null) {
        return 1
    }

    if (secondValue === null) {
        return -1
    }

    return sortDirection.value === 'desc' ? secondValue - firstValue : firstValue - secondValue
})

const OrderList = (componentProps) => h('section', { class: 'index-surface index-surface--nested' }, [
    h('div', { class: 'px-4 py-3 border-b border-border' }, [
        h('div', { class: 'flex items-center justify-between gap-3' }, [
            h('h3', { class: 'surface-section__title' }, componentProps.title),
            h('span', { class: componentProps.side === 'sell' ? 'tag tag--success' : 'tag tag--warn' }, componentProps.side),
        ]),
    ]),
    componentProps.orders.length
        ? componentProps.orders.map((order) => h('div', { class: 'index-record', key: order.entityId }, [
            h('div', { class: 'grid gap-3' }, [
                h('div', { class: 'min-w-0' }, [
                    h('p', { class: 'index-record__title prose-wrap' }, order.claimName || 'Unknown market'),
                    h('div', { class: 'mt-2 flex flex-wrap gap-2' }, [
                        order.ownerUsername ? h('span', { class: 'tag' }, order.ownerUsername) : null,
                        order.regionName ? h('span', { class: 'tag' }, `Region: ${order.regionName}`) : null,
                        order.updatedAt ? h('span', { class: 'tag' }, normalizeTimestamp(order.updatedAt)) : null,
                    ]),
                    order.claimEntityId ? h(Link, {
                        href: componentProps.claimLinkHref(order),
                        class: 'dashboard-link mt-3 inline-flex',
                    }, () => 'Filter to this claim ->') : null,
                ]),
                h('div', { class: 'grid gap-2 border-t border-border pt-3 text-xs font-ui text-muted-2 sm:grid-cols-3' }, [
                    h('div', { class: 'rounded-md border border-border bg-surface-2 px-3 py-2' }, [
                        h('span', { class: 'block uppercase tracking-wide text-[10px] text-muted-3' }, 'Unit price'),
                        h('span', { class: 'mt-1 block text-sm font-semibold text-primary' }, formatCoins(order.price)),
                    ]),
                    h('div', { class: 'rounded-md border border-border bg-surface-2 px-3 py-2' }, [
                        h('span', { class: 'block uppercase tracking-wide text-[10px] text-muted-3' }, 'Qty available'),
                        h('span', { class: 'mt-1 block text-sm font-semibold text-primary' }, formatCount(order.quantity)),
                    ]),
                    h('div', { class: 'rounded-md border border-border bg-surface-2 px-3 py-2' }, [
                        h('span', { class: 'block uppercase tracking-wide text-[10px] text-muted-3' }, 'Line total'),
                        h('span', { class: 'mt-1 block text-sm font-semibold text-primary' }, formatTotal(order)),
                    ]),
                ]),
            ]),
        ]))
        : h('div', { class: 'empty-state-panel' }, [
            h('p', { class: 'text-muted-3 text-sm font-ui' }, componentProps.emptyLabel),
        ]),
])

OrderList.props = {
    title: { type: String, required: true },
    side: { type: String, required: true },
    emptyLabel: { type: String, required: true },
    orders: { type: Array, default: () => [] },
    claimLinkHref: { type: Function, required: true },
}
</script>
