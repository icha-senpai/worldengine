<template>
    <div class="index-record">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="index-record__title prose-wrap">{{ recipe.name }}</p>
                <p class="index-record__subtitle prose-wrap">
                    {{ recipeMeta }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2 sm:justify-end">
                <span v-if="recipe.outputQuantity" class="tag">Makes {{ formatQuantity(recipe.outputQuantity) }}</span>
                <span v-if="batches > 1" class="tag">{{ formatQuantity(batches) }} batches</span>
            </div>
        </div>

        <div v-if="recipe.ingredients?.length" class="mt-3 grid gap-2">
            <div
                v-for="ingredient in recipe.ingredients"
                :key="`${ingredient.kind}-${ingredient.id}-${ingredient.name}`"
                class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-muted-2"
            >
                <div class="flex items-start justify-between gap-3">
                    <span class="text-primary prose-wrap">{{ ingredient.name }}</span>
                    <span class="shrink-0 font-ui text-muted-3">x{{ formatQuantity(scaledQuantity(ingredient.quantity)) }}</span>
                </div>

                <div v-if="ingredient.recipes?.length" class="mt-3 grid gap-2 border-l border-border pl-3">
                    <CraftingRecipeNode
                        v-for="childRecipe in ingredient.recipes"
                        :key="childRecipe.id ?? childRecipe.name"
                        :recipe="childRecipe"
                        :desired-quantity="scaledQuantity(ingredient.quantity)"
                    />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'

defineOptions({ name: 'CraftingRecipeNode' })

const props = defineProps({
    recipe: { type: Object, required: true },
    desiredQuantity: { type: Number, default: 1 },
})

const formatQuantity = (quantity) => {
    const number = Number(quantity)

    if (!Number.isFinite(number)) {
        return quantity
    }

    return new Intl.NumberFormat().format(number)
}

const batches = computed(() => {
    const outputQuantity = Math.max(1, Number(props.recipe.outputQuantity ?? 1) || 1)

    return Math.max(1, Math.ceil(props.desiredQuantity / outputQuantity))
})

const recipeMeta = computed(() => [
    props.recipe.station,
    props.recipe.skill,
    props.recipe.duration ? `${props.recipe.duration}s` : null,
].filter(Boolean).join(' · '))

const scaledQuantity = (quantity) => {
    const number = Number(quantity)

    if (!Number.isFinite(number)) {
        return quantity
    }

    return number * batches.value
}
</script>
