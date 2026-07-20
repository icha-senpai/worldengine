<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="page-hero">
                <div class="page-hero__copy">
                    <div class="page-hero__eyebrow">
                        <span>Bitcraft tools</span>
                    </div>
                    <h1 class="page-hero__title page-hero__title--lg">Crafting Calculator</h1>
                    <p class="page-hero__subtitle">Search an item and inspect its Bitjita recipe data.</p>
                </div>
            </div>
        </template>

        <form @submit.prevent="submit" class="index-panel max-w-3xl">
            <label class="field-label">Item search</label>
            <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                <TextInput v-model.trim="form.q" type="text" class="flex-1" placeholder="Pickaxe, plank, ingot..." />
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
                        <p class="surface-section__subtitle">{{ items.length }} item{{ items.length === 1 ? '' : 's' }}</p>
                    </div>
                </div>

                <div v-if="items.length" class="index-surface index-surface--nested">
                    <Link
                        v-for="item in items"
                        :key="item.id"
                        :href="route('bitcraft.crafting', { q: form.q, itemId: item.id })"
                        class="index-record block hover:border-[rgb(var(--accent-cyan-rgb)/0.35)] transition-colors"
                        :class="{ 'border-[rgb(var(--accent-cyan-rgb)/0.5)]': selectedItemId === Number(item.id) }"
                    >
                        <p class="index-record__title prose-wrap">{{ item.name }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
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
                                <span v-if="detail.item.category">{{ detail.item.category }}</span>
                                <span v-if="detail.item.tier"> · Tier {{ detail.item.tier }}</span>
                                <span v-if="detail.item.rarity"> · {{ detail.item.rarity }}</span>
                            </p>
                        </div>
                    </div>

                    <p v-if="detail.item.description" class="prose-wrap text-sm leading-relaxed text-muted-2">
                        {{ detail.item.description }}
                    </p>

                    <div class="mt-5 grid gap-4">
                        <RecipeGroup title="Crafting recipes" :recipes="detail.craftingRecipes" />
                        <RecipeGroup title="Extraction recipes" :recipes="detail.extractionRecipes" />
                        <RecipeGroup title="Used by recipes" :recipes="detail.recipesUsingItem" />
                    </div>
                </div>

                <div v-else class="empty-state-panel">
                    <p class="text-muted-3 text-sm font-ui">Select an item to inspect its recipes.</p>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, h, reactive, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import TextInput from '@/Components/TextInput.vue'

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    items: { type: Array, default: () => [] },
    detail: { type: Object, default: null },
    error: { type: String, default: null },
})

const form = reactive({
    q: props.filters.q ?? '',
})

watch(() => props.filters, (filters) => {
    form.q = filters.q ?? ''
})

const selectedItemId = computed(() => Number(props.filters.itemId))

const submit = () => {
    router.get(route('bitcraft.crafting'), form.q ? { q: form.q } : {}, {
        preserveState: true,
        replace: true,
    })
}

const RecipeGroup = (props) => {
    if (!props.recipes.length) {
        return h('div', { class: 'empty-state-panel' }, [
            h('p', { class: 'text-muted-3 text-sm font-ui' }, `No ${props.title.toLowerCase()} found.`),
        ])
    }

    return h('section', { class: 'index-surface index-surface--nested' }, [
        h('div', { class: 'px-4 py-3 border-b border-border' }, [
            h('h3', { class: 'surface-section__title' }, props.title),
        ]),
        ...props.recipes.map((recipe) => h('div', { class: 'index-record', key: recipe.id ?? recipe.name }, [
            h('div', { class: 'flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between' }, [
                h('div', { class: 'min-w-0' }, [
                    h('p', { class: 'index-record__title prose-wrap' }, recipe.name),
                    h('p', { class: 'index-record__subtitle prose-wrap' }, [
                        recipe.station,
                        recipe.skill,
                        recipe.duration ? `${recipe.duration}s` : null,
                    ].filter(Boolean).join(' · ')),
                ]),
                recipe.outputQuantity ? h('span', { class: 'tag' }, `Makes ${recipe.outputQuantity}`) : null,
            ]),
            recipe.ingredients.length ? h('div', { class: 'mt-3 grid gap-2 sm:grid-cols-2' }, recipe.ingredients.map((ingredient) => h('div', {
                class: 'rounded-md border border-border bg-surface px-3 py-2 text-sm text-muted-2',
                key: `${ingredient.type}-${ingredient.id}-${ingredient.name}`,
            }, [
                h('span', { class: 'text-primary' }, ingredient.name),
                h('span', { class: 'float-right font-ui text-muted-3' }, ingredient.quantity ? `x${ingredient.quantity}` : ''),
            ]))) : null,
        ])),
    ])
}

RecipeGroup.props = {
    title: { type: String, required: true },
    recipes: { type: Array, default: () => [] },
}
</script>
