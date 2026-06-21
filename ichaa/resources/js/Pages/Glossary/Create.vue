<template>
    <ScaffoldFormPage
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

const form = useForm({
    term: '',
    usage_context: '',
    definition: null,
    origin_universe: '',
    era_introduced: '',
    term_status: '',
})

const sections = computed(() => [
    {
        title: 'Term',
        fields: [
            { key: 'term', label: 'Term', required: true },
            { key: 'usage_context', label: 'Usage Context', required: true },
            { key: 'origin_universe', label: 'Origin Universe' },
            { key: 'era_introduced', label: 'Era Introduced' },
            { key: 'term_status', label: 'Term Status' },
        ],
    },
    {
        title: 'Definition',
        fields: [
            { key: 'definition', label: 'Definition JSON', type: 'json', rows: 8, required: true },
        ],
    },
])

const submit = () => form.post(route('glossary.store'))
</script>
