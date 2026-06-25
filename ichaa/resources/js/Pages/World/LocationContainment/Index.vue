<template>
    <div>
        <ScaffoldIndexPage
            title="Location Containment"
            :count="containments.length"
            count-label="containments"
            sync-resource="location_containment"
            :create-href="route('location-containment.create')"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('location-containment.index')"
            create-label="New Containment"
            :items="items"
            empty-title="No containment records found"
            :empty-cta-href="route('location-containment.create')"
            empty-cta-label="Create the first containment ->"
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
                <CreateLocationContainment
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
import CreateLocationContainment from '@/Pages/World/LocationContainment/Create.vue'
import { badge, buildMeta } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    containments: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    containmentTypes: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: null },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('location-containment.index', {
    q: props.filters.q ?? '',
    containment_type: props.filters.containment_type ?? '',
})

const filterFields = computed(() => [
    { key: 'q', type: 'text', placeholder: 'Search containment...' },
    { key: 'containment_type', type: 'select', placeholder: 'All containment types', options: props.containmentTypes },
])

const items = computed(() =>
    props.containments.map((containment) => ({
        id: containment.id,
        href: route('location-containment.show', containment.id),
        title: `${containment.child_location?.name ?? 'Unknown'} -> ${containment.parent_location?.name ?? 'Unknown'}`,
        badges: [badge('Type', containment.containment_type)],
        meta: buildMeta([
            { label: 'Era Start', value: containment.era_start },
            { label: 'Era End', value: containment.era_end },
            { label: 'Active', value: containment.is_active },
        ]),
    }))
)
</script>
