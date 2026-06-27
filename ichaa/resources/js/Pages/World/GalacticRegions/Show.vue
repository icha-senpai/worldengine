<template>
    <ScaffoldShowPage
        :title="title"
        :badge="badge"
        back-label="Galactic Regions"
        :back-href="route('galactic-regions.index')"
        :sections="sections"
    />
</template>

<script setup>
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'

defineProps({
    title: { type: String, required: true },
    badge: { type: String, default: '' },
    sections: { type: Array, default: () => [] },
})
</script>
