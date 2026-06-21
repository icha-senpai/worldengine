<template>
    <ScaffoldFormPage
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
    term: { type: Object, required: true },
})

const form = useForm({
    term: props.term.term ?? '',
    usage_context: props.term.usage_context ?? '',
    definition: props.term.definition ?? null,
    term_status: props.term.term_status ?? '',
})

const sections = computed(() => [
    {
        title: 'Term',
        fields: [
            { key: 'term', label: 'Term', required: true },
            { key: 'usage_context', label: 'Usage Context', required: true },
            { key: 'term_status', label: 'Term Status' },
        ],
    },
    {
        title: 'Definition',
        fields: [
            { key: 'definition', label: 'Definition JSON', type: 'json', rows: 8 },
        ],
    },
])

const submit = () => form.put(route('glossary.update', props.term.id))
</script>
