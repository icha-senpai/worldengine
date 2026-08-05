<template>
    <section class="surface-section xl:col-span-2">
        <div class="surface-section__header">
            <div class="surface-section__copy">
                <span class="surface-section__title">Market Shop</span>
                <p class="surface-section__subtitle">{{ buyableCount }} offers affordable · {{ shop.offers.length }} listed.</p>
            </div>
        </div>

        <div class="surface-section__body">
            <div class="grid gap-4 xl:grid-cols-[17rem_minmax(0,1fr)]">
                <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-ui text-primary">Supply Counter</p>
                        <span class="tag">{{ activeBoard.count }} {{ activeBoard.unit }}</span>
                    </div>
                    <p class="mt-1 text-xs text-muted-3">{{ activeBoard.description }}</p>

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button
                            v-for="board in offerBoards"
                            :key="board.key"
                            type="button"
                            class="rounded-md border border-border bg-canvas px-3 py-2 text-left transition hover:border-focus/60"
                            :class="{ 'border-focus/70 bg-focus/10': selectedBoard === board.key }"
                            @click="selectedBoard = board.key"
                        >
                            <span class="block text-xs font-ui text-primary">{{ board.label }}</span>
                            <span class="mt-1 block text-[11px] text-muted-3">{{ board.count }} {{ board.unit }}</span>
                        </button>
                    </div>

                    <div class="mt-4 grid gap-2">
                        <button
                            v-for="group in offerGroups"
                            :key="group.key"
                            type="button"
                            class="grid gap-2 rounded-md border border-border bg-canvas px-3 py-2 text-left transition hover:border-focus/60"
                            :class="{ 'border-focus/70 bg-focus/10': selectedFilter === group.key }"
                            @click="selectedFilter = group.key"
                        >
                            <span class="flex min-w-0 items-center justify-between gap-2">
                                <span class="truncate text-xs font-ui text-primary">{{ group.label }}</span>
                                <span class="text-[11px] text-muted-3">{{ group.buyable }}/{{ group.count }}</span>
                            </span>
                            <span class="h-1.5 overflow-hidden rounded-full bg-surface-1">
                                <span class="block h-full rounded-full bg-focus" :style="{ width: `${groupProgress(group)}%` }" />
                            </span>
                        </button>
                    </div>

                    <div class="mt-3 grid gap-2 text-xs">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Affordable</span>
                            <span class="text-primary">{{ activeGroup.buyable }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Rare+</span>
                            <span class="text-primary">{{ activeGroup.rare }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Highest Price</span>
                            <span class="text-primary">{{ activeGroup.highestPrice }}g</span>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-2">
                        <button
                            v-for="offer in visibleOffers.slice(0, 8)"
                            :key="`jump-${offer.key}`"
                            type="button"
                            class="grid gap-1 rounded-md border border-border bg-canvas px-3 py-2 text-left"
                        >
                            <span class="truncate text-xs font-ui text-primary">{{ offer.label }}</span>
                            <span class="text-[11px] text-muted-3">{{ offer.price }}g · {{ offer.quality }}</span>
                        </button>
                    </div>
                </div>

                <div class="grid content-start gap-4">
                    <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-ui text-primary">{{ activeBoard.label }}</p>
                                <p class="mt-1 text-xs text-muted-3">{{ activeGroup.label }} · {{ visibleOffers.length }} visible</p>
                            </div>
                            <span class="tag">{{ activeGroup.highestPrice }}g max</span>
                        </div>
                    </div>

                    <div v-if="visibleOffers.length" class="grid gap-2">
                        <article
                            v-for="(offer, index) in visibleOffers"
                            :key="offer.key"
                            class="grid min-h-32 items-start gap-3 rounded-md border border-border bg-surface-2 px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_7rem]"
                        >
                            <div class="grid h-9 w-9 place-items-center rounded-md border border-border bg-canvas text-sm font-ui text-primary">
                                #{{ index + 1 }}
                            </div>

                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="min-w-0 truncate text-sm font-ui text-primary">{{ offer.label }}</p>
                                    <span class="tag capitalize">{{ offer.rarity }}</span>
                                    <span class="tag capitalize">{{ offer.quality }}</span>
                                    <span
                                        v-if="offer.kind === 'tool'"
                                        class="tag"
                                        :class="{ 'tag--success': offer.is_equipped }"
                                    >
                                        {{ offer.ownership_status }}
                                    </span>
                                </div>
                                <p class="mt-1 text-xs text-muted-2">
                                    {{ offer.category }}<span v-if="offer.skill_label"> · {{ offer.skill_label }}</span>
                                </p>
                                <p v-if="offer.current_tool && offer.kind === 'tool'" class="mt-1 text-xs text-muted-3">
                                    Equipped: {{ offer.current_tool.item_name }} · +{{ offer.current_tool.experience_bonus }} XP · +{{ offer.current_tool.yield_bonus }} yield
                                </p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <span class="tag">{{ offer.weight }} wt</span>
                                    <span class="tag">{{ offer.vendor_value }}g value</span>
                                    <span v-if="offer.required_level > 1" class="tag">Lv {{ offer.required_level }}</span>
                                    <span v-if="offer.kind === 'tool'" class="tag">+{{ offer.bonuses.experience }} XP</span>
                                    <span v-if="offer.kind === 'tool'" class="tag">+{{ offer.bonuses.yield }} yield</span>
                                    <span v-else class="tag">x{{ offer.quantity }}</span>
                                </div>
                            </div>

                            <div class="grid content-between gap-3 text-left md:text-right">
                                <div>
                                    <p class="text-sm font-ui text-primary">{{ offer.price }} gold</p>
                                    <p v-if="offer.is_equipped" class="mt-1 text-xs text-success">Already equipped</p>
                                    <p v-else-if="offer.is_downgrade" class="mt-1 text-xs text-muted-3">Current tool is stronger</p>
                                    <p v-else-if="!offer.is_unlocked" class="mt-1 text-xs text-muted-3">Level {{ offer.skill_level }} / {{ offer.required_level }}</p>
                                    <p v-else-if="!offer.can_buy" class="mt-1 text-xs text-muted-3">Need gold</p>
                                    <p v-else class="mt-1 text-xs text-success">Ready</p>
                                </div>

                                <button
                                    type="button"
                                    class="app-btn app-btn--sm"
                                    :disabled="form.processing || !offer.can_buy"
                                    @click="buy(offer.key)"
                                >
                                    {{ runningOffer === offer.key ? 'Buying...' : offer.is_equipped ? 'Equipped' : offer.is_downgrade ? 'Owned' : 'Buy' }}
                                </button>
                            </div>
                        </article>
                    </div>

                    <button
                        v-if="canShowMoreOffers"
                        type="button"
                        class="app-btn app-btn--ghost app-btn--sm justify-self-center"
                        @click="visibleLimit += boardPageSize"
                    >
                        Show More
                    </button>

                    <p v-if="!visibleOffers.length" class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2">
                        {{ emptyBoardMessage }}
                    </p>
                </div>
            </div>

            <p v-if="form.errors.offer" class="mt-4 text-sm text-(--accent-pink)">
                {{ form.errors.offer }}
            </p>
        </div>
    </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { shopReloadProps } from './reloadProps'

const props = defineProps({
    shop: {
        type: Object,
        required: true,
    },
    searchTerm: {
        type: String,
        default: '',
    },
})

const selectedFilter = ref('Tools')
const selectedBoard = ref('buyable')
const boardPageSize = 12
const visibleLimit = ref(boardPageSize)
const runningOffer = ref('')
const form = useForm({
    offer: null,
})

const offerGroups = computed(() => ['Tools', 'Materials', 'Commissions'].map((key) => {
    const offers = props.shop.offers.filter((offer) => offerMatchesGroup(offer, key))

    return {
        key,
        label: key,
        count: offers.length,
        buyable: offers.filter((offer) => offer.can_buy).length,
        rare: offers.filter((offer) => ['rare', 'epic', 'legendary'].includes(offer.rarity)).length,
        highestPrice: Math.max(0, ...offers.map((offer) => offer.price)),
    }
}))
const activeGroup = computed(() => offerGroups.value.find((group) => group.key === selectedFilter.value) ?? offerGroups.value[0])
const filteredOffers = computed(() => props.shop.offers
    .filter((offer) => offerMatchesGroup(offer, selectedFilter.value))
    .filter((offer) => searchMatches(offer, props.searchTerm))
    .sort((a, b) => Number(b.can_buy) - Number(a.can_buy) || b.price - a.price))
const buyableOffers = computed(() => filteredOffers.value.filter((offer) => offer.can_buy))
const usefulLockedOffers = computed(() => filteredOffers.value.filter((offer) => !offer.can_buy && !offer.is_equipped && !offer.is_downgrade))
const offerBoards = computed(() => [
    {
        key: 'buyable',
        label: 'Buyable',
        count: buyableOffers.value.length,
        unit: 'ready',
        entries: buyableOffers.value,
        description: `${activeGroup.value.label} you can afford and use now.`,
    },
    {
        key: 'plan',
        label: 'Plan',
        count: usefulLockedOffers.value.length,
        unit: 'locked',
        entries: usefulLockedOffers.value,
        description: 'Useful offers that need gold or levels.',
    },
])
const activeBoard = computed(() => offerBoards.value.find((board) => board.key === selectedBoard.value) ?? offerBoards.value[0])
const visibleOffers = computed(() => activeBoard.value.entries.slice(0, visibleLimit.value))
const canShowMoreOffers = computed(() => activeBoard.value.entries.length > visibleOffers.value.length)
const buyableCount = computed(() => props.shop.offers.filter((offer) => offer.can_buy).length)
const emptyBoardMessage = computed(() => {
    if (selectedBoard.value === 'buyable') {
        return 'No buyable offers match. Check Plan for what to save for.'
    }

    return 'No offers match.'
})

watch([selectedBoard, selectedFilter, () => props.searchTerm], () => {
    visibleLimit.value = boardPageSize
})

watch([buyableOffers, usefulLockedOffers], () => {
    if (!buyableOffers.value.length && usefulLockedOffers.value.length && selectedBoard.value === 'buyable') {
        selectedBoard.value = 'plan'
    }

    if (!usefulLockedOffers.value.length && buyableOffers.value.length && selectedBoard.value === 'plan') {
        selectedBoard.value = 'buyable'
    }
}, { immediate: true })

function offerMatchesGroup(offer, group) {
    if (group === 'Tools') {
        return offer.kind === 'tool'
    }

    return offer.category === group
}

function searchMatches(offer, query) {
    const normalizedQuery = query.trim().toLowerCase()

    if (!normalizedQuery) {
        return true
    }

    return [
        offer.label,
        offer.category,
        offer.skill_label,
        offer.item_name,
        offer.rarity,
        offer.quality,
        offer.item_class,
        offer.material_family,
        ...(offer.tags ?? []),
    ].filter(Boolean).join(' ').toLowerCase().includes(normalizedQuery)
}

function groupProgress(group) {
    if (!group.count) {
        return 0
    }

    return Math.round((group.buyable / group.count) * 100)
}

function buy(offer) {
    form.offer = offer
    form.post(route('evergather.shop.purchases.store'), {
        preserveScroll: true,
        only: shopReloadProps,
        onStart: () => {
            runningOffer.value = offer
        },
        onFinish: () => {
            runningOffer.value = ''
        },
    })
}
</script>
