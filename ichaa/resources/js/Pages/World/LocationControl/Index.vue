<template>
    <div>
        <ScaffoldIndexPage
            title="Location Control"
            :count="records.length"
            count-label="control records"
            sync-resource="location_control"
            :create-href="route('location-control.create')"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('location-control.index')"
            create-label="New Control Record"
            :items="items"
            empty-title="No control records found"
            :empty-cta-href="route('location-control.create')"
            empty-cta-label="Create the first control record ->"
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
                <CreateLocationControl
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
import CreateLocationControl from '@/Pages/World/LocationControl/Create.vue'
import { badge, buildMeta } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    records: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    controlTypes: { type: Array, default: () => [] },
    resistanceLevels: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: null },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('location-control.index', {
    q: props.filters.q ?? '',
    control_type: props.filters.control_type ?? '',
    resistance_level: props.filters.resistance_level ?? '',
})

const filterFields = computed(() => [
    { key: 'q', type: 'text', placeholder: 'Search control records...' },
    { key: 'control_type', type: 'select', placeholder: 'All control types', options: props.controlTypes },
    { key: 'resistance_level', type: 'select', placeholder: 'All resistance levels', options: props.resistanceLevels },
])

const items = computed(() =>
    props.records.map((record) => ({
        id: record.id,
        href: route('location-control.show', record.id),
        title: `${record.location?.name ?? 'Unknown'} -> ${record.controlling_entity?.name ?? 'Unknown'}`,
        badges: [badge('Type', record.control_type)],
        meta: buildMeta([
            { label: 'Resistance', value: record.resistance_level },
            { label: 'Start', value: record.control_start_era },
            { label: 'End', value: record.control_end_era },
        ]),
    }))
)
</script>
