<template>
    <ScaffoldFormPage
        title="Edit Meta Note"
        :back-href="route('meta.show', note.id)"
        back-label="Meta Note"
        :cancel-href="route('meta.show', note.id)"
        submit-label="Save Meta Note"
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
    note: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    noteTypes: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    actionStatuses: { type: Array, default: () => [] },
    symbolScopes: { type: Array, default: () => [] },
})

const form = useForm({
    title: props.note.title ?? '',
    category: props.note.category ?? '',
    meta_note_type: props.note.meta_note_type ?? '',
    content: props.note.content ?? null,
    priority: props.note.priority ?? '',
    action_status: props.note.action_status ?? '',
    resolution_notes: props.note.resolution_notes ?? null,
    resolved_at: props.note.resolved_at ?? '',
    sense_sight: props.note.sense_sight ?? '',
    sense_sound: props.note.sense_sound ?? '',
    sense_smell: props.note.sense_smell ?? '',
    sense_taste: props.note.sense_taste ?? '',
    sense_touch: props.note.sense_touch ?? '',
    sense_magical: props.note.sense_magical ?? '',
    emotional_register: props.note.emotional_register ?? '',
})

const sections = computed(() => [
    {
        title: 'Identity',
        fields: [
            { key: 'title', label: 'Title', required: true },
            { key: 'category', label: 'Category', type: 'select', options: props.categories },
            { key: 'meta_note_type', label: 'Note Type', type: 'select', options: props.noteTypes },
            { key: 'priority', label: 'Priority', type: 'select', options: props.priorities },
            { key: 'action_status', label: 'Action Status', type: 'select', options: props.actionStatuses },
            { key: 'resolved_at', label: 'Resolved At', type: 'text', placeholder: 'YYYY-MM-DD HH:MM:SS or leave blank' },
        ],
    },
    {
        title: 'Content',
        fields: [
            { key: 'content', label: 'Content JSON', type: 'json', rows: 8 },
            { key: 'resolution_notes', label: 'Resolution Notes JSON', type: 'json', rows: 6 },
        ],
    },
    {
        title: 'Sensory and Emotional',
        fields: [
            { key: 'sense_sight', label: 'Sight' },
            { key: 'sense_sound', label: 'Sound' },
            { key: 'sense_smell', label: 'Smell' },
            { key: 'sense_taste', label: 'Taste' },
            { key: 'sense_touch', label: 'Touch' },
            { key: 'sense_magical', label: 'Magical' },
            { key: 'emotional_register', label: 'Emotional Register' },
        ],
    },
])

const submit = () => form.put(route('meta.update', props.note.id))
</script>
