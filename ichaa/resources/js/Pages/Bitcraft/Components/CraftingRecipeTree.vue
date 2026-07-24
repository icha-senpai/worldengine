<template>
    <section v-if="recipes.length" class="crafting-tree" aria-label="Crafting tree">
        <header class="crafting-tree__header">
            <h3>Crafting tree</h3>
        </header>

        <CraftingRecipeNode
            v-for="recipe in recipes"
            :key="recipe.id ?? recipe.name"
            :recipe="recipe"
            :desired-quantity="desiredQuantity"
            root
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

<style scoped>
.crafting-tree {
    border: 1px solid rgb(var(--border-color-rgb) / 0.8);
    border-radius: 8px;
    background:
        linear-gradient(180deg, rgb(var(--bg-surface-2-rgb) / 0.34), rgb(var(--bg-surface-rgb) / 0.94)),
        var(--bg-surface);
    overflow-x: auto;
}

.crafting-tree__header {
    border-bottom: 1px solid rgb(var(--border-color-rgb) / 0.72);
    padding: 12px 14px;
}

.crafting-tree__header h3 {
    margin: 0;
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 14px;
    font-weight: 700;
}
</style>
