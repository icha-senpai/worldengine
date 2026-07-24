<template>
    <div class="crafting-node" :class="{ 'crafting-node--root': root }">
        <div class="crafting-node__recipe">
            <div class="crafting-node__recipe-head">
                <h4>{{ activeRecipe.name }}</h4>

                <select
                    v-if="hasAlternatives"
                    v-model.number="selectedAlternativeIndex"
                    class="crafting-node__route-select"
                    aria-label="Recipe route"
                >
                    <option
                        v-for="(alternative, index) in alternatives"
                        :key="alternative.id ?? alternative.name"
                        :value="index"
                    >
                        {{ routeLabel(alternative) }}
                    </option>
                </select>
            </div>

            <div v-if="recipeMeta || activeRecipe.outputQuantity || batches > 1" class="crafting-node__meta">
                <span v-if="recipeMeta">{{ recipeMeta }}</span>
                <span v-if="activeRecipe.outputQuantity">Makes {{ formatQuantity(activeRecipe.outputQuantity) }}</span>
                <span v-if="batches > 1">{{ formatQuantity(batches) }} batches</span>
            </div>
        </div>

        <div v-if="activeRecipe.ingredients?.length" class="crafting-node__ingredients">
            <template
                v-for="ingredient in activeRecipe.ingredients"
                :key="`${ingredient.kind}-${ingredient.id}-${ingredient.name}`"
            >
                <details v-if="ingredient.recipes?.length" class="crafting-ingredient" open>
                    <summary class="crafting-ingredient__row">
                        <span class="crafting-ingredient__icon" aria-hidden="true">{{ itemInitials(ingredient.name) }}</span>
                        <span class="crafting-ingredient__copy">
                            <span class="crafting-ingredient__name">
                                <strong>{{ formatQuantity(scaledQuantity(ingredient.quantity)) }}x</strong>
                                {{ ingredient.name }}
                            </span>
                            <span v-if="ingredientMeta(ingredient)" class="crafting-ingredient__meta">{{ ingredientMeta(ingredient) }}</span>
                        </span>
                        <span v-if="ingredient.tier" class="crafting-ingredient__tier">T{{ ingredient.tier }}</span>
                        <span class="crafting-ingredient__chevron" aria-hidden="true"></span>
                    </summary>

                    <div class="crafting-ingredient__children">
                        <CraftingRecipeNode
                            v-for="childRecipe in ingredient.recipes"
                            :key="childRecipe.id ?? childRecipe.name"
                            :recipe="childRecipe"
                            :desired-quantity="scaledQuantity(ingredient.quantity)"
                        />
                    </div>
                </details>

                <div v-else class="crafting-ingredient">
                    <div class="crafting-ingredient__row">
                        <span class="crafting-ingredient__icon" aria-hidden="true">{{ itemInitials(ingredient.name) }}</span>
                        <span class="crafting-ingredient__copy">
                            <span class="crafting-ingredient__name">
                                <strong>{{ formatQuantity(scaledQuantity(ingredient.quantity)) }}x</strong>
                                {{ ingredient.name }}
                            </span>
                            <span v-if="ingredientMeta(ingredient)" class="crafting-ingredient__meta">{{ ingredientMeta(ingredient) }}</span>
                        </span>
                        <span v-if="ingredient.tier" class="crafting-ingredient__tier">T{{ ingredient.tier }}</span>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

defineOptions({ name: 'CraftingRecipeNode' })

const props = defineProps({
    recipe: { type: Object, required: true },
    desiredQuantity: { type: Number, default: 1 },
    root: { type: Boolean, default: false },
})

const selectedAlternativeIndex = ref(0)

const alternatives = computed(() => {
    if (Array.isArray(props.recipe.alternatives) && props.recipe.alternatives.length > 1) {
        return props.recipe.alternatives
    }

    return [props.recipe]
})

const hasAlternatives = computed(() => alternatives.value.length > 1)

const activeRecipe = computed(() => alternatives.value[selectedAlternativeIndex.value] ?? alternatives.value[0] ?? props.recipe)

watch(() => props.recipe, () => {
    selectedAlternativeIndex.value = 0
})

const formatQuantity = (quantity) => {
    const number = Number(quantity)

    if (!Number.isFinite(number)) {
        return quantity
    }

    return new Intl.NumberFormat().format(number)
}

const batches = computed(() => {
    const outputQuantity = Math.max(1, Number(activeRecipe.value.outputQuantity ?? 1) || 1)

    return Math.max(1, Math.ceil(props.desiredQuantity / outputQuantity))
})

const recipeMeta = computed(() => [
    activeRecipe.value.station,
    activeRecipe.value.skill,
    activeRecipe.value.duration ? `${activeRecipe.value.duration}s` : null,
].filter(Boolean).join(' · '))

const routeLabel = (recipe) => [
    recipe.name,
    recipe.station,
    recipe.outputQuantity ? `Makes ${formatQuantity(recipe.outputQuantity)}` : null,
].filter(Boolean).join(' · ')

const ingredientMeta = (ingredient) => [
    ingredient.kind === 'cargo' ? 'Cargo' : null,
].filter(Boolean).join(' · ')

const itemInitials = (name) => String(name ?? '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('') || '?'

const scaledQuantity = (quantity) => {
    const number = Number(quantity)

    if (!Number.isFinite(number)) {
        return quantity
    }

    return number * batches.value
}
</script>

<style scoped>
.crafting-node {
    display: grid;
    gap: 10px;
}

.crafting-node--root {
    padding: 12px 14px 14px;
}

.crafting-node__recipe {
    display: grid;
    gap: 6px;
}

.crafting-node__recipe-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.crafting-node__recipe h4 {
    margin: 0;
    color: var(--text-muted);
    font-family: var(--font-ui);
    font-size: 14px;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.crafting-node__route-select {
    min-width: min(260px, 100%);
    max-width: 100%;
    border: 1px solid rgb(var(--border-color-rgb) / 0.82);
    border-radius: 6px;
    background: rgb(var(--bg-surface-rgb) / 0.94);
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 12px;
    font-weight: 650;
    line-height: 1.2;
    padding: 7px 30px 7px 9px;
}

.crafting-node__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.crafting-node__meta span {
    border: 1px solid rgb(var(--border-color-rgb) / 0.72);
    border-radius: 5px;
    background: rgb(var(--bg-surface-3-rgb) / 0.34);
    color: var(--text-muted-2);
    font-family: var(--font-ui);
    font-size: 11px;
    font-weight: 650;
    line-height: 1;
    padding: 5px 7px;
}

.crafting-node__ingredients {
    display: grid;
    gap: 10px;
}

.crafting-ingredient {
    position: relative;
    display: block;
}

.crafting-ingredient::before {
    content: "";
    position: absolute;
    top: -10px;
    left: -13px;
    width: 13px;
    height: 33px;
    border-bottom: 1px solid rgb(var(--border-color-2-rgb) / 0.8);
    border-left: 1px solid rgb(var(--border-color-2-rgb) / 0.8);
}

.crafting-node--root > .crafting-node__ingredients > .crafting-ingredient::before {
    display: none;
}

.crafting-ingredient__row {
    display: grid;
    grid-template-columns: 34px minmax(0, 1fr) auto auto;
    align-items: center;
    gap: 10px;
    min-height: 58px;
    border: 1px solid rgb(var(--border-color-2-rgb) / 0.62);
    border-radius: 8px;
    background:
        linear-gradient(180deg, rgb(var(--bg-surface-3-rgb) / 0.44), rgb(var(--bg-surface-2-rgb) / 0.8)),
        var(--bg-surface-2);
    color: var(--text-primary);
    cursor: default;
    list-style: none;
    padding: 8px 10px;
}

details > .crafting-ingredient__row {
    cursor: pointer;
}

.crafting-ingredient__row::-webkit-details-marker {
    display: none;
}

.crafting-ingredient__icon {
    display: grid;
    width: 34px;
    height: 34px;
    place-items: center;
    border: 1px solid rgb(var(--border-color-rgb) / 0.7);
    border-radius: 6px;
    background:
        radial-gradient(circle at 35% 25%, rgb(var(--accent-cyan-rgb) / 0.2), transparent 42%),
        rgb(var(--bg-surface-rgb) / 0.92);
    color: var(--text-muted-2);
    font-family: var(--font-ui);
    font-size: 11px;
    font-weight: 800;
}

.crafting-ingredient__copy {
    display: grid;
    min-width: 0;
    gap: 3px;
}

.crafting-ingredient__name {
    min-width: 0;
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 14px;
    line-height: 1.25;
    overflow-wrap: anywhere;
}

.crafting-ingredient__name strong {
    display: inline-block;
    margin-right: 7px;
    border-radius: 4px;
    background: rgb(var(--bg-surface-rgb) / 0.78);
    color: var(--text-muted);
    font-size: 12px;
    font-weight: 800;
    line-height: 1;
    padding: 4px 6px;
    vertical-align: 1px;
}

.crafting-ingredient__meta {
    color: var(--text-muted-3);
    font-family: var(--font-ui);
    font-size: 11px;
    font-weight: 650;
}

.crafting-ingredient__tier {
    border-radius: 4px;
    background: rgb(var(--bg-surface-rgb) / 0.75);
    color: var(--text-muted);
    font-family: var(--font-ui);
    font-size: 12px;
    font-weight: 800;
    line-height: 1;
    padding: 5px 7px;
}

.crafting-ingredient__chevron {
    width: 8px;
    height: 8px;
    border-right: 2px solid var(--accent-cyan);
    border-bottom: 2px solid var(--accent-cyan);
    transform: rotate(45deg);
    transition: transform 150ms ease;
}

details:not([open]) > .crafting-ingredient__row .crafting-ingredient__chevron {
    transform: rotate(-45deg);
}

.crafting-ingredient__children {
    position: relative;
    display: grid;
    gap: 10px;
    margin-left: 14px;
    padding-top: 10px;
    padding-left: 14px;
}

.crafting-ingredient__children::before {
    content: "";
    position: absolute;
    top: 0;
    bottom: 29px;
    left: 0;
    border-left: 1px solid rgb(var(--border-color-2-rgb) / 0.78);
}

@media (max-width: 640px) {
    .crafting-node--root {
        padding: 10px;
    }

    .crafting-node__recipe-head {
        align-items: stretch;
        flex-direction: column;
    }

    .crafting-node__route-select {
        width: 100%;
    }

    .crafting-ingredient__row {
        grid-template-columns: 30px minmax(0, 1fr) auto;
        min-height: 54px;
        gap: 8px;
    }

    .crafting-ingredient__icon {
        width: 30px;
        height: 30px;
    }

    .crafting-ingredient__children {
        margin-left: 10px;
        padding-left: 12px;
    }

    .crafting-ingredient__tier {
        display: none;
    }
}
</style>
