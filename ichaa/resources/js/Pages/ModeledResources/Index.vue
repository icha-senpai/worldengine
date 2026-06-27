<template>
    <ScaffoldIndexPage
        :title="resource.title"
        :count="countRecords(records)"
        :count-label="resource.countLabel"
        :items="items"
        :empty-title="resource.emptyTitle"
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
    resource: { type: Object, required: true },
    records: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    filterFields: { type: Array, default: () => [] },
})

const initialFilters = Object.fromEntries(
    props.filterFields.map((field) => [field.key, props.filters[field.key] ?? '']),
)

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters(
    props.resource.routeName,
    initialFilters,
)
</script>
