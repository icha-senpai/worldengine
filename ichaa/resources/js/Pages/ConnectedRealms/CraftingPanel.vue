<template>
    <section class="surface-section">
        <div class="surface-section__header">
            <div class="surface-section__copy">
                <span class="surface-section__title">Crafting</span>
                <p class="surface-section__subtitle">{{ craftableCount }} ready · {{ recipes.length }} known.</p>
            </div>
        </div>

        <div class="surface-section__body">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="filter in filters"
                    :key="filter.key"
                    type="button"
                    class="rounded-md border border-border bg-surface-2 px-3 py-2 text-xs font-ui text-muted-2 transition hover:border-focus/60 hover:text-primary"
                    :class="{ 'border-focus/70 bg-focus/10 text-primary': selectedFilter === filter.key }"
                    @click="selectedFilter = filter.key"
                >
                    {{ filter.label }} · {{ filter.count }}
                </button>
            </div>

            <div class="mt-4 grid gap-4 xl:grid-cols-[16rem_minmax(0,1fr)]">
                <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                    <p class="text-sm font-ui text-primary">{{ activeFilter.label }}</p>
                    <div class="mt-3 grid gap-2 text-xs">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Ready</span>
                            <span class="text-primary">{{ visibleRecipes.filter((recipe) => recipe.can_craft).length }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">XP</span>
                            <span class="text-primary">{{ visibleExperience }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Gold Cost</span>
                            <span class="text-primary">{{ visibleGoldCost }}g</span>
                        </div>
                    </div>
                </div>

                <div class="grid gap-3">
                    <article
                        v-for="(recipe, index) in visibleRecipes"
                        :key="recipe.key"
                        class="grid gap-3 rounded-md border border-border bg-surface-2 px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_auto]"
                        :class="{ 'opacity-70': !recipe.is_unlocked }"
                    >
                        <div class="grid h-9 w-9 place-items-center rounded-md border border-border bg-canvas text-sm font-ui text-primary">
                            #{{ index + 1 }}
                        </div>

                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="min-w-0 truncate text-sm font-ui text-primary">{{ recipe.label }}</p>
                                <span class="tag">{{ recipe.category }}</span>
                                <span class="tag">{{ recipe.skill_label }}</span>
                                <span class="tag">Lv {{ recipe.required_level }}</span>
                            </div>
                            <div class="mt-3 grid gap-2">
                                <div
                                    v-for="ingredient in recipe.ingredients"
                                    :key="ingredient.item_key"
                                    class="flex items-center justify-between gap-3 text-xs"
                                >
                                    <span class="min-w-0 truncate text-muted-2">{{ ingredient.item_name }}</span>
                                    <span :class="ingredient.has_enough ? 'text-success' : 'text-muted-3'">
                                        {{ ingredient.owned_quantity }} / {{ ingredient.quantity }}
                                    </span>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span
                                    v-for="output in recipe.outputs"
                                    :key="output.item_key"
                                    class="tag capitalize"
                                >
                                    {{ output.quantity }} {{ output.item_name }} · {{ output.rarity }} · {{ output.total_weight }} wt
                                </span>
                            </div>
                        </div>

                        <div class="grid content-between gap-3 text-left md:text-right">
                            <div>
                                <p class="text-sm font-ui text-primary">+{{ recipe.experience }} XP</p>
                                <p v-if="recipe.gold_cost > 0" class="mt-1 text-xs text-muted-2">{{ recipe.gold_cost }} gold</p>
                                <p v-if="!recipe.is_unlocked" class="mt-1 text-xs text-muted-3">Level {{ recipe.skill_level }} / {{ recipe.required_level }}</p>
                            </div>
                            <button
                                type="button"
                                class="app-btn app-btn--sm"
                                :disabled="form.processing || !recipe.can_craft"
                                @click="craft(recipe.key)"
                            >
                                Craft
                            </button>
                        </div>
                    </article>

                    <p v-if="!visibleRecipes.length" class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2">
                        No recipes match.
                    </p>
                </div>
            </div>

            <p v-if="form.errors.recipe" class="mt-4 text-sm text-(--accent-pink)">
                {{ form.errors.recipe }}
            </p>
        </div>
    </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { craftingReloadProps } from './reloadProps'

const props = defineProps({
    recipes: {
        type: Array,
        required: true,
    },
    searchTerm: {
        type: String,
        default: '',
    },
})

const form = useForm({
    recipe: null,
})
const selectedFilter = ref('Ready')

const craftableCount = computed(() => props.recipes.filter((recipe) => recipe.can_craft).length)
const filters = computed(() => ['Ready', 'All', ...new Set(props.recipes.map((recipe) => recipe.category))].map((filter) => ({
    key: filter,
    label: filter,
    count: props.recipes.filter((recipe) => filter === 'All' || (filter === 'Ready' ? recipe.can_craft : recipe.category === filter)).length,
})))
const activeFilter = computed(() => filters.value.find((filter) => filter.key === selectedFilter.value) ?? filters.value[0])
const visibleRecipes = computed(() => props.recipes.filter((recipe) => {
    if (selectedFilter.value === 'Ready') {
        return recipe.can_craft
    }

    if (selectedFilter.value === 'All') {
        return true
    }

    return recipe.category === selectedFilter.value
}).filter((recipe) => searchMatches(recipe, props.searchTerm)))
const visibleExperience = computed(() => visibleRecipes.value.reduce((total, recipe) => total + recipe.experience, 0))
const visibleGoldCost = computed(() => visibleRecipes.value.reduce((total, recipe) => total + recipe.gold_cost, 0))

function searchMatches(recipe, query) {
    const normalizedQuery = query.trim().toLowerCase()

    if (!normalizedQuery) {
        return true
    }

    return [
        recipe.label,
        recipe.category,
        recipe.skill_label,
        ...(recipe.ingredients ?? []).flatMap(itemSearchFields),
        ...(recipe.outputs ?? []).flatMap(itemSearchFields),
    ].filter(Boolean).join(' ').toLowerCase().includes(normalizedQuery)
}

function itemSearchFields(item) {
    return [
        item.item_name,
        item.rarity,
        item.quality,
        item.item_class,
        item.material_family,
        ...(item.tags ?? []),
    ]
}

function craft(recipe) {
    form.recipe = recipe
    form.post(route('evergather.crafting.store'), {
        preserveScroll: true,
        only: craftingReloadProps,
    })
}
</script>
