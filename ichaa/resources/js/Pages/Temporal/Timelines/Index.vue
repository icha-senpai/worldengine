<template>
    <ScaffoldIndexPage
        title="Timelines"
        :count="countRecords(timelines)"
        count-label="timelines"
        sync-resource="timelines"
        :create-href="route('timelines.create')"
        create-label="New Timeline"
        :items="items"
        empty-title="No timelines found"
        :empty-cta-href="route('timelines.create')"
        empty-cta-label="Create the first timeline ->"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import { asArray, buildMeta, countRecords, formatLabel } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    timelines: { type: Array, required: true },
})

const items = computed(() =>
    asArray(props.timelines).map((timeline) => ({
        id: timeline.id,
        href: route('timelines.show', timeline.id),
        title: timeline.name,
        meta: buildMeta([
            { label: 'Status', value: formatLabel(timeline.status) },
        ]),
        stats: timeline.entry_count ? [{ label: 'Entries', value: timeline.entry_count }] : [],
    }))
)
</script>
