<template>
    <ScaffoldIndexPage
        title="Crossover Entry Points"
        :count="entryPoints.length"
        count-label="entry points"
        :create-href="route('crossover-entry-points.create')"
        create-label="New Entry Point"
        :items="items"
        empty-title="No entry points found"
        :empty-cta-href="route('crossover-entry-points.create')"
        empty-cta-label="Create the first entry point ->"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import { buildMeta } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    entryPoints: { type: Array, default: () => [] },
})

const items = computed(() =>
    props.entryPoints.map((entryPoint) => ({
        id: entryPoint.id,
        href: route('crossover-entry-points.show', entryPoint.id),
        title: entryPoint.source_universe,
        meta: buildMeta([
            { label: 'Status', value: entryPoint.status },
        ]),
    }))
)
</script>
