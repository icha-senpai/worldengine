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
            <label class="field-label">Recipe search</label>
            <div class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_120px_auto]">
                <TextInput v-model.trim="form.q" type="text" placeholder="Timber, pickaxe, plank, ingot..." />
                <TextInput v-model.number="form.quantity" type="number" min="1" max="999999" inputmode="numeric" />
                <AppButton type="submit" variant="primary">Search</AppButton>
            </div>
        </form>

        <div v-if="error" class="mt-5 rounded-md border border-[rgb(var(--accent-pink-rgb)/0.28)] bg-[rgb(var(--accent-pink-rgb)/0.08)] px-4 py-3 text-sm text-(--accent-pink)">
            {{ error }}
        </div>

        <div class="mt-5 grid gap-4 xl:grid-cols-[340px_minmax(0,1fr)]">
            <section class="surface-section">
                <div class="surface-section__header">
                    <div class="surface-section__copy">
                        <h2 class="surface-section__title">Matches</h2>
                        <p class="surface-section__subtitle">{{ items.length }} recipe target{{ items.length === 1 ? '' : 's' }}</p>
                    </div>
                </div>

                <div v-if="items.length" class="index-surface index-surface--nested">
                    <Link
                        v-for="item in items"
                        :key="`${item.kind}:${item.id}`"
                        :href="route('bitcraft.crafting', selectedParams(item))"
                        class="index-record block hover:border-[rgb(var(--accent-cyan-rgb)/0.35)] transition-colors"
                        :class="{ 'border-[rgb(var(--accent-cyan-rgb)/0.5)]': isSelected(item) }"
                    >
                        <p class="index-record__title prose-wrap">{{ item.name }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <span class="tag">{{ item.kind === 'cargo' ? 'Cargo' : 'Item' }}</span>
                            <span v-if="item.category" class="tag">{{ item.category }}</span>
                            <span v-if="item.tier" class="tag">Tier {{ item.tier }}</span>
                            <span v-if="item.rarity" class="tag">{{ item.rarity }}</span>
                        </div>
                    </Link>
                </div>

                <div v-else class="empty-state-panel">
                    <p class="text-muted-3 text-sm font-ui">Search for an item to load recipe options.</p>
                </div>
            </section>

            <section class="surface-section">
                <div v-if="detail">
                    <div class="surface-section__header">
                        <div class="surface-section__copy">
                            <h2 class="surface-section__title">{{ detail.item.name }}</h2>
                            <p class="surface-section__subtitle">
                                <span>{{ detail.item.kind === 'cargo' ? 'Cargo' : 'Item' }}</span>
                                <span v-if="detail.item.category"> · {{ detail.item.category }}</span>
                                <span v-if="detail.item.tier"> · Tier {{ detail.item.tier }}</span>
                                <span v-if="detail.item.rarity"> · {{ detail.item.rarity }}</span>
                            </p>
                        </div>
                    </div>

                    <p v-if="detail.item.description" class="prose-wrap text-sm leading-relaxed text-muted-2">
                        {{ detail.item.description }}
                    </p>

                    <div class="mt-5 grid gap-4">
                        <CraftingRecipeTree :recipes="detail.recipeTree" :desired-quantity="desiredQuantity" />
                    </div>
                </div>

                <div v-else class="empty-state-panel">
                    <p class="text-muted-3 text-sm font-ui">Select an item or cargo target to inspect its recipes.</p>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, reactive, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import TextInput from '@/Components/TextInput.vue'
import CraftingRecipeTree from '@/Pages/Bitcraft/Components/CraftingRecipeTree.vue'

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    items: { type: Array, default: () => [] },
    detail: { type: Object, default: null },
    error: { type: String, default: null },
})

const form = reactive({
    q: props.filters.q ?? '',
    quantity: props.filters.quantity ?? 1,
})

watch(() => props.filters, (filters) => {
    form.q = filters.q ?? ''
    form.quantity = filters.quantity ?? 1
})

const selectedItemId = computed(() => Number(props.filters.itemId))
const selectedItemKind = computed(() => props.filters.itemKind ?? 'item')
const desiredQuantity = computed(() => Math.max(1, Number(props.filters.quantity ?? 1) || 1))

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

</script>
