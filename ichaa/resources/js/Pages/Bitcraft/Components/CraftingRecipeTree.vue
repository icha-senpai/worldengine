<template>
    <section v-if="recipes.length" class="index-surface index-surface--nested">
        <div class="px-4 py-3 border-b border-border">
            <h3 class="surface-section__title">Crafting tree</h3>
        </div>

        <CraftingRecipeNode
            v-for="recipe in recipes"
            :key="recipe.id ?? recipe.name"
            :recipe="recipe"
            :desired-quantity="desiredQuantity"
        />
    </section>

    <div v-else class="empty-state-panel">
        <p class="text-muted-3 text-sm font-ui">No crafting steps found.</p>
    </div>
</template>

<script setup>
import CraftingRecipeNode from './CraftingRecipeNode.vue'

defineProps({
    recipes: { type: Array, default: () => [] },
    desiredQuantity: { type: Number, default: 1 },
})
</script>
