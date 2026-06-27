<template>
    <div>
        <ScaffoldIndexPage
            :title="title"
            :count="countRecords(records)"
            :count-label="countLabel"
            :create-href="createHref"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="indexHref"
            :create-label="createLabel"
            :items="items"
            :empty-title="emptyTitle"
            :empty-cta-href="createHref"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            :empty-cta-label="emptyCtaLabel"
            :pagination="records"
        >
            <template v-if="filterFields.length" #toolbar>
                <ScaffoldFilterBar
                    :fields="filterFields"
                    :form="filterForm"
                    :has-active-filters="hasActiveFilters"
                    :on-apply="applyFilters"
                    :on-clear="clearFilters"
                />
            </template>

            <template v-if="createDrawer" #create-drawer>
                <ResourceForm
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
import ResourceForm from '@/Pages/ScaffoldResources/Form.vue'
import { countRecords } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    title: { type: String, required: true },
    countLabel: { type: String, default: 'records' },
    indexHref: { type: [String, Object], required: true },
    records: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    filterFields: { type: Array, default: () => [] },
    createHref: { type: [String, Object], default: null },
    createLabel: { type: String, default: 'Create Record' },
    emptyTitle: { type: String, default: 'No records found yet' },
    emptyCtaLabel: { type: String, default: 'Create the first record ->' },
    createDrawer: { type: Object, default: null },
    filterRoute: { type: String, required: true },
})

const filterDefaults = computed(() =>
    Object.fromEntries(
        props.filterFields.map((field) => [field.key, props.filters[field.key] ?? '']),
    ),
)

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters(
    props.filterRoute,
    filterDefaults.value,
)
</script>
