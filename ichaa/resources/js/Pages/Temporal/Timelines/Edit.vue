<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Timeline"
        :back-href="route('timelines.show', timeline.id)"
        back-label="Timeline"
        :cancel-href="route('timelines.show', timeline.id)"
        submit-label="Save Timeline"
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
    timeline: { type: Object, required: true },
})

const form = useForm({
    name: props.timeline.name ?? '',
    summary: props.timeline.summary ?? null,
})

const sections = computed(() => [
    {
        title: 'Timeline',
        fields: [
            { key: 'name', label: 'Name', required: true },
            { key: 'summary', label: 'Summary', type: 'json', jsonMode: 'document', rows: 4 },
        ],
    },
])

const submit = () => form.put(route('timelines.update', props.timeline.id))
</script>
