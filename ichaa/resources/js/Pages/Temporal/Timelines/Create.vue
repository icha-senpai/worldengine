<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="New Timeline"
        :back-href="route('timelines.index')"
        back-label="Timelines"
        :cancel-href="route('timelines.index')"
        submit-label="Create Timeline"
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

const props = defineProps({
    embedded: { type: Boolean, default: false },
})

const form = useForm({
    name: '',
    summary: null,
    visibility: 'private',
})

const sections = computed(() => [
    {
        title: 'Timeline',
        fields: [
            { key: 'name', label: 'Name', required: true },
            { key: 'summary', label: 'Summary', type: 'json', jsonMode: 'document', rows: 4 },
            { key: 'visibility', label: 'Visibility' },
        ],
    },
])

const submit = () => form.post(route('timelines.store'))
</script>
