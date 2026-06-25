<template>
    <div>
        <ScaffoldIndexPage
            title="Crossover Entry Points"
            :count="entryPoints.length"
            count-label="entry points"
            sync-resource="crossover_entry_points"
            :create-href="route('crossover-entry-points.create')"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('crossover-entry-points.index')"
            create-label="New Entry Point"
            :items="items"
            empty-title="No entry points found"
            :empty-cta-href="route('crossover-entry-points.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first entry point ->"
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
            <CreateCrossoverEntryPoint
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
import CreateCrossoverEntryPoint from '@/Pages/Lore/CrossoverEntryPoints/Create.vue'
import { buildMeta } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    entryPoints: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: null },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('crossover-entry-points.index', {
    q: props.filters.q ?? '',
    status: props.filters.status ?? '',
    visibility: props.filters.visibility ?? '',
})

const filterFields = computed(() => [
    { key: 'q', type: 'text', placeholder: 'Search universes...' },
    { key: 'status', type: 'select', placeholder: 'All statuses', options: props.statuses },
    { key: 'visibility', placeholder: 'All visibility' },
])

const items = computed(() =>
    props.entryPoints.map((entryPoint) => ({
        id: entryPoint.id,
        href: route('crossover-entry-points.show', entryPoint.id),
        title: entryPoint.source_universe,
        meta: buildMeta([
            { label: 'Status', value: entryPoint.status },
            { label: 'Visibility', value: entryPoint.visibility },
        ]),
    }))
)
</script>
