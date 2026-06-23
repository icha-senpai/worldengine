<template>
    <div>
        <ScaffoldShowPage
            :title="`${routeRecord.origin?.name ?? 'Unknown'} -> ${routeRecord.destination?.name ?? 'Unknown'}`"
            back-label="Travel Routes"
            :back-href="route('travel-routes.index')"
            :edit-href="route('travel-routes.edit', routeRecord.id)"
            :edit-preserve-scroll="true"
            :edit-preserve-state="true"
            :destroy-href="route('travel-routes.destroy', routeRecord.id)"
            :badge="routeRecord.route_type || 'route'"
            :sections="sections"
        />

        <EditTravelRoute
            v-if="editDrawer"
            embedded
            :route-record="routeRecord"
            v-bind="editDrawer"
        />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import EditTravelRoute from '@/Pages/World/TravelRoutes/Edit.vue'
import { sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    routeRecord: { type: Object, required: true },
    editDrawer: { type: Object, default: null },
})

const sections = computed(() => [
    {
        title: 'Route',
        entries: [
            sectionEntry('Origin', props.routeRecord.origin?.name, props.routeRecord.origin ? { href: route('entities.show', props.routeRecord.origin.id) } : {}),
            sectionEntry('Destination', props.routeRecord.destination?.name, props.routeRecord.destination ? { href: route('entities.show', props.routeRecord.destination.id) } : {}),
            sectionEntry('Controlled By', props.routeRecord.controlled_by?.name, props.routeRecord.controlled_by ? { href: route('entities.show', props.routeRecord.controlled_by.id) } : {}),
            sectionEntry('Route Type', props.routeRecord.route_type),
            sectionEntry('Standard Duration', props.routeRecord.standard_duration),
            sectionEntry('Bidirectional', props.routeRecord.bidirectional),
            sectionEntry('Is Active', props.routeRecord.is_active),
        ],
    },
    {
        title: 'Details',
        entries: [
            sectionEntry('Method Variants', props.routeRecord.method_variants, { kind: 'json' }),
            sectionEntry('Hazards', props.routeRecord.hazards, { kind: 'json' }),
        ],
        fullWidth: true,
    },
])
</script>
