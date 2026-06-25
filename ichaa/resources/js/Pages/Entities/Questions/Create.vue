<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="New Question"
        :back-href="backHref"
        back-label="Entity"
        :cancel-href="backHref"
        submit-label="Create Question"
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
    entity: { type: Object, required: true },
})

const backHref = computed(() => route('entities.show', { entity: props.entity.id, tab: 'questions' }))

const priorityOptions = [
    { value: 'blocking', label: 'Blocking' },
    { value: 'high', label: 'High' },
    { value: 'medium', label: 'Medium' },
    { value: 'low', label: 'Low' },
]

const statusOptions = [
    { value: 'open', label: 'Open' },
    { value: 'deferred', label: 'Deferred' },
    { value: 'resolved', label: 'Resolved' },
]

const form = useForm({
    question: '',
    context: '',
    priority: 'medium',
    status: 'open',
    resolution: '',
})

const sections = computed(() => [
    {
        title: 'Question',
        fields: [
            { key: 'question', label: 'Question', required: true, placeholder: 'What needs to be resolved?' },
            {
                key: 'context',
                label: 'Context',
                type: 'textarea',
                rows: 4,
                placeholder: 'Why does this matter? What does it affect?',
            },
            {
                key: 'priority',
                label: 'Priority',
                type: 'select',
                options: priorityOptions,
                placeholder: 'Select priority...',
            },
            {
                key: 'status',
                label: 'Status',
                type: 'select',
                options: statusOptions,
                placeholder: 'Select status...',
            },
            {
                key: 'resolution',
                label: 'Resolution',
                type: 'textarea',
                rows: 4,
                placeholder: 'How was this resolved, if already known?',
            },
        ],
    },
])

const submit = () => form.post(route('entities.questions.store', {
    entity: props.entity.id,
    tab: 'questions',
}))
</script>
