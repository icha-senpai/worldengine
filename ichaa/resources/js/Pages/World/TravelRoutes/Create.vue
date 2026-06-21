<template>
    <ScaffoldFormPage
        title="New Travel Route"
        :back-href="route('travel-routes.index')"
        back-label="Travel Routes"
        :cancel-href="route('travel-routes.index')"
        submit-label="Create Route"
        processing-label="Creating..."
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
    locationEntities: { type: Array, default: () => [] },
    routeTypes: { type: Array, default: () => [] },
})

const locationOptions = computed(() => toEntityOptions(props.locationEntities))

const form = useForm({
    origin_location_entity_id: '',
    destination_location_entity_id: '',
    route_type: '',
    bidirectional: false,
    standard_duration: '',
    method_variants: null,
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
            { key: 'route_type', label: 'Route Type', type: 'select', required: true, options: props.routeTypes },
            { key: 'bidirectional', label: 'Bidirectional', type: 'checkbox' },
            { key: 'standard_duration', label: 'Standard Duration' },
            {
                key: 'method_variants',
                label: 'Method Variants JSON',
                type: 'json',
                jsonMode: 'object-list',
                jsonObjectFields: ['method_name', 'required_ability_or_artifact', 'duration', 'conditions', 'notes'],
                rows: 6,
            },
        ],
    },
])

const submit = () => form.post(route('travel-routes.store'))
</script>
