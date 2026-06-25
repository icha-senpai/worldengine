<template>
    <div>
        <ScaffoldIndexPage
            title="Travel Routes"
            :count="routes.length"
            count-label="routes"
            sync-resource="travel_routes"
            :create-href="route('travel-routes.create')"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('travel-routes.index')"
            create-label="New Route"
            :items="items"
            empty-title="No travel routes found"
            :empty-cta-href="route('travel-routes.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first route ->"
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
            <CreateTravelRoute
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
import CreateTravelRoute from '@/Pages/World/TravelRoutes/Create.vue'
import { badge, buildMeta } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    routes: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    routeTypes: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: null },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('travel-routes.index', {
    q: props.filters.q ?? '',
    route_type: props.filters.route_type ?? '',
    visibility: props.filters.visibility ?? '',
})

const filterFields = computed(() => [
    { key: 'q', type: 'text', placeholder: 'Search routes...' },
    { key: 'route_type', type: 'select', placeholder: 'All route types', options: props.routeTypes },
    { key: 'visibility', placeholder: 'All visibility' },
])

const items = computed(() =>
    props.routes.map((travelRoute) => ({
        id: travelRoute.id,
        href: route('travel-routes.show', travelRoute.id),
        title: `${travelRoute.origin?.name ?? 'Unknown'} -> ${travelRoute.destination?.name ?? 'Unknown'}`,
        badges: [badge('Type', travelRoute.route_type)],
        meta: buildMeta([
            { label: 'Duration', value: travelRoute.standard_duration },
            { label: 'Bidirectional', value: travelRoute.bidirectional },
            { label: 'Active', value: travelRoute.is_active },
        ]),
    }))
)
</script>
