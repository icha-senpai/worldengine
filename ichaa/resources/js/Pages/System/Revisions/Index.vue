<template>
    <div>
        <ScaffoldIndexPage
            title="Revisions"
            :count="countRecords(revisions)"
            count-label="revisions"
            :items="items"
            empty-title="No revisions recorded yet"
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
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldFilterBar from '@/Components/scaffold/ScaffoldFilterBar.vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import { countRecords } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    revisions: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    resourceTypes: { type: Array, default: () => [] },
    actions: { type: Array, default: () => [] },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('revisions.index', {
    q: props.filters.q ?? '',
    resource_type: props.filters.resource_type ?? '',
    action: props.filters.action ?? '',
})

const filterFields = computed(() => [
    { key: 'q', type: 'text', placeholder: 'Search revisions...' },
    { key: 'resource_type', type: 'select', placeholder: 'All resources', options: props.resourceTypes },
    { key: 'action', type: 'select', placeholder: 'All actions', options: props.actions },
])
</script>
