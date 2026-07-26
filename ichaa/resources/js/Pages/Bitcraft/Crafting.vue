<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="page-hero">
                <div class="page-hero__copy">
                    <div class="page-hero__eyebrow">
                        <span>Bitcraft tools</span>
                    </div>
                    <h1 class="page-hero__title page-hero__title--lg">Crafting Calculator</h1>
                    <p class="page-hero__subtitle">Search craftable items and cargo, then scale their Bitjita recipe data.</p>
                </div>
            </div>
        </template>

        <form @submit.prevent="submit" class="index-panel max-w-3xl">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <label class="field-label">Recipe search</label>
                <span class="tag" :class="snapshot?.available ? 'tag--success' : ''">{{ snapshotLabel }}</span>
            </div>
            <div class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_120px_auto]">
                <TextInput v-model.trim="form.q" type="text" placeholder="Timber, pickaxe, plank, ingot..." />
                <TextInput v-model.number="form.quantity" type="number" min="1" max="999999" inputmode="numeric" />
                <AppButton type="submit" variant="primary" :disabled="searching">{{ searching ? 'Searching...' : 'Search' }}</AppButton>
            </div>
        </form>

        <div v-if="error" class="mt-5 rounded-md border border-[rgb(var(--accent-pink-rgb)/0.28)] bg-[rgb(var(--accent-pink-rgb)/0.08)] px-4 py-3 text-sm text-(--accent-pink)">
            {{ error }}
        </div>

        <div class="mt-5 grid gap-4 2xl:grid-cols-[300px_minmax(0,1fr)]">
            <section class="surface-section min-w-0">
                <div class="surface-section__header">
                    <div class="surface-section__copy">
                        <h2 class="surface-section__title">Matches</h2>
                        <p class="surface-section__subtitle">{{ items.length }} recipe target{{ items.length === 1 ? '' : 's' }}</p>
                    </div>
                </div>

                <div class="surface-section__body">
                    <div v-if="items.length" class="index-surface index-surface--nested">
                        <Link
                            v-for="item in items"
                            :key="`${item.kind}:${item.id}`"
                            :href="route('bitcraft.crafting', selectedParams(item))"
                            class="index-record crafting-match hover:border-[rgb(var(--accent-cyan-rgb)/0.35)] transition-colors"
                            :class="{ 'border-[rgb(var(--accent-cyan-rgb)/0.5)]': isSelected(item) }"
                        >
                            <span class="crafting-item-icon" :style="itemFrameStyle(item)" aria-hidden="true">
                                <img
                                    v-if="itemIconUrl(item)"
                                    :src="itemIconUrl(item)"
                                    alt=""
                                    loading="lazy"
                                    @error="hideBrokenIcon(item.iconAssetName)"
                                >
                                <span v-else>{{ itemInitials(item.name) }}</span>
                            </span>
                            <span class="min-w-0">
                                <span class="index-record__title prose-wrap block">{{ item.name }}</span>
                                <span class="mt-2 flex flex-wrap gap-2">
                                    <span class="tag">{{ item.kind === 'cargo' ? 'Cargo' : 'Item' }}</span>
                                    <span v-if="item.category" class="tag">{{ item.category }}</span>
                                    <span v-if="item.tier" class="tag bitcraft-tier-badge" :style="tierStyle(item.tier)">
                                        Tier {{ item.tier }}
                                    </span>
                                    <span v-if="item.rarity" class="tag bitcraft-rarity-badge" :style="rarityStyle(item.rarity)">
                                        {{ item.rarity }}
                                    </span>
                                </span>
                            </span>
                        </Link>
                    </div>

                    <div v-else class="empty-state-panel">
                        <p class="text-muted-3 text-sm font-ui">Search for an item to load recipe options.</p>
                    </div>
                </div>
            </section>

            <section class="surface-section min-w-0">
                <div v-if="detail">
                    <div class="surface-section__header">
                        <span class="crafting-detail-icon" :style="itemFrameStyle(detail.item)" aria-hidden="true">
                            <img
                                v-if="itemIconUrl(detail.item)"
                                :src="itemIconUrl(detail.item)"
                                alt=""
                                loading="lazy"
                                @error="hideBrokenIcon(detail.item.iconAssetName)"
                            >
                            <span v-else>{{ itemInitials(detail.item.name) }}</span>
                        </span>
                        <div class="surface-section__copy">
                            <h2 class="surface-section__title">{{ detail.item.name }}</h2>
                            <p class="surface-section__subtitle">
                                <span>{{ detail.item.kind === 'cargo' ? 'Cargo' : 'Item' }}</span>
                                <span v-if="detail.item.category"> · {{ detail.item.category }}</span>
                                <span v-if="detail.item.tier" class="crafting-detail-tier bitcraft-tier-badge" :style="tierStyle(detail.item.tier)">
                                    T{{ detail.item.tier }}
                                </span>
                                <span v-if="detail.item.rarity" class="crafting-detail-rarity bitcraft-rarity-badge" :style="rarityStyle(detail.item.rarity)">
                                    {{ detail.item.rarity }}
                                </span>
                            </p>
                        </div>
                    </div>

                    <div class="surface-section__body">
                        <p v-if="detail.item.description" class="prose-wrap text-sm leading-relaxed text-muted-2">
                            {{ detail.item.description }}
                        </p>

                        <div class="grid gap-4" :class="{ 'mt-5': detail.item.description }">
                            <CraftingRecipeTree :recipes="detail.recipeTree" :desired-quantity="desiredQuantity" />
                        </div>
                    </div>
                </div>

                <div v-else class="surface-section__body">
                    <div class="empty-state-panel">
                        <p class="text-muted-3 text-sm font-ui">Select an item or cargo target to inspect its recipes.</p>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import TextInput from '@/Components/TextInput.vue'
import CraftingRecipeTree from '@/Pages/Bitcraft/Components/CraftingRecipeTree.vue'
import { bitcraftItemFrameStyle, bitcraftRarityStyle, bitcraftTierStyle, bitjitaAssetUrl } from '@/Pages/Bitcraft/bitjitaAssets.js'

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    items: { type: Array, default: () => [] },
    detail: { type: Object, default: null },
    snapshot: { type: Object, default: () => ({}) },
    error: { type: String, default: null },
})

const form = reactive({
    q: props.filters.q ?? '',
    quantity: props.filters.quantity ?? 1,
})
const brokenIconAssets = ref(new Set())
const searching = ref(false)

watch(() => props.filters, (filters) => {
    form.q = filters.q ?? ''
    form.quantity = filters.quantity ?? 1
})

const selectedItemId = computed(() => Number(props.filters.itemId))
const selectedItemKind = computed(() => props.filters.itemKind ?? 'item')
const desiredQuantity = computed(() => Math.max(1, Number(props.filters.quantity ?? 1) || 1))
const snapshotLabel = computed(() => {
    if (!props.snapshot?.available) {
        return 'Live fallback'
    }

    if (!props.snapshot.generatedAt) {
        return 'Static snapshot'
    }

    const date = new Date(props.snapshot.generatedAt)

    if (Number.isNaN(date.getTime())) {
        return 'Static snapshot'
    }

    return `Snapshot ${date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    })}`
})

const searchParams = () => {
    const params = {}

    if (form.q) {
        params.q = form.q
    }

    if (Number(form.quantity) > 1) {
        params.quantity = Number(form.quantity)
    }

    if (form.q === (props.filters.q ?? '') && props.filters.itemId) {
        params.itemId = props.filters.itemId
        params.itemKind = props.filters.itemKind ?? 'item'
    }

    return params
}

const submit = () => {
    router.get(route('bitcraft.crafting'), searchParams(), {
        preserveState: true,
        replace: true,
        onStart: () => {
            searching.value = true
        },
        onFinish: () => {
            searching.value = false
        },
    })
}

const selectedParams = (item) => {
    const params = {
        q: form.q,
        itemId: item.id,
        itemKind: item.kind,
    }

    if (Number(form.quantity) > 1) {
        params.quantity = Number(form.quantity)
    }

    return params
}

const isSelected = (item) => selectedItemId.value === Number(item.id) && selectedItemKind.value === item.kind

const itemIconUrl = (item) => {
    const assetName = item?.iconAssetName

    if (brokenIconAssets.value.has(assetName)) {
        return null
    }

    return bitjitaAssetUrl(assetName)
}

const itemInitials = (name) => String(name ?? '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('') || '?'

const hideBrokenIcon = (assetName) => {
    brokenIconAssets.value = new Set([...brokenIconAssets.value, assetName])
}

const tierStyle = (tier) => bitcraftTierStyle(tier)
const rarityStyle = (rarity) => bitcraftRarityStyle(rarity)
const itemFrameStyle = (item) => bitcraftItemFrameStyle(item?.tier, item?.rarity)
</script>

<style scoped>
.crafting-match {
    display: grid;
    grid-template-columns: 38px minmax(0, 1fr);
    gap: 12px;
    align-items: center;
}

.crafting-item-icon,
.crafting-detail-icon {
    position: relative;
    display: grid;
    place-items: center;
    flex: none;
    border: 1px solid var(--bitcraft-item-frame-border, rgb(var(--border-color-rgb) / 0.7));
    border-radius: 6px;
    background:
        radial-gradient(circle at 35% 25%, var(--bitcraft-item-frame-bg, rgb(var(--accent-cyan-rgb) / 0.2)), transparent 42%),
        linear-gradient(180deg, color-mix(in srgb, var(--bitcraft-item-frame-accent, transparent) 12%, transparent), transparent),
        rgb(var(--bg-surface-rgb) / 0.92);
    box-shadow: inset 0 1px 0 rgb(255 255 255 / 0.08), 0 0 12px color-mix(in srgb, var(--bitcraft-item-frame-accent, transparent) 20%, transparent);
    color: var(--bitcraft-item-frame-text, var(--text-muted-2));
    font-family: var(--font-ui);
    font-size: 11px;
    font-weight: 800;
    overflow: hidden;
}

.crafting-item-icon {
    width: 38px;
    height: 38px;
}

.crafting-detail-icon {
    width: 46px;
    height: 46px;
}

.crafting-item-icon img,
.crafting-detail-icon img {
    position: absolute;
    inset: 4px;
    width: calc(100% - 8px);
    height: calc(100% - 8px);
    object-fit: contain;
}

.crafting-detail-tier {
    display: inline-flex;
    align-items: center;
    margin-left: 6px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 800;
    line-height: 1;
    padding: 3px 6px;
    vertical-align: 1px;
}

.crafting-detail-rarity {
    display: inline-flex;
    align-items: center;
    margin-left: 6px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 800;
    line-height: 1;
    padding: 3px 6px;
    vertical-align: 1px;
}

.bitcraft-tier-badge {
    border-color: var(--bitcraft-tier-border, currentColor);
    background:
        linear-gradient(180deg, var(--bitcraft-tier-bg, transparent), rgb(var(--bg-surface-rgb) / 0.7)),
        rgb(var(--bg-surface-rgb) / 0.7);
    box-shadow: inset 0 1px 0 rgb(255 255 255 / 0.08), 0 0 12px color-mix(in srgb, var(--bitcraft-tier-accent, transparent) 22%, transparent);
    color: var(--bitcraft-tier-text, currentColor);
}

.bitcraft-rarity-badge {
    border-color: var(--bitcraft-rarity-border, currentColor);
    background:
        linear-gradient(180deg, var(--bitcraft-rarity-bg, transparent), rgb(var(--bg-surface-rgb) / 0.7)),
        rgb(var(--bg-surface-rgb) / 0.7);
    box-shadow: inset 0 1px 0 rgb(255 255 255 / 0.08), 0 0 12px color-mix(in srgb, var(--bitcraft-rarity-accent, transparent) 22%, transparent);
    color: var(--bitcraft-rarity-text, currentColor);
}
</style>
