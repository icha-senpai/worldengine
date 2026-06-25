<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Travel Route"
        :back-href="route('travel-routes.show', routeRecord.id)"
        back-label="Travel Route"
        :cancel-href="route('travel-routes.show', routeRecord.id)"
        submit-label="Save Route"
        processing-label="Saving..."
        :form="form"
        :sections="sections"
        :on-submit="submit"
    />
</template>

<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import ScaffoldFormPage from '@/Components/scaffold/ScaffoldFormPage.vue'
import { toEntityOptions } from '@/Components/scaffold/formatters'

const props = defineProps({
    embedded: { type: Boolean, default: false },
    routeRecord: { type: Object, required: true },
    locationEntities: { type: Array, default: () => [] },
    routeTypes: { type: Array, default: () => [] },
})

const locationOptions = computed(() => toEntityOptions(props.locationEntities))

const form = useForm({
    origin_location_entity_id: props.routeRecord.origin_location_entity_id ?? '',
    destination_location_entity_id: props.routeRecord.destination_location_entity_id ?? '',
    route_type: props.routeRecord.route_type ?? '',
    bidirectional: props.routeRecord.bidirectional ?? false,
    standard_duration: props.routeRecord.standard_duration ?? '',
    method_variants: props.routeRecord.method_variants ?? [],
    hazards: props.routeRecord.hazards ?? [],
    is_active: props.routeRecord.is_active ?? false,
})

const sections = computed(() => [
    {
        title: 'Route',
        fields: [
            {
                key: 'origin_location_entity_id',
                label: 'Origin Location',
                type: 'select',
                required: true,
                options: locationOptions.value,
                placeholder: 'Select the origin location...',
            },
            {
                key: 'destination_location_entity_id',
                label: 'Destination Location',
                type: 'select',
                required: true,
                options: locationOptions.value,
                placeholder: 'Select the destination location...',
            },
            { key: 'route_type', label: 'Route Type', type: 'select', options: props.routeTypes },
            { key: 'bidirectional', label: 'Bidirectional', type: 'checkbox' },
            { key: 'standard_duration', label: 'Standard Duration' },
            { key: 'is_active', label: 'Is Active', type: 'checkbox' },
            {
                key: 'method_variants',
                label: 'Method Variants',
                type: 'json',
                jsonMode: 'object-list',
                jsonObjectFields: ['method_name', 'required_ability_or_artifact', 'duration', 'conditions', 'notes'],
                rows: 6,
                emptyValue: [],
            },
            {
                key: 'hazards',
                label: 'Hazards',
                type: 'json',
                jsonMode: 'object-list',
                jsonObjectFields: ['hazard_type', 'description', 'era_active', 'severity'],
                rows: 6,
                emptyValue: [],
            },
        ],
    },
])

const submit = () => form.put(route('travel-routes.update', props.routeRecord.id))
</script>
