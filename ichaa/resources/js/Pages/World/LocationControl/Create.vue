<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="New Location Control Record"
        :back-href="route('location-control.index')"
        back-label="Location Control"
        :cancel-href="route('location-control.index')"
        submit-label="Create Control Record"
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
    embedded: { type: Boolean, default: false },
    locationEntities: { type: Array, default: () => [] },
    entities: { type: Array, default: () => [] },
    controlTypes: { type: Array, default: () => [] },
    resistanceLevels: { type: Array, default: () => [] },
})

const locationOptions = computed(() => toEntityOptions(props.locationEntities))
const entityOptions = computed(() => toEntityOptions(props.entities))

const form = useForm({
    location_entity_id: '',
    controlling_entity_id: '',
    control_type: '',
    control_start_era: '',
})

const sections = computed(() => [
    {
        title: 'Control',
        fields: [
            {
                key: 'location_entity_id',
                label: 'Location',
                type: 'select',
                required: true,
                options: locationOptions.value,
                placeholder: 'Select the controlled location...',
            },
            {
                key: 'controlling_entity_id',
                label: 'Controlling Entity',
                type: 'select',
                required: true,
                options: entityOptions.value,
                placeholder: 'Select whoever currently controls it...',
            },
            { key: 'control_type', label: 'Control Type', type: 'select', required: true, options: props.controlTypes },
            { key: 'control_start_era', label: 'Control Start Era' },
        ],
    },
])

const submit = () => form.post(route('location-control.store'))
</script>
