<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Crossover Entry Point"
        :back-href="route('crossover-entry-points.show', entryPoint.id)"
        back-label="Entry Point"
        :cancel-href="route('crossover-entry-points.show', entryPoint.id)"
        submit-label="Save Entry Point"
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
    entryPoint: { type: Object, required: true },
    statuses: { type: Array, default: () => [] },
})

const form = useForm({
    entry_mechanism: props.entryPoint.entry_mechanism ?? null,
    power_transition_rules: props.entryPoint.power_transition_rules ?? null,
    physical_transition_rules: props.entryPoint.physical_transition_rules ?? null,
    memory_and_identity_rules: props.entryPoint.memory_and_identity_rules ?? null,
    psychological_transition_rules: props.entryPoint.psychological_transition_rules ?? null,
    return_rules: props.entryPoint.return_rules ?? null,
    status: props.entryPoint.status ?? '',
})

const sections = computed(() => [
    {
        title: 'Entry Point',
        fields: [
            { key: 'status', label: 'Status', type: 'select', options: props.statuses },
            { key: 'entry_mechanism', label: 'Entry Mechanism JSON', type: 'json', rows: 6 },
            { key: 'power_transition_rules', label: 'Power Transition Rules JSON', type: 'json', rows: 6 },
            { key: 'physical_transition_rules', label: 'Physical Transition Rules JSON', type: 'json', rows: 6 },
            { key: 'memory_and_identity_rules', label: 'Memory and Identity Rules JSON', type: 'json', rows: 6 },
            { key: 'psychological_transition_rules', label: 'Psychological Transition Rules JSON', type: 'json', rows: 6 },
            { key: 'return_rules', label: 'Return Rules JSON', type: 'json', rows: 6 },
        ],
    },
])

const submit = () => form.put(route('crossover-entry-points.update', props.entryPoint.id))
</script>
