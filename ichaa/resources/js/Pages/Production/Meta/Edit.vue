<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Meta Note"
        :back-href="backHref"
        :back-label="backLabel"
        :cancel-href="backHref"
        submit-label="Save Meta Note"
        processing-label="Saving..."
        :destroy-href="route('meta.destroy', note.id)"
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
    note: { type: Object, required: true },
    entities: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    noteTypes: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    actionStatuses: { type: Array, default: () => [] },
    symbolScopes: { type: Array, default: () => [] },
})

const entityOptions = computed(() => toEntityOptions(props.entities))
const backHref = computed(() => route('meta.show', props.note.id))
const backLabel = computed(() => props.note.title || 'Meta Note')

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
    symbol_name: props.note.symbol_name ?? '',
    symbol_origin_entity_id: props.note.symbol_origin_entity_id ?? '',
    symbol_usage_context: props.note.symbol_usage_context ?? '',
    symbol_associated_entity_ids: Array.isArray(props.note.symbol_associated_entity_ids)
        ? [...props.note.symbol_associated_entity_ids]
        : [],
    symbol_scope: props.note.symbol_scope ?? '',
    visibility: props.note.visibility ?? 'private',
    content_classification: props.note.content_classification ?? 'restricted',
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
            { key: 'visibility', label: 'Visibility', placeholder: 'private, author_only, public_knowledge...' },
            { key: 'content_classification', label: 'Content Classification', placeholder: 'restricted, sensitive, open...' },
        ],
    },
    {
        title: 'Content',
        fields: [
            {
                key: 'content',
                label: 'Content',
                type: 'json',
                jsonMode: 'document',
                rows: 8,
                placeholder: 'Write the core note...',
                help: 'Rich text with the full editor suite.',
            },
            {
                key: 'resolution_notes',
                label: 'Resolution Notes',
                type: 'json',
                jsonMode: 'document',
                rows: 6,
                placeholder: 'Add resolution details if this note is settled...',
                help: 'Use this when the note is resolved or needs outcome context.',
            },
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
    {
        title: 'Symbolism',
        fields: [
            { key: 'symbol_name', label: 'Symbol Name' },
            { key: 'symbol_scope', label: 'Symbol Scope', type: 'select', options: props.symbolScopes, placeholder: 'Select a scope...' },
            {
                key: 'symbol_origin_entity_id',
                label: 'Symbol Origin Entity',
                type: 'select',
                options: entityOptions.value,
                placeholder: 'Optional origin entity...',
            },
            { key: 'symbol_usage_context', label: 'Symbol Usage Context', type: 'textarea', rows: 3 },
            {
                key: 'symbol_associated_entity_ids',
                label: 'Associated Entities',
                type: 'multiselect',
                options: entityOptions.value,
                emptyMessage: 'No entities exist yet to associate with this symbol.',
            },
        ],
    },
])

const submit = () => form.put(route('meta.update', props.note.id))
</script>
