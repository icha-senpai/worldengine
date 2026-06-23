<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Canon Reference"
        :back-href="route('canon-references.show', reference.id)"
        back-label="Reference"
        :cancel-href="route('canon-references.show', reference.id)"
        submit-label="Save Reference"
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
    embedded: { type: Boolean, default: false },
    reference: { type: Object, required: true },
    entities: { type: Array, default: () => [] },
    researchStatuses: { type: Array, default: () => [] },
})

const entityOptions = computed(() => toEntityOptions(props.entities))

const form = useForm({
    title: props.reference.title ?? '',
    content: props.reference.content ?? null,
    research_status: props.reference.research_status ?? '',
    research_confidence: props.reference.research_confidence ?? '',
    canon_disputed: props.reference.canon_disputed ?? false,
    au_entity_id: props.reference.au_entity_id ?? '',
})

const sections = computed(() => [
    {
        title: 'Reference',
        fields: [
            { key: 'title', label: 'Title', required: true },
            { key: 'research_status', label: 'Research Status', type: 'select', options: props.researchStatuses },
            { key: 'research_confidence', label: 'Research Confidence' },
            { key: 'canon_disputed', label: 'Canon Disputed', type: 'checkbox' },
            {
                key: 'au_entity_id',
                label: 'AU Entity',
                type: 'select',
                options: entityOptions.value,
                placeholder: 'Optional AU counterpart...',
            },
        ],
    },
    {
        title: 'Content',
        fields: [
            { key: 'content', label: 'Content JSON', type: 'json', rows: 8 },
        ],
    },
])

const submit = () => form.put(route('canon-references.update', props.reference.id))
</script>
