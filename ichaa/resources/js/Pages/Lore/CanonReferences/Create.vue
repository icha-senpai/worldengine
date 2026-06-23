<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="New Canon Reference"
        :back-href="route('canon-references.index')"
        back-label="Canon References"
        :cancel-href="route('canon-references.index')"
        submit-label="Create Reference"
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
import { formatLabel } from '@/Components/scaffold/formatters'

const props = defineProps({
    embedded: { type: Boolean, default: false },
    parentReferences: { type: Array, default: () => [] },
    levels: { type: Array, default: () => [] },
    categoryTypes: { type: Array, default: () => [] },
    elementTypes: { type: Array, default: () => [] },
    researchStatuses: { type: Array, default: () => [] },
    universePriorities: { type: Array, default: () => [] },
})

const parentReferenceOptions = computed(() =>
    props.parentReferences.map((reference) => ({
        value: reference.id,
        label: `${reference.title} (#${reference.id}${reference.level ? ` · ${formatLabel(reference.level)}` : ''}${reference.universe ? ` · ${reference.universe}` : ''})`,
    }))
)

const form = useForm({
    universe: '',
    level: '',
    title: '',
    parent_reference_id: '',
    universe_priority: '',
    research_status: '',
})

const sections = computed(() => [
    {
        title: 'Reference',
        fields: [
            { key: 'universe', label: 'Universe', required: true },
            { key: 'level', label: 'Level', type: 'select', required: true, options: props.levels },
            { key: 'title', label: 'Title', required: true },
            {
                key: 'parent_reference_id',
                label: 'Parent Reference',
                type: 'select',
                options: parentReferenceOptions.value,
                placeholder: 'Optional parent canon reference...',
            },
            { key: 'universe_priority', label: 'Universe Priority', type: 'select', options: props.universePriorities },
            { key: 'research_status', label: 'Research Status', type: 'select', options: props.researchStatuses },
        ],
    },
])

const submit = () => form.post(route('canon-references.store'))
</script>
