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

const props = defineProps({
    embedded: { type: Boolean, default: false },
    secret: { type: Object, required: true },
    secretTypes: { type: Array, default: () => [] },
    exposureRisks: { type: Array, default: () => [] },
})

const form = useForm({
    title: props.secret.title ?? '',
    secret_content: props.secret.secret_content ?? null,
    exposure_risk: props.secret.exposure_risk ?? '',
    revelation_trigger: props.secret.revelation_trigger ?? '',
    status: props.secret.status ?? '',
})

const sections = computed(() => [
    {
        title: 'Identity',
        fields: [
            { key: 'title', label: 'Title', required: true },
            { key: 'exposure_risk', label: 'Exposure Risk', type: 'select', options: props.exposureRisks },
            { key: 'revelation_trigger', label: 'Revelation Trigger', type: 'textarea', rows: 3 },
            { key: 'status', label: 'Status' },
        ],
    },
    {
        title: 'Content',
        fields: [
            { key: 'secret_content', label: 'Secret Content JSON', type: 'json', rows: 8 },
        ],
    },
])

const submit = () => form.put(route('secrets.update', props.secret.id))
</script>
