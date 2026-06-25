<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="New Session Log"
        :back-href="route('session-logs.index')"
        back-label="Session Logs"
        :cancel-href="route('session-logs.index')"
        submit-label="Create Session Log"
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
import {
    toCollectionOptions,
    toEntityOptions,
    toGroupRelationshipOptions,
} from '@/Components/scaffold/formatters'

const props = defineProps({
    embedded: { type: Boolean, default: false },
    entities: { type: Array, default: () => [] },
    groupRelationships: { type: Array, default: () => [] },
    collections: { type: Array, default: () => [] },
    significanceLevels: { type: Array, default: () => [] },
})

const entityOptions = computed(() => toEntityOptions(props.entities))
const groupRelationshipOptions = computed(() => toGroupRelationshipOptions(props.groupRelationships))
const collectionOptions = computed(() => toCollectionOptions(props.collections))

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
    title: '',
    session_date: '',
    external_tool: '',
    focus_entity_ids: [],
    focus_group_relationship_ids: [],
    focus_collection_ids: [],
    focus_description: '',
    decisions_made: null,
    changes_applied: null,
    open_threads: null,
    session_significance: '',
    notes: null,
})

const sections = computed(() => [
    {
        title: 'Session',
        fields: [
            { key: 'title', label: 'Title', required: true },
            { key: 'session_date', label: 'Session Date', type: 'date' },
            { key: 'external_tool', label: 'External Tool', type: 'select', required: true, options: externalTools },
            { key: 'session_significance', label: 'Session Significance', type: 'select', options: props.significanceLevels },
            { key: 'focus_description', label: 'Focus Description' },
        ],
    },
    {
        title: 'Focus',
        fields: [
            {
                key: 'focus_entity_ids',
                label: 'Focus Entities',
                type: 'multiselect',
                options: entityOptions.value,
                emptyMessage: 'Create some entities first if you want to tag this session to them.',
            },
            {
                key: 'focus_group_relationship_ids',
                label: 'Focus Group Relationships',
                type: 'multiselect',
                options: groupRelationshipOptions.value,
                emptyMessage: 'No group relationships exist yet.',
            },
            {
                key: 'focus_collection_ids',
                label: 'Focus Collections',
                type: 'multiselect',
                options: collectionOptions.value,
                emptyMessage: 'No collections exist yet.',
            },
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

const submit = () => form.post(route('session-logs.store'))
</script>
