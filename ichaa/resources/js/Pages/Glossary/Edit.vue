<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Glossary Term"
        :back-href="route('glossary.show', term.id)"
        back-label="Term"
        :cancel-href="route('glossary.show', term.id)"
        submit-label="Save Term"
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
    term: { type: Object, required: true },
    usageContexts: { type: Array, default: () => [] },
    termStatuses: { type: Array, default: () => [] },
    originUniverses: { type: Array, default: () => [] },
})

const form = useForm({
    term: props.term.term ?? '',
    usage_context: props.term.usage_context ?? '',
    definition: props.term.definition ?? null,
    origin_universe: props.term.origin_universe ?? '',
    era_introduced: props.term.era_introduced ?? '',
    term_status: props.term.term_status ?? '',
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
            { key: 'definition', label: 'Definition', type: 'json', jsonMode: 'document', rows: 8 },
        ],
    },
])

const submit = () => form.put(route('glossary.update', props.term.id))
</script>
