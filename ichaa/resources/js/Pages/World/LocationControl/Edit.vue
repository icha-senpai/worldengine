<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Location Control Record"
        :back-href="route('location-control.index')"
        back-label="Location Control"
        :cancel-href="route('location-control.index')"
        submit-label="Save Control Record"
        processing-label="Saving..."
        :destroy-href="route('location-control.destroy', record.id)"
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
    record: { type: Object, required: true },
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
    location_entity_id: props.record.location_entity_id ?? '',
    controlling_entity_id: props.record.controlling_entity_id ?? '',
    control_type: props.record.control_type ?? '',
    control_start_era: props.record.control_start_era ?? '',
    resistance_level: props.record.resistance_level ?? '',
    resistance_entity_ids: (props.record.resistance_entities ?? []).map((entity) => entity.id),
    control_end_era: props.record.control_end_era ?? '',
    is_current: props.record.is_current ?? true,
    how_control_was_established: props.record.how_control_was_established ?? null,
    how_control_ended: props.record.how_control_ended ?? null,
    notes: props.record.notes ?? null,
    visibility: props.record.visibility ?? 'private',
    content_classification: props.record.content_classification ?? 'restricted',
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
            {
                key: 'resistance_level',
                label: 'Resistance Level',
                type: 'select',
                options: props.resistanceLevels,
                help: `${props.record.location?.name ?? 'Unknown'} -> ${props.record.controlling_entity?.name ?? 'Unknown'} (${props.record.control_type ?? 'control'})`,
            },
            {
                key: 'resistance_entity_ids',
                label: 'Resistance Entities',
                type: 'multiselect',
                options: resistanceOptions.value,
                emptyMessage: 'No resistance actors available yet.',
            },
            { key: 'control_end_era', label: 'Control End Era' },
            { key: 'is_current', label: 'Current', type: 'checkbox' },
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

const submit = () => form.put(route('location-control.update', props.record.id))
</script>
