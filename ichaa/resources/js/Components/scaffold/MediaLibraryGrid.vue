<template>
    <div v-if="items.length" class="media-library-grid">
        <MediaLibraryCard
            v-for="item in items"
            :key="item.id"
            :item="item"
            :selected="selectedId === item.id"
            @select="$emit('select', $event)"
        />
    </div>

    <div v-else class="media-library-empty">
        <p class="media-library-empty__title">No media matched those filters.</p>
        <p class="media-library-empty__copy">Try a different search term or switch the type and purpose filters.</p>
    </div>
</template>

<script setup>
import MediaLibraryCard from '@/Components/scaffold/MediaLibraryCard.vue'

defineProps({
    items: { type: Array, default: () => [] },
    selectedId: { type: Number, default: null },
})

defineEmits(['select'])
</script>
