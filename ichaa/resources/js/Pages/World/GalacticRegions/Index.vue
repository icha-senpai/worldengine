<template>
    <ScaffoldIndexPage
        title="Galactic Regions"
        :count="countRecords(regions)"
        count-label="regions"
        :items="items"
        empty-title="No galactic regions found"
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
    </ScaffoldIndexPage>
</template>

<script setup>
import ScaffoldFilterBar from '@/Components/scaffold/ScaffoldFilterBar.vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import { countRecords } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    regions: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    filterFields: { type: Array, default: () => [] },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('galactic-regions.index', {
    q: props.filters.q ?? '',
    type: props.filters.type ?? '',
    universe: props.filters.universe ?? '',
    mapped: props.filters.mapped ?? '',
})
</script>
