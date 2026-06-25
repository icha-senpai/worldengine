<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Location Containment"
        :back-href="route('location-containment.index')"
        back-label="Location Containment"
        :cancel-href="route('location-containment.index')"
        submit-label="Save Containment"
        processing-label="Saving..."
        :destroy-href="route('location-containment.destroy', containment.id)"
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
    containment: { type: Object, required: true },
    locationEntities: { type: Array, default: () => [] },
    containmentTypes: { type: Array, default: () => [] },
})

const locationOptions = computed(() => toEntityOptions(props.locationEntities))

const form = useForm({
    child_location_entity_id: props.containment.child_location_entity_id ?? '',
    parent_location_entity_id: props.containment.parent_location_entity_id ?? '',
    containment_type: props.containment.containment_type ?? '',
    era_start: props.containment.era_start ?? '',
    era_end: props.containment.era_end ?? '',
    is_active: props.containment.is_active ?? true,
})

const sections = computed(() => [
    {
        title: 'Containment',
        fields: [
            {
                key: 'child_location_entity_id',
                label: 'Child Location',
                type: 'select',
                required: true,
                options: locationOptions.value,
                placeholder: 'Select the contained location...',
            },
            {
                key: 'parent_location_entity_id',
                label: 'Parent Location',
                type: 'select',
                required: true,
                options: locationOptions.value,
                placeholder: 'Select the parent location...',
            },
            { key: 'containment_type', label: 'Containment Type', type: 'select', required: true, options: props.containmentTypes },
            { key: 'era_start', label: 'Era Start' },
            {
                key: 'era_end',
                label: 'Era End',
                help: `${props.containment.child_location?.name ?? 'Unknown'} -> ${props.containment.parent_location?.name ?? 'Unknown'} (${props.containment.containment_type ?? 'containment'})`,
            },
            { key: 'is_active', label: 'Is Active', type: 'checkbox' },
        ],
    },
])

const submit = () => form.put(route('location-containment.update', props.containment.id))
</script>
