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
    resistanceEntities: { type: Array, default: () => [] },
    controlTypes: { type: Array, default: () => [] },
    resistanceLevels: { type: Array, default: () => [] },
    visibilityLevels: { type: Array, default: () => [] },
    contentClassifications: { type: Array, default: () => [] },
})

const locationOptions = computed(() => toEntityOptions(props.locationEntities))
const entityOptions = computed(() => toEntityOptions(props.entities))
const resistanceOptions = computed(() => toEntityOptions(props.resistanceEntities))

const form = useForm({
    location_entity_id: '',
    controlling_entity_id: '',
    control_type: '',
    control_start_era: '',
    control_end_era: '',
    resistance_level: '',
    resistance_entity_ids: [],
    how_control_was_established: null,
    how_control_ended: null,
    notes: null,
    visibility: 'private',
    content_classification: 'restricted',
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
            { key: 'control_end_era', label: 'Control End Era' },
            { key: 'resistance_level', label: 'Resistance Level', type: 'select', options: props.resistanceLevels },
            {
                key: 'resistance_entity_ids',
                label: 'Resistance Entities',
                type: 'multiselect',
                options: resistanceOptions.value,
                emptyMessage: 'No resistance actors available yet.',
            },
        ],
    },
    {
        title: 'Access',
        fields: [
            { key: 'visibility', label: 'Visibility', type: 'select', options: props.visibilityLevels },
            { key: 'content_classification', label: 'Content Classification', type: 'select', options: props.contentClassifications },
        ],
    },
    {
        title: 'Narrative',
        fields: [
            { key: 'how_control_was_established', label: 'How Control Was Established JSON', type: 'json', jsonMode: 'document', rows: 6 },
            { key: 'how_control_ended', label: 'How Control Ended JSON', type: 'json', jsonMode: 'document', rows: 6 },
            { key: 'notes', label: 'Notes JSON', type: 'json', jsonMode: 'document', rows: 6 },
        ],
    },
])

const submit = () => form.post(route('location-control.store'))
</script>
