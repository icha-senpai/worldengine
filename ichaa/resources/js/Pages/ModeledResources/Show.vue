<template>
    <ScaffoldShowPage
        :title="title"
        :badge="badge"
        :back-label="resource.backLabel"
        :back-href="resource.backHref"
        :sections="sections"
    />
</template>

<script setup>
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'

defineProps({
    resource: { type: Object, required: true },
    title: { type: String, required: true },
    badge: { type: String, default: '' },
    sections: { type: Array, default: () => [] },
})
</script>
