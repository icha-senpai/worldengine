<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="New Crossover Entry Point"
        :back-href="route('crossover-entry-points.index')"
        back-label="Crossover Entry Points"
        :cancel-href="route('crossover-entry-points.index')"
        submit-label="Create Entry Point"
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

const props = defineProps({
    embedded: { type: Boolean, default: false },
    statuses: { type: Array, default: () => [] },
})

const form = useForm({
    source_universe: '',
    entry_mechanism: null,
    status: '',
})

const sections = computed(() => [
    {
        title: 'Entry Point',
        fields: [
            { key: 'source_universe', label: 'Source Universe', required: true },
            { key: 'status', label: 'Status', type: 'select', options: props.statuses },
            { key: 'entry_mechanism', label: 'Entry Mechanism JSON', type: 'json', jsonMode: 'document', rows: 8 },
        ],
    },
])

const submit = () => form.post(route('crossover-entry-points.store'))
</script>
