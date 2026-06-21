<template>
    <ScaffoldFormPage
        title="Edit Document"
        :back-href="route('documents.show', document.id)"
        back-label="Document"
        :cancel-href="route('documents.show', document.id)"
        submit-label="Save Document"
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
import { toEntityOptions } from '@/Components/scaffold/formatters'

const props = defineProps({
    document: { type: Object, required: true },
    entities: { type: Array, default: () => [] },
    documentTypes: { type: Array, default: () => [] },
    documentStatuses: { type: Array, default: () => [] },
    authenticityStates: { type: Array, default: () => [] },
})

const entityOptions = computed(() => toEntityOptions(props.entities))

const form = useForm({
    title: props.document.title ?? '',
    document_authenticity: props.document.document_authenticity ?? '',
    document_status: props.document.document_status ?? '',
    official_narrative: props.document.official_narrative ?? null,
    true_content: props.document.true_content ?? null,
    true_author_entity_id: props.document.true_author_entity_id ?? '',
    suppressed_by_entity_id: props.document.suppressed_by_entity_id ?? '',
})

const sections = computed(() => [
    {
        title: 'Identity',
        fields: [
            { key: 'title', label: 'Title', required: true },
            { key: 'document_authenticity', label: 'Authenticity', type: 'select', options: props.authenticityStates },
            { key: 'document_status', label: 'Document Status', type: 'select', options: props.documentStatuses },
        ],
    },
    {
        title: 'Links',
        fields: [
            {
                key: 'true_author_entity_id',
                label: 'True Author',
                type: 'select',
                options: entityOptions.value,
                placeholder: 'Optional hidden real author...',
            },
            {
                key: 'suppressed_by_entity_id',
                label: 'Suppressed By',
                type: 'select',
                options: entityOptions.value,
                placeholder: 'Optional suppressing entity...',
            },
        ],
    },
    {
        title: 'Narratives',
        fields: [
            { key: 'official_narrative', label: 'Official Narrative JSON', type: 'json', rows: 8 },
            { key: 'true_content', label: 'True Content JSON', type: 'json', rows: 8 },
        ],
    },
])

const submit = () => form.put(route('documents.update', props.document.id))
</script>
