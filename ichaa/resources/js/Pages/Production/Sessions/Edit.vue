<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Session Log"
        :back-href="route('session-logs.show', session.id)"
        back-label="Session"
        :cancel-href="route('session-logs.show', session.id)"
        submit-label="Save Session Log"
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
    session: { type: Object, required: true },
    significanceLevels: { type: Array, default: () => [] },
})

const externalTools = [
    'notion',
    'chatgpt',
    'qwen',
    'claude',
    'handwritten',
    'voice_memo',
    'other',
]

const form = useForm({
    title: props.session.title ?? '',
    session_date: props.session.session_date ?? '',
    external_tool: props.session.external_tool ?? '',
    focus_description: props.session.focus_description ?? '',
    decisions_made: props.session.decisions_made ?? null,
    changes_applied: props.session.changes_applied ?? null,
    open_threads: props.session.open_threads ?? null,
    session_significance: props.session.session_significance ?? '',
    notes: props.session.notes ?? null,
})

const sections = computed(() => [
    {
        title: 'Session',
        fields: [
            { key: 'title', label: 'Title', required: true },
            { key: 'session_date', label: 'Session Date', type: 'text', placeholder: 'YYYY-MM-DD' },
            { key: 'external_tool', label: 'External Tool', type: 'select', options: externalTools },
            { key: 'session_significance', label: 'Session Significance', type: 'select', options: props.significanceLevels },
            { key: 'focus_description', label: 'Focus Description' },
        ],
    },
    {
        title: 'Working Notes',
        fields: [
            { key: 'decisions_made', label: 'Decisions Made JSON', type: 'json', jsonMode: 'document', rows: 6 },
            { key: 'changes_applied', label: 'Changes Applied JSON', type: 'json', jsonMode: 'document', rows: 6 },
            { key: 'open_threads', label: 'Open Threads JSON', type: 'json', jsonMode: 'document', rows: 6 },
            { key: 'notes', label: 'Notes JSON', type: 'json', jsonMode: 'document', rows: 6 },
        ],
    },
])

const submit = () => form.put(route('session-logs.update', props.session.id))
</script>
