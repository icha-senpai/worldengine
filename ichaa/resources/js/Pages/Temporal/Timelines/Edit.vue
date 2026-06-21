<template>
    <ScaffoldFormPage
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
    timeline: { type: Object, required: true },
})

const form = useForm({
    name: props.timeline.name ?? '',
    summary: props.timeline.summary ?? '',
})

const sections = computed(() => [
    {
        title: 'Timeline',
        fields: [
            { key: 'name', label: 'Name', required: true },
            { key: 'summary', label: 'Summary', type: 'textarea', rows: 4 },
        ],
    },
])

const submit = () => form.put(route('timelines.update', props.timeline.id))
</script>
