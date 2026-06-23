<template>
    <div>
        <ScaffoldIndexPage
            title="Timelines"
            :count="countRecords(timelines)"
            count-label="timelines"
            sync-resource="timelines"
            :create-href="route('timelines.create')"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('timelines.index')"
            create-label="New Timeline"
            :items="items"
            empty-title="No timelines found"
            :empty-cta-href="route('timelines.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first timeline ->"
        >
        <template #create-drawer>
            <CreateTimeline
                v-if="createDrawer"
                embedded
                v-bind="createDrawer"
            />
        </template>
        </ScaffoldIndexPage>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import CreateTimeline from '@/Pages/Temporal/Timelines/Create.vue'
import { asArray, buildMeta, countRecords, formatLabel } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    timelines: { type: Array, required: true },
    createDrawer: { type: Object, default: null },
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
