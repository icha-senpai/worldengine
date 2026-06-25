<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Secret"
        :back-href="route('secrets.show', secret.id)"
        back-label="Secret"
        :cancel-href="route('secrets.show', secret.id)"
        submit-label="Save Secret"
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
    secret: { type: Object, required: true },
    entities: { type: Array, default: () => [] },
    secretTypes: { type: Array, default: () => [] },
    exposureRisks: { type: Array, default: () => [] },
})

const entityOptions = computed(() => toEntityOptions(props.entities))

const form = useForm({
    title: props.secret.title ?? '',
    secret_content: props.secret.secret_content ?? null,
    secret_type: props.secret.secret_type ?? '',
    subject_entity_ids: props.secret.subject_entity_ids ?? [],
    holder_entity_ids: props.secret.holder_entity_ids ?? [],
    known_by_entity_ids: props.secret.known_by_entity_ids ?? [],
    exposure_risk: props.secret.exposure_risk ?? '',
    revelation_trigger: props.secret.revelation_trigger ?? '',
    status: props.secret.status ?? '',
})

const sections = computed(() => [
    {
        title: 'Identity',
        fields: [
            { key: 'title', label: 'Title', required: true },
            { key: 'secret_type', label: 'Secret Type', type: 'select', options: props.secretTypes },
            { key: 'exposure_risk', label: 'Exposure Risk', type: 'select', options: props.exposureRisks },
            { key: 'revelation_trigger', label: 'Revelation Trigger', type: 'textarea', rows: 3 },
            { key: 'status', label: 'Status' },
        ],
    },
    {
        title: 'Content',
        fields: [
            { key: 'secret_content', label: 'Secret Content JSON', type: 'json', jsonMode: 'document', rows: 8 },
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

const submit = () => form.put(route('secrets.update', props.secret.id))
</script>
