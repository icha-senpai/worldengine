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
import { computed, h } from 'vue'
import { Link } from '@inertiajs/vue3'
import PopupCard from '@/Components/ui/PopupCard.vue'

const props = defineProps({
    show: { type: Boolean, default: false },
    orderBook: { type: Object, default: null },
    claimLinkHref: { type: Function, required: true },
})

defineEmits(['close'])

const sortedSellOrders = computed(() => sortedOrders(props.orderBook?.sellOrders ?? [], 'sell'))
const sortedBuyOrders = computed(() => sortedOrders(props.orderBook?.buyOrders ?? [], 'buy'))

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
    const price = Number(order.price)
    const quantity = Number(order.quantity)

    return Number.isFinite(price) && Number.isFinite(quantity) ? formatCoins(price * quantity) : '-'
}

const sortedOrders = (orders, side) => [...orders].sort((first, second) => {
    const firstPrice = Number(first.price)
    const secondPrice = Number(second.price)

    if (!Number.isFinite(firstPrice) || !Number.isFinite(secondPrice)) {
        return 0
    }

    return side === 'buy' ? secondPrice - firstPrice : firstPrice - secondPrice
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
