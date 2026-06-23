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
            create-label="New Route"
            :items="items"
            empty-title="No travel routes found"
            :empty-cta-href="route('travel-routes.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first route ->"
            
        />

        <CreateTravelRoute
            v-if="createDrawer"
            embedded
            v-bind="createDrawer"
        />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import CreateTravelRoute from '@/Pages/World/TravelRoutes/Create.vue'
import { badge, buildMeta } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    routes: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: null },
})

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
