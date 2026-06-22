<template>
    <ScaffoldIndexPage
        title="Location Control"
        :count="records.length"
        count-label="control records"
        sync-resource="location_control"
        :create-href="route('location-control.create')"
        create-label="New Control Record"
        :items="items"
        empty-title="No control records found"
        :empty-cta-href="route('location-control.create')"
        empty-cta-label="Create the first control record ->"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import { badge, buildMeta } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    records: { type: Array, default: () => [] },
})

const items = computed(() =>
    props.records.map((record) => ({
        id: record.id,
        href: route('location-control.edit', record.id),
        title: `${record.location?.name ?? 'Unknown'} -> ${record.controlling_entity?.name ?? 'Unknown'}`,
        badges: [badge('Type', record.control_type)],
        meta: buildMeta([
            { label: 'Resistance', value: record.resistance_level },
            { label: 'Start', value: record.control_start_era },
            { label: 'End', value: record.control_end_era },
        ]),
    }))
)
</script>
