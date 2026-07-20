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

        <form @submit.prevent="submit" class="index-panel">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1.3fr)_minmax(160px,0.7fr)_minmax(210px,0.95fr)_minmax(190px,0.85fr)_minmax(190px,0.85fr)_minmax(150px,0.65fr)]">
                <label class="field-group">
                    <span class="field-label">Item search</span>
                    <TextInput v-model.trim="form.q" type="text" placeholder="Astralite, timber, pickaxe..." />
                </label>

                <label class="field-group">
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

            <div v-if="isBarterTool" class="mt-4 grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(140px,0.4fr)]">
                <label class="field-group">
                    <span class="field-label">Listing side</span>
                    <SelectInput v-model="form.side">
                        <option value="">Both</option>
                        <option value="sell">Sell</option>
                        <option value="buy">Buy</option>
                    </SelectInput>
                </label>
            </div>

            <div class="mt-4 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap gap-3">
                    <label class="inline-flex items-center gap-2 text-sm font-ui text-muted-2">
                        <input v-model="form.hasOrders" type="checkbox" class="rounded border-border bg-surface text-primary focus:ring-primary" />
                        Has orders
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm font-ui text-muted-2">
                        <input v-model="form.hasSellOrders" type="checkbox" class="rounded border-border bg-surface text-primary focus:ring-primary" />
                        Sell orders
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm font-ui text-muted-2">
                        <input v-model="form.hasBuyOrders" type="checkbox" class="rounded border-border bg-surface text-primary focus:ring-primary" />
                        Buy orders
                    </label>
                </div>

                <div class="flex flex-wrap gap-2">
                    <AppButton type="submit" variant="primary">Search</AppButton>
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
                            {{ market.items.length }} item{{ market.items.length === 1 ? '' : 's' }} found
                            <span v-if="market.claim?.name"> at {{ market.claim.name }}</span>
                            <span v-else-if="isBarterTool && market.claims.length"> across {{ market.claims.length }} claim{{ market.claims.length === 1 ? '' : 's' }}</span>
                        </p>
                    </div>
                </div>

                <div v-if="market.items.length" class="space-y-5">
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
                                class="index-record"
                                :class="{
                                    'border-[rgb(var(--accent-cyan-rgb)/0.5)]': isSelectedExplorerItem(item),
                                }"
                            >
                                <div class="flex min-h-16 gap-3">
                                    <div class="flex size-12 shrink-0 items-center justify-center rounded-md border border-border bg-surface-2 text-xs font-ui text-muted-3">
                                        {{ item.tier ? `T${item.tier}` : 'Item' }}
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <p class="index-record__title prose-wrap">{{ item.name }}</p>
                                        <p class="index-record__subtitle prose-wrap">{{ item.category || 'Uncategorized' }}</p>
                                    </div>
                                </div>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    <span v-if="item.rarity" class="tag">{{ item.rarity }}</span>
                                    <span v-if="item.tier" class="tag">Tier {{ item.tier }}</span>
                                    <span class="tag">{{ item.kind }}</span>
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
                                    <span class="text-right text-primary">{{ formatCoins(item.lowestSellPrice) }}</span>
                                    <span>Highest buy</span>
                                    <span class="text-right text-primary">{{ formatCoins(item.highestBuyPrice) }}</span>
                                </div>
                            </article>
                        </div>
                    </section>
                </div>

                <div v-else class="empty-state-panel">
                    <p class="text-muted-3 text-sm font-ui">{{ explorerEmptyLabel }}</p>
                </div>
            </section>

            <section class="surface-section">
                <div class="surface-section__header">
                    <div class="surface-section__copy">
                        <h2 class="surface-section__title">{{ tool.claimSectionTitle }}</h2>
                        <p class="surface-section__subtitle">{{ tool.claimSectionSubtitle }}</p>
                    </div>
                </div>

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
            </section>

        </div>

        <BarterListingsPopup
            :show="isBarterTool && activeBarterPopupOpen"
            :item="activeBarterItem"
            :listings="activeBarterListings"
            :side="activeBarterSide"
            :side-options="sideOptions"
            :claim="market.claim"
            :claims="market.claims"
            :has-stall-order-listings="hasStallOrderListings"
            @close="closeBarterPopup"
            @update:side="setSide"
        />

        <MarketOrderBookPopup
            :show="!isBarterTool && activeMarketPopupOpen && Boolean(market.orderBook)"
            :order-book="market.orderBook"
            :claim-link-href="marketClaimHref"
            @close="closeMarketPopup"
        />

    </AuthenticatedLayout>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import BarterListingsPopup from '@/Pages/Bitcraft/Components/BarterListingsPopup.vue'
import MarketOrderBookPopup from '@/Pages/Bitcraft/Components/MarketOrderBookPopup.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextInput from '@/Components/TextInput.vue'

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
    hasOrders: props.filters.hasOrders ?? true,
    hasSellOrders: props.filters.hasSellOrders ?? false,
    hasBuyOrders: props.filters.hasBuyOrders ?? false,
})

const activeBarterItemId = ref(String(props.filters.itemId ?? ''))
const activeBarterItemKind = ref(String(props.filters.itemKind ?? ''))
const activeBarterSide = ref(props.filters.side ?? '')
const activeBarterPopupOpen = ref(Boolean(props.filters.itemId))
const activeMarketPopupOpen = ref(Boolean(props.market.orderBook))
const selectedOrderBookItemId = computed(() => String(props.market.orderBook?.item?.id ?? ''))
const isBarterTool = computed(() => props.tool.key === 'barter-stalls')
const hasStallOrderListings = computed(() => (props.market.listings ?? []).some((listing) => listing.source === 'stall-order'))
const scrollStorageKey = computed(() => `bitcraft:${props.tool.key}:${window.location.pathname}${window.location.search}`)
const explorerTitle = computed(() => (isBarterTool.value ? 'Barter Stall Explorer' : 'Market Explorer'))
const explorerEmptyLabel = computed(() => (
    isBarterTool.value ? 'Search an item with an empire, region, or claim to explore barter stall listings.' : 'No market matches yet.'
))
const groupedMarketItems = computed(() => {
    const groups = new Map()

    for (const item of props.market.items ?? []) {
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
const activeBarterListings = computed(() => (
    props.market.listings ?? []
).filter((listing) => barterListingMatchesActiveItem(listing)))
const sideOptions = [
    { label: 'Both', value: '' },
    { label: 'Sell', value: 'sell' },
    { label: 'Buy', value: 'buy' },
]

watch(() => props.filters, (filters) => {
    form.q = filters.q ?? ''
    form.category = filters.category ?? ''
    form.claimQ = filters.claimQ ?? ''
    form.claimEntityId = filters.claimEntityId ?? ''
    form.empire = filters.empire ?? filters.empireName ?? ''
    form.empireEntityId = filters.empireEntityId ?? ''
    form.region = filters.region ?? filters.regionName ?? filters.regionId ?? ''
    form.side = filters.side ?? ''
    form.hasOrders = filters.hasOrders ?? true
    form.hasSellOrders = filters.hasSellOrders ?? false
    form.hasBuyOrders = filters.hasBuyOrders ?? false
    activeBarterItemId.value = String(filters.itemId ?? '')
    activeBarterItemKind.value = String(filters.itemKind ?? '')
    activeBarterSide.value = filters.side ?? ''
    activeBarterPopupOpen.value = Boolean(filters.itemId)
})

watch(() => props.market.orderBook, (orderBook) => {
    if (orderBook) {
        activeMarketPopupOpen.value = true

        return
    }

    activeMarketPopupOpen.value = false
}, { immediate: true })

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

const marketItemParams = (item, orderSide = null) => {
    const payload = {
        ...cleanPayload(),
        itemId: item.id,
        itemKind: item.kind ?? 'item',
    }

    if (!isBarterTool.value && orderSide === 'sell') {
        payload.hasSellOrders = 1
        delete payload.hasBuyOrders
    }

    if (!isBarterTool.value && orderSide === 'buy') {
        payload.hasBuyOrders = 1
        delete payload.hasSellOrders
    }

    if (isBarterTool.value && orderSide) {
        payload.side = orderSide
    }

    return payload
}

const barterItemAnchor = (item) => `barter-item-${item.kind ?? item.type ?? 'item'}-${item.id}`

const submit = () => {
    router.get(route(props.tool.routeName ?? 'bitcraft.market'), cleanPayload(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

const reset = () => {
    router.get(route(props.tool.routeName ?? 'bitcraft.market'), {}, {
        preserveState: true,
        replace: true,
    })
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

const openBarterItem = (item, side) => {
    setSide(side, item)
}

const openMarketItem = (item, side) => {
    const params = marketItemParams(item, side)

    router.get(route(props.tool.routeName ?? 'bitcraft.market', params), {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

const setSide = (side, item = null) => {
    if (item && isBarterTool.value) {
        activeBarterItemId.value = String(item.id)
        activeBarterItemKind.value = String(item.kind ?? item.type ?? 'item')
        activeBarterSide.value = side
        activeBarterPopupOpen.value = true

        return
    }

    if (isBarterTool.value) {
        activeBarterSide.value = side

        return
    }

    form.side = side
    const params = item ? marketItemParams(item, side) : cleanPayload()

    router.get(route(props.tool.routeName ?? 'bitcraft.market', params), {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
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

    return !activeBarterSide.value || listing.side === activeBarterSide.value
}

const closeBarterPopup = () => {
    activeBarterPopupOpen.value = false
    activeBarterItemId.value = ''
    activeBarterItemKind.value = ''
}

const closeMarketPopup = () => {
    activeMarketPopupOpen.value = false
}

const marketClaimHref = (order) => route(props.tool.routeName ?? 'bitcraft.market', {
    ...cleanPayload(),
    ...(order.claimName ? { claimQ: order.claimName } : {}),
    claimEntityId: order.claimEntityId,
})

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
    rememberScroll()
    window.removeEventListener('beforeunload', rememberScroll)
    window.removeEventListener('pagehide', rememberScroll)
})

const formatCoins = (value) => {
    if (value === null || value === undefined || value === '') {
        return '—'
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

</script>
