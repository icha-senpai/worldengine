<template>
    <ScaffoldFormPage
        title="New Secret"
        :back-href="route('secrets.index')"
        back-label="Secrets"
        :cancel-href="route('secrets.index')"
        submit-label="Create Secret"
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
    entities: { type: Array, default: () => [] },
    secretTypes: { type: Array, default: () => [] },
    exposureRisks: { type: Array, default: () => [] },
})

const entityOptions = computed(() => toEntityOptions(props.entities))

const form = useForm({
    title: '',
    secret_content: null,
    secret_type: '',
    subject_entity_ids: [],
    holder_entity_ids: [],
    known_by_entity_ids: [],
    exposure_risk: '',
    status: '',
})

const sections = computed(() => [
    {
        title: 'Identity',
        fields: [
            { key: 'title', label: 'Title', required: true },
            { key: 'secret_type', label: 'Secret Type', type: 'select', required: true, options: props.secretTypes },
            { key: 'exposure_risk', label: 'Exposure Risk', type: 'select', options: props.exposureRisks },
            { key: 'status', label: 'Status' },
        ],
    },
    {
        title: 'Content',
        fields: [
            { key: 'secret_content', label: 'Secret Content JSON', type: 'json', rows: 8, required: true },
            {
                key: 'subject_entity_ids',
                label: 'Subject Entities',
                type: 'multiselect',
                options: entityOptions.value,
            },
            {
                key: 'holder_entity_ids',
                label: 'Holder Entities',
                type: 'multiselect',
                options: entityOptions.value,
            },
            {
                key: 'known_by_entity_ids',
                label: 'Known By Entities',
                type: 'multiselect',
                options: entityOptions.value,
            },
        ],
    },
])

const submit = () => form.post(route('secrets.store'))
</script>
