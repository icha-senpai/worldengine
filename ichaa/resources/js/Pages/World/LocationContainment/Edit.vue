<template>
    <ScaffoldFormPage
        title="Edit Location Containment"
        :back-href="route('location-containment.index')"
        back-label="Location Containment"
        :cancel-href="route('location-containment.index')"
        submit-label="Save Containment"
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

const props = defineProps({
    containment: { type: Object, required: true },
})

const form = useForm({
    era_end: props.containment.era_end ?? '',
    is_active: props.containment.is_active ?? true,
})

const sections = computed(() => [
    {
        title: 'Update',
        fields: [
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
