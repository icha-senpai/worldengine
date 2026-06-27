<template>
    <ScaffoldIndexPage
        title="Notion Sync Mappings"
        :count="countRecords(mappings)"
        count-label="mappings"
        :items="items"
        empty-title="No Notion sync mappings found"
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
    mappings: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    filterFields: { type: Array, default: () => [] },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('admin.notion-sync-mappings.index', {
    q: props.filters.q ?? '',
    sync_resource: props.filters.sync_resource ?? '',
})
</script>
