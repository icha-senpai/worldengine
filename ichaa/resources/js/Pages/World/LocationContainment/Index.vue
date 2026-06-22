<template>
    <ScaffoldIndexPage
        title="Location Containment"
        :count="containments.length"
        count-label="containments"
        sync-resource="location_containment"
        :create-href="route('location-containment.create')"
        create-label="New Containment"
        :items="items"
        empty-title="No containment records found"
        :empty-cta-href="route('location-containment.create')"
        empty-cta-label="Create the first containment ->"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import { badge, buildMeta } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    containments: { type: Array, default: () => [] },
})

const items = computed(() =>
    props.containments.map((containment) => ({
        id: containment.id,
        href: route('location-containment.edit', containment.id),
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
