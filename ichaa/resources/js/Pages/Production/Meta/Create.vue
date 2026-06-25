<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="New Meta Note"
        :back-href="backHref"
        back-label="Meta"
        :cancel-href="backHref"
        submit-label="Create Meta Note"
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
    embedded: { type: Boolean, default: false },
    entities: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    noteTypes: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    actionStatuses: { type: Array, default: () => [] },
    symbolScopes: { type: Array, default: () => [] },
    visibilityLevels: { type: Array, default: () => [] },
    contentClassifications: { type: Array, default: () => [] },
})

const entityOptions = computed(() => toEntityOptions(props.entities))
const backHref = computed(() => route('meta.index'))

const form = useForm({
    title: '',
    category: '',
    meta_note_type: '',
    content: null,
    sense_sight: '',
    sense_sound: '',
    sense_smell: '',
    sense_taste: '',
    sense_touch: '',
    sense_magical: '',
    emotional_register: '',
    symbol_name: '',
    symbol_origin_entity_id: '',
    symbol_usage_context: '',
    symbol_associated_entity_ids: [],
    symbol_scope: '',
    priority: '',
    action_status: '',
    visibility: 'private',
    content_classification: 'restricted',
})

const sections = computed(() => [
    {
        title: 'Identity',
        fields: [
            { key: 'title', label: 'Title', required: true },
            { key: 'category', label: 'Category', type: 'select', required: true, options: props.categories },
            { key: 'meta_note_type', label: 'Note Type', type: 'select', required: true, options: props.noteTypes },
            { key: 'priority', label: 'Priority', type: 'select', options: props.priorities },
            { key: 'action_status', label: 'Action Status', type: 'select', options: props.actionStatuses },
            { key: 'visibility', label: 'Visibility', type: 'select', options: props.visibilityLevels },
            { key: 'content_classification', label: 'Content Classification', type: 'select', options: props.contentClassifications },
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
            { key: 'symbol_scope', label: 'Symbol Scope', type: 'select', options: props.symbolScopes },
        ],
    },
])

const submit = () => form.post(route('meta.store'))
</script>
