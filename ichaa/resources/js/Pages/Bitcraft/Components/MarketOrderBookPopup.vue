<template>
    <PopupCard
        :show="show"
        :title="orderBook?.item?.name ?? 'Order book'"
        :subtitle="subtitle"
        eyebrow="Market order book"
        max-width="6xl"
        @close="$emit('close')"
    >
        <template #actions>
            <span class="tag">{{ formatCount(allSellOrders.length) }} sell</span>
            <span class="tag">{{ formatCount(allBuyOrders.length) }} buy</span>
            <span v-if="sortedPackageSellOrders.length" class="tag">{{ formatCount(sortedPackageSellOrders.length) }} package sell</span>
            <span v-if="sortedPackageBuyOrders.length" class="tag">{{ formatCount(sortedPackageBuyOrders.length) }} package buy</span>
        </template>

        <section v-if="orderBook?.item" class="market-order-item" data-test="order-book-item">
            <span
                class="market-order-item__icon"
                :style="orderBookItemFrameStyle"
                aria-hidden="true"
            >
                <img
                    v-if="orderBookItemIconUrl"
                    :src="orderBookItemIconUrl"
                    alt=""
                    loading="lazy"
                    @error="hideBrokenIcon(orderBook.item.iconAssetName)"
                >
                <span v-else>{{ itemInitials(orderBook.item.name) }}</span>
            </span>
            <div class="market-order-item__copy">
                <strong class="market-order-item__title">{{ orderBook.item.name }}</strong>
                <div class="market-order-item__tags">
                    <span v-if="orderBook.item.rarity" class="tag bitcraft-rarity-badge" :style="rarityStyle(orderBook.item.rarity)">{{ orderBook.item.rarity }}</span>
                    <BitcraftTierBadge v-if="hasTier(orderBook.item.tier)" :tier="orderBook.item.tier" />
                    <span v-if="orderBook.item.category" class="tag">{{ orderBook.item.category }}</span>
                </div>
            </div>
        </section>

        <section class="market-summary-grid" data-test="order-book-summary">
            <div class="market-summary-card market-summary-card--sell">
                <span class="market-summary-card__label">Best sell</span>
                <strong>{{ formatCoins(bestSellPrice) }}</strong>
                <span>{{ bestSellOrder ? `${formatCount(bestSellOrder.quantity)} at ${orderLocationName(bestSellOrder)}` : 'No sell orders' }}</span>
            </div>
            <div class="market-summary-card market-summary-card--buy">
                <span class="market-summary-card__label">Best buy</span>
                <strong>{{ formatCoins(bestBuyPrice) }}</strong>
                <span>{{ bestBuyOrder ? `${formatCount(bestBuyOrder.quantity)} at ${orderLocationName(bestBuyOrder)}` : 'No buy orders' }}</span>
            </div>
            <div class="market-summary-card">
                <span class="market-summary-card__label">Spread</span>
                <strong>{{ formatCoins(spreadValue) }}</strong>
                <span>{{ spreadPercentLabel }}</span>
            </div>
            <div class="market-summary-card">
                <span class="market-summary-card__label">Liquidity</span>
                <strong>{{ formatCount(totalSellQuantity + totalBuyQuantity) }}</strong>
                <span>{{ formatCount(totalSellQuantity) }} for sale · {{ formatCount(totalBuyQuantity) }} wanted</span>
            </div>
        </section>

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
            <section class="market-order-table-panel">
                <div class="market-order-table-panel__header">
                    <div>
                        <h3 class="surface-section__title">Sell Orders</h3>
                        <p class="mt-1 text-xs font-ui text-muted-2">{{ formatCount(filteredSellOrders.length) }} shown</p>
                    </div>
                    <span class="tag tag--success">sell</span>
                </div>

                <div class="market-order-filters">
                    <input v-model.trim="sellFilters.minQuantity" type="number" min="0" inputmode="numeric" placeholder="Min qty" aria-label="Minimum sell quantity" />
                    <input v-model.trim="sellFilters.location" type="search" placeholder="Location" aria-label="Filter sell location" />
                    <input v-model.trim="sellFilters.owner" type="search" placeholder="Seller" aria-label="Filter seller" />
                </div>

                <OrderTable
                    :orders="sortedSellOrders"
                    side="sell"
                    owner-label="Seller"
                    empty-label="No active sell orders match these filters."
                    :claim-link-href="claimLinkHref"
                />

                <div v-if="allSellOrders.length" class="cost-calculator" data-test="cost-to-buy">
                    <label class="cost-calculator__field">
                        <span>Cost to buy</span>
                        <input v-model.trim="buyQuantity" type="number" min="1" inputmode="numeric" placeholder="quantity" />
                    </label>
                    <div class="cost-calculator__result">
                        <strong>{{ costToBuyLabel }}</strong>
                        <span>{{ costToBuyDetail }}</span>
                    </div>
                </div>
            </section>

            <section class="market-order-table-panel">
                <div class="market-order-table-panel__header">
                    <div>
                        <h3 class="surface-section__title">Buy Orders</h3>
                        <p class="mt-1 text-xs font-ui text-muted-2">{{ formatCount(filteredBuyOrders.length) }} shown</p>
                    </div>
                    <span class="tag tag--warn">buy</span>
                </div>

                <div class="market-order-filters">
                    <input v-model.trim="buyFilters.minQuantity" type="number" min="0" inputmode="numeric" placeholder="Min qty" aria-label="Minimum buy quantity" />
                    <input v-model.trim="buyFilters.location" type="search" placeholder="Location" aria-label="Filter buy location" />
                    <input v-model.trim="buyFilters.owner" type="search" placeholder="Buyer" aria-label="Filter buyer" />
                </div>

                <OrderTable
                    :orders="sortedBuyOrders"
                    side="buy"
                    owner-label="Buyer"
                    empty-label="No active buy orders match these filters."
                    :claim-link-href="claimLinkHref"
                />
            </section>
        </div>

        <section v-if="regionRows.length" class="market-order-table-panel mt-4" data-test="order-book-regions">
            <div class="market-order-table-panel__header">
                <div>
                    <h3 class="surface-section__title">By Region</h3>
                    <p class="mt-1 text-xs font-ui text-muted-2">Best visible orders grouped by region.</p>
                </div>
                <span class="tag">{{ formatCount(regionRows.length) }} regions</span>
            </div>

            <div class="market-table-scroll">
                <table class="market-order-table">
                    <thead>
                        <tr>
                            <th>Region</th>
                            <th class="text-right">Best sell</th>
                            <th class="text-right">For sale</th>
                            <th class="text-right">Best buy</th>
                            <th class="text-right">Wanted</th>
                            <th class="text-right">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in regionRows" :key="row.regionName">
                            <td>{{ row.regionName }}</td>
                            <td class="text-right text-danger">{{ formatCoins(row.bestSell) }}</td>
                            <td class="text-right">{{ formatCount(row.sellQuantity) }}</td>
                            <td class="text-right text-success">{{ formatCoins(row.bestBuy) }}</td>
                            <td class="text-right">{{ formatCount(row.buyQuantity) }}</td>
                            <td class="text-right">{{ formatShortTime(row.updatedAt) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="hasPackageOrders" class="mt-4">
            <div class="mb-3 border-b border-border pb-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="surface-section__title">Package Orders</h3>
                        <p class="mt-1 text-xs font-ui text-muted-2">
                            {{ packageInfoLabel }}
                        </p>
                    </div>
                    <span class="tag">Cargo package</span>
                </div>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                <section class="market-order-table-panel">
                    <div class="market-order-table-panel__header">
                        <h3 class="surface-section__title">Package Sell Orders</h3>
                        <span class="tag tag--success">sell</span>
                    </div>
                    <OrderTable
                        :orders="sortedPackageSellOrders"
                        side="sell"
                        owner-label="Seller"
                        empty-label="No active package sell orders."
                        :claim-link-href="claimLinkHref"
                        price-label="Package price"
                        quantity-label="Packages"
                    />
                </section>

                <section class="market-order-table-panel">
                    <div class="market-order-table-panel__header">
                        <h3 class="surface-section__title">Package Buy Orders</h3>
                        <span class="tag tag--warn">buy</span>
                    </div>
                    <OrderTable
                        :orders="sortedPackageBuyOrders"
                        side="buy"
                        owner-label="Buyer"
                        empty-label="No active package buy orders."
                        :claim-link-href="claimLinkHref"
                        price-label="Package price"
                        quantity-label="Packages"
                    />
                </section>
            </div>
        </section>

        <section class="market-order-table-panel mt-4" data-test="order-book-detail">
            <div class="market-order-table-panel__header">
                <h3 class="surface-section__title">Order Book Detail</h3>
                <span class="tag">stats</span>
            </div>
            <div class="market-detail-grid">
                <Metric label="Sell available" :value="formatCount(totalSellQuantity)" />
                <Metric label="Buy wanted" :value="formatCount(totalBuyQuantity)" />
                <Metric label="Sell orders" :value="formatCount(allSellOrders.length)" />
                <Metric label="Buy orders" :value="formatCount(allBuyOrders.length)" />
                <Metric label="Largest sell order" :value="formatCount(largestSellOrderQuantity)" />
                <Metric label="Largest buy order" :value="formatCount(largestBuyOrderQuantity)" />
                <Metric label="New sells" :value="formatCount(orderBook?.stats?.recentSellOrders)" />
                <Metric label="New buys" :value="formatCount(orderBook?.stats?.recentBuyOrders)" />
                <Metric label="Order book updated" :value="formatShortTime(orderBook?.stats?.lastUpdated)" />
            </div>
        </section>
    </PopupCard>
</template>

<script setup>
import { computed, h, reactive, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import PopupCard from '@/Components/ui/PopupCard.vue'
import BitcraftTierBadge from '@/Pages/Bitcraft/Components/BitcraftTierBadge.vue'
import { bitcraftItemFrameStyle, bitcraftRarityStyle, bitjitaAssetUrl, hasBitcraftTier } from '@/Pages/Bitcraft/bitjitaAssets.js'

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
const buyQuantity = ref('')
const brokenIconAssets = ref(new Set())
const sellFilters = reactive({ minQuantity: '', location: '', owner: '' })
const buyFilters = reactive({ minQuantity: '', location: '', owner: '' })

const orderBookItem = computed(() => props.orderBook?.item ?? null)
const orderBookItemIconUrl = computed(() => {
    const assetName = orderBookItem.value?.iconAssetName

    if (!assetName || brokenIconAssets.value.has(assetName)) {
        return null
    }

    return bitjitaAssetUrl(assetName)
})
const orderBookItemFrameStyle = computed(() => bitcraftItemFrameStyle(orderBookItem.value?.tier, orderBookItem.value?.rarity))
const allSellOrders = computed(() => props.orderBook?.sellOrders ?? [])
const allBuyOrders = computed(() => props.orderBook?.buyOrders ?? [])
const filteredSellOrders = computed(() => filterOrders(allSellOrders.value, sellFilters))
const filteredBuyOrders = computed(() => filterOrders(allBuyOrders.value, buyFilters))
const sortedSellOrders = computed(() => sortedOrders(filteredSellOrders.value))
const sortedBuyOrders = computed(() => sortedOrders(filteredBuyOrders.value))
const sortedPackageSellOrders = computed(() => sortedOrders(props.orderBook?.packageSellOrders ?? []))
const sortedPackageBuyOrders = computed(() => sortedOrders(props.orderBook?.packageBuyOrders ?? []))
const packageOrderCount = computed(() => sortedPackageSellOrders.value.length + sortedPackageBuyOrders.value.length)
const hasPackageOrders = computed(() => packageOrderCount.value > 0)
const bestSellOrder = computed(() => bestOrder(allSellOrders.value, 'sell'))
const bestBuyOrder = computed(() => bestOrder(allBuyOrders.value, 'buy'))
const bestSellPrice = computed(() => numericValue(props.orderBook?.stats?.lowestSell) ?? numericValue(bestSellOrder.value?.price))
const bestBuyPrice = computed(() => numericValue(props.orderBook?.stats?.highestBuy) ?? numericValue(bestBuyOrder.value?.price))
const spreadValue = computed(() => {
    if (bestSellPrice.value === null || bestBuyPrice.value === null) {
        return null
    }

    return bestSellPrice.value - bestBuyPrice.value
})
const spreadPercentLabel = computed(() => {
    if (spreadValue.value === null || bestSellPrice.value === null || bestSellPrice.value <= 0) {
        return 'Need both sides'
    }

    return `${formatPercent(spreadValue.value / bestSellPrice.value)} of best sell`
})
const totalSellQuantity = computed(() => totalQuantity(allSellOrders.value))
const totalBuyQuantity = computed(() => totalQuantity(allBuyOrders.value))
const largestSellOrderQuantity = computed(() => maxQuantity(allSellOrders.value))
const largestBuyOrderQuantity = computed(() => maxQuantity(allBuyOrders.value))
const regionRows = computed(() => buildRegionRows([...allSellOrders.value, ...allBuyOrders.value]))
const costToBuy = computed(() => purchasePlan(sortedForBuying(filteredSellOrders.value), numericValue(buyQuantity.value)))
const costToBuyLabel = computed(() => {
    if (!buyQuantity.value) {
        return 'Enter a quantity'
    }

    if (!costToBuy.value.requested) {
        return 'Enter a valid quantity'
    }

    if (!costToBuy.value.filled) {
        return 'No sell orders available'
    }

    const suffix = costToBuy.value.filled < costToBuy.value.requested
        ? ` for ${formatCount(costToBuy.value.filled)} of ${formatCount(costToBuy.value.requested)}`
        : ''

    return `${formatCoins(costToBuy.value.total)} hex${suffix}`
})
const costToBuyDetail = computed(() => {
    if (!costToBuy.value.filled) {
        return 'Fills cheapest-first across visible sell orders.'
    }

    return `${formatCount(costToBuy.value.ordersUsed)} order${costToBuy.value.ordersUsed === 1 ? '' : 's'} · avg ${formatCoins(costToBuy.value.averagePrice)} each`
})

const subtitle = computed(() => {
    const category = props.orderBook?.item?.category

    return category ? `Real Bitjita orders · ${category}` : 'Real Bitjita orders'
})

const itemInitials = (name) => String(name ?? '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('') || '?'

const hideBrokenIcon = (assetName) => {
    brokenIconAssets.value = new Set([...brokenIconAssets.value, assetName])
}

const rarityStyle = (rarity) => bitcraftRarityStyle(rarity)
const hasTier = (tier) => hasBitcraftTier(tier)

const packageInfoLabel = computed(() => {
    const packageInfo = props.orderBook?.packageInfo

    if (!packageInfo) {
        return 'Related cargo package orders'
    }

    const cargoName = packageInfo.cargoName ?? 'Package'
    const itemName = packageInfo.itemName ?? props.orderBook?.item?.name
    const ratio = numericValue(packageInfo.ratio)

    return ratio && itemName ? `${cargoName} · ${formatCount(ratio)}x ${itemName}` : cargoName
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

    if (number === 2147483647 || number === Infinity) {
        return '∞'
    }

    return Number.isFinite(number) ? number.toLocaleString() : String(value)
}

const normalizeTimestamp = (value) => {
    if (!value) {
        return null
    }

    if (typeof value === 'number') {
        return new Date(value)
    }

    if (/^\d+$/.test(String(value))) {
        const number = Number(value)

        return new Date(String(value).length > 13 ? Math.floor(number / 1000) : number)
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

    return date
}

const formatTimestamp = (value, options = { dateStyle: 'medium', timeStyle: 'short' }) => {
    const normalized = normalizeTimestamp(value)

    if (normalized === null) {
        return '-'
    }

    if (typeof normalized === 'string') {
        return normalized
    }

    return new Intl.DateTimeFormat(undefined, options).format(normalized)
}

const formatShortTime = (value) => formatTimestamp(value, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })

const formatTotal = (order) => {
    if (isInfiniteQuantity(order.quantity)) {
        return '∞'
    }

    const total = lineTotal(order)

    return total === null ? '-' : formatCoins(total)
}

const lineTotal = (order) => {
    const price = numericValue(order.price)
    const quantity = numericValue(order.quantity)

    if (isInfiniteQuantity(order.quantity)) {
        return Infinity
    }

    return price !== null && quantity !== null ? price * quantity : null
}

const isInfiniteQuantity = (value) => Number(value) === 2147483647

const numericValue = (value) => {
    const number = Number(value)

    return Number.isFinite(number) ? number : null
}

const formatPercent = (value) => {
    const number = Number(value)

    return Number.isFinite(number) ? `${(number * 100).toFixed(1)}%` : '-'
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
        return orderLocationName(first).localeCompare(orderLocationName(second))
    }

    if (firstValue === null) {
        return 1
    }

    if (secondValue === null) {
        return -1
    }

    return sortDirection.value === 'desc' ? secondValue - firstValue : firstValue - secondValue
})

const filterOrders = (orders, filters) => {
    const minQuantity = numericValue(filters.minQuantity)
    const location = filters.location.toLowerCase()
    const owner = filters.owner.toLowerCase()

    return orders.filter((order) => {
        if (minQuantity !== null && (numericValue(order.quantity) ?? 0) < minQuantity) {
            return false
        }

        if (location && !`${orderLocationName(order)} ${order.claimName ?? ''} ${order.regionName ?? ''}`.toLowerCase().includes(location)) {
            return false
        }

        if (owner && !String(order.ownerUsername ?? '').toLowerCase().includes(owner)) {
            return false
        }

        return true
    })
}

const bestOrder = (orders, side) => [...orders]
    .filter((order) => numericValue(order.price) !== null)
    .sort((first, second) => side === 'sell'
        ? numericValue(first.price) - numericValue(second.price)
        : numericValue(second.price) - numericValue(first.price))[0] ?? null

const totalQuantity = (orders) => {
    if (orders.some((order) => isInfiniteQuantity(order.quantity))) {
        return Infinity
    }

    return orders.reduce((total, order) => total + (numericValue(order.quantity) ?? 0), 0)
}
const maxQuantity = (orders) => Math.max(0, ...orders.map((order) => numericValue(order.quantity) ?? 0))
const sortedForBuying = (orders) => [...orders]
    .filter((order) => numericValue(order.price) !== null && numericValue(order.quantity) !== null)
    .sort((first, second) => numericValue(first.price) - numericValue(second.price))

const purchasePlan = (orders, requested) => {
    if (requested === null || requested <= 0) {
        return { requested: 0, filled: 0, total: 0, averagePrice: 0, ordersUsed: 0 }
    }

    let remaining = requested
    let filled = 0
    let total = 0
    let ordersUsed = 0

    for (const order of orders) {
        if (remaining <= 0) {
            break
        }

        const quantity = numericValue(order.quantity) ?? 0
        const price = numericValue(order.price) ?? 0
        const taken = Math.min(remaining, quantity)

        if (taken <= 0) {
            continue
        }

        filled += taken
        total += taken * price
        remaining -= taken
        ordersUsed += 1
    }

    return {
        requested,
        filled,
        total,
        averagePrice: filled > 0 ? total / filled : 0,
        ordersUsed,
    }
}

const buildRegionRows = (orders) => {
    const regions = new Map()

    for (const order of orders) {
        const regionName = order.regionName || 'Unknown region'

        if (!regions.has(regionName)) {
            regions.set(regionName, {
                regionName,
                bestSell: null,
                bestBuy: null,
                sellQuantity: 0,
                buyQuantity: 0,
                updatedAt: null,
            })
        }

        const row = regions.get(regionName)
        const price = numericValue(order.price)
        const quantity = isInfiniteQuantity(order.quantity) ? Infinity : numericValue(order.quantity) ?? 0

        if (order.side === 'sell') {
            row.sellQuantity = row.sellQuantity === Infinity || quantity === Infinity ? Infinity : row.sellQuantity + quantity
            row.bestSell = row.bestSell === null || (price !== null && price < row.bestSell) ? price : row.bestSell
        } else {
            row.buyQuantity = row.buyQuantity === Infinity || quantity === Infinity ? Infinity : row.buyQuantity + quantity
            row.bestBuy = row.bestBuy === null || (price !== null && price > row.bestBuy) ? price : row.bestBuy
        }

        row.updatedAt = latestTimestamp(row.updatedAt, order.updatedAt)
    }

    return Array.from(regions.values())
        .sort((first, second) => String(first.regionName).localeCompare(String(second.regionName)))
}

const latestTimestamp = (first, second) => {
    const firstDate = normalizeTimestamp(first)
    const secondDate = normalizeTimestamp(second)

    if (!firstDate) {
        return second
    }

    if (!secondDate) {
        return first
    }

    if (typeof firstDate === 'string' || typeof secondDate === 'string') {
        return second ?? first
    }

    return secondDate.getTime() > firstDate.getTime() ? second : first
}

const orderLocationName = (order) => {
    if (order?.locationName) {
        return order.locationName
    }

    if (order?.claimName) {
        return order.claimName
    }

    if (order?.stallMatchStatus === 'multiple') {
        return 'Multiple possible stalls'
    }

    if (order?.stallMatchStatus === 'unknown') {
        return 'Stall unknown'
    }

    return 'Unknown market'
}

const formatCoordinate = (value) => {
    const number = Number(value)

    return Number.isFinite(number) ? Math.round(number / 3).toLocaleString() : value
}

const orderLocationDetail = (order) => {
    const parts = [
        order.stallBuildingName,
        order.stallEntityId,
        order.claimEntityId ? `Claim ${order.claimEntityId}` : null,
        order.stallOwnerName,
    ].filter(Boolean)

    if (order.stallLocationX && order.stallLocationZ) {
        parts.push(`N${formatCoordinate(order.stallLocationZ)}, E${formatCoordinate(order.stallLocationX)}`)
    }

    return parts.join(' · ')
}

const OrderTable = (componentProps) => h('div', { class: 'market-table-scroll' }, [
    componentProps.orders.length
        ? h('table', { class: 'market-order-table' }, [
            h('thead', [
                h('tr', [
                    h('th', componentProps.quantityLabel ?? 'Qty'),
                    h('th', componentProps.priceLabel ?? 'Price'),
                    h('th', 'Location'),
                    h('th', componentProps.ownerLabel),
                    h('th', 'Updated'),
                    h('th', { class: 'text-right' }, 'Sum'),
                ]),
            ]),
            h('tbody', componentProps.orders.map((order) => {
                const locationDetail = orderLocationDetail(order)

                return h('tr', { key: order.entityId }, [
                    h('td', formatCount(order.quantity)),
                    h('td', {
                        class: componentProps.side === 'sell'
                            ? 'market-order-price market-order-price--sell text-danger'
                            : 'market-order-price market-order-price--buy text-success',
                    }, [
                        h('strong', formatCoins(order.price)),
                        order.priceCurrency ? h('span', { class: 'ml-1 text-xs text-muted-2' }, order.priceCurrency) : null,
                        order.bundlePrice ? h('span', { class: 'mt-1 block text-xs text-muted-2' }, `Bundle ${formatCoins(order.bundlePrice)}${order.priceCurrency ? ` ${order.priceCurrency}` : ''}`) : null,
                    ]),
                    h('td', [
                        h('span', { class: 'index-record__title prose-wrap' }, orderLocationName(order)),
                        order.regionName ? h('span', { class: 'mt-1 block text-xs text-muted-2' }, order.regionName) : null,
                        locationDetail ? h('span', { class: 'mt-1 block text-xs text-muted-3' }, locationDetail) : null,
                        order.offerSummary ? h('span', { class: 'mt-2 block text-xs text-muted-2 prose-wrap' }, [
                            h('span', { class: 'text-muted-3' }, 'Offers: '),
                            order.offerSummary,
                        ]) : null,
                        order.requiredSummary ? h('span', { class: 'mt-1 block text-xs text-muted-2 prose-wrap' }, [
                            h('span', { class: 'text-muted-3' }, 'Requires: '),
                            order.requiredSummary,
                        ]) : null,
                        order.claimEntityId && componentProps.claimLinkHref ? h(Link, {
                            href: componentProps.claimLinkHref(order),
                            class: 'dashboard-link mt-1 inline-flex text-xs',
                        }, () => 'Filter to claim') : null,
                    ]),
                    h('td', order.ownerUsername || '-'),
                    h('td', formatShortTime(order.updatedAt)),
                    h('td', { class: 'text-right' }, formatTotal(order)),
                ])
            })),
        ])
        : h('div', { class: 'empty-state-panel' }, [
            h('p', { class: 'text-muted-3 text-sm font-ui' }, componentProps.emptyLabel),
        ]),
])

OrderTable.props = {
    orders: { type: Array, default: () => [] },
    side: { type: String, required: true },
    ownerLabel: { type: String, required: true },
    emptyLabel: { type: String, required: true },
    claimLinkHref: { type: Function, required: true },
    priceLabel: { type: String, default: 'Price' },
    quantityLabel: { type: String, default: 'Qty' },
}

const Metric = (componentProps) => h('div', { class: 'market-detail-metric' }, [
    h('span', componentProps.label),
    h('strong', componentProps.value),
])

Metric.props = {
    label: { type: String, required: true },
    value: { type: String, required: true },
}
</script>

<style scoped>
.market-order-item {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
    border: 1px solid rgb(var(--border-color-2-rgb) / 0.2);
    border-radius: 8px;
    background: rgb(var(--bg-surface-rgb) / 0.72);
    padding: 12px;
}

.market-order-item__icon {
    position: relative;
    display: grid;
    width: 52px;
    height: 52px;
    flex-shrink: 0;
    place-items: center;
    overflow: hidden;
    border: 1px solid var(--bitcraft-item-frame-border, rgb(var(--border-color-rgb) / 0.7));
    border-radius: 6px;
    background:
        radial-gradient(circle at 35% 25%, var(--bitcraft-item-frame-bg, rgb(var(--accent-cyan-rgb) / 0.2)), transparent 42%),
        linear-gradient(180deg, color-mix(in srgb, var(--bitcraft-item-frame-accent, transparent) 12%, transparent), transparent),
        rgb(var(--bg-surface-rgb) / 0.92);
    box-shadow: inset 0 1px 0 rgb(255 255 255 / 0.08), 0 0 12px color-mix(in srgb, var(--bitcraft-item-frame-accent, transparent) 20%, transparent);
    color: var(--bitcraft-item-frame-text, var(--text-muted-3));
    font-family: var(--font-ui);
    font-size: 12px;
    font-weight: 800;
}

.market-order-item__icon img {
    width: 44px;
    height: 44px;
    object-fit: contain;
}

.market-order-item__copy {
    min-width: 0;
}

.market-order-item__title {
    display: block;
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 14px;
    line-height: 1.25;
}

.market-order-item__tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 6px;
}

.bitcraft-rarity-badge {
    border-color: var(--bitcraft-rarity-border, currentColor);
    background:
        linear-gradient(180deg, var(--bitcraft-rarity-bg, transparent), rgb(var(--bg-surface-rgb) / 0.7)),
        rgb(var(--bg-surface-rgb) / 0.7);
    box-shadow: inset 0 1px 0 rgb(255 255 255 / 0.08), 0 0 12px color-mix(in srgb, var(--bitcraft-rarity-accent, transparent) 22%, transparent);
    color: var(--bitcraft-rarity-text, currentColor);
}

.market-summary-grid {
    --market-sell-color: #e9a16f;
    --market-buy-color: var(--success);

    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 16px;
}

.market-summary-card {
    min-width: 0;
    border: 1px solid rgb(var(--border-color-2-rgb) / 0.24);
    border-radius: 8px;
    background: rgb(var(--bg-surface-2-rgb) / 0.66);
    padding: 12px;
}

.market-summary-card strong {
    display: block;
    margin-top: 4px;
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 20px;
    line-height: 1.15;
}

.market-summary-card span:last-child {
    display: block;
    margin-top: 4px;
    color: var(--text-muted-2);
    font-size: 12px;
}

.market-summary-card__label {
    color: var(--text-muted-3);
    font-family: var(--font-ui);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.market-summary-card--sell strong {
    color: var(--market-sell-color);
}

.market-summary-card--buy strong {
    color: var(--market-buy-color);
}

.market-order-table-panel {
    --market-sell-color: #e9a16f;
    --market-buy-color: var(--success);

    overflow: hidden;
    border: 1px solid rgb(var(--border-color-2-rgb) / 0.2);
    border-radius: 8px;
    background: rgb(var(--bg-surface-rgb) / 0.72);
    color: var(--text-primary);
}

.market-order-table-panel__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    border-bottom: 1px solid rgb(var(--border-color-2-rgb) / 0.18);
    padding: 12px;
}

.market-order-filters {
    display: grid;
    grid-template-columns: minmax(0, 0.75fr) minmax(0, 1fr) minmax(0, 1fr);
    gap: 8px;
    border-bottom: 1px solid rgb(var(--border-color-2-rgb) / 0.14);
    padding: 10px 12px;
}

.market-order-filters input,
.cost-calculator input {
    min-height: 34px;
    width: 100%;
    border: 1px solid rgb(var(--border-color-2-rgb) / 0.36);
    border-radius: 6px;
    background: rgb(var(--bg-surface-2-rgb) / 0.74);
    color: var(--text-primary);
    caret-color: var(--accent-cyan);
    font-size: 12px;
}

.market-order-filters input::placeholder,
.cost-calculator input::placeholder {
    color: var(--text-muted-2);
    opacity: 1;
}

.market-order-filters input:focus,
.cost-calculator input:focus {
    border-color: rgb(var(--accent-cyan-rgb) / 0.56);
    box-shadow: 0 0 0 1px rgb(var(--accent-cyan-rgb) / 0.22);
}

.market-table-scroll {
    overflow-x: auto;
}

.market-order-table {
    width: 100%;
    min-width: 680px;
    border-collapse: collapse;
    font-family: var(--font-ui);
    font-size: 12px;
}

.market-order-table th,
.market-order-table td {
    border-bottom: 1px solid rgb(var(--border-color-2-rgb) / 0.14);
    padding: 10px 12px;
    text-align: left;
    vertical-align: top;
}

.market-table-scroll :deep(.market-order-table th),
.market-table-scroll :deep(.market-order-table td) {
    border-bottom: 1px solid rgb(var(--border-color-2-rgb) / 0.14);
    padding: 10px 12px;
    text-align: left;
    vertical-align: top;
}

.market-order-table td {
    color: var(--text-muted);
}

.market-table-scroll :deep(.market-order-table td) {
    color: var(--text-muted);
}

.market-order-table th {
    color: var(--text-muted-3);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.market-table-scroll :deep(.market-order-table th) {
    color: var(--text-muted-3);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.market-order-table tbody tr:hover {
    background: rgb(var(--bg-surface-3-rgb) / 0.22);
}

.market-table-scroll :deep(.market-order-table tbody tr:hover) {
    background: rgb(var(--bg-surface-3-rgb) / 0.22);
}

.market-order-table td.text-danger strong {
    color: var(--market-sell-color);
}

.market-order-table td.text-success strong {
    color: var(--market-buy-color);
}

.market-table-scroll :deep(.market-order-table td.text-danger strong) {
    color: var(--market-sell-color);
}

.market-table-scroll :deep(.market-order-table td.text-success strong) {
    color: var(--market-buy-color);
}

.market-table-scroll :deep(.market-order-table td.text-danger),
.market-table-scroll :deep(.market-order-table td.market-order-price--sell),
.market-table-scroll :deep(.market-order-table td.market-order-price--sell strong) {
    color: var(--market-sell-color);
}

.market-table-scroll :deep(.market-order-table td.text-success),
.market-table-scroll :deep(.market-order-table td.market-order-price--buy),
.market-table-scroll :deep(.market-order-table td.market-order-price--buy strong) {
    color: var(--market-buy-color);
}

.market-order-table .index-record__title {
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 12px;
    font-weight: 700;
    line-height: 1.35;
}

.market-table-scroll :deep(.market-order-table .index-record__title) {
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 12px;
    font-weight: 700;
    line-height: 1.35;
}

.market-order-table .text-muted-2,
.market-order-table .dashboard-link {
    color: var(--text-muted-2);
}

.market-order-table .dashboard-link:hover {
    color: var(--accent-cyan);
}

.market-table-scroll :deep(.market-order-table .text-muted-2),
.market-table-scroll :deep(.market-order-table .dashboard-link) {
    color: var(--text-muted-2);
}

.market-table-scroll :deep(.market-order-table .dashboard-link:hover) {
    color: var(--accent-cyan);
}

.cost-calculator {
    display: grid;
    grid-template-columns: minmax(0, 220px) minmax(0, 1fr);
    gap: 12px;
    border-top: 1px solid rgb(var(--border-color-2-rgb) / 0.16);
    padding: 12px;
}

.cost-calculator__field span,
.cost-calculator__result span {
    display: block;
    color: var(--text-muted-2);
    font-family: var(--font-ui);
    font-size: 11px;
}

.cost-calculator__result strong {
    display: block;
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 14px;
}

.market-detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    padding: 12px;
}

.market-detail-metric {
    border: 1px solid rgb(var(--border-color-2-rgb) / 0.18);
    border-radius: 6px;
    background: rgb(var(--bg-surface-2-rgb) / 0.54);
    padding: 10px;
}

.market-detail-grid :deep(.market-detail-metric) {
    border: 1px solid rgb(var(--border-color-2-rgb) / 0.18);
    border-radius: 6px;
    background: rgb(var(--bg-surface-2-rgb) / 0.54);
    padding: 10px;
}

.market-detail-metric span {
    display: block;
    color: var(--text-muted-2);
    font-family: var(--font-ui);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.market-detail-grid :deep(.market-detail-metric span) {
    display: block;
    color: var(--text-muted-2);
    font-family: var(--font-ui);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.market-detail-metric strong {
    display: block;
    margin-top: 4px;
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 13px;
}

.market-detail-grid :deep(.market-detail-metric strong) {
    display: block;
    margin-top: 4px;
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 13px;
}

@media (min-width: 900px) {
    .market-summary-grid,
    .market-detail-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

@media (max-width: 639px) {
    .market-order-filters,
    .cost-calculator {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
