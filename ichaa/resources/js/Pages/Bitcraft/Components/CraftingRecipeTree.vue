<template>
    <section v-if="recipeTree.length" class="crafting-tree" aria-label="Crafting tree">
        <header class="crafting-tree__header">
            <h3>Crafting</h3>

            <div class="crafting-tree__actions">
                <button
                    type="button"
                    class="crafting-tree__load-all"
                    :disabled="loadingAllBranches || deferredBranchCount === 0"
                    @click="loadAllBranches"
                >
                    {{ loadingAllBranches ? 'Loading...' : loadAllLabel }}
                </button>

                <div class="crafting-tree__tabs" role="tablist" aria-label="Recipe views">
                    <button
                        type="button"
                        role="tab"
                        class="crafting-tree__tab"
                        :class="{ 'crafting-tree__tab--active': activeTab === 'tree' }"
                        :aria-selected="activeTab === 'tree' ? 'true' : 'false'"
                        @click="activeTab = 'tree'"
                    >
                        Tree
                    </button>
                    <button
                        type="button"
                        role="tab"
                        class="crafting-tree__tab"
                        :class="{ 'crafting-tree__tab--active': activeTab === 'totals' }"
                        :aria-selected="activeTab === 'totals' ? 'true' : 'false'"
                        @click="activeTab = 'totals'"
                    >
                        Totals
                    </button>
                </div>
            </div>
        </header>

        <p v-if="loadAllError" class="crafting-tree__load-error">{{ loadAllError }}</p>

        <div v-show="activeTab === 'tree'" class="crafting-tree__panel crafting-tree__panel--tree" role="tabpanel">
            <CraftingRecipeNode
                v-for="recipe in recipeTree"
                :key="recipe.id ?? recipe.name"
                :recipe="recipe"
                :desired-quantity="desiredQuantity"
                root
                @route-selected="handleRouteSelected"
            />
        </div>

        <div v-show="activeTab === 'totals'" class="crafting-tree__panel crafting-tree__panel--totals" role="tabpanel">
            <div v-if="totalGroups.length" class="crafting-totals">
                <section
                    v-for="group in totalGroups"
                    :key="group.key"
                    class="crafting-total-group"
                >
                    <header class="crafting-total-group__header">
                        <h4>{{ group.label }}</h4>
                        <span>{{ group.items.length }} item{{ group.items.length === 1 ? '' : 's' }}</span>
                    </header>

                    <div class="crafting-total-group__items">
                        <div
                            v-for="item in group.items"
                            :key="item.key"
                            class="crafting-total"
                        >
                            <span class="crafting-total__icon" :style="itemFrameStyle(item)" aria-hidden="true">
                                <img
                                    v-if="itemIconUrl(item)"
                                    :src="itemIconUrl(item)"
                                    alt=""
                                    loading="lazy"
                                    @error="hideBrokenIcon(item.iconAssetName)"
                                >
                                <span v-else>{{ itemInitials(item.name) }}</span>
                            </span>
                            <span class="crafting-total__copy">
                                <span class="crafting-total__name">{{ item.name }}</span>
                                <span v-if="itemMeta(item)" class="crafting-total__meta">{{ itemMeta(item) }}</span>
                            </span>
                            <strong class="crafting-total__quantity">{{ formatQuantity(item.quantity) }}x</strong>
                        </div>
                    </div>
                </section>
            </div>

            <div v-else class="empty-state-panel">
                <p class="text-muted-3 text-sm font-ui">No ingredients found.</p>
            </div>
        </div>
    </section>

    <div v-else class="empty-state-panel">
        <p class="text-muted-3 text-sm font-ui">No crafting steps found.</p>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import CraftingRecipeNode from './CraftingRecipeNode.vue'
import { bitcraftItemFrameStyle, bitjitaAssetUrl } from '@/Pages/Bitcraft/bitjitaAssets.js'

const props = defineProps({
    recipes: { type: Array, default: () => [] },
    desiredQuantity: { type: Number, default: 1 },
    storageKey: { type: String, default: null },
})

const activeTab = ref('tree')
const recipeTree = ref([])
const brokenIconAssets = ref(new Set())
const loadingAllBranches = ref(false)
const loadAllError = ref(null)
const selectedRoutes = ref({})

watch(() => props.recipes, (recipes) => {
    recipeTree.value = JSON.parse(JSON.stringify(recipes ?? []))
    applyPersistedSelections(recipeTree.value)
    loadAllError.value = null
}, { immediate: true })

watch(() => props.storageKey, () => {
    restorePersistedState()
    applyPersistedSelections(recipeTree.value)
}, { immediate: true })

watch(activeTab, () => {
    persistRecipeState()
})

const deferredIngredients = computed(() => collectDeferredIngredients(recipeTree.value))

const deferredBranchCount = computed(() => new Set(deferredIngredients.value.map((ingredient) => branchKey(ingredient))).size)

const loadAllLabel = computed(() => {
    if (deferredBranchCount.value === 0) {
        return 'All loaded'
    }

    return `Load all (${deferredBranchCount.value})`
})

const itemTotals = computed(() => {
    const totals = new Map()

    recipeTree.value.forEach((recipe) => {
        addRecipeTotals(totals, recipe, props.desiredQuantity)
    })

    return [...totals.values()]
        .sort((left, right) => tierNumber(left) - tierNumber(right) || left.name.localeCompare(right.name))
})

const totalGroups = computed(() => {
    const groups = []

    for (let tier = 1; tier <= 10; tier += 1) {
        const items = itemTotals.value.filter((item) => tierNumber(item) === tier)

        if (items.length) {
            groups.push({
                key: `tier-${tier}`,
                label: `Tier ${tier}`,
                items,
            })
        }
    }

    const otherTierItems = itemTotals.value.filter((item) => tierNumber(item) > 10)

    if (otherTierItems.length) {
        groups.push({
            key: 'tier-other',
            label: 'Other tier',
            items: otherTierItems,
        })
    }

    const noTierItems = itemTotals.value.filter((item) => {
        const tier = tierNumber(item)

        return tier < 1
    })

    if (noTierItems.length) {
        groups.push({
            key: 'tier-none',
            label: 'No tier',
            items: noTierItems,
        })
    }

    return groups
})

const addRecipeTotals = (totals, recipe, desiredQuantity) => {
    const activeRecipe = selectedRecipe(recipe)
    const outputQuantity = positiveNumber(activeRecipe?.outputQuantity, 1)
    const batches = Math.max(1, Math.ceil(positiveNumber(desiredQuantity, 1) / outputQuantity))

    for (const ingredient of activeRecipe?.ingredients ?? []) {
        const requiredQuantity = positiveNumber(ingredient.quantity, 1) * batches
        const childRecipes = Array.isArray(ingredient.recipes) ? ingredient.recipes : []

        if (childRecipes.length) {
            childRecipes.forEach((childRecipe) => addRecipeTotals(totals, childRecipe, requiredQuantity))
            continue
        }

        addIngredientTotal(totals, ingredient, requiredQuantity)
    }
}

const selectedRecipe = (recipe) => {
    const alternatives = Array.isArray(recipe?.alternatives) && recipe.alternatives.length
        ? recipe.alternatives
        : [recipe]
    const selectedIndex = Number(recipe?.selectedAlternativeIndex ?? 0)

    return alternatives[selectedIndex] ?? alternatives[0] ?? recipe
}

const addIngredientTotal = (totals, ingredient, quantity) => {
    const key = `${ingredient.kind ?? 'item'}:${ingredient.id ?? ingredient.name}`
    const existing = totals.get(key)

    if (existing) {
        existing.quantity += quantity
        existing.deferred = existing.deferred || Boolean(ingredient.recipesDeferred)
        return
    }

    totals.set(key, {
        key,
        id: ingredient.id,
        kind: ingredient.kind,
        name: ingredient.name ?? 'Unknown item',
        quantity,
        category: ingredient.category,
        tier: ingredient.tier,
        rarity: ingredient.rarity,
        iconAssetName: ingredient.iconAssetName,
        deferred: Boolean(ingredient.recipesDeferred),
    })
}

const handleRouteSelected = ({ recipe, index }) => {
    recipe.selectedAlternativeIndex = Number(index) || 0
    selectedRoutes.value = {
        ...selectedRoutes.value,
        [recipeSelectionKey(recipe)]: recipe.selectedAlternativeIndex,
    }
    persistRecipeState()
}

const restorePersistedState = () => {
    const state = readPersistedState()
    selectedRoutes.value = state.routes
    activeTab.value = state.activeTab
}

const applyPersistedSelections = (recipes) => {
    for (const recipe of recipes ?? []) {
        const index = Number(selectedRoutes.value[recipeSelectionKey(recipe)])
        const alternatives = Array.isArray(recipe.alternatives) && recipe.alternatives.length
            ? recipe.alternatives
            : [recipe]

        if (Number.isInteger(index) && alternatives[index]) {
            recipe.selectedAlternativeIndex = index
        }

        for (const alternative of alternatives) {
            for (const ingredient of alternative.ingredients ?? []) {
                applyPersistedSelections(ingredient.recipes ?? [])
            }
        }
    }
}

const readPersistedState = () => {
    if (!props.storageKey || typeof window === 'undefined') {
        return defaultPersistedState()
    }

    try {
        const state = JSON.parse(window.localStorage.getItem(props.storageKey) ?? '{}')
        const activeTab = ['tree', 'totals'].includes(state.activeTab) ? state.activeTab : 'tree'
        const routes = state.routes && typeof state.routes === 'object' && !Array.isArray(state.routes)
            ? state.routes
            : {}

        return { activeTab, routes }
    } catch (error) {
        return defaultPersistedState()
    }
}

const persistRecipeState = () => {
    if (!props.storageKey || typeof window === 'undefined') {
        return
    }

    window.localStorage.setItem(props.storageKey, JSON.stringify({
        activeTab: activeTab.value,
        routes: selectedRoutes.value,
    }))
}

const defaultPersistedState = () => ({
    activeTab: 'tree',
    routes: {},
})

const recipeSelectionKey = (recipe) => [
    recipe?.id ?? '',
    recipe?.name ?? '',
    recipe?.station ?? '',
    recipe?.outputQuantity ?? '',
].join('|')

const collectDeferredIngredients = (recipes) => {
    const ingredients = []

    for (const recipe of recipes ?? []) {
        collectDeferredIngredientsFromRecipe(recipe, ingredients)
    }

    return ingredients
}

const collectDeferredIngredientsFromRecipe = (recipe, ingredients) => {
    const activeRecipe = selectedRecipe(recipe)

    for (const ingredient of activeRecipe?.ingredients ?? []) {
        if (ingredient.recipesDeferred && ingredient.id && ingredient.kind) {
            ingredients.push(ingredient)
        }

        for (const childRecipe of ingredient.recipes ?? []) {
            collectDeferredIngredientsFromRecipe(childRecipe, ingredients)
        }
    }
}

const loadAllBranches = async () => {
    if (loadingAllBranches.value || deferredBranchCount.value === 0) {
        return
    }

    loadingAllBranches.value = true
    loadAllError.value = null
    const requestedKeys = new Set()

    try {
        while (true) {
            const batch = uniqueDeferredIngredients()
                .filter((ingredient) => !requestedKeys.has(branchKey(ingredient)))
                .slice(0, 8)

            if (!batch.length) {
                break
            }

            batch.forEach((ingredient) => requestedKeys.add(branchKey(ingredient)))
            await Promise.all(batch.map((ingredient) => loadBranchForIngredient(ingredient)))
        }

        if (deferredBranchCount.value > 0) {
            loadAllError.value = 'Loaded available branches. Some repeated branches are still deferred.'
        }
    } catch (error) {
        loadAllError.value = 'Could not load every branch.'
    } finally {
        loadingAllBranches.value = false
    }
}

const uniqueDeferredIngredients = () => {
    const seen = new Set()

    return deferredIngredients.value.filter((ingredient) => {
        const key = branchKey(ingredient)

        if (seen.has(key)) {
            return false
        }

        seen.add(key)

        return true
    })
}

const loadBranchForIngredient = async (ingredient) => {
    const response = await fetch(route('bitcraft.crafting.branch', {
        itemId: ingredient.id,
        itemKind: ingredient.kind,
    }), {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    })

    if (!response.ok) {
        throw new Error('Branch request failed.')
    }

    const payload = await response.json()
    applyBranchPayload(branchKey(ingredient), payload)
}

const applyBranchPayload = (key, payload) => {
    const recipes = Array.isArray(payload.recipes) ? payload.recipes : []

    deferredIngredients.value
        .filter((ingredient) => branchKey(ingredient) === key)
        .forEach((ingredient) => {
            const branchRecipes = JSON.parse(JSON.stringify(recipes))
            applyPersistedSelections(branchRecipes)
            ingredient.recipes = branchRecipes
            ingredient.recipesDeferred = false
        })
}

const branchKey = (ingredient) => `${ingredient.kind}:${ingredient.id}`

const positiveNumber = (value, fallback) => {
    const number = Number(value)

    return Number.isFinite(number) && number > 0 ? number : fallback
}

const formatQuantity = (quantity) => new Intl.NumberFormat(undefined, {
    maximumFractionDigits: 2,
}).format(quantity)

const itemMeta = (item) => [
    item.kind === 'cargo' ? 'Cargo' : 'Item',
    item.rarity,
    item.deferred ? 'Deferred' : null,
].filter(Boolean).join(' · ')

const tierNumber = (item) => {
    const tier = Number(item?.tier)

    return Number.isFinite(tier) ? tier : 0
}

const itemInitials = (name) => String(name ?? '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('') || '?'

const hideBrokenIcon = (assetName) => {
    if (!assetName) {
        return
    }

    brokenIconAssets.value = new Set([...brokenIconAssets.value, assetName])
}

const itemIconUrl = (item) => {
    if (!item?.iconAssetName || brokenIconAssets.value.has(item.iconAssetName)) {
        return null
    }

    return bitjitaAssetUrl(item.iconAssetName)
}

const itemFrameStyle = (item) => bitcraftItemFrameStyle(item?.tier, item?.rarity)
</script>

<style scoped>
.crafting-tree {
    border: 1px solid rgb(var(--border-color-rgb) / 0.8);
    border-radius: 8px;
    background:
        linear-gradient(180deg, rgb(var(--bg-surface-2-rgb) / 0.34), rgb(var(--bg-surface-rgb) / 0.94)),
        var(--bg-surface);
    min-width: 0;
    overflow: hidden;
    width: 100%;
}

.crafting-tree__header {
    border-bottom: 1px solid rgb(var(--border-color-rgb) / 0.72);
    padding: 12px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.crafting-tree__header h3 {
    margin: 0;
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 14px;
    font-weight: 700;
}

.crafting-tree__actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 8px;
}

.crafting-tree__load-all {
    border: 1px solid rgb(var(--accent-cyan-rgb) / 0.45);
    border-radius: 7px;
    background: rgb(var(--accent-cyan-rgb) / 0.12);
    color: var(--accent-cyan);
    cursor: pointer;
    font-family: var(--font-ui);
    font-size: 12px;
    font-weight: 800;
    line-height: 1;
    padding: 8px 10px;
}

.crafting-tree__load-all:disabled {
    cursor: default;
    opacity: 0.55;
}

.crafting-tree__load-error {
    margin: 0;
    border-bottom: 1px solid rgb(var(--border-color-rgb) / 0.58);
    color: var(--accent-pink);
    font-family: var(--font-ui);
    font-size: 12px;
    font-weight: 650;
    padding: 10px 14px;
}

.crafting-tree__tabs {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px solid rgb(var(--border-color-rgb) / 0.7);
    border-radius: 8px;
    background: rgb(var(--bg-surface-rgb) / 0.58);
    padding: 3px;
}

.crafting-tree__tab {
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: var(--text-muted);
    cursor: pointer;
    font-family: var(--font-ui);
    font-size: 12px;
    font-weight: 700;
    padding: 5px 10px;
}

.crafting-tree__tab--active {
    background: rgb(var(--accent-cyan-rgb) / 0.16);
    color: var(--text-primary);
}

.crafting-tree__panel {
    display: grid;
    gap: 10px;
    min-width: 0;
}

.crafting-tree__panel--tree {
    overflow-x: auto;
    overscroll-behavior-x: contain;
    scrollbar-gutter: stable;
}

.crafting-tree__panel--totals {
    padding: 12px;
}

.crafting-totals {
    display: grid;
    gap: 14px;
}

.crafting-total-group {
    display: grid;
    gap: 8px;
}

.crafting-total-group__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.crafting-total-group__header h4 {
    margin: 0;
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
}

.crafting-total-group__header span {
    color: var(--text-muted-3);
    font-family: var(--font-ui);
    font-size: 11px;
}

.crafting-total-group__items {
    display: grid;
    gap: 8px;
}

.crafting-total {
    display: grid;
    grid-template-columns: 36px minmax(0, 1fr) auto;
    align-items: center;
    gap: 10px;
    border: 1px solid rgb(var(--border-color-rgb) / 0.62);
    border-radius: 8px;
    background: rgb(var(--bg-surface-rgb) / 0.72);
    padding: 8px 10px;
}

.crafting-total__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border: 1px solid rgb(var(--border-color-rgb) / 0.74);
    border-radius: 7px;
    background: rgb(var(--bg-surface-2-rgb) / 0.64);
    color: var(--text-muted);
    font-family: var(--font-ui);
    font-size: 11px;
    font-weight: 800;
    overflow: hidden;
}

.crafting-total__icon img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.crafting-total__copy {
    min-width: 0;
    display: grid;
    gap: 2px;
}

.crafting-total__name {
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 13px;
    font-weight: 700;
}

.crafting-total__meta {
    color: var(--text-muted-3);
    font-family: var(--font-ui);
    font-size: 11px;
}

.crafting-total__quantity {
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 13px;
    white-space: nowrap;
}

@media (max-width: 640px) {
    .crafting-tree__header {
        align-items: flex-start;
        flex-direction: column;
    }

    .crafting-tree__actions {
        justify-content: flex-start;
        width: 100%;
    }

    .crafting-total {
        grid-template-columns: 32px minmax(0, 1fr);
    }

    .crafting-total__icon {
        width: 32px;
        height: 32px;
    }

    .crafting-total__quantity {
        grid-column: 2;
    }
}
</style>
