<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Perception State"
        :back-href="route('perception-states.show', state.id)"
        back-label="Perception State"
        :cancel-href="route('perception-states.show', state.id)"
        submit-label="Save Perception State"
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
    embedded: { type: Boolean, default: false },
    state: { type: Object, required: true },
    maintenanceMethods: { type: Array, default: () => [] },
    maintenanceEfforts: { type: Array, default: () => [] },
    revelationRisks: { type: Array, default: () => [] },
})

const form = useForm({
    true_state: props.state.true_state ?? null,
    perceived_state: props.state.perceived_state ?? null,
    divergence_level: props.state.divergence_level ?? '',
    maintenance_effort: props.state.maintenance_effort ?? '',
    revelation_risk: props.state.revelation_risk ?? '',
})

const sections = computed(() => [
    {
        title: 'State',
        fields: [
            { key: 'divergence_level', label: 'Divergence Level' },
            { key: 'maintenance_effort', label: 'Maintenance Effort', type: 'select', options: props.maintenanceEfforts },
            { key: 'revelation_risk', label: 'Revelation Risk', type: 'select', options: props.revelationRisks },
            { key: 'true_state', label: 'True State JSON', type: 'json', rows: 8 },
            { key: 'perceived_state', label: 'Perceived State JSON', type: 'json', rows: 8 },
        ],
    },
])

const submit = () => form.put(route('perception-states.update', props.state.id))
</script>
