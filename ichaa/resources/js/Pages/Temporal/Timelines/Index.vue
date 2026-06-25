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
        <template #toolbar>
            <ScaffoldFilterBar
                :fields="filterFields"
                :form="filterForm"
                :has-active-filters="hasActiveFilters"
                :on-apply="applyFilters"
                :on-clear="clearFilters"
            />
        </template>
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
import ScaffoldFilterBar from '@/Components/scaffold/ScaffoldFilterBar.vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import CreateTimeline from '@/Pages/Temporal/Timelines/Create.vue'
import { asArray, buildMeta, countRecords, formatLabel } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    timelines: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: null },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('timelines.index', {
    q: props.filters.q ?? '',
    status: props.filters.status ?? '',
    visibility: props.filters.visibility ?? '',
})

const filterFields = computed(() => [
    { key: 'q', type: 'text', placeholder: 'Search timelines...' },
    { key: 'status', type: 'select', placeholder: 'All statuses', options: props.statuses },
    { key: 'visibility', placeholder: 'All visibility' },
])

const items = computed(() =>
    asArray(props.timelines).map((timeline) => ({
        id: timeline.id,
        href: route('timelines.show', timeline.id),
        title: timeline.name,
        meta: buildMeta([
            { label: 'Status', value: formatLabel(timeline.status) },
            { label: 'Visibility', value: formatLabel(timeline.visibility || 'private') },
        ]),
        stats: timeline.entry_count ? [{ label: 'Entries', value: timeline.entry_count }] : [],
    }))
)
</script>
