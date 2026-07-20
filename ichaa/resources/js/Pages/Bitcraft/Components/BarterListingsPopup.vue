<template>
    <PopupCard
        :show="show"
        :title="item?.name ?? 'Barter listings'"
        :subtitle="subtitle"
        eyebrow="Barter stall listings"
        max-width="2xl"
        @close="$emit('close')"
    >
        <template #actions>
            <button
                v-for="option in sideOptions"
                :key="option.value || 'both'"
                type="button"
                class="tag transition-colors hover:text-focus"
                :class="{ 'border-[rgb(var(--accent-cyan-rgb)/0.55)] text-focus': side === option.value }"
                @click="$emit('update:side', option.value)"
            >
                {{ option.label }}
            </button>
        </template>

        <div v-if="listings.length" class="index-surface index-surface--nested">
            <div v-for="listing in listings" :key="listing.entityId" class="index-record">
                <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_220px] lg:items-start">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="tag" :class="listing.side === 'sell' ? 'tag--success' : 'tag--warn'">
                                {{ listing.side }}
                            </span>
                            <p class="index-record__title prose-wrap">{{ listing.itemName }}</p>
                        </div>

                        <div class="mt-2 flex flex-wrap gap-2">
                            <span v-if="listing.itemTier" class="tag">Tier {{ listing.itemTier }}</span>
                            <span v-if="listing.itemRarity" class="tag">{{ listing.itemRarity }}</span>
                            <span v-if="listing.ownerUsername" class="tag">{{ listing.ownerUsername }}</span>
                            <span v-if="listing.claimName" class="tag">{{ listing.claimName }}</span>
                            <span v-if="listing.regionName" class="tag">{{ listing.regionName }}</span>
                            <span v-if="listing.stall" class="tag">{{ listing.stall.name }}</span>
                            <span v-else-if="listing.stallMatchStatus === 'multiple'" class="tag">Multiple possible stalls</span>
                            <span v-else-if="listing.stallMatchStatus === 'unknown'" class="tag">Stall unknown</span>
                        </div>

                        <p v-if="listing.stall" class="mt-2 text-xs font-ui text-muted-3">
                            {{ listing.stall.buildingName }}
                            <span v-if="listing.stall.entityId"> · {{ listing.stall.entityId }}</span>
                            <span v-if="listing.claimEntityId"> · Claim {{ listing.claimEntityId }}</span>
                            <span v-if="listing.stall.ownerName"> · {{ listing.stall.ownerName }}</span>
                            <span v-if="listing.stall.locationX && listing.stall.locationZ">
                                · N{{ formatCoordinate(listing.stall.locationZ) }}, E{{ formatCoordinate(listing.stall.locationX) }}
                            </span>
                        </p>
                        <p v-else-if="listing.claimEntityId" class="mt-2 text-xs font-ui text-muted-3">
                            Claim {{ listing.claimEntityId }}
                        </p>

                        <div v-if="listing.offerSummary || listing.requiredSummary" class="mt-3 grid gap-1 text-xs font-ui text-muted-2">
                            <p v-if="listing.offerSummary" class="prose-wrap">
                                <span class="text-muted-3">Offers:</span> {{ listing.offerSummary }}
                            </p>
                            <p v-if="listing.requiredSummary" class="prose-wrap">
                                <span class="text-muted-3">Requires:</span> {{ listing.requiredSummary }}
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2 text-xs font-ui text-muted-2">
                        <span>{{ listing.priceCurrency ? 'Unit price' : 'Trade' }}</span>
                        <span class="text-right text-primary">{{ formatCoins(listing.price) }}</span>
                        <span v-if="listing.bundlePrice">Bundle price</span>
                        <span v-if="listing.bundlePrice" class="text-right text-primary">
                            {{ formatCoins(listing.bundlePrice) }} {{ listing.priceCurrency }}
                        </span>
                        <span>Quantity</span>
                        <span class="text-right text-primary">{{ formatCount(listing.quantity) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="empty-state-panel">
            <p class="text-muted-3 text-sm font-ui">No listings found for that item.</p>
        </div>
    </PopupCard>
</template>

<script setup>
import { computed } from 'vue'
import PopupCard from '@/Components/ui/PopupCard.vue'

const props = defineProps({
    show: { type: Boolean, default: false },
    item: { type: Object, default: null },
    listings: { type: Array, default: () => [] },
    side: { type: String, default: '' },
    sideOptions: { type: Array, default: () => [] },
    claim: { type: Object, default: null },
    claims: { type: Array, default: () => [] },
    hasStallOrderListings: { type: Boolean, default: false },
})

defineEmits(['close', 'update:side'])

const subtitle = computed(() => {
    const parts = [`${props.listings.length} listing${props.listings.length === 1 ? '' : 's'}`]

    if (props.claim?.name) {
        parts.push(`at ${props.claim.name}`)
    } else if (props.claims.length) {
        parts.push(`across ${props.claims.length} claim${props.claims.length === 1 ? '' : 's'}`)
    }

    if (props.hasStallOrderListings) {
        parts.push('Bitjita stall orders')
    }

    return parts.join(' · ')
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

const formatCoordinate = (value) => {
    const number = Number(value)

    return Number.isFinite(number) ? Math.round(number / 3).toLocaleString() : value
}
</script>
