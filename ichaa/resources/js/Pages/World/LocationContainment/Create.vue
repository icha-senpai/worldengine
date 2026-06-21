<template>
    <ScaffoldFormPage
        title="New Location Containment"
        :back-href="route('location-containment.index')"
        back-label="Location Containment"
        :cancel-href="route('location-containment.index')"
        submit-label="Create Containment"
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
    containmentTypes: { type: Array, default: () => [] },
})

const locationOptions = computed(() => toEntityOptions(props.locationEntities))

const form = useForm({
    child_location_entity_id: '',
    parent_location_entity_id: '',
    containment_type: '',
    era_start: '',
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
        ],
    },
])

const submit = () => form.post(route('location-containment.store'))
</script>
