<template>
    <section class="surface-section xl:col-span-2">
        <div class="surface-section__header">
            <div class="surface-section__copy">
                <span class="surface-section__title">Marketplace</span>
                <p class="surface-section__subtitle">{{ marketplace.active_listings.length }} active listings · {{ marketplace.recent_transactions.length }} recent sales.</p>
            </div>
        </div>

        <div class="surface-section__body">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="board in boards"
                    :key="board.key"
                    type="button"
                    class="rounded-md border border-border bg-surface-2 px-3 py-2 text-xs font-ui text-muted-2 transition hover:border-focus/60 hover:text-primary"
                    :class="{ 'border-focus/70 bg-focus/10 text-primary': activeBoard === board.key }"
                    @click="activeBoard = board.key"
                >
                    {{ board.label }} · {{ board.count }}
                </button>
            </div>

            <div class="mt-4 grid gap-4 xl:grid-cols-[16rem_minmax(0,1fr)]">
                <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                    <p class="text-sm font-ui text-primary">{{ activeBoardRecord.label }}</p>
                    <div class="mt-3 grid gap-2 text-xs">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Listings</span>
                            <span class="text-primary">{{ visibleActiveListings.length }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Sellable</span>
                            <span class="text-primary">{{ visibleSellableInventory.length }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Volume</span>
                            <span class="text-primary">{{ marketVolume }}g</span>
                        </div>
                    </div>
                </div>

                <form
                    v-if="activeBoard === 'sell'"
                    class="rounded-md border border-border bg-surface-2 px-3 py-3"
                    @submit.prevent="submitListing"
                >
                    <p class="text-sm font-ui text-primary">Sell</p>

                    <div class="mt-3 grid gap-3">
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="rounded-md border border-border bg-canvas px-3 py-2 text-xs font-ui text-muted-2"
                                :class="{ 'border-focus/70 bg-focus/10 text-primary': listingForm.listing_type === 'item' }"
                                @click="listingForm.listing_type = 'item'"
                            >
                                Items · {{ visibleSellableInventory.length }}
                            </button>
                            <button
                                type="button"
                                class="rounded-md border border-border bg-canvas px-3 py-2 text-xs font-ui text-muted-2"
                                :class="{ 'border-focus/70 bg-focus/10 text-primary': listingForm.listing_type === 'tool' }"
                                @click="listingForm.listing_type = 'tool'"
                            >
                                Tools · {{ visibleSellableTools.length }}
                            </button>
                        </div>

                        <label v-if="listingForm.listing_type === 'item'" class="grid gap-1">
                            <span class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">Item</span>
                            <select
                                v-model="listingForm.item_key"
                                class="rounded-md border-border bg-surface text-sm text-primary focus:border-focus focus:ring-focus"
                            >
                                <option value="">Select item</option>
                                <option
                                    v-for="item in visibleSellableInventory"
                                    :key="item.item_key"
                                    :value="item.item_key"
                                >
                                    {{ item.item_name }} ({{ item.quantity }}) · {{ item.quality }} · {{ item.total_weight }} wt
                                </option>
                            </select>
                        </label>

                        <label v-else class="grid gap-1">
                            <span class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">Tool</span>
                            <select
                                v-model.number="listingForm.tool_id"
                                class="rounded-md border-border bg-surface text-sm text-primary focus:border-focus focus:ring-focus"
                            >
                                <option value="">Select tool</option>
                                <option
                                    v-for="tool in visibleSellableTools"
                                    :key="tool.tool_id"
                                    :value="tool.tool_id"
                                >
                                    {{ tool.item_name }} · {{ tool.rarity }} · +{{ tool.experience_bonus }} XP · {{ tool.market_price_band }}
                                </option>
                            </select>
                        </label>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="grid gap-1">
                                <span class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">Quantity</span>
                                <input
                                    v-model.number="listingForm.quantity"
                                    type="number"
                                    min="1"
                                    :disabled="listingForm.listing_type === 'tool'"
                                    :max="selectedListingItem?.quantity ?? 999999"
                                    class="rounded-md border-border bg-surface text-sm text-primary focus:border-focus focus:ring-focus"
                                >
                            </label>

                            <label class="grid gap-1">
                                <span class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">Unit Price</span>
                                <input
                                    v-model.number="listingForm.unit_price"
                                    type="number"
                                    :min="selectedListingEntry?.market_floor_price ?? 1"
                                    :max="selectedListingEntry?.market_ceiling_price ?? 999999"
                                    class="rounded-md border-border bg-surface text-sm text-primary focus:border-focus focus:ring-focus"
                                >
                            </label>
                        </div>

                        <div v-if="selectedListingEntry" class="rounded-md border border-border bg-canvas px-3 py-3 text-xs text-muted-2">
                            <div class="flex items-center justify-between gap-3">
                                <span>Market range</span>
                                <span class="text-primary">{{ selectedListingEntry.market_price_band }}</span>
                            </div>
                            <div class="mt-2 flex items-center justify-between gap-3">
                                <span>NPC floor</span>
                                <span class="text-primary">{{ selectedListingEntry.npc_buy_price }}g each</span>
                            </div>
                            <div v-if="selectedListingTool" class="mt-2 flex items-center justify-between gap-3">
                                <span>Stats</span>
                                <span class="text-primary">+{{ selectedListingTool.experience_bonus }} XP · +{{ selectedListingTool.yield_bonus }} yield</span>
                            </div>
                        </div>
                    </div>

                    <p v-if="listingError" class="mt-3 text-xs text-(--accent-pink)">
                        {{ listingError }}
                    </p>

                    <button
                        type="submit"
                        class="app-btn app-btn--primary app-btn--sm mt-4"
                        :disabled="listingForm.processing || !selectedListingEntry"
                    >
                        List
                    </button>
                </form>

                <form
                    v-else-if="activeBoard === 'vendor'"
                    class="rounded-md border border-border bg-surface-2 px-3 py-3"
                    @submit.prevent="sellToNpc"
                >
                    <p class="text-sm font-ui text-primary">{{ marketplace.npc_vendor.name }}</p>
                    <p class="mt-1 text-xs text-muted-2">{{ marketplace.npc_vendor.description }}</p>

                    <div class="mt-3 grid gap-3">
                        <label class="grid gap-1">
                            <span class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">Item</span>
                            <select
                                v-model="vendorForm.item_key"
                                class="rounded-md border-border bg-surface text-sm text-primary focus:border-focus focus:ring-focus"
                            >
                                <option value="">Select item</option>
                                <option
                                    v-for="item in visibleSellableInventory"
                                    :key="item.item_key"
                                    :value="item.item_key"
                                >
                                    {{ item.item_name }} ({{ item.quantity }}) · {{ item.npc_buy_price }}g each
                                </option>
                            </select>
                        </label>

                        <label class="grid gap-1">
                            <span class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">Quantity</span>
                            <input
                                v-model.number="vendorForm.quantity"
                                type="number"
                                min="1"
                                :max="selectedVendorItem?.quantity ?? 999999"
                                class="rounded-md border-border bg-surface text-sm text-primary focus:border-focus focus:ring-focus"
                            >
                        </label>

                        <div v-if="selectedVendorItem" class="rounded-md border border-border bg-canvas px-3 py-3 text-xs text-muted-2">
                            <div class="flex items-center justify-between gap-3">
                                <span>Buyback</span>
                                <span class="text-primary">{{ selectedVendorItem.npc_buy_price }}g each</span>
                            </div>
                            <div class="mt-2 flex items-center justify-between gap-3">
                                <span>Total</span>
                                <span class="text-primary">{{ vendorSaleTotal }}g</span>
                            </div>
                            <div class="mt-2 flex items-center justify-between gap-3">
                                <span>Player market</span>
                                <span class="text-primary">{{ selectedVendorItem.market_price_band }}</span>
                            </div>
                        </div>
                    </div>

                    <p v-if="vendorError" class="mt-3 text-xs text-(--accent-pink)">
                        {{ vendorError }}
                    </p>

                    <button
                        type="submit"
                        class="app-btn app-btn--primary app-btn--sm mt-4"
                        :disabled="vendorForm.processing || !vendorForm.item_key"
                    >
                        Sell to NPC
                    </button>
                </form>

                <div v-else-if="activeBoard === 'market'" class="grid gap-3">
                    <article
                        v-for="(row, index) in visibleMarketRows"
                        :key="`${row.listing_type}-${row.item_key ?? row.item_name}`"
                        class="grid gap-3 rounded-md border border-border bg-surface-2 px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_auto]"
                    >
                        <div class="grid h-9 w-9 place-items-center rounded-md border border-border bg-canvas text-sm font-ui text-primary">
                            #{{ index + 1 }}
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="truncate text-sm font-ui text-primary">{{ row.item_name }}</p>
                                <span class="tag capitalize">{{ row.listing_type }}</span>
                                <span class="tag capitalize">{{ row.rarity }}</span>
                                <span class="tag">{{ row.velocity }}</span>
                            </div>
                            <p class="mt-1 text-xs text-muted-2">
                                {{ row.active_listing_count }} listings · {{ row.active_supply }} supply · {{ row.recent_sale_count }} recent sales
                            </p>
                            <p class="mt-1 text-xs text-muted-3">
                                Floor {{ row.market_floor_price }}g · average {{ row.average_price }}g · cap {{ row.market_ceiling_price }}g
                            </p>
                        </div>
                        <div class="text-left md:text-right">
                            <p class="text-sm font-ui text-primary">{{ row.recommended_price }}g</p>
                            <p class="mt-1 text-xs text-muted-2">suggested list</p>
                            <p class="mt-1 text-xs text-muted-3">{{ row.recent_volume }}g volume</p>
                        </div>
                    </article>

                    <p v-if="!visibleMarketRows.length" class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2">
                        No market signals match.
                    </p>
                </div>

                <div v-else-if="activeBoard === 'sales'" class="grid gap-3">
                    <article
                        v-for="(transaction, index) in visibleTransactions"
                        :key="transaction.id"
                        class="grid gap-3 rounded-md border border-border bg-surface-2 px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_auto]"
                    >
                        <div class="grid h-9 w-9 place-items-center rounded-md border border-border bg-canvas text-sm font-ui text-primary">
                            #{{ index + 1 }}
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-ui text-primary">{{ transaction.item_name }}</p>
                            <p class="mt-1 text-xs text-muted-2">
                                {{ transaction.buyer_name }} bought {{ transaction.listing_type === 'tool' ? 'a tool' : `x${transaction.quantity}` }}
                            </p>
                            <p v-if="transaction.tool" class="mt-1 text-xs text-muted-3">
                                +{{ transaction.tool.experience_bonus }} XP · +{{ transaction.tool.yield_bonus }} yield
                            </p>
                        </div>
                        <p class="text-sm font-ui text-primary md:text-right">{{ transaction.total_price }}g</p>
                    </article>

                    <p v-if="!visibleTransactions.length" class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2">
                        No sales match.
                    </p>
                </div>

                <div v-else class="grid gap-3">
                    <article
                        v-for="(listing, index) in visibleActiveListings"
                        :key="listing.id"
                        class="grid gap-3 rounded-md border border-border bg-surface-2 px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_auto]"
                    >
                        <div class="grid h-9 w-9 place-items-center rounded-md border border-border bg-canvas text-sm font-ui text-primary">
                            #{{ index + 1 }}
                        </div>

                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="min-w-0 truncate text-sm font-ui text-primary">{{ listing.item_name }}</p>
                                <span class="tag capitalize">{{ listing.listing_type }}</span>
                                <span class="tag capitalize">{{ listing.rarity }}</span>
                                <span class="tag capitalize">{{ listing.quality }}</span>
                            </div>
                            <p class="mt-1 text-xs text-muted-2">
                                {{ listing.listing_type === 'tool' ? '1 tool' : listing.quantity }} at {{ listing.unit_price }} gold · {{ listing.seller_name }}
                            </p>
                            <p class="mt-1 text-xs capitalize text-muted-3">
                                {{ listing.item_class }} · {{ listing.total_weight }} wt · {{ listing.market_price_band }}
                            </p>
                            <p v-if="listing.tool" class="mt-1 text-xs text-muted-3">
                                +{{ listing.tool.experience_bonus }} XP · +{{ listing.tool.yield_bonus }} yield · {{ listing.tool.status_label ?? 'Listed' }}
                            </p>
                        </div>

                        <div class="grid content-between gap-3 text-left md:text-right">
                            <p class="text-sm font-ui text-primary">{{ listing.total_price }} gold</p>
                            <button
                                v-if="listing.is_mine"
                                type="button"
                                class="app-btn app-btn--ghost app-btn--sm"
                                :disabled="actionForm.processing"
                                @click="cancelListing(listing.id)"
                            >
                                Cancel
                            </button>
                            <button
                                v-else
                                type="button"
                                class="app-btn app-btn--sm"
                                :disabled="actionForm.processing || !listing.can_buy"
                                @click="buyListing(listing.id)"
                            >
                                Buy
                            </button>
                        </div>
                    </article>

                    <p v-if="!visibleActiveListings.length" class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2">
                        No active listings.
                    </p>
                </div>
            </div>

            <p v-if="actionForm.errors.listing" class="mt-4 text-sm text-(--accent-pink)">
                {{ actionForm.errors.listing }}
            </p>
        </div>
    </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { marketplaceReloadProps } from './reloadProps'

const props = defineProps({
    marketplace: {
        type: Object,
        required: true,
    },
    searchTerm: {
        type: String,
        default: '',
    },
})

const activeBoard = ref('listings')
const listingForm = useForm({
    listing_type: 'item',
    item_key: '',
    tool_id: '',
    quantity: 1,
    unit_price: 1,
})
const vendorForm = useForm({
    item_key: '',
    quantity: 1,
})

const actionForm = useForm({})

const listingError = computed(() => listingForm.errors.item_key ?? listingForm.errors.tool_id ?? listingForm.errors.quantity ?? listingForm.errors.unit_price)
const vendorError = computed(() => vendorForm.errors.item_key ?? vendorForm.errors.quantity)
const visibleSellableInventory = computed(() => props.marketplace.sellable_inventory.filter((item) => searchMatches(item, props.searchTerm)))
const visibleSellableTools = computed(() => (props.marketplace.sellable_tools ?? []).filter((tool) => searchMatches(tool, props.searchTerm)))
const visibleActiveListings = computed(() => props.marketplace.active_listings.filter((listing) => searchMatches(listing, props.searchTerm)))
const visibleTransactions = computed(() => props.marketplace.recent_transactions.filter((transaction) => searchMatches(transaction, props.searchTerm)))
const visibleMarketRows = computed(() => (props.marketplace.market_board?.rows ?? []).filter((row) => searchMatches(row, props.searchTerm)))
const marketVolume = computed(() => visibleTransactions.value.reduce((total, transaction) => total + transaction.total_price, 0))
const selectedListingItem = computed(() => visibleSellableInventory.value.find((item) => item.item_key === listingForm.item_key))
const selectedListingTool = computed(() => visibleSellableTools.value.find((tool) => Number(tool.tool_id) === Number(listingForm.tool_id)))
const selectedListingEntry = computed(() => listingForm.listing_type === 'tool' ? selectedListingTool.value : selectedListingItem.value)
const selectedVendorItem = computed(() => visibleSellableInventory.value.find((item) => item.item_key === vendorForm.item_key))
const vendorSaleTotal = computed(() => {
    if (!selectedVendorItem.value) {
        return 0
    }

    return selectedVendorItem.value.npc_buy_price * Math.max(1, Number(vendorForm.quantity || 1))
})
const boards = computed(() => [
    { key: 'listings', label: 'Listings', count: visibleActiveListings.value.length },
    { key: 'market', label: 'Market Board', count: visibleMarketRows.value.length },
    { key: 'sell', label: 'Sell', count: visibleSellableInventory.value.length + visibleSellableTools.value.length },
    { key: 'vendor', label: 'Ledger Steward', count: visibleSellableInventory.value.length },
    { key: 'sales', label: 'Sales', count: visibleTransactions.value.length },
])
const activeBoardRecord = computed(() => boards.value.find((board) => board.key === activeBoard.value) ?? boards.value[0])

function searchMatches(entry, query) {
    const normalizedQuery = query.trim().toLowerCase()

    if (!normalizedQuery) {
        return true
    }

    return [
        entry.item_name,
        entry.rarity,
        entry.quality,
        entry.item_class,
        entry.material_family,
        entry.seller_name,
        entry.buyer_name,
        entry.status_label,
        entry.listing_type,
        entry.velocity,
        ...(entry.tags ?? []),
    ].filter(Boolean).join(' ').toLowerCase().includes(normalizedQuery)
}

function submitListing() {
    if (listingForm.listing_type === 'tool') {
        listingForm.quantity = 1
        listingForm.item_key = ''
    } else {
        listingForm.tool_id = ''
    }

    listingForm.post(route('evergather.marketplace.listings.store'), {
        preserveScroll: true,
        only: marketplaceReloadProps,
        onSuccess: () => listingForm.reset(),
    })
}

function sellToNpc() {
    vendorForm.post(route('evergather.marketplace.vendor-sales.store'), {
        preserveScroll: true,
        only: marketplaceReloadProps,
        onSuccess: () => vendorForm.reset(),
    })
}

function buyListing(listing) {
    actionForm.post(route('evergather.marketplace.listings.buy', listing), {
        preserveScroll: true,
        only: marketplaceReloadProps,
    })
}

function cancelListing(listing) {
    actionForm.delete(route('evergather.marketplace.listings.destroy', listing), {
        preserveScroll: true,
        only: marketplaceReloadProps,
    })
}
</script>
