<template>
    <section class="surface-section xl:col-span-2">
        <div class="surface-section__header">
            <div class="surface-section__copy">
                <span class="surface-section__title">Guild Shop</span>
                <p class="surface-section__subtitle">{{ buyableCount }} offers affordable · {{ shop.offers.length }} listed.</p>
            </div>
        </div>

        <div class="surface-section__body">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="group in offerGroups"
                    :key="group.key"
                    type="button"
                    class="rounded-md border border-border bg-surface-2 px-3 py-2 text-xs font-ui text-muted-2 transition hover:border-focus/60 hover:text-primary"
                    :class="{ 'border-focus/70 bg-focus/10 text-primary': selectedFilter === group.key }"
                    @click="selectedFilter = group.key"
                >
                    {{ group.label }} · {{ group.count }}
                </button>
            </div>

            <div class="mt-4 grid gap-4 xl:grid-cols-[16rem_minmax(0,1fr)]">
                <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                    <p class="text-sm font-ui text-primary">{{ activeGroup.label }}</p>
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

                <div class="grid gap-4">
                    <div v-if="visibleOffers.length" class="grid gap-2">
                        <article
                            v-for="(offer, index) in visibleOffers"
                            :key="offer.key"
                            class="grid gap-3 rounded-md border border-border bg-surface-2 px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_auto]"
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
                                    {{ offer.is_equipped ? 'Equipped' : offer.is_downgrade ? 'Owned' : 'Buy' }}
                                </button>
                            </div>
                        </article>
                    </div>

                    <p v-else class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2">
                        No offers match.
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
import { computed, ref } from 'vue'
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
const visibleOffers = computed(() => props.shop.offers
    .filter((offer) => offerMatchesGroup(offer, selectedFilter.value))
    .filter((offer) => searchMatches(offer, props.searchTerm))
    .sort((a, b) => Number(b.can_buy) - Number(a.can_buy) || b.price - a.price))
const buyableCount = computed(() => props.shop.offers.filter((offer) => offer.can_buy).length)

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

function buy(offer) {
    form.offer = offer
    form.post(route('evergather.shop.purchases.store'), {
        preserveScroll: true,
        only: shopReloadProps,
    })
}
</script>
