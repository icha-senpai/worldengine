<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="New Glossary Term"
        :back-href="route('glossary.index')"
        back-label="Glossary"
        :cancel-href="route('glossary.index')"
        submit-label="Create Term"
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
    usageContexts: { type: Array, default: () => [] },
    termStatuses: { type: Array, default: () => [] },
    originUniverses: { type: Array, default: () => [] },
})

const form = useForm({
    term: '',
    usage_context: '',
    definition: null,
    origin_universe: '',
    era_introduced: '',
    term_status: 'active',
})

const sections = computed(() => [
    {
        title: 'Term',
        fields: [
            { key: 'term', label: 'Term', required: true },
            { key: 'usage_context', label: 'Usage Context', type: 'select', required: true, options: props.usageContexts },
            { key: 'origin_universe', label: 'Origin Universe', type: 'select', options: props.originUniverses },
            { key: 'era_introduced', label: 'Era Introduced' },
            { key: 'term_status', label: 'Term Status', type: 'select', options: props.termStatuses },
        ],
    },
    {
        title: 'Definition',
        fields: [
            { key: 'definition', label: 'Definition', type: 'json', jsonMode: 'document', rows: 8, required: true },
        ],
    },
])

const submit = () => form.post(route('glossary.store'))
</script>
