<template>
    <section class="surface-section">
        <div class="surface-section__header">
            <div class="surface-section__copy">
                <span class="surface-section__title">Crafting</span>
                <p class="surface-section__subtitle">{{ craftableCount }} ready · {{ recipes.length }} known.</p>
            </div>
        </div>

        <div class="surface-section__body">
            <div class="grid gap-4 xl:grid-cols-[17rem_minmax(0,1fr)]">
                <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-ui text-primary">Recipe Board</p>
                        <span class="tag">{{ activeBoard.count }} {{ activeBoard.unit }}</span>
                    </div>
                    <p class="mt-1 text-xs text-muted-3">{{ activeBoard.description }}</p>

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button
                            v-for="board in craftBoards"
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
                        <template
                            v-for="filter in filters"
                            :key="filter.key"
                        >
                            <button
                                type="button"
                                class="grid gap-2 rounded-md border border-border bg-canvas px-3 py-2 text-left transition hover:border-focus/60"
                                :class="{ 'border-focus/70 bg-focus/10': selectedFilter === filter.key }"
                                @click="selectCategory(filter.key)"
                            >
                                <span class="flex min-w-0 items-center justify-between gap-2">
                                    <span class="truncate text-xs font-ui text-primary">{{ filter.label }}</span>
                                    <span class="text-[11px] text-muted-3">{{ filter.count }}</span>
                                </span>
                                <span class="h-1.5 overflow-hidden rounded-full bg-surface-1">
                                    <span class="block h-full rounded-full bg-focus" :style="{ width: `${filterProgress(filter)}%` }" />
                                </span>
                            </button>

                            <div v-if="showSkillFiltersFor(filter)" class="ml-3 grid gap-1.5 border-l border-border pl-3">
                                <button
                                    v-for="skillFilter in nestedSkillFilters"
                                    :key="skillFilter.key"
                                    type="button"
                                    class="grid min-w-0 gap-1.5 rounded-md border border-transparent px-3 py-1.5 text-left transition hover:border-focus/50 hover:bg-focus/5"
                                    :class="{ 'border-focus/60 bg-focus/10': selectedSkillFilter === skillFilter.key }"
                                    @click="selectedSkillFilter = skillFilter.key"
                                >
                                    <span class="flex min-w-0 items-center justify-between gap-2">
                                        <span class="min-w-0 truncate text-xs text-primary">{{ skillFilter.label }}</span>
                                        <span class="text-[11px] text-muted-3">{{ skillFilter.metaLabel }}</span>
                                    </span>
                                    <span class="h-1 overflow-hidden rounded-full bg-surface-1">
                                        <span class="block h-full rounded-full bg-focus" :style="{ width: `${skillFilter.progress}%` }" />
                                    </span>
                                </button>
                            </div>
                        </template>
                    </div>

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
                    <button
                        type="button"
                        class="app-btn app-btn--ghost app-btn--sm mt-4 w-full"
                        :class="{ 'border-focus/70 bg-focus/10 text-primary': autoRepeatEnabled }"
                        :disabled="!repeatRecipeKey"
                        @click="toggleAutoRepeatRecipe"
                    >
                        {{ autoRepeatEnabled ? 'Repeating' : 'Repeat Last' }}
                    </button>
                </div>

                <div class="grid content-start gap-3">
                    <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-ui text-primary">{{ activeBoard.label }}</p>
                                <p class="mt-1 text-xs text-muted-3">{{ activeScopeLabel }} · {{ visibleRecipes.length }} visible</p>
                            </div>
                            <span class="tag">{{ visibleExperience }} XP</span>
                        </div>
                    </div>

                    <article
                        v-for="(recipe, index) in visibleRecipes"
                        :key="recipe.key"
                        class="grid min-h-32 items-start gap-3 rounded-md border border-border bg-surface-2 px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_7rem]"
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
                                <span v-if="recipe.material_preservation?.can_apply" class="tag">
                                    Preserves 1 material
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
                                :disabled="isRecipeDisabled(recipe)"
                                @click="requestRecipe(recipe.key)"
                            >
                                {{ recipeActionLabel(recipe) }}
                            </button>
                        </div>
                    </article>

                    <button
                        v-if="canShowMoreRecipes"
                        type="button"
                        class="app-btn app-btn--ghost app-btn--sm justify-self-center"
                        @click="visibleLimit += boardPageSize"
                    >
                        Show More
                    </button>

                    <p v-if="!visibleRecipes.length" class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2">
                        {{ emptyBoardMessage }}
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
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { craftingReloadProps } from './reloadProps'
import { usePersistedPanelState } from './usePanelState'

const props = defineProps({
    recipes: {
        type: Array,
        required: true,
    },
    player: {
        type: Object,
        required: true,
    },
    lastResult: {
        type: Object,
        default: null,
    },
    searchTerm: {
        type: String,
        default: '',
    },
})

const form = useForm({
    recipe: null,
})
const { selectedFilter, selectedSkillFilter, selectedBoard } = usePersistedPanelState('evergather.crafting-board-state', {
    selectedFilter: 'All',
    selectedSkillFilter: 'All',
    selectedBoard: 'ready',
}, {
    selectedBoard: ['ready', 'prepare'],
})
const boardPageSize = 12
const visibleLimit = ref(boardPageSize)
const runningRecipe = ref('')
const repeatRecipeKey = ref('')
const autoRepeatEnabled = ref(false)
const queuedRecipes = ref([])
const localRecipes = ref([...props.recipes])

const craftableCount = computed(() => localRecipes.value.filter((recipe) => recipe.can_craft).length)
const filters = computed(() => ['All', ...new Set(localRecipes.value.map((recipe) => recipe.category))].map((filter) => ({
    key: filter,
    label: filter,
    count: localRecipes.value.filter((recipe) => filter === 'All' || recipe.category === filter).length,
})))
const activeFilter = computed(() => filters.value.find((filter) => filter.key === selectedFilter.value) ?? filters.value[0])
const categoryRecipes = computed(() => localRecipes.value
    .filter((recipe) => selectedFilter.value === 'All' || recipe.category === selectedFilter.value))
const skillFilters = computed(() => [
    {
        key: 'All',
        label: 'All Skills',
        count: categoryRecipes.value.length,
        metaLabel: categoryRecipes.value.length,
        progress: categoryRecipes.value.length ? Math.round((categoryRecipes.value.filter((recipe) => recipe.can_craft).length / categoryRecipes.value.length) * 100) : 0,
    },
    ...[...new Set(categoryRecipes.value.map((recipe) => recipe.skill_label))].map((skillLabel) => {
        const recipes = categoryRecipes.value.filter((recipe) => recipe.skill_label === skillLabel)
        const progress = recipes[0]?.skill_progress
        const level = progress?.level ?? recipes[0]?.skill_level ?? 1

        return {
            key: skillLabel,
            label: skillLabel,
            count: recipes.length,
            metaLabel: `Lv ${level}`,
            progress: skillProgressPercent(progress),
        }
    }),
])
const nestedSkillFilters = computed(() => skillFilters.value.filter((filter) => filter.key !== 'All'))
const activeSkillFilter = computed(() => skillFilters.value.find((filter) => filter.key === selectedSkillFilter.value) ?? skillFilters.value[0])
const showSkillFilters = computed(() => selectedFilter.value !== 'All' && nestedSkillFilters.value.length > 0)
const activeScopeLabel = computed(() => {
    if (!showSkillFilters.value || selectedSkillFilter.value === 'All') {
        return activeFilter.value.label
    }

    return `${activeFilter.value.label} - ${activeSkillFilter.value.label}`
})
const filteredRecipes = computed(() => localRecipes.value
    .filter((recipe) => selectedFilter.value === 'All' || recipe.category === selectedFilter.value)
    .filter((recipe) => selectedSkillFilter.value === 'All' || recipe.skill_label === selectedSkillFilter.value)
    .filter((recipe) => searchMatches(recipe, props.searchTerm)))
const readyRecipes = computed(() => filteredRecipes.value.filter((recipe) => recipe.can_craft))
const prepareRecipes = computed(() => filteredRecipes.value.filter((recipe) => recipe.is_unlocked && !recipe.can_craft))
const craftBoards = computed(() => [
    {
        key: 'ready',
        label: 'Ready',
        count: readyRecipes.value.length,
        unit: 'recipes',
        entries: readyRecipes.value,
        description: `${activeScopeLabel.value} recipes you can craft now.`,
    },
    {
        key: 'prepare',
        label: 'Prepare',
        count: prepareRecipes.value.length,
        unit: 'short',
        entries: prepareRecipes.value,
        description: 'Unlocked recipes that need materials or gold.',
    },
])
const activeBoard = computed(() => craftBoards.value.find((board) => board.key === selectedBoard.value) ?? craftBoards.value[0])
const visibleRecipes = computed(() => activeBoard.value.entries.slice(0, visibleLimit.value))
const canShowMoreRecipes = computed(() => activeBoard.value.entries.length > visibleRecipes.value.length)
const visibleExperience = computed(() => visibleRecipes.value.reduce((total, recipe) => total + recipe.experience, 0))
const visibleGoldCost = computed(() => visibleRecipes.value.reduce((total, recipe) => total + recipe.gold_cost, 0))
const emptyBoardMessage = computed(() => {
    if (selectedBoard.value === 'ready') {
        return 'No craftable recipes match. Check Prepare for recipes missing supplies.'
    }

    return 'No recipes match.'
})

watch(selectedFilter, () => {
    selectedSkillFilter.value = 'All'
})

watch(filters, () => {
    if (!filters.value.some((filter) => filter.key === selectedFilter.value)) {
        selectedFilter.value = 'All'
    }
}, { immediate: true })

watch(skillFilters, () => {
    if (!skillFilters.value.some((filter) => filter.key === selectedSkillFilter.value)) {
        selectedSkillFilter.value = 'All'
    }
})

watch([selectedBoard, selectedFilter, selectedSkillFilter, () => props.searchTerm], () => {
    visibleLimit.value = boardPageSize
})

watch([readyRecipes, prepareRecipes], () => {
    if (!readyRecipes.value.length && prepareRecipes.value.length && selectedBoard.value === 'ready') {
        selectedBoard.value = 'prepare'
    }

    if (!prepareRecipes.value.length && readyRecipes.value.length && selectedBoard.value === 'prepare') {
        selectedBoard.value = 'ready'
    }
}, { immediate: true })

watch(() => props.recipes, (recipes) => {
    localRecipes.value = [...recipes]
}, { deep: true })

watch(() => props.lastResult, (result) => {
    applyCraftingResult(result)
}, { immediate: true })

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

function filterProgress(filter) {
    if (!filter.count) {
        return 0
    }

    const readyCount = localRecipes.value.filter((recipe) => (filter.key === 'All' || recipe.category === filter.key) && recipe.can_craft).length

    return Math.round((readyCount / filter.count) * 100)
}

function showSkillFiltersFor(filter) {
    return showSkillFilters.value && selectedFilter.value === filter.key
}

function selectCategory(category) {
    selectedFilter.value = category
    selectedSkillFilter.value = 'All'
}

function requestRecipe(recipe) {
    repeatRecipeKey.value = recipe

    if (form.processing) {
        queuedRecipes.value.push(recipe)

        return
    }

    craft(recipe)
}

function craft(recipe) {
    repeatRecipeKey.value = recipe
    form.recipe = recipe
    form.post(route('evergather.crafting.store'), {
        async: true,
        preserveScroll: true,
        only: craftingReloadProps,
        onStart: () => {
            runningRecipe.value = ''
        },
        onSuccess: () => {
            queueNextRecipe()
        },
        onFinish: () => {
            runningRecipe.value = ''
        },
    })
}

function maybeRepeatRecipe() {
    if (!autoRepeatEnabled.value || !repeatRecipeKey.value || form.processing) {
        return
    }

    const recipe = localRecipes.value.find((entry) => entry.key === repeatRecipeKey.value)

    if (!recipe?.can_craft) {
        autoRepeatEnabled.value = false

        return
    }

    craft(repeatRecipeKey.value)
}

function isRecipeDisabled(recipe) {
    return !recipe.can_craft
}

function recipeActionLabel(recipe) {
    if (requiresToolRepair(recipe)) {
        return 'Repair Tool'
    }

    return runningRecipe.value === recipe.key ? 'Crafting...' : 'Craft'
}

function requiresToolRepair(recipe) {
    return Boolean(recipe.requires_tool_repair || recipe.equipped_tool?.is_broken)
}

function toggleAutoRepeatRecipe() {
    autoRepeatEnabled.value = !autoRepeatEnabled.value

    if (autoRepeatEnabled.value) {
        queueNextRecipe()
    }
}

function queueNextRecipe(delay = 0) {
    window.setTimeout(() => {
        const queuedRecipe = queuedRecipes.value.shift()

        if (queuedRecipe) {
            const recipe = localRecipes.value.find((entry) => entry.key === queuedRecipe)

            if (recipe?.can_craft) {
                craft(queuedRecipe)
            }

            return
        }

        maybeRepeatRecipe()
    }, delay)
}

function applyCraftingResult(result) {
    if (result?.type !== 'crafting') {
        return
    }

    const deltas = itemDeltas(result.items_created ?? [], result.items_consumed ?? [])
    const progress = result.skill_progress

    localRecipes.value = localRecipes.value.map((recipe) => {
        const nextRecipe = patchRecipeInventory(recipe, deltas)

        if (progress && nextRecipe.skill === progress.skill) {
            nextRecipe.skill_label = progress.skill_label ?? nextRecipe.skill_label
            nextRecipe.skill_level = progress.level ?? nextRecipe.skill_level
            nextRecipe.skill_progress = progress
            nextRecipe.is_unlocked = nextRecipe.skill_level >= nextRecipe.required_level
        }

        nextRecipe.can_craft = nextRecipe.is_unlocked
            && props.player.gold >= nextRecipe.gold_cost
            && nextRecipe.ingredients.every((ingredient) => ingredient.has_enough)
            && !requiresToolRepair(nextRecipe)

        return nextRecipe
    })
}

function patchRecipeInventory(recipe, deltas) {
    return {
        ...recipe,
        ingredients: recipe.ingredients.map((ingredient) => patchOwnedItem(ingredient, deltas)),
    }
}

function itemDeltas(addedItems, removedItems) {
    const deltas = {}

    addedItems.forEach((item) => {
        deltas[item.item_key] = (deltas[item.item_key] ?? 0) + Number(item.quantity ?? 0)
    })

    removedItems.forEach((item) => {
        deltas[item.item_key] = (deltas[item.item_key] ?? 0) - Number(item.quantity ?? 0)
    })

    return deltas
}

function patchOwnedItem(item, deltas) {
    const ownedQuantity = Math.max(0, Number(item.owned_quantity ?? 0) + Number(deltas[item.item_key] ?? 0))

    return {
        ...item,
        owned_quantity: ownedQuantity,
        has_enough: ownedQuantity >= Number(item.quantity ?? 0),
    }
}

function skillProgressPercent(progress) {
    if (!progress) {
        return 0
    }

    if (progress.next_level_experience === null) {
        return 100
    }

    const levelSpan = Number(progress.next_level_experience) - Number(progress.current_level_experience ?? 0)

    if (levelSpan <= 0) {
        return 0
    }

    return Math.max(0, Math.min(100, Math.round((Number(progress.experience_into_level ?? 0) / levelSpan) * 100)))
}
</script>
