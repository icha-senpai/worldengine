<template>
    <ScaffoldShowPage
        :title="`${routeRecord.origin?.name ?? 'Unknown'} -> ${routeRecord.destination?.name ?? 'Unknown'}`"
        back-label="Travel Routes"
        :back-href="route('travel-routes.index')"
        :edit-href="route('travel-routes.edit', routeRecord.id)"
        :badge="routeRecord.route_type || 'route'"
        :sections="sections"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import { sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    route: { type: Object, required: true },
})

const routeRecord = computed(() => props.route)

const sections = computed(() => [
    {
        title: 'Route',
        entries: [
            sectionEntry('Origin', routeRecord.value.origin?.name, routeRecord.value.origin ? { href: route('entities.show', routeRecord.value.origin.id) } : {}),
            sectionEntry('Destination', routeRecord.value.destination?.name, routeRecord.value.destination ? { href: route('entities.show', routeRecord.value.destination.id) } : {}),
            sectionEntry('Controlled By', routeRecord.value.controlled_by?.name, routeRecord.value.controlled_by ? { href: route('entities.show', routeRecord.value.controlled_by.id) } : {}),
            sectionEntry('Route Type', routeRecord.value.route_type),
            sectionEntry('Standard Duration', routeRecord.value.standard_duration),
            sectionEntry('Bidirectional', routeRecord.value.bidirectional),
            sectionEntry('Is Active', routeRecord.value.is_active),
        ],
    },
    {
        title: 'Details',
        entries: [
            sectionEntry('Method Variants', routeRecord.value.method_variants, { kind: 'json' }),
            sectionEntry('Hazards', routeRecord.value.hazards, { kind: 'json' }),
        ],
        fullWidth: true,
    },
])
</script>
