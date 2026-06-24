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

const props = defineProps({
    embedded: { type: Boolean, default: false },
    record: { type: Object, required: true },
    resistanceLevels: { type: Array, default: () => [] },
})

const form = useForm({
    resistance_level: props.record.resistance_level ?? '',
    control_end_era: props.record.control_end_era ?? '',
    how_control_ended: props.record.how_control_ended ?? null,
})

const sections = computed(() => [
    {
        title: 'Update',
        fields: [
            {
                key: 'resistance_level',
                label: 'Resistance Level',
                type: 'select',
                options: props.resistanceLevels,
                help: `${props.record.location?.name ?? 'Unknown'} -> ${props.record.controlling_entity?.name ?? 'Unknown'} (${props.record.control_type ?? 'control'})`,
            },
            { key: 'control_end_era', label: 'Control End Era' },
            { key: 'how_control_ended', label: 'How Control Ended JSON', type: 'json', jsonMode: 'document', rows: 6 },
        ],
    },
])

const submit = () => form.put(route('location-control.update', props.record.id))
</script>
