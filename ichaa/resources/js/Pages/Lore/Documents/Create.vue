<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="New Document"
        :back-href="route('documents.index')"
        back-label="Documents"
        :cancel-href="route('documents.index')"
        submit-label="Create Document"
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
import { toEntityOptions } from '@/Components/scaffold/formatters'

const props = defineProps({
    embedded: { type: Boolean, default: false },
    entities: { type: Array, default: () => [] },
    documentTypes: { type: Array, default: () => [] },
    documentStatuses: { type: Array, default: () => [] },
    authenticityStates: { type: Array, default: () => [] },
})

const entityOptions = computed(() => toEntityOptions(props.entities))

const form = useForm({
    title: '',
    document_type: '',
    document_authenticity: '',
    document_status: '',
    official_narrative: null,
    true_content: null,
    official_author_entity_id: '',
    true_author_entity_id: '',
    era_created: '',
})

const sections = computed(() => [
    {
        title: 'Identity',
        fields: [
            { key: 'title', label: 'Title', required: true },
            { key: 'document_type', label: 'Document Type', type: 'select', required: true, options: props.documentTypes },
            { key: 'document_authenticity', label: 'Authenticity', type: 'select', options: props.authenticityStates },
            { key: 'document_status', label: 'Document Status', type: 'select', options: props.documentStatuses },
            { key: 'era_created', label: 'Era Created', placeholder: 'Optional era label' },
        ],
    },
    {
        title: 'Authorship',
        fields: [
            {
                key: 'official_author_entity_id',
                label: 'Official Author',
                type: 'select',
                options: entityOptions.value,
                placeholder: 'Optional credited author...',
            },
            {
                key: 'true_author_entity_id',
                label: 'True Author',
                type: 'select',
                options: entityOptions.value,
                placeholder: 'Optional hidden real author...',
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

const submit = () => form.post(route('documents.store'))
</script>
