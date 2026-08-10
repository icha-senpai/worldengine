<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="page-hero">
                <div class="page-hero__copy">
                    <div class="page-hero__eyebrow">
                        <span>Bitcraft tools</span>
                    </div>
                    <h1 class="page-hero__title page-hero__title--lg">{{ tool.title }}</h1>
                    <p class="page-hero__subtitle">{{ tool.subtitle }}</p>
                </div>
            </div>
        </template>

        <form @submit.prevent="submit" class="market-search-panel">
            <div v-if="searching" class="market-search-panel__progress" aria-hidden="true"></div>

            <div class="market-search-panel__header">
                <div class="min-w-0">
                    <p class="market-search-panel__eyebrow">{{ isBarterTool ? 'Barter stalls' : 'Market finder' }}</p>
                    <h2 class="market-search-panel__title">Item Search</h2>
                </div>

                <div class="market-search-panel__stats">
                    <span class="tag">{{ explorerMarketItemCount }} item{{ explorerMarketItemCount === 1 ? '' : 's' }}</span>
                    <span class="tag">{{ market.claims.length }} claim{{ market.claims.length === 1 ? '' : 's' }}</span>
                    <span v-if="updatedAtLabel" class="tag">Updated {{ updatedAtLabel }}</span>
                    <span v-if="cacheSummary" class="tag">{{ cacheSummary }}</span>
                </div>
            </div>

            <div class="market-search-panel__primary">
                <label class="field-group market-search-panel__query">
                    <span class="field-label">Item search</span>
                    <TextInput v-model.trim="form.q" type="search" class="market-search-panel__search-input" placeholder="Astralite, timber, pickaxe..." />
                </label>

                <label class="field-group market-search-panel__category">
                    <span class="field-label">Category</span>
                    <SelectInput v-model="form.category" @change="submit">
                        <option value="">All categories</option>
                        <option
                            v-for="category in categoryOptions"
                            :key="category"
                            :value="category"
                        >
                            {{ category }}
                        </option>
                    </SelectInput>
                </label>

                <label v-if="isBarterTool" class="field-group market-search-panel__side">
                    <span class="field-label">Listing side</span>
                    <SelectInput v-model="form.side">
                        <option value="">Both</option>
                        <option value="sell">Sell</option>
                        <option value="buy">Buy</option>
                    </SelectInput>
                </label>
            </div>

            <div class="market-search-panel__filters">
                <label class="field-group">
                    <span class="field-label">{{ tool.claimIdLabel }}</span>
                    <TextInput v-model.trim="form.claimEntityId" type="text" inputmode="numeric" placeholder="288230376165363891" />
                </label>

                <label class="field-group">
                    <span class="field-label">{{ tool.claimSearchLabel }}</span>
                    <TextInput v-model.trim="form.claimQ" type="text" placeholder="Jita, Rivendell..." />
                </label>

                <label class="field-group">
                    <span class="field-label">Empire</span>
                    <TextInput v-model.trim="form.empire" type="text" placeholder="Earth Kingdom" @input="form.empireEntityId = ''" />
                </label>

                <label class="field-group">
                    <span class="field-label">Region</span>
                    <TextInput v-model.trim="form.region" type="text" list="bitcraft-regions" placeholder="Solmere or 8" />
                    <datalist id="bitcraft-regions">
                        <option v-for="region in regions" :key="region.regionId" :value="region.regionName">
                            {{ region.regionName }} (#{{ region.regionId }})
                        </option>
                    </datalist>
                </label>
            </div>

            <div class="market-search-panel__footer">
                <div class="market-search-panel__toggles" aria-label="Order filters">
                    <label class="market-search-toggle" :class="{ 'is-active': form.hasOrders }">
                        <input v-model="form.hasOrders" type="checkbox" class="market-search-toggle__input" />
                        <span class="market-search-toggle__copy">
                            <span class="market-search-toggle__label">Has orders</span>
                            <span class="market-search-toggle__hint">Any side</span>
                        </span>
                    </label>
                    <label class="market-search-toggle" :class="{ 'is-active': form.hasSellOrders }">
                        <input v-model="form.hasSellOrders" type="checkbox" class="market-search-toggle__input" />
                        <span class="market-search-toggle__copy">
                            <span class="market-search-toggle__label">Sell orders</span>
                            <span class="market-search-toggle__hint">Players selling</span>
                        </span>
                    </label>
                    <label class="market-search-toggle" :class="{ 'is-active': form.hasBuyOrders }">
                        <input v-model="form.hasBuyOrders" type="checkbox" class="market-search-toggle__input" />
                        <span class="market-search-toggle__copy">
                            <span class="market-search-toggle__label">Buy orders</span>
                            <span class="market-search-toggle__hint">Players buying</span>
                        </span>
                    </label>
                </div>

                <div class="market-search-panel__actions">
                    <AppButton type="submit" variant="primary" :disabled="searching">{{ searching ? 'Searching...' : 'Search' }}</AppButton>
                    <AppButton v-if="form.claimEntityId" type="button" variant="ghost" @click="clearClaim">{{ tool.clearLabel }}</AppButton>
                    <AppButton type="button" variant="ghost" @click="reset">Reset</AppButton>
                </div>
            </div>
        </form>

        <div v-if="error" class="mt-5 rounded-md border border-[rgb(var(--accent-pink-rgb)/0.28)] bg-[rgb(var(--accent-pink-rgb)/0.08)] px-4 py-3 text-sm text-(--accent-pink)">
            {{ error }}
        </div>

        <div class="mt-5 grid gap-4 xl:grid-cols-[minmax(0,1fr)_280px]">
            <section class="surface-section">
                <div class="surface-section__header">
                    <div class="surface-section__copy">
                        <h2 class="surface-section__title">{{ explorerTitle }}</h2>
                        <p class="surface-section__subtitle">
                            {{ explorerMarketItemCount }} item{{ explorerMarketItemCount === 1 ? '' : 's' }} found
                            <span v-if="market.claim?.name"> at {{ market.claim.name }}</span>
                            <span v-else-if="isBarterTool && market.claims.length"> across {{ market.claims.length }} claim{{ market.claims.length === 1 ? '' : 's' }}</span>
                        </p>
                    </div>
                    <div v-if="hasBuyOrderSortControls" class="flex flex-wrap gap-2 sm:justify-end" aria-label="Market explorer buy order sorting">
                        <button
                            v-for="option in regionBuySortOptions"
                            :key="option.value"
                            type="button"
                            data-test="region-buy-sort"
                            class="tag transition-colors hover:text-focus"
                            :class="{ 'tag--warn': regionBuySortKey === option.value }"
                            @click="setRegionBuySort(option.value)"
                        >
                            {{ regionBuySortLabel(option) }}
                        </button>
                    </div>
                </div>

                <div class="surface-section__body">
                    <div v-if="explorerMarketItemCount" class="space-y-5">
                        <section
                            v-for="group in groupedMarketItems"
                            :key="group.category"
                            class="space-y-3"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-sm font-ui font-semibold text-primary">{{ group.category }}</h3>
                                <span class="tag">{{ group.items.length }}</span>
                            </div>

                            <div class="grid gap-3 lg:grid-cols-2 2xl:grid-cols-3">
                                <article
                                    v-for="item in group.items"
                                    :key="`${item.type}-${item.id}`"
                                    :id="isBarterTool ? barterItemAnchor(item) : null"
                                    data-test="market-item-card"
                                    class="index-record market-item-card"
                                    :class="{
                                        'border-[rgb(var(--accent-cyan-rgb)/0.5)]': isSelectedExplorerItem(item),
                                    }"
                                    @pointerenter="prefetchMarketItemStats(item)"
                                    @focusin="prefetchMarketItemStats(item)"
                                >
                                    <div class="flex min-h-16 gap-3">
                                        <span
                                            class="market-item-icon"
                                            :style="itemFrameStyle(item)"
                                            aria-hidden="true"
                                        >
                                            <img
                                                v-if="marketItemIconUrl(item)"
                                                :src="marketItemIconUrl(item)"
                                                alt=""
                                                loading="lazy"
                                                class="absolute inset-1 size-10 object-contain"
                                                @error="hideBrokenIcon(item.iconAssetName)"
                                            >
                                            <span v-else>{{ itemInitials(item.name) }}</span>
                                        </span>

                                        <div class="min-w-0 flex-1">
                                            <p class="index-record__title prose-wrap">{{ item.name }}</p>
                                            <p class="index-record__subtitle prose-wrap">{{ item.category || 'Uncategorized' }}</p>
                                        </div>

                                    </div>

                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <span v-if="item.rarity" class="tag bitcraft-rarity-badge" :style="rarityStyle(item.rarity)">{{ item.rarity }}</span>
                                        <BitcraftTierBadge v-if="hasTier(item.tier)" :tier="item.tier" />
                                        <span class="tag">{{ item.kind }}</span>
                                        <span v-if="buyOrderReference(item).quantity" class="tag">{{ buyOrderReference(item).orderLabel }} qty {{ formatCount(buyOrderReference(item).quantity) }}</span>
                                    </div>

                                    <div class="mt-4 grid grid-cols-2 gap-2">
                                        <button
                                            v-if="isBarterTool"
                                            type="button"
                                            class="tag justify-center text-center transition-colors"
                                            :class="item.sellOrderCount ? 'tag--success hover:text-focus' : 'opacity-45 pointer-events-none'"
                                            :disabled="!item.sellOrderCount"
                                            @click="openBarterItem(item, 'sell')"
                                        >
                                            Sell {{ item.sellOrderCount ? formatCount(item.sellOrderCount) : '-' }}
                                        </button>
                                        <button
                                            v-else
                                            type="button"
                                            class="tag justify-center text-center transition-colors"
                                            :class="item.sellOrderCount ? 'tag--success hover:text-focus' : 'opacity-45 pointer-events-none'"
                                            :disabled="!item.sellOrderCount"
                                            @click="openMarketItem(item, 'sell')"
                                        >
                                            Sell {{ item.sellOrderCount ? formatCount(item.sellOrderCount) : '-' }}
                                        </button>
                                        <button
                                            v-if="isBarterTool"
                                            type="button"
                                            class="tag justify-center text-center transition-colors"
                                            :class="item.buyOrderCount ? 'tag--warn hover:text-focus' : 'opacity-45 pointer-events-none'"
                                            :disabled="!item.buyOrderCount"
                                            @click="openBarterItem(item, 'buy')"
                                        >
                                            Buy {{ item.buyOrderCount ? formatCount(item.buyOrderCount) : '-' }}
                                        </button>
                                        <button
                                            v-else
                                            type="button"
                                            class="tag justify-center text-center transition-colors"
                                            :class="item.buyOrderCount ? 'tag--warn hover:text-focus' : 'opacity-45 pointer-events-none'"
                                            :disabled="!item.buyOrderCount"
                                            @click="openMarketItem(item, 'buy')"
                                        >
                                            Buy {{ item.buyOrderCount ? formatCount(item.buyOrderCount) : '-' }}
                                        </button>
                                    </div>

                                    <div class="mt-3 grid grid-cols-2 gap-2 text-xs font-ui text-muted-2">
                                        <span>Lowest sell</span>
                                        <span class="text-right text-primary">{{ formatCoins(marketItemStats(item).lowestSellPrice) }}</span>
                                        <span>Highest buy</span>
                                        <span class="text-right text-primary">{{ formatCoins(marketItemStats(item).highestBuyPrice) }}</span>
                                        <span>{{ buyOrderReference(item).orderLabel }} quantity</span>
                                        <span class="text-right text-primary">{{ formatOptionalCount(buyOrderReference(item).quantity) }}</span>
                                        <span>{{ buyOrderReference(item).orderLabel }} line</span>
                                        <span class="text-right text-primary">{{ formatCoins(buyOrderReference(item).lineTotal) }}</span>
                                    </div>
                                </article>
                            </div>
                        </section>
                    </div>

                    <div v-else class="empty-state-panel">
                        <p class="text-muted-3 text-sm font-ui">{{ explorerEmptyLabel }}</p>
                    </div>
                </div>
            </section>

            <section class="surface-section">
                <div class="surface-section__header">
                    <div class="surface-section__copy">
                        <h2 class="surface-section__title">{{ tool.claimSectionTitle }}</h2>
                        <p class="surface-section__subtitle">{{ tool.claimSectionSubtitle }}</p>
                    </div>
                </div>

                <div class="surface-section__body">
                    <div v-if="market.claims.length" class="grid gap-2">
                        <button
                            v-for="claim in market.claims"
                            :key="claim.entityId"
                            type="button"
                            class="index-record text-left hover:border-[rgb(var(--accent-cyan-rgb)/0.35)] transition-colors"
                            :class="{ 'border-[rgb(var(--accent-cyan-rgb)/0.5)]': form.claimEntityId === String(claim.entityId) }"
                            @click="selectClaim(claim)"
                        >
                            <span class="index-record__title prose-wrap">{{ claim.name }}</span>
                            <span class="mt-1 block index-record__subtitle prose-wrap">
                                {{ claim.regionName || 'Unknown region' }}
                                <span v-if="claim.tier"> · Tier {{ claim.tier }}</span>
                                <span v-if="claim.empireName"> · {{ claim.empireName }}</span>
                            </span>
                            <span v-if="claim.tradeBuildingCount" class="mt-2 block text-xs font-ui text-muted-2">
                                {{ claim.tradeBuildingCount }} {{ claim.tradeBuildingCount === 1 ? tool.tradeBuildingSingular : tool.tradeBuildingPlural }}
                                <span v-if="claim.tradeOrderCount"> · {{ claim.tradeOrderCount }} trade slots</span>
                            </span>
                            <span class="mt-2 block text-[11px] font-ui text-muted-3">{{ claim.entityId }}</span>
                        </button>
                    </div>
                    <div v-else class="empty-state-panel">
                        <p class="text-muted-3 text-sm font-ui">{{ tool.claimEmptyLabel }}</p>
                    </div>
                </div>
            </section>

        </div>

        <MarketOrderBookPopup
            :show="activeOrderBookPopupOpen && Boolean(activeOrderBook)"
            :order-book="activeOrderBook"
            :claim-link-href="marketClaimHref"
            @close="closeOrderBookPopup"
        />

    </AuthenticatedLayout>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import MarketOrderBookPopup from '@/Pages/Bitcraft/Components/MarketOrderBookPopup.vue'
import BitcraftTierBadge from '@/Pages/Bitcraft/Components/BitcraftTierBadge.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextInput from '@/Components/TextInput.vue'
import { bitcraftItemFrameStyle, bitcraftRarityStyle, bitjitaAssetUrl, hasBitcraftTier } from '@/Pages/Bitcraft/bitjitaAssets.js'

const queryHasFlag = (key) => typeof window !== 'undefined'
    && new URLSearchParams(window.location.search).has(key)

const filterBoolean = (filters, key, fallback = false) => {
    if (filters[key] !== null && filters[key] !== undefined) {
        return filters[key]
    }

    return queryHasFlag(key) || fallback
}

const defaultHasOrders = (tool) => tool?.key !== 'barter-stalls'

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    regions: { type: Array, default: () => [] },
    market: {
        type: Object,
        default: () => ({
            items: [],
            categories: [],
            claims: [],
            tradeBuildings: [],
            listings: [],
            empires: [],
            orderBook: null,
            metrics: {},
        }),
    },
    tool: {
        type: Object,
        default: () => ({
            key: 'market',
            routeName: 'bitcraft.market',
            title: 'Market Finder',
            subtitle: 'Find market trades by item, claim, and region.',
            claimIdLabel: 'Claim / market ID',
            claimSearchLabel: 'Claim search',
            claimSectionTitle: 'Markets',
            claimSectionSubtitle: 'Matching claims',
            claimEmptyLabel: 'Search a name or region to find claims.',
            tradeBuildingSingular: 'market building',
            tradeBuildingPlural: 'market buildings',
            buildingSectionTitle: 'Market Buildings',
            buildingSectionSubtitle: 'Market buildings inside the claim',
            buildingEmptyLabel: 'Pick a claim to find market buildings.',
            listingSectionTitle: 'Market Listings',
            clearLabel: 'Clear Market',
            claimLinkLabel: 'Use this market ->',
        }),
    },
    error: { type: String, default: null },
    cache: {
        type: Object,
        default: () => ({
            updatedAt: null,
            sources: [],
        }),
    },
})

const form = reactive({
    q: props.filters.q ?? '',
    category: props.filters.category ?? '',
    claimQ: props.filters.claimQ ?? '',
    claimEntityId: props.filters.claimEntityId ?? '',
    empire: props.filters.empire ?? props.filters.empireName ?? '',
    empireEntityId: props.filters.empireEntityId ?? '',
    region: props.filters.region ?? props.filters.regionName ?? props.filters.regionId ?? '',
    side: props.filters.side ?? '',
    hasOrders: filterBoolean(props.filters, 'hasOrders', defaultHasOrders(props.tool)),
    hasSellOrders: filterBoolean(props.filters, 'hasSellOrders'),
    hasBuyOrders: filterBoolean(props.filters, 'hasBuyOrders'),
})

const activeBarterItemId = ref(String(props.filters.itemId ?? ''))
const activeBarterItemKind = ref(String(props.filters.itemKind ?? ''))
const activeBarterPopupOpen = ref(false)
const activeMarketPopupOpen = ref(false)
const regionBuySortKey = ref('price')
const regionBuySortDirection = ref('desc')
const localMarketOrderBook = ref(null)
const localBarterListings = ref(null)
const prefetchedOrderBooks = ref(new Map())
const prefetchingOrderBooks = ref(new Set())
const prefetchedBarterListings = ref(new Map())
const prefetchingBarterListings = ref(new Set())
const brokenIconAssets = ref(new Set())
const searching = ref(false)
const syncingFilters = ref(false)
let debouncedSearchTimer = null
const effectiveMarketOrderBook = computed(() => localMarketOrderBook.value ?? props.market.orderBook)
const selectedOrderBookItemId = computed(() => String(effectiveMarketOrderBook.value?.item?.id ?? ''))
const isBarterTool = computed(() => props.tool.key === 'barter-stalls')
const effectiveBarterListings = computed(() => localBarterListings.value ?? props.market.listings ?? [])
const scrollStorageKey = computed(() => `bitcraft:${props.tool.key}:${window.location.pathname}${window.location.search}`)
const explorerTitle = computed(() => (isBarterTool.value ? 'Barter Stall Explorer' : 'Market Explorer'))
const activeItemSearchLabel = computed(() => form.q || form.category || '')
const activeRegionLabel = computed(() => form.region || '')
const hasBuyOrderSortControls = computed(() => !isBarterTool.value && form.hasBuyOrders && explorerMarketItemCount.value > 0)
const regionBuySortOptions = [
    { label: 'Buy', value: 'price' },
    { label: 'Count', value: 'count' },
    { label: 'Quantity', value: 'quantity' },
]
const buyOrderSortValue = (item, key, direction = regionBuySortDirection.value) => {
    if (key === 'quantity') {
        return direction === 'asc'
            ? numericValue(item.smallestBuyOrderQuantity ?? item.buyOrderQuantity)
            : numericValue(item.largestBuyOrderQuantity ?? item.buyOrderQuantity)
    }

    if (key === 'count') {
        return numericValue(item.buyOrderCount)
    }

    return direction === 'asc'
        ? numericValue(item.lowestBuyPrice ?? item.highestBuyPrice)
        : numericValue(item.highestBuyPrice)
}
const buyOrderReference = (item) => {
    const stats = marketItemStats(item)

    if (regionBuySortKey.value === 'price' && regionBuySortDirection.value === 'asc') {
        return {
            priceLabel: 'Low',
            orderLabel: 'Low',
            price: stats.lowestBuyPrice ?? stats.highestBuyPrice,
            quantity: stats.lowestBuyQuantity ?? stats.highestBuyQuantity,
            lineTotal: stats.lowestBuyLineTotal ?? stats.highestBuyLineTotal,
        }
    }

    if (regionBuySortKey.value === 'quantity') {
        const isLow = regionBuySortDirection.value === 'asc'

        return {
            priceLabel: 'Price',
            orderLabel: isLow ? 'Qty low' : 'Qty high',
            price: isLow
                ? stats.smallestBuyOrderPrice ?? stats.lowestBuyPrice ?? stats.highestBuyPrice
                : stats.largestBuyOrderPrice ?? stats.highestBuyPrice,
            quantity: isLow
                ? stats.smallestBuyOrderQuantity ?? stats.lowestBuyQuantity ?? stats.highestBuyQuantity
                : stats.largestBuyOrderQuantity ?? stats.highestBuyQuantity,
            lineTotal: isLow
                ? stats.smallestBuyOrderLineTotal ?? stats.lowestBuyLineTotal ?? stats.highestBuyLineTotal
                : stats.largestBuyOrderLineTotal ?? stats.highestBuyLineTotal,
        }
    }

    return {
        priceLabel: 'High',
        orderLabel: 'High',
        price: stats.highestBuyPrice,
        quantity: stats.highestBuyQuantity,
        lineTotal: stats.highestBuyLineTotal,
    }
}
const orderBookForItem = (item) => {
    const cachedOrderBook = prefetchedOrderBooks.value.get(orderBookCacheKey(orderBookLookupParams(item)))

    if (cachedOrderBook) {
        return cachedOrderBook
    }

    return marketOrderBookMatches(item) ? effectiveMarketOrderBook.value : null
}
const marketItemStats = (item) => {
    const stats = orderBookForItem(item)?.stats ?? {}

    return {
        ...item,
        lowestSellPrice: item.lowestSellPrice ?? stats.lowestSell,
        highestBuyPrice: item.highestBuyPrice ?? stats.highestBuy,
        lowestBuyPrice: item.lowestBuyPrice ?? stats.lowestBuy,
        sellOrderCount: item.sellOrderCount ?? stats.sellOrderCount,
        buyOrderCount: item.buyOrderCount ?? stats.buyOrderCount,
        highestBuyQuantity: item.highestBuyQuantity ?? stats.highestBuyQuantity,
        highestBuyLineTotal: item.highestBuyLineTotal ?? stats.highestBuyLineTotal,
        lowestBuyQuantity: item.lowestBuyQuantity ?? stats.lowestBuyQuantity,
        lowestBuyLineTotal: item.lowestBuyLineTotal ?? stats.lowestBuyLineTotal,
        largestBuyOrderPrice: item.largestBuyOrderPrice ?? stats.largestBuyOrderPrice,
        largestBuyOrderQuantity: item.largestBuyOrderQuantity ?? stats.largestBuyOrderQuantity,
        largestBuyOrderLineTotal: item.largestBuyOrderLineTotal ?? stats.largestBuyOrderLineTotal,
        smallestBuyOrderPrice: item.smallestBuyOrderPrice ?? stats.smallestBuyOrderPrice,
        smallestBuyOrderQuantity: item.smallestBuyOrderQuantity ?? stats.smallestBuyOrderQuantity,
        smallestBuyOrderLineTotal: item.smallestBuyOrderLineTotal ?? stats.smallestBuyOrderLineTotal,
    }
}
const compareBuyOrderTieBreakers = (first, second) => {
    const firstPrice = numericValue(first.highestBuyPrice) ?? -Infinity
    const secondPrice = numericValue(second.highestBuyPrice) ?? -Infinity

    if (firstPrice !== secondPrice) {
        return secondPrice - firstPrice
    }

    const firstQuantity = numericValue(first.buyOrderQuantity) ?? -Infinity
    const secondQuantity = numericValue(second.buyOrderQuantity) ?? -Infinity

    if (firstQuantity !== secondQuantity) {
        return secondQuantity - firstQuantity
    }

    const firstCount = numericValue(first.buyOrderCount) ?? -Infinity
    const secondCount = numericValue(second.buyOrderCount) ?? -Infinity

    if (firstCount !== secondCount) {
        return secondCount - firstCount
    }

    return String(first.name ?? '').localeCompare(String(second.name ?? ''))
}
const compareBuyOrderItems = (first, second) => {
    const firstValue = buyOrderSortValue(first, regionBuySortKey.value)
    const secondValue = buyOrderSortValue(second, regionBuySortKey.value)

    if (firstValue === null && secondValue !== null) {
        return 1
    }

    if (firstValue !== null && secondValue === null) {
        return -1
    }

    if (firstValue !== null && secondValue !== null && firstValue !== secondValue) {
        const direction = regionBuySortDirection.value === 'asc' ? 1 : -1

        return (firstValue - secondValue) * direction
    }

    return compareBuyOrderTieBreakers(first, second)
}
const setRegionBuySort = (key) => {
    if (regionBuySortKey.value === key) {
        regionBuySortDirection.value = regionBuySortDirection.value === 'desc' ? 'asc' : 'desc'

        return
    }

    regionBuySortKey.value = key
    regionBuySortDirection.value = 'desc'
}
const regionBuySortLabel = (option) => {
    const direction = regionBuySortKey.value === option.value ? regionBuySortDirection.value : 'desc'

    return `${option.label} ${direction === 'desc' ? 'high' : 'low'}`
}
const explorerEmptyLabel = computed(() => {
    if (searching.value) {
        return isBarterTool.value ? 'Searching barter stalls...' : 'Searching market orders...'
    }

    if (activeItemSearchLabel.value && activeRegionLabel.value) {
        return isBarterTool.value
            ? `No barter stall listings found for ${activeItemSearchLabel.value} in ${activeRegionLabel.value}.`
            : `No market orders found for ${activeItemSearchLabel.value} in ${activeRegionLabel.value}.`
    }

    if (activeItemSearchLabel.value) {
        return isBarterTool.value
            ? `No barter stall listings found for ${activeItemSearchLabel.value}.`
            : `No market orders found for ${activeItemSearchLabel.value}.`
    }

    if (activeRegionLabel.value) {
        return isBarterTool.value
            ? `No barter stalls found in ${activeRegionLabel.value}.`
            : `No market items found in ${activeRegionLabel.value}.`
    }

    return isBarterTool.value ? 'Search an item with an empire, region, or claim to explore barter stall listings.' : 'No market matches yet.'
})
const explorerMarketItems = computed(() => {
    const items = props.market.items ?? []

    if (!isBarterTool.value && form.hasBuyOrders) {
        return [...items]
            .filter((item) => Number(item.buyOrderCount ?? 0) > 0)
            .sort(compareBuyOrderItems)
    }

    return items
})
const explorerMarketItemCount = computed(() => explorerMarketItems.value.length)
const groupedMarketItems = computed(() => {
    const groups = new Map()

    for (const item of explorerMarketItems.value) {
        const category = item.category || 'Uncategorized'

        if (!groups.has(category)) {
            groups.set(category, [])
        }

        groups.get(category).push(item)
    }

    return Array.from(groups, ([category, items]) => ({
        category,
        items,
    }))
})
const categoryOptions = computed(() => {
    const categories = new Set([
        ...((form.category ? [form.category] : [])),
        ...(props.market.categories ?? []),
    ])

    return Array.from(categories).filter(Boolean).sort((first, second) => first.localeCompare(second))
})
const activeBarterItem = computed(() => (props.market.items ?? []).find((item) => isSelectedBarterItem(item)) ?? null)
const activeBarterOrderBook = computed(() => {
    const item = activeBarterItem.value

    if (!item) {
        return null
    }

    const orders = effectiveBarterListings.value
        .filter((listing) => barterListingMatchesActiveItem(listing))
        .map(barterListingToOrder)
    const sellOrders = orders.filter((order) => order.side === 'sell')
    const buyOrders = orders.filter((order) => order.side === 'buy')

    return {
        item: {
            id: item.id,
            kind: item.kind ?? item.type ?? 'item',
            name: item.name,
            category: item.category,
            tier: item.tier,
            rarity: item.rarity,
            iconAssetName: item.iconAssetName,
        },
        stats: barterOrderBookStats(sellOrders, buyOrders),
        sellOrders,
        buyOrders,
        packageInfo: null,
        packageSellOrders: [],
        packageBuyOrders: [],
    }
})
const activeOrderBook = computed(() => (isBarterTool.value ? activeBarterOrderBook.value : effectiveMarketOrderBook.value))
const activeOrderBookPopupOpen = computed(() => (isBarterTool.value ? activeBarterPopupOpen.value : activeMarketPopupOpen.value))
const cacheSources = computed(() => props.cache?.sources ?? [])
const updatedAtLabel = computed(() => formatTime(props.cache?.updatedAt))
const cacheSummary = computed(() => {
    if (!cacheSources.value.length) {
        return ''
    }

    const shortestCache = Math.min(...cacheSources.value.map((source) => Number(source.maxAgeSeconds)).filter(Number.isFinite))

    return Number.isFinite(shortestCache) ? `Cache ${formatDuration(shortestCache)}` : ''
})

watch(() => props.filters, (filters) => {
    syncingFilters.value = true
    form.q = filters.q ?? ''
    form.category = filters.category ?? ''
    form.claimQ = filters.claimQ ?? ''
    form.claimEntityId = filters.claimEntityId ?? ''
    form.empire = filters.empire ?? filters.empireName ?? ''
    form.empireEntityId = filters.empireEntityId ?? ''
    form.region = filters.region ?? filters.regionName ?? filters.regionId ?? ''
    form.side = filters.side ?? ''
    form.hasOrders = filterBoolean(filters, 'hasOrders', defaultHasOrders(props.tool))
    form.hasSellOrders = filterBoolean(filters, 'hasSellOrders')
    form.hasBuyOrders = filterBoolean(filters, 'hasBuyOrders')
    activeBarterItemId.value = String(filters.itemId ?? '')
    activeBarterItemKind.value = String(filters.itemKind ?? '')
    activeBarterPopupOpen.value = false
    localBarterListings.value = null
    prefetchedBarterListings.value = new Map()
    prefetchingBarterListings.value = new Set()

    nextTick(() => {
        syncingFilters.value = false
    })
})

watch(() => props.market.orderBook, (orderBook) => {
    localMarketOrderBook.value = null
    activeMarketPopupOpen.value = false
}, { immediate: true })

watch(() => [form.q, form.region], () => {
    scheduleDebouncedSearch()
}, { flush: 'post' })

const cleanPayload = () => ({
    ...(form.q ? { q: form.q } : {}),
    ...(form.category ? { category: form.category } : {}),
    ...(form.claimQ ? { claimQ: form.claimQ } : {}),
    ...(form.claimEntityId ? { claimEntityId: form.claimEntityId } : {}),
    ...(form.empire ? { empire: form.empire } : {}),
    ...(form.empireEntityId ? { empireEntityId: form.empireEntityId } : {}),
    ...(form.region ? { region: form.region } : {}),
    ...(form.side ? { side: form.side } : {}),
    ...(form.hasOrders ? { hasOrders: 1 } : {}),
    ...(form.hasSellOrders ? { hasSellOrders: 1 } : {}),
    ...(form.hasBuyOrders ? { hasBuyOrders: 1 } : {}),
})

const orderBookLookupParams = (item) => ({
    ...(form.claimEntityId ? { claimEntityId: form.claimEntityId } : {}),
    ...(form.region ? { region: form.region } : {}),
    itemId: item.id,
    itemKind: item.kind ?? 'item',
})

const orderBookCacheKey = (params) => JSON.stringify({
    claimEntityId: params.claimEntityId ?? '',
    region: params.region ?? '',
    itemId: String(params.itemId ?? ''),
    itemKind: params.itemKind ?? 'item',
})

const barterListingLookupParams = (item) => ({
    ...cleanPayload(),
    itemId: item.id,
    itemKind: item.kind ?? 'item',
})

const barterListingsCacheKey = (params) => JSON.stringify({
    q: params.q ?? '',
    category: params.category ?? '',
    claimQ: params.claimQ ?? '',
    claimEntityId: params.claimEntityId ?? '',
    empire: params.empire ?? '',
    empireEntityId: params.empireEntityId ?? '',
    region: params.region ?? '',
    side: params.side ?? '',
    itemId: String(params.itemId ?? ''),
    itemKind: params.itemKind ?? 'item',
})

const loadedFilterValue = (key) => {
    if (key === 'region') {
        return props.filters.region ?? props.filters.regionName ?? props.filters.regionId ?? ''
    }

    if (key === 'empire') {
        return props.filters.empire ?? props.filters.empireName ?? ''
    }

    return props.filters[key] ?? ''
}

const marketOrderBookMatches = (item) => {
    if (isBarterTool.value || !effectiveMarketOrderBook.value) {
        return false
    }

    if (selectedOrderBookItemId.value !== String(item.id)) {
        return false
    }

    const payload = cleanPayload()
    const sameItemKind = String(props.filters.itemKind ?? item.kind ?? 'item') === String(item.kind ?? 'item')
    const sameSearchContext = ['q', 'category', 'claimQ', 'claimEntityId', 'empire', 'empireEntityId', 'region']
        .every((key) => String(payload[key] ?? '') === String(loadedFilterValue(key)))

    return sameItemKind && sameSearchContext
}

const barterItemAnchor = (item) => `barter-item-${item.kind ?? item.type ?? 'item'}-${item.id}`

const itemInitials = (name) => String(name ?? '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('') || '?'

const marketItemIconUrl = (item) => {
    const assetName = item?.iconAssetName

    if (brokenIconAssets.value.has(assetName)) {
        return null
    }

    return bitjitaAssetUrl(assetName)
}

const hideBrokenIcon = (assetName) => {
    brokenIconAssets.value = new Set([...brokenIconAssets.value, assetName])
}

const itemFrameStyle = (item) => bitcraftItemFrameStyle(item?.tier, item?.rarity)
const rarityStyle = (rarity) => bitcraftRarityStyle(rarity)
const hasTier = (tier) => hasBitcraftTier(tier)

const visitTool = (url, params = {}) => {
    router.get(url, params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => {
            searching.value = true
        },
        onFinish: () => {
            searching.value = false
        },
    })
}

const submit = () => {
    clearDebouncedSearch()
    visitTool(route(props.tool.routeName ?? 'bitcraft.market'), cleanPayload())
}

const reset = () => {
    clearDebouncedSearch()
    visitTool(route(props.tool.routeName ?? 'bitcraft.market'), {})
}

const selectClaim = (claim) => {
    form.claimQ = claim.name ?? ''
    form.claimEntityId = /^\d+$/.test(String(claim.entityId ?? '')) ? String(claim.entityId) : ''
    submit()
}

const clearClaim = () => {
    form.claimEntityId = ''
    submit()
}

function isSelectedBarterItem(item) {
    return isBarterTool.value
        && String(item.id) === activeBarterItemId.value
        && (!activeBarterItemKind.value || String(item.kind ?? item.type ?? 'item') === activeBarterItemKind.value)
}

function isSelectedExplorerItem(item) {
    return isBarterTool.value
        ? isSelectedBarterItem(item)
        : selectedOrderBookItemId.value === String(item.id)
}

const openBarterItem = async (item) => {
    activeBarterItemId.value = String(item.id)
    activeBarterItemKind.value = String(item.kind ?? item.type ?? 'item')

    const cachedListings = prefetchedBarterListings.value.get(barterListingsCacheKey(barterListingLookupParams(item)))

    if (cachedListings) {
        localBarterListings.value = cachedListings
        activeBarterPopupOpen.value = true

        return
    }

    await loadBarterItemListings(item)
}

const openMarketItem = async (item) => {
    const cachedOrderBook = prefetchedOrderBooks.value.get(orderBookCacheKey(orderBookLookupParams(item)))

    if (cachedOrderBook) {
        localMarketOrderBook.value = cachedOrderBook
        activeMarketPopupOpen.value = true

        return
    }

    if (marketOrderBookMatches(item)) {
        activeMarketPopupOpen.value = true

        return
    }

    await loadMarketOrderBook(item)
}

const prefetchMarketItemStats = (item) => {
    if (isBarterTool.value) {
        prefetchBarterItemListings(item)

        return
    }

    if (Number(item.sellOrderCount ?? 0) + Number(item.buyOrderCount ?? 0) <= 0) {
        return
    }

    prefetchMarketOrderBook(item)
}

const prefetchBarterItemListings = async (item) => {
    if (Number(item.sellOrderCount ?? 0) + Number(item.buyOrderCount ?? 0) <= 0) {
        return
    }

    const params = barterListingLookupParams(item)
    const key = barterListingsCacheKey(params)

    if (prefetchedBarterListings.value.has(key) || prefetchingBarterListings.value.has(key)) {
        return
    }

    prefetchingBarterListings.value = new Set([...prefetchingBarterListings.value, key])

    try {
        const response = await fetch(route('bitcraft.barter-stalls.listings', params), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })

        if (!response.ok) {
            return
        }

        const payload = await response.json()
        const listings = Array.isArray(payload.listings) ? payload.listings : []
        const barterListings = new Map(prefetchedBarterListings.value)
        barterListings.set(key, listings)
        prefetchedBarterListings.value = barterListings
    } finally {
        const prefetching = new Set(prefetchingBarterListings.value)
        prefetching.delete(key)
        prefetchingBarterListings.value = prefetching
    }
}

const prefetchMarketOrderBook = async (item) => {
    const params = orderBookLookupParams(item)
    const key = orderBookCacheKey(params)

    if (prefetchedOrderBooks.value.has(key) || prefetchingOrderBooks.value.has(key)) {
        return
    }

    prefetchingOrderBooks.value = new Set([...prefetchingOrderBooks.value, key])

    try {
        const response = await fetch(route('bitcraft.market.order-book', params), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })

        if (!response.ok) {
            return
        }

        const payload = await response.json()

        if (payload.orderBook) {
            const orderBooks = new Map(prefetchedOrderBooks.value)
            orderBooks.set(key, payload.orderBook)
            prefetchedOrderBooks.value = orderBooks
        }
    } finally {
        const prefetching = new Set(prefetchingOrderBooks.value)
        prefetching.delete(key)
        prefetchingOrderBooks.value = prefetching
    }
}

const loadMarketOrderBook = async (item) => {
    const params = orderBookLookupParams(item)
    const key = orderBookCacheKey(params)

    searching.value = true

    try {
        const response = await fetch(route('bitcraft.market.order-book', params), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })

        if (!response.ok) {
            return
        }

        const payload = await response.json()

        if (!payload.orderBook) {
            return
        }

        const orderBooks = new Map(prefetchedOrderBooks.value)
        orderBooks.set(key, payload.orderBook)
        prefetchedOrderBooks.value = orderBooks
        localMarketOrderBook.value = payload.orderBook
        activeMarketPopupOpen.value = true
    } finally {
        searching.value = false
    }
}

const loadBarterItemListings = async (item) => {
    const params = barterListingLookupParams(item)
    const key = barterListingsCacheKey(params)

    searching.value = true

    try {
        const response = await fetch(route('bitcraft.barter-stalls.listings', params), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })

        if (!response.ok) {
            return
        }

        const payload = await response.json()
        const listings = Array.isArray(payload.listings) ? payload.listings : []
        const barterListings = new Map(prefetchedBarterListings.value)
        barterListings.set(key, listings)
        prefetchedBarterListings.value = barterListings
        localBarterListings.value = listings
        activeBarterPopupOpen.value = true
    } finally {
        searching.value = false
    }
}

const clearDebouncedSearch = () => {
    if (debouncedSearchTimer) {
        clearTimeout(debouncedSearchTimer)
        debouncedSearchTimer = null
    }
}

const shouldRunDebouncedSearch = () => {
    const itemQuery = form.q.trim()
    const regionQuery = form.region.trim()
    const loadedItemQuery = String(loadedFilterValue('q') ?? '')
    const loadedRegionQuery = String(loadedFilterValue('region') ?? '')

    if (itemQuery === loadedItemQuery && regionQuery === loadedRegionQuery) {
        return false
    }

    if (itemQuery === '' && regionQuery === '') {
        return loadedItemQuery !== '' || loadedRegionQuery !== ''
    }

    return itemQuery.length >= 2 || regionQuery.length >= 2 || /^\d+$/.test(regionQuery)
}

const scheduleDebouncedSearch = () => {
    if (syncingFilters.value) {
        return
    }

    clearDebouncedSearch()

    if (!shouldRunDebouncedSearch()) {
        return
    }

    debouncedSearchTimer = setTimeout(() => {
        submit()
    }, 450)
}

const barterListingMatchesActiveItem = (listing) => {
    if (!activeBarterItemId.value) {
        return false
    }

    if (String(listing.itemId) !== activeBarterItemId.value) {
        return false
    }

    if (activeBarterItemKind.value && String(listing.itemType ?? 'item') !== activeBarterItemKind.value) {
        return false
    }

    return true
}

const closeOrderBookPopup = () => {
    if (!isBarterTool.value) {
        activeMarketPopupOpen.value = false

        return
    }

    activeBarterPopupOpen.value = false
    activeBarterItemId.value = ''
    activeBarterItemKind.value = ''
}

const marketClaimHref = (order) => route(props.tool.routeName ?? 'bitcraft.market', {
    ...cleanPayload(),
    ...(order.claimName ? { claimQ: order.claimName } : {}),
    claimEntityId: order.claimEntityId,
})

const barterListingToOrder = (listing) => ({
    entityId: listing.entityId,
    side: listing.side,
    price: listing.price,
    quantity: listing.quantity,
    claimEntityId: listing.claimEntityId,
    claimName: listing.claimName,
    locationName: listing.stall?.name ?? listing.claimName,
    regionName: listing.regionName,
    ownerUsername: listing.ownerUsername ?? listing.stall?.ownerName,
    updatedAt: listing.updatedAt,
    source: listing.source,
    itemName: listing.itemName,
    itemTier: listing.itemTier,
    itemRarity: listing.itemRarity,
    offerSummary: listing.offerSummary,
    requiredSummary: listing.requiredSummary,
    bundlePrice: listing.bundlePrice,
    priceCurrency: listing.priceCurrency,
    stallMatchStatus: listing.stallMatchStatus,
    stallName: listing.stall?.name,
    stallBuildingName: listing.stall?.buildingName,
    stallEntityId: listing.stall?.entityId,
    stallOwnerName: listing.stall?.ownerName,
    stallLocationX: listing.stall?.locationX,
    stallLocationZ: listing.stall?.locationZ,
})

const barterOrderBookStats = (sellOrders, buyOrders) => ({
    lowestSell: minOrderValue(sellOrders, 'price'),
    highestBuy: maxOrderValue(buyOrders, 'price'),
    sellOrderCount: sellOrders.length,
    buyOrderCount: buyOrders.length,
    lastUpdated: latestOrderTimestamp([...sellOrders, ...buyOrders]),
})

const minOrderValue = (orders, key) => {
    const values = orders.map((order) => numericValue(order[key])).filter((value) => value !== null)

    return values.length ? Math.min(...values) : null
}

const maxOrderValue = (orders, key) => {
    const values = orders.map((order) => numericValue(order[key])).filter((value) => value !== null)

    return values.length ? Math.max(...values) : null
}

const latestOrderTimestamp = (orders) => {
    let latest = null
    let latestTime = -Infinity

    for (const order of orders) {
        if (!order.updatedAt) {
            continue
        }

        const time = Date.parse(String(order.updatedAt).replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00'))

        if (Number.isFinite(time) && time > latestTime) {
            latest = order.updatedAt
            latestTime = time
        }
    }

    return latest
}

const rememberScroll = () => {
    sessionStorage.setItem(scrollStorageKey.value, String(window.scrollY))
}

const restoreScroll = () => {
    nextTick(() => {
        if (window.location.hash) {
            document.getElementById(window.location.hash.slice(1))?.scrollIntoView()

            return
        }

        const scrollY = Number(sessionStorage.getItem(scrollStorageKey.value))

        if (Number.isFinite(scrollY) && scrollY > 0) {
            window.scrollTo({ top: scrollY })
        }
    })
}

onMounted(() => {
    restoreScroll()
    window.addEventListener('beforeunload', rememberScroll)
    window.addEventListener('pagehide', rememberScroll)
})

onBeforeUnmount(() => {
    clearDebouncedSearch()
    rememberScroll()
    window.removeEventListener('beforeunload', rememberScroll)
    window.removeEventListener('pagehide', rememberScroll)
})

const formatTime = (value) => {
    if (!value) {
        return ''
    }

    const date = new Date(value)

    if (Number.isNaN(date.getTime())) {
        return ''
    }

    return new Intl.DateTimeFormat(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    }).format(date)
}

const formatDuration = (seconds) => {
    if (!Number.isFinite(seconds)) {
        return ''
    }

    if (seconds < 60) {
        return `${seconds}s`
    }

    if (seconds < 3600) {
        return `${Math.round(seconds / 60)}m`
    }

    return `${Math.round(seconds / 3600)}h`
}

const formatCoins = (value) => {
    if (value === null || value === undefined || value === '') {
        return '—'
    }

    const number = Number(value)

    return Number.isFinite(number) ? number.toLocaleString() : String(value)
}

function numericValue(value) {
    const number = Number(value)

    return Number.isFinite(number) ? number : null
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

const formatOptionalCount = (value) => {
    if (value === null || value === undefined || value === '') {
        return '—'
    }

    return formatCount(value)
}

</script>

<style scoped>
.market-search-panel {
    position: relative;
    margin-bottom: 20px;
    padding: 18px;
    overflow: hidden;
    border: 1px solid rgb(var(--border-color-2-rgb) / 0.28);
    border-radius: 12px;
    background:
        radial-gradient(circle at top left, rgb(var(--accent-cyan-rgb) / 0.14), transparent 34%),
        linear-gradient(180deg, rgb(var(--bg-surface-2-rgb) / 0.98), rgb(var(--bg-surface-rgb) / 0.96));
    box-shadow:
        inset 0 1px 0 rgb(var(--text-primary-rgb) / 0.05),
        0 18px 38px rgb(0 0 0 / 0.18);
}

.market-search-panel__progress {
    position: absolute;
    top: 0;
    left: 0;
    height: 2px;
    width: 38%;
    background: linear-gradient(90deg, transparent, var(--accent-cyan), var(--accent-pink), transparent);
    animation: market-search-progress 1s ease-in-out infinite;
}

.market-search-panel__header,
.market-search-panel__footer {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
}

.market-search-panel__eyebrow {
    color: var(--accent-cyan);
    font-family: var(--font-ui);
    font-size: 11px;
    letter-spacing: 0.14em;
    text-transform: uppercase;
}

.market-search-panel__title {
    margin-top: 3px;
    color: var(--text-primary);
    font-size: 22px;
    font-weight: 500;
    line-height: 1.15;
}

.market-search-panel__stats {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 8px;
}

@keyframes market-search-progress {
    0% {
        transform: translateX(-100%);
    }

    100% {
        transform: translateX(270%);
    }
}

.market-search-panel__primary {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 12px;
    margin-top: 18px;
}

.market-search-panel__filters {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 12px;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid rgb(var(--border-color-2-rgb) / 0.18);
}

.market-search-panel__footer {
    flex-direction: column;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid rgb(var(--border-color-2-rgb) / 0.18);
}

.market-search-panel__toggles,
.market-search-panel__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.market-search-panel__actions {
    width: 100%;
}

.market-search-panel__actions :deep(.app-btn) {
    flex: 1 1 120px;
}

.market-search-panel__search-input {
    height: 52px;
    padding-inline: 18px;
    border-color: rgb(var(--accent-cyan-rgb) / 0.36);
    font-size: 16px;
    background:
        linear-gradient(180deg, rgb(var(--bg-surface-4-rgb) / 0.36), rgb(var(--bg-surface-rgb) / 0.96)),
        var(--bg-surface);
}

.market-search-toggle {
    position: relative;
    display: inline-flex;
    min-width: 132px;
    flex: 1 1 132px;
    cursor: pointer;
    border: 1px solid rgb(var(--border-color-2-rgb) / 0.22);
    border-radius: 8px;
    background: rgb(var(--bg-surface-2-rgb) / 0.64);
    padding: 10px 12px;
    transition: border-color 0.15s, background 0.15s, color 0.15s, transform 0.15s;
}

.market-search-toggle:hover {
    border-color: rgb(var(--accent-cyan-rgb) / 0.38);
    background: rgb(var(--bg-surface-3-rgb) / 0.68);
}

.market-search-toggle.is-active {
    border-color: rgb(var(--accent-cyan-rgb) / 0.54);
    background: rgb(var(--accent-cyan-rgb) / 0.12);
    box-shadow: inset 0 1px 0 rgb(var(--text-primary-rgb) / 0.05);
}

.market-search-toggle__input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
}

.market-search-toggle__input:focus-visible + .market-search-toggle__copy {
    outline: 2px solid rgb(var(--accent-cyan-rgb) / 0.7);
    outline-offset: 4px;
    border-radius: 4px;
}

.market-search-toggle__copy {
    display: grid;
    gap: 2px;
    min-width: 0;
    pointer-events: none;
}

.market-search-toggle__label {
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.market-search-toggle__hint {
    color: var(--text-muted-3);
    font-family: var(--font-ui);
    font-size: 11px;
}

.market-search-toggle.is-active .market-search-toggle__hint {
    color: var(--accent-cyan-2);
}

.market-item-card {
    position: relative;
    z-index: 0;
    border: 1px solid rgb(var(--border-color-2-rgb) / 0.28);
    border-radius: 8px;
    background:
        linear-gradient(180deg, rgb(var(--bg-surface-3-rgb) / 0.16), rgb(var(--bg-surface-rgb) / 0.94)),
        var(--bg-surface);
    box-shadow: inset 0 1px 0 rgb(255 255 255 / 0.035);
    transition: border-color 0.16s ease, background 0.16s ease, box-shadow 0.16s ease, transform 0.16s ease;
}

.market-item-card:hover,
.market-item-card:focus-within {
    z-index: 1;
    border-color: rgb(var(--accent-cyan-rgb) / 0.56);
    background:
        linear-gradient(180deg, rgb(var(--bg-surface-4-rgb) / 0.24), rgb(var(--bg-surface-2-rgb) / 0.98)),
        var(--bg-surface-2);
    box-shadow:
        inset 0 1px 0 rgb(255 255 255 / 0.06),
        0 14px 34px rgb(0 0 0 / 0.32),
        0 0 0 1px rgb(var(--accent-cyan-rgb) / 0.08),
        0 0 28px rgb(var(--accent-cyan-rgb) / 0.1);
    transform: translateY(-3px);
}

@media (prefers-reduced-motion: reduce) {
    .market-item-card {
        transition: border-color 0.16s ease, background 0.16s ease, box-shadow 0.16s ease;
    }

    .market-item-card:hover,
    .market-item-card:focus-within {
        transform: none;
    }
}

.market-item-icon {
    position: relative;
    display: grid;
    width: 48px;
    height: 48px;
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

.bitcraft-rarity-badge {
    border-color: var(--bitcraft-rarity-border, currentColor);
    background:
        linear-gradient(180deg, var(--bitcraft-rarity-bg, transparent), rgb(var(--bg-surface-rgb) / 0.7)),
        rgb(var(--bg-surface-rgb) / 0.7);
    box-shadow: inset 0 1px 0 rgb(255 255 255 / 0.08), 0 0 12px color-mix(in srgb, var(--bitcraft-rarity-accent, transparent) 22%, transparent);
    color: var(--bitcraft-rarity-text, currentColor);
}

@media (min-width: 640px) {
    .market-search-panel {
        padding: 20px;
    }

    .market-search-panel__primary {
        grid-template-columns: minmax(0, 1fr) minmax(180px, 220px);
        align-items: end;
    }

    .market-search-panel__primary:has(.market-search-panel__side) {
        grid-template-columns: minmax(0, 1fr) minmax(160px, 200px) minmax(140px, 170px);
    }

    .market-search-panel__filters {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .market-search-panel__actions {
        width: auto;
        justify-content: flex-end;
    }

    .market-search-panel__actions :deep(.app-btn) {
        flex: 0 0 auto;
    }
}

@media (min-width: 1024px) {
    .market-search-panel__footer {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
    }

    .market-search-panel__toggles {
        flex: 1 1 auto;
    }

    .market-search-panel__filters {
        grid-template-columns: minmax(190px, 0.95fr) minmax(190px, 1fr) minmax(190px, 1fr) minmax(150px, 0.8fr);
    }

    .market-search-toggle {
        flex: 0 1 150px;
    }
}
</style>
